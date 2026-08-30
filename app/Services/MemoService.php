<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Events\MemoIssued;
use App\Models\Memo;
use App\Models\MemoRecipient;
use App\Models\Status;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens when a memo is drafted, addressed, issued, read or withdrawn (§17).
 *
 * Two of these are more than bookkeeping and are worth reading before changing anything here.
 *
 * ISSUING IS STRUCTURAL, NOT NOMINAL. `issued_at` is stamped on the first move AWAY FROM THE
 * CONFIGURED INITIAL STATUS — not when the target status happens to be called "issued". A tenant
 * that renames Draft/Issued to Prepared/Released, or inserts an approval step, keeps working: the
 * draft curtain in ScopeCompiler::applyPublication() reads the timestamp, and the timestamp
 * follows the shape of the state machine rather than an English word somebody chose in a settings
 * screen (§62, §63).
 *
 * ISSUING A RESTRICTED MEMO GRANTS ITS RECIPIENTS ACCESS TO IT, EXPLICITLY. Being on the
 * distribution reaches the memo through `memo.view:assigned` — but Gate 5 runs afterwards, and a
 * `confidential` memo needs a record ACL row on top. Without one, the module's most important case
 * fails in its most alarming direction: a disciplinary memo addressed to somebody in another
 * department would be invisible to the very person it was written for, while the issuer's screen
 * shows it as delivered. So the service writes the ACL rows, at issue, through the record-ACL
 * engine that exists for exactly this — rather than the model growing a special case that the
 * compiler would have to be taught about (§90).
 *
 * What this class does NOT do is authorize anything: callers have been through MemoPolicy.
 */
