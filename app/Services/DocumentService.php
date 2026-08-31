<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Document\FileVault;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens to a document as an IDENTITY — its metadata, its lock, its lifecycle.
 *
 * Revisions belong to DocumentVersionService; this class never writes one. The split is the same one
 * the schema makes: this is the number people quote, that is what the number pointed at on a given
 * day, and keeping the two services apart is what stops "update the document" quietly meaning
 * "overwrite the approved revision".
 *
 * CHECK-OUT is here, and it is worth being precise about what it is and is not. It is not a security
 * control — DocumentPolicy decides who may edit. It is a COORDINATION control: it stops two people
 * unknowingly writing the same procedure at the same time and one of them losing an afternoon. That
 * is why an expired lock can be taken over by anybody who could have edited anyway, and why taking
 * one over is recorded rather than prevented. Treating it as security would mean holding a lock
 * granted permission, which it must never do.
 */
class DocumentService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly WorkflowEngine $workflow,
        private readonly FileVault $vault,
        private readonly SettingsRepository $settings,
        private readonly ActorProfile $profile,
    ) {}

    // ================================================================== creating

    /**
     * Raise a document.
     *
     * Tenant, number, authorship and status are set HERE and are not accepted from the caller,
     * whatever a form posts (§74, §84). The category supplies the defaults a company configured —
     * confidentiality, whether it is controlled, native or file-based — so a register behaves
     * consistently without anybody re-choosing on every upload.
     *
     * @param  array<string,mixed>  $attributes  already validated and policy-checked
     */
    public function create(array $attributes, User $actor): Document
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $category = isset($attributes['document_category_id'])
                ? DocumentCategory::query()->find($attributes['document_category_id'])
                : null;

            $document = new Document($this->safeAttributes($attributes));

            $document->organization_id = $actor->organization_id;
            $document->created_by = $actor->getKey();
            $document->owner_id = $attributes['owner_id'] ?? $actor->getKey();
            $document->number = $this->numbers->next('document');
            $document->status_id = $this->statuses->initialFor('document')?->id;

            $document->kind = $this->resolveKind($attributes, $category);
            $document->confidentiality = $this->resolveConfidentiality($attributes, $category);
            $document->is_controlled = (bool) ($attributes['is_controlled'] ?? $category?->is_controlled ?? false);

            if ($category?->retention_months !== null) {
                $document->retention_until = now()->addMonths((int) $category->retention_months);
            }

            $document->save();

            $this->activity->record(
                $document,
                'created',
                $actor->name.' raised '.$document->displayName().'.',
                array_filter([
                    'number' => $document->number,
                    'category' => $category?->name,
                    'kind' => $document->kind->value,
                ]),
                $actor,
                $document->project_id !== null ? (int) $document->project_id : null,
                $document->org_unit_id !== null ? (int) $document->org_unit_id : null,
            );

            // Ownership widens reach; the memoized profile no longer describes reality.
            $this->profile->flush();

            return $document;
        });
    }

    /**
     * Change a document's metadata.
     *
     * Never its number and never its organization. A document number is quoted in transmittals,
     * client correspondence and other companies' registers; letting it be edited would break
     * references this system cannot see and cannot fix.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Document $document, array $attributes, User $actor): Document
    {
        return DB::transaction(function () use ($document, $attributes, $actor) {
            $before = $document->only(['title', 'confidentiality', 'document_category_id', 'owner_id']);

            $document->fill($this->safeAttributes($attributes));

            if (array_key_exists('confidentiality', $attributes)) {
                $document->confidentiality = Confidentiality::from((string) $attributes['confidentiality']);
            }

            $document->save();

            $changed = array_keys(array_diff_assoc(
                $document->only(array_keys($before)),
                $before,
            ));

            if ($changed !== []) {
                $this->activity->record(
                    $document,
                    'updated',
                    $actor->name.' updated '.implode(', ', $changed).'.',
                    ['fields' => $changed],
                    $actor,
                );
            }

            if (in_array('confidentiality', $changed, true) || in_array('owner_id', $changed, true)) {
                $this->profile->flush();
            }

            return $document;
        });
    }

    // ================================================================== check-out

    /**
     * Take the document out for editing.
     *
     * Returns the document either way; the caller checks `isLockedBy()`. Throwing on a held lock
     * would be wrong — "somebody else has it" is a normal state of a shared register, not an error.
     *
     * @throws \DomainException when somebody else holds a live lock
     */
    public function checkOut(Document $document, User $actor): Document
    {
        if ($document->isLockedByAnother($actor)) {
            $document->loadMissing('lockedBy');

            throw new \DomainException(sprintf(
                '%s has had this document checked out since %s. Ask them to check it back in.',
                $document->lockedBy?->name ?? 'Somebody else',
                $document->locked_at?->diffForHumans() ?? 'earlier',
            ));
        }

        $takenOver = $document->lockHasExpired() && (int) $document->locked_by !== (int) $actor->getKey();
        $previousHolder = $takenOver ? $document->lockedBy?->name : null;

        $document->forceFill([
            'locked_by' => $actor->getKey(),
            'locked_at' => now(),
            'lock_expires_at' => now()->addHours($this->lockHours()),
        ])->save();

        $this->activity->record(
            $document,
            'checked_out',
            $takenOver
                ? $actor->name.' took over the check-out from '.($previousHolder ?? 'a lapsed lock').'.'
                : $actor->name.' checked out '.$document->displayName().' for editing.',
            array_filter(['taken_over_from' => $previousHolder]),
            $actor,
        );

        return $document;
    }

    /** Put it back, so somebody else can pick it up. */
    public function checkIn(Document $document, User $actor, bool $force = false): Document
    {
        if (! $document->isLocked()) {
            return $document;
        }

        // `force` is for `document.manage` holders unsticking somebody's forgotten lock. The policy
        // decides whether the caller has it; this only distinguishes the two cases for the timeline,
        // because "an administrator released your lock" and "you released it" read very differently
        // to the person who comes back to it.
        if (! $force && ! $document->isLockedBy($actor)) {
            throw new \DomainException('This document is checked out to somebody else.');
        }

        $holder = $document->lockedBy?->name;

        $document->forceFill([
            'locked_by' => null,
            'locked_at' => null,
            'lock_expires_at' => null,
        ])->save();

        $this->activity->record(
            $document,
            'checked_in',
            $force && $holder !== null && $holder !== $actor->name
                ? $actor->name.' released the check-out held by '.$holder.'.'
                : $actor->name.' checked in '.$document->displayName().'.',
            [],
            $actor,
        );

        return $document;
    }

    private function lockHours(): int
    {
        return max(1, $this->settings->int('documents.checkout_hours', 8));
    }

    // ================================================================== lifecycle

    /**
     * Send the current draft round for approval.
     *
     * Returns null when the category has no route configured, and the document is approved outright
     * — which is the correct behaviour, not a silent failure. A company that keeps an uncontrolled
     * reference register does not want an approval step invented for it, and the engine deliberately
     * distinguishes "no route" from "route that could not find anybody" (the second stalls).
     *
     * @throws \DomainException when there is nothing to submit
     */
    public function submit(Document $document, User $actor): ?WorkflowInstance
    {
        if ($document->versions()->whereNull('approved_at')->doesntExist()) {
            throw new \DomainException('There is no draft revision to submit. Create a revision first.');
        }

        if ($document->isInWorkflow()) {
            throw new \DomainException('This document is already out for approval.');
        }

        return DB::transaction(function () use ($document, $actor) {
            $this->checkIn($document, $actor, force: true);

            $instance = $document->workflowInstances()->exists()
                ? $this->workflow->resubmit($document, $actor)
                : $this->workflow->start($document, $actor);

            if ($instance === null) {
                // No route configured. The draft becomes the current revision immediately, and the
                // timeline says exactly why it was not reviewed — so nobody later mistakes an
                // unreviewed document for an approved one.
                $this->approveWithoutRoute($document, $actor);

                return null;
            }

            $inReview = $this->statuses->statusesFor('document')->firstWhere('key', 'in_review');

            if ($inReview !== null) {
                $document->forceFill(['status_id' => $inReview->id])->save();
            }

            return $instance;
        });
    }

    /** Withdraw a document from approval. */
    public function withdraw(Document $document, User $actor, string $reason): Document
    {
        $instance = $document->currentWorkflowInstance();

        if ($instance === null) {
            throw new \DomainException('This document is not out for approval.');
        }

        $this->workflow->cancel($instance, $actor, $reason);

        $draft = $this->statuses->statusesFor('document')->firstWhere('key', 'draft');

        if ($draft !== null) {
            $document->forceFill(['status_id' => $draft->id])->save();
        }

        return $document;
    }

    /**
     * Take a document out of circulation without destroying it.
     *
     * Archiving rather than deleting is the default for a reason §59 cares about: a controlled
     * document that was in force for two years is evidence of what the company required at the time,
     * and that remains true after it is superseded.
     */
    public function archive(Document $document, User $actor, ?string $reason = null): Document
    {
        $archived = $this->statuses->statusesFor('document')->firstWhere('key', 'archived');

        if ($archived !== null) {
            $document->forceFill(['status_id' => $archived->id])->save();
        }

        $this->checkIn($document, $actor, force: true);

        $this->activity->record(
            $document,
            'archived',
            $actor->name.' archived '.$document->displayName().'.'.($reason !== null ? ' '.$reason : ''),
            array_filter(['reason' => $reason]),
            $actor,
        );

        return $document;
    }

    public function restore(Document $document, User $actor): Document
    {
        if ($document->trashed()) {
            $document->restore();
        }

        $draft = $this->statuses->statusesFor('document')->firstWhere('key', 'draft');

        if ($draft !== null) {
            $document->forceFill(['status_id' => $draft->id])->save();
        }

        $this->activity->record($document, 'restored', $actor->name.' restored '.$document->displayName().'.', [], $actor);

        return $document;
    }

    /**
     * Soft-delete a document and its stored files.
     *
     * The ROWS are soft-deleted; the FILES are removed for real. Keeping bytes on disk for a record
     * nobody can reach is a data-retention liability with no upside, and the version rows retain the
     * hash, size and original name — so the audit trail can still say what was deleted, which is the
     * part that has to survive.
     */
    public function delete(Document $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor) {
            foreach ($document->versions()->whereNotNull('file_path')->get() as $version) {
                $this->vault->delete($version->file_path, $version->file_disk ?? FileVault::DISK);
            }

            $this->activity->record(
                $document,
                'deleted',
                $actor->name.' deleted '.$document->displayName().'.',
                ['number' => $document->number],
                $actor,
            );

            $document->delete();
        });
    }

    // ================================================================== internals

    /**
     * No route is configured, so submitting issues the revision there and then.
     *
     * This does not re-implement approval — it delegates to the same primitive the workflow engine
     * ends on, because the two paths differ only in who approved and why. When this method DID
     * re-implement it, it forgot to promote the draft label, and a document could sit in the
     * register marked approved and current while still showing "P01" as its revision.
     *
     * The narrative is the one thing worth saying differently: the timeline has to record that this
     * revision was never reviewed by anybody, so nobody reading it later mistakes an unreviewed
     * document for an approved one.
     */
    private function approveWithoutRoute(Document $document, User $actor): void
    {
        $version = $document->versions()->whereNull('approved_at')->orderByDesc('id')->first();

        if ($version === null) {
            return;
        }

        app(DocumentVersionService::class)->approveRevision(
            $document,
            $version,
            $actor,
            'Revision :revision was issued directly: no approval route is configured for this category.',
            routed: false,
        );
    }

    /**
     * Strip everything a caller must never set.
     *
     * A denylist here rather than `$fillable` on the model, because the model is also used by
     * seeders and the workflow engine, which legitimately set some of these. The boundary that
     * matters is user input, and this is the only place user input becomes a Document.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'number', 'created_by', 'current_version_id',
            'locked_by', 'locked_at', 'lock_expires_at', 'status_id',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    /** @param array<string,mixed> $attributes */
    private function resolveKind(array $attributes, ?DocumentCategory $category): DocumentKind
    {
        if (isset($attributes['kind'])) {
            return $attributes['kind'] instanceof DocumentKind
                ? $attributes['kind']
                : DocumentKind::from((string) $attributes['kind']);
        }

        return $category?->defaultKind() ?? DocumentKind::Native;
    }

    /** @param array<string,mixed> $attributes */
    private function resolveConfidentiality(array $attributes, ?DocumentCategory $category): Confidentiality
    {
        if (isset($attributes['confidentiality'])) {
            return $attributes['confidentiality'] instanceof Confidentiality
                ? $attributes['confidentiality']
                : Confidentiality::from((string) $attributes['confidentiality']);
        }

        return $category?->defaultConfidentiality() ?? Confidentiality::Internal;
    }
}
