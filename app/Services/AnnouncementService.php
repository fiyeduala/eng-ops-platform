<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Events\AnnouncementPublished;
use App\Models\Announcement;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drafting, publishing, correcting and retiring notices (§18).
 *
 * Shorter than MemoService by exactly the amount a broadcast is simpler than a directed
 * communication: there is no distribution list, no confidentiality, and therefore no record ACL to
 * maintain. What remains is the publication stamp, and it is symmetrical in a way the memo's is
 * not — an announcement can come back OFF the board.
 *
 *   Moving away from the initial status  -> stamp published_at, fire the event.
 *   Moving back TO the initial status    -> clear it.
 *
 * The clear matters. `published_at` is what ScopeCompiler::applyPublication() reads, so leaving it
 * set on an unpublished notice would mean "unpublish" removed the word Published from a badge while
 * the whole company carried on seeing the notice — the worst kind of security control, the one that
 * looks like it worked.
 *
 * Both directions are keyed off the shape of the configured state machine, never off the status
 * keys 'draft' and 'published', so a tenant that renames them keeps a working curtain (§62).
 */
class AnnouncementService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes  already validated
     */
    public function create(array $attributes, User $actor): Announcement
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $announcement = new Announcement($attributes);

            $announcement->organization_id = $actor->organization_id;
            $announcement->created_by = $actor->id;
            $announcement->number = $this->numbers->next('announcement');
            $announcement->status_id ??= $this->statuses->initialFor('announcement')?->id;

            // Never from the request: this column is the draft curtain.
            $announcement->published_at = null;
            $announcement->published_by = null;

            $announcement->save();

            $this->activity->record(
                $announcement,
                'created',
                sprintf('%s drafted announcement %s', $actor->name, $announcement->displayName()),
                ['number' => $announcement->number],
                $actor,
            );

            return $announcement->refresh();
        });
    }

    /**
     * Edit the notice — including one already on the board, deliberately. See AnnouncementPolicy
     * for why a live notice is corrected rather than superseded.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Announcement $announcement, array $attributes, User $actor): Announcement
    {
        unset(
            $attributes['number'],
            $attributes['organization_id'],
            $attributes['created_by'],
            $attributes['status_id'],
            $attributes['published_at'],
            $attributes['published_by'],
        );

        return DB::transaction(function () use ($announcement, $attributes, $actor) {
            $announcement->fill($attributes);

            $changed = array_keys($announcement->getDirty());

            if ($changed === []) {
                return $announcement;
            }

            $announcement->save();

            $this->activity->record(
                $announcement,
                'updated',
                sprintf('%s updated %s', $actor->name, $announcement->displayName()),
                ['fields' => $changed],
                $actor,
            );

            return $announcement->refresh();
        });
    }

    /**
     * Move the notice through the configured whitelist, stamping or clearing publication.
     *
     * The event fires only on a first publication. Re-publishing after an unpublish deliberately
     * does not notify the company a second time about a notice most of them already read — the
     * activity trail records that it happened, which is the audience that actually needs to know.
     *
     * @throws \DomainException when the move is not defined or not permitted
     */
    public function transition(Announcement $announcement, Status $to, User $actor, ?string $comment = null): Announcement
    {
        $initial = $this->statuses->initialFor('announcement');
        $returningToInitial = $initial !== null && (int) $to->getKey() === (int) $initial->getKey();

        return DB::transaction(function () use ($announcement, $to, $actor, $comment, $returningToInitial) {
            $wasPublished = $announcement->isPublished();

            $this->statuses->transition($announcement, $to, $actor, $comment);
            $announcement->refresh();

            if ($returningToInitial) {
                if ($wasPublished) {
                    $announcement->published_at = null;
                    $announcement->published_by = null;
                    $announcement->saveQuietly();
                    $announcement->refresh();
                }

                return $announcement;
            }

            if (! $wasPublished) {
                $announcement->published_at = now();
                $announcement->published_by = $actor->id;
                $announcement->saveQuietly();
                $announcement->refresh();

                AnnouncementPublished::dispatch($announcement, (int) $actor->id);
            }

            return $announcement;
        });
    }
}