class MemoService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes  already validated
     */
    public function create(array $attributes, User $actor): Memo
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $memo = new Memo($attributes);

            // Tenant, number, status and authorship belong to the service, never to the request.
            $memo->organization_id = $actor->organization_id;
            $memo->created_by = $actor->id;
            $memo->number = $this->numbers->next('memo');
            $memo->status_id ??= $this->statuses->initialFor('memo')?->id;
            $memo->confidentiality ??= Confidentiality::Internal;

            // A memo is born a draft whatever anybody posts. The publication column is the access
            // boundary for this module, so it is set in exactly one place — transition() — and
            // never from an attribute array (§84).
            $memo->issued_at = null;
            $memo->issued_by = null;

            $memo->save();

            $this->activity->record(
                $memo,
                'created',
                sprintf('%s drafted memo %s', $actor->name, $memo->displayName()),
                ['number' => $memo->number],
                $actor,
            );

            return $memo->refresh();
        });
    }

    /**
     * Edit a draft.
     *
     * Every column that decides who can see the memo is stripped here and handled by its own
     * method: the number is the numbering engine's, the status is the state machine's, and the
     * publication stamp is transition()'s. MemoPolicy has already refused this call outright if the
     * memo is issued.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Memo $memo, array $attributes, User $actor): Memo
    {
        unset(
            $attributes['number'],
            $attributes['organization_id'],
            $attributes['created_by'],
            $attributes['status_id'],
            $attributes['issued_at'],
            $attributes['issued_by'],
            $attributes['withdrawn_reason'],
        );

        return DB::transaction(function () use ($memo, $attributes, $actor) {
            $memo->fill($attributes);

            $changed = array_keys($memo->getDirty());

            if ($changed === []) {
                return $memo;
            }

            $memo->save();

            $this->activity->record(
                $memo,
                'updated',
                sprintf('%s updated %s', $actor->name, $memo->displayName()),
                ['fields' => $changed],
                $actor,
            );

            // project_id, org_unit_id and confidentiality are all reach columns.
            $this->profile->flush();

            return $memo->refresh();
        });
    }

    /**
     * Put people on the distribution.
     *
     * The caller has cleared every id through MemoPolicy::address() with that exact candidate.
     * Returns the ids actually added, so the controller can report "3 added, 2 were already on it"
     * rather than claiming work it did not do.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,int>
     */
    public function address(Memo $memo, array $userIds, User $actor, string $kind = 'to'): array
    {
        $kind = in_array($kind, ['to', 'cc'], true) ? $kind : 'to';

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = $memo->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $new = array_values(array_diff($userIds, $existing));

        if ($new === []) {
            return [];
        }

        DB::transaction(function () use ($memo, $new, $actor, $kind) {
            foreach ($new as $userId) {
                MemoRecipient::query()->create([
                    'organization_id' => $memo->organization_id,
                    'memo_id' => $memo->getKey(),
                    'user_id' => $userId,
                    'kind' => $kind,
                    'created_by' => $actor->id,
                ]);
            }

            // Adding somebody to an ALREADY ISSUED memo has to grant them the same explicit access
            // the original recipients got, or a late addition to a confidential distribution would
            // be told about a memo they cannot open.
            if ($memo->isIssued()) {
                $this->grantRecipientAccess($memo, $new, $actor);
            }

            $this->activity->record(
                $memo,
                'addressed',
                sprintf('%s added %d recipient(s) to %s', $actor->name, count($new), $memo->displayName()),
                ['user_ids' => $new, 'kind' => $kind],
                $actor,
            );

            // The new recipients' reach has changed.
            $this->profile->flush();
        });

        return $new;
    }

    /**
     * Take somebody off a draft's distribution.
     *
     * Only reachable while the memo is a draft, because MemoPolicy::update() closes on issue.
     * Removing a name from an issued memo would falsify the record of who was told.
     */
    public function unaddress(Memo $memo, int $userId, User $actor): void
    {
        DB::transaction(function () use ($memo, $userId, $actor) {
            $removed = $memo->recipients()->where('user_id', $userId)->delete();

            if ($removed === 0) {
                return;
            }

            $this->revokeRecipientAccess($memo, [$userId]);

            $this->activity->record(
                $memo,
                'unaddressed',
                sprintf('%s removed a recipient from %s', $actor->name, $memo->displayName()),
                ['user_id' => $userId],
                $actor,
            );

            $this->profile->flush();
        });
    }

    /**
     * Move the memo through the configured whitelist, and do the two things that follow from it.
     *
     * The state machine decides whether the move is legal and who may make it. This adds:
     *
     *   - The publication stamp, on the first move away from the initial status. See the class
     *     docblock for why that is a shape and not a name.
     *   - The retraction reason, on a move into a terminal status after issue. Withdrawing a memo
     *     people have already acted on is a statement, and "it disappeared" is not one.
     *
     * @throws \DomainException when the move is not defined, not permitted, or incomplete
     */
    public function transition(Memo $memo, Status $to, User $actor, ?string $comment = null): Memo
    {
        $initial = $this->statuses->initialFor('memo');
        $leavingInitial = $initial === null || (int) $to->getKey() !== (int) $initial->getKey();

        // A memo addressed to nobody is not a communication, and issuing one would fire an event
        // with an empty audience — indistinguishable, afterwards, from an audience that was
        // correctly filtered out. Refused here rather than in the form request because a memo can
        // lose its last recipient between the two.
        if ($memo->isDraft() && $leavingInitial && ! $memo->recipients()->exists()) {
            throw new \DomainException('Add at least one recipient before issuing this memo.');
        }

        return DB::transaction(function () use ($memo, $to, $actor, $comment, $leavingInitial) {
            $wasDraft = $memo->isDraft();

            $this->statuses->transition($memo, $to, $actor, $comment);
            $memo->refresh();

            if ($wasDraft && $leavingInitial) {
                $memo->issued_at = now();
                $memo->issued_by = $actor->id;
                $memo->saveQuietly();
                $memo->refresh();

                $recipientIds = $memo->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

                $this->grantRecipientAccess($memo, $recipientIds, $actor);
                $this->profile->flush();

                MemoIssued::dispatch($memo, (int) $actor->id);

                return $memo;
            }

            // Withdrawal: terminal, and only meaningful for something that went out.
            if ($to->is_terminal && $memo->isIssued() && $memo->withdrawn_reason === null) {
                $memo->withdrawn_reason = $comment !== null
                    ? mb_substr(trim($comment), 0, 500)
                    : 'Withdrawn by '.$actor->name;
                $memo->saveQuietly();
                $memo->refresh();
            }

            return $memo;
        });
    }

    /**
     * Stamp a read receipt.
     *
     * Idempotent, and first-read-wins: the interesting fact is when somebody first opened it, and
     * re-stamping on every visit would turn the receipt into a last-seen tracker nobody asked for.
     * MemoRecipientPolicy has already established that the actor is the person named on the row.
     */
    public function markRead(MemoRecipient $recipient): void
    {
        if ($recipient->read_at !== null) {
            return;
        }

        $recipient->read_at = now();

        // saveQuietly: a read receipt is not an edit to the memo, and letting it raise the audit
        // observer would bury the record's real history under one row per person per open.
        $recipient->saveQuietly();
    }

    // ------------------------------------------------------------------ record ACL

    /**
     * Give named recipients explicit access to a memo their ordinary scope will not reach.
     *
     * Only for `restricted` and `confidential`. A `public` or `internal` memo is already reachable
     * through `memo.view:assigned`, and writing an ACL row per recipient for every routine memo
     * would fill the table that the access engine consults on every single record check.
     *
     * The ability is the exact permission key, never `*`. A recipient should be able to READ the
     * memo they were sent; nothing about being addressed implies editing it, withdrawing it, or
     * changing who else received it.
     *
     * @param  array<int,int>  $userIds
     */
    private function grantRecipientAccess(Memo $memo, array $userIds, User $actor): void
    {
        if ($userIds === [] || ! $this->needsExplicitGrant($memo)) {
            return;
        }

        $now = now();

        $rows = array_map(fn (int $userId) => [
            'organization_id' => $memo->organization_id,
            'record_type' => $memo->getMorphClass(),
            'record_id' => $memo->getKey(),
            'grantee_type' => 'user',
            'grantee_id' => $userId,
            'ability' => 'memo.view',
            'effect' => 'allow',
            'granted_by' => $actor->id,
            'reason' => 'On the distribution list',
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds);

        // upsert against the table's own unique key, so re-issuing or re-adding is harmless.
        DB::table('record_permissions')->upsert(
            $rows,
            ['record_type', 'record_id', 'grantee_type', 'grantee_id', 'ability'],
            ['effect', 'granted_by', 'reason', 'expires_at', 'updated_at'],
        );
    }

    /**
     * Take the grant back when somebody comes off a draft's distribution.
     *
     * Scoped to the rows this service wrote — grantee_type `user`, ability `memo.view` — so a
     * separate, deliberate share of the same memo to the same person survives. Removing somebody
     * from a list must not silently revoke a grant somebody else made for another reason.
     *
     * @param  array<int,int>  $userIds
     */
    private function revokeRecipientAccess(Memo $memo, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        DB::table('record_permissions')
            ->where('record_type', $memo->getMorphClass())
            ->where('record_id', $memo->getKey())
            ->where('grantee_type', 'user')
            ->where('ability', 'memo.view')
            ->where('reason', 'On the distribution list')
            ->whereIn('grantee_id', $userIds)
            ->delete();
    }

    private function needsExplicitGrant(Memo $memo): bool
    {
        return in_array(
            $memo->confidentiality,
            [Confidentiality::Restricted, Confidentiality::Confidential],
            true,
        );
    }
}
