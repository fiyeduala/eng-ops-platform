<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\ExpiryWatch;
use App\Models\Role;
use App\Models\User;
use App\Support\ExpiryCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * The rules behind the renewals editor (§73, §84, §68).
 *
 * A WATCH IS A STANDING INSTRUCTION TO E-MAIL PEOPLE, which is what makes this a security service
 * and not a CRUD wrapper. Everything a company types here is eventually used to decide who hears
 * about a record — and a notification that reaches somebody who cannot open the record it describes
 * is a leak with a friendly tone (§68). The dispatcher is the last line on that and refuses such a
 * recipient outright; this service is the first, and its job is to stop a watch being BUILT around
 * an audience that will silently be dropped, because "the reminders were configured and nobody ever
 * got one" is the failure mode that ends with an expired permit on a live site.
 *
 * SO THE RECIPIENT LISTS ARE CHECKED AGAINST THIS COMPANY (§74, §75). Role and user ids arrive from
 * checkboxes, which is to say from the browser, and a watch naming another company's role would be
 * a standing instruction to mail strangers about your certificates. Ids that are not this
 * organization's are dropped rather than refused — they cannot have come from the form this screen
 * renders, so there is no honest message to show about them.
 *
 * THE REGISTER AND THE DATE COLUMN ARE WHITELISTED, not validated for plausibility. ExpirySweeper
 * puts `date_field` into a where clause every morning; the only safe source for it is the list the
 * platform offers, asked here on the way in and again in the sweeper on the way out, because a
 * watch outlives the register it points at.
 */
final class ExpiryWatchService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /** @param  array<string,mixed>  $attributes */
    public function create(array $attributes, User $actor): ExpiryWatch
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $watch = new ExpiryWatch;

            // §74: from the actor, never the payload, and first so the duplicate check below asks
            // about this company rather than about every company at once.
            $watch->organization_id = $actor->organization_id;

            $this->fill($watch, $attributes, $actor);
            $watch->save();

            $this->activity->record(
                $watch,
                'created',
                $actor->name.' started watching “'.$watch->name.'”.',
                ['entity_type' => $watch->entity_type, 'date_field' => $watch->date_field],
                $actor,
            );

            return $watch;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(ExpiryWatch $watch, array $attributes, User $actor): ExpiryWatch
    {
        return DB::transaction(function () use ($watch, $attributes, $actor) {
            $this->fill($watch, $attributes, $actor);

            $changed = array_keys($watch->getDirty());

            if ($changed === []) {
                return $watch;
            }

            $watch->save();

            $this->activity->record(
                $watch,
                'updated',
                $actor->name.' changed the reminders for “'.$watch->name.'”.',
                ['fields' => $changed],
                $actor,
            );

            return $watch;
        });
    }

    public function delete(ExpiryWatch $watch, User $actor): void
    {
        $this->activity->record(
            $watch,
            'deleted',
            $actor->name.' stopped watching “'.$watch->name.'” altogether.',
            ['entity_type' => $watch->entity_type, 'date_field' => $watch->date_field],
            $actor,
        );

        $watch->delete();
    }

    /**
     * Silence a watch without forgetting what it watched.
     *
     * Offered separately from deleting because the two mean different things to whoever reads this
     * screen next year. A watch switched off says "we decided to stop chasing this"; a deleted one
     * says nothing at all, and the next administrator rebuilds it from scratch with different lead
     * days and a different audience.
     */
    public function setActive(ExpiryWatch $watch, bool $active, User $actor): ExpiryWatch
    {
        if ($watch->is_active === $active) {
            return $watch;
        }

        $watch->forceFill(['is_active' => $active])->save();

        $this->activity->record(
            $watch,
            $active ? 'activated' : 'deactivated',
            $actor->name.($active ? ' switched on ' : ' switched off ').'the watch “'.$watch->name.'”.',
            [],
            $actor,
        );

        return $watch;
    }

    // ================================================================== validation

    /** @param  array<string,mixed>  $attributes */
    private function fill(ExpiryWatch $watch, array $attributes, User $actor): void
    {
        $entityType = (string) ($attributes['entity_type'] ?? $watch->entity_type ?? '');
        $dateField = (string) ($attributes['date_field'] ?? $watch->date_field ?? '');

        if (! ExpiryCatalogue::offers($entityType, $dateField)) {
            throw new \DomainException('That is not a date this platform can watch.');
        }

        if ($this->alreadyWatched($watch, $entityType, $dateField, $actor)) {
            // One watch per date, enforced by a unique index and explained here. Two watches on the
            // same column are not twice the safety — they are two e-mails about one certificate,
            // and the second one is what teaches people to filter the first into a folder.
            throw new \DomainException(
                'That date is already being watched. Change the existing watch rather than adding a '
                .'second one, or people will get every reminder twice.'
            );
        }

        $name = trim((string) ($attributes['name'] ?? $watch->name ?? ''));

        if ($name === '') {
            throw new \DomainException('A watch needs a name — it is what people read in the reminder.');
        }

        $watch->entity_type = $entityType;
        $watch->date_field = $dateField;
        $watch->name = mb_substr($name, 0, 120);
        $watch->lead_days = $this->leadDays($attributes['lead_days'] ?? null);
        $watch->warn_when_overdue = (bool) ($attributes['warn_when_overdue'] ?? false);
        $watch->notify_role_ids = $this->roleIds($attributes['notify_role_ids'] ?? [], $actor);
        $watch->notify_user_ids = $this->userIds($attributes['notify_user_ids'] ?? [], $actor);
        $watch->notify_owner = (bool) ($attributes['notify_owner'] ?? false);
        $watch->notify_followers = (bool) ($attributes['notify_followers'] ?? false);

        if (
            $watch->notify_role_ids === [] && $watch->notify_user_ids === []
            && ! $watch->notify_owner && ! $watch->notify_followers
        ) {
            // A watch with no audience runs every morning, finds everything, and tells nobody. It
            // would pass every test in this file and be worse than not existing, because the screen
            // would show a company that it is being looked after.
            throw new \DomainException('A watch has to warn somebody. Choose at least one person, role or owner.');
        }

        if (array_key_exists('is_active', $attributes)) {
            $watch->is_active = (bool) $attributes['is_active'];
        } elseif ($watch->is_active === null) {
            $watch->is_active = true;
        }
    }

    /**
     * How many days before the date this watch speaks, largest first.
     *
     * @param  mixed  $raw
     * @return array<int,int>
     */
    private function leadDays($raw): array
    {
        $days = [];

        foreach ((array) ($raw ?? []) as $day) {
            if (! is_numeric($day)) {
                continue;
            }

            $day = (int) $day;

            if ($day < 0 || $day > ExpiryWatch::MAX_LEAD_DAY) {
                throw new \DomainException(
                    'Reminders can be set from the day itself up to '.ExpiryWatch::MAX_LEAD_DAY
                    .' days ahead. Further out than that is not a reminder, it is a guess.'
                );
            }

            $days[] = $day;
        }

        $days = array_values(array_unique($days));

        if ($days === []) {
            return ExpiryWatch::DEFAULT_LEAD_DAYS;
        }

        if (count($days) > ExpiryWatch::MAX_LEAD_DAYS) {
            throw new \DomainException(
                'A watch may send at most '.ExpiryWatch::MAX_LEAD_DAYS.' reminders about one date. '
                .'Past that people stop reading them, which is the opposite of what this is for.'
            );
        }

        rsort($days);

        return $days;
    }

    /**
     * @param  mixed  $raw
     * @return array<int,int>
     */
    private function roleIds($raw, User $actor): array
    {
        $ids = $this->integers($raw);

        if ($ids === []) {
            return [];
        }

        return Role::query()
            ->where('organization_id', $actor->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $raw
     * @return array<int,int>
     */
    private function userIds($raw, User $actor): array
    {
        $ids = $this->integers($raw);

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $actor->organization_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $raw
     * @return array<int,int>
     */
    private function integers($raw): array
    {
        $ids = [];

        foreach ((array) ($raw ?? []) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function alreadyWatched(ExpiryWatch $watch, string $entityType, string $dateField, User $actor): bool
    {
        return ExpiryWatch::query()
            ->where('organization_id', $actor->organization_id)
            ->where('entity_type', $entityType)
            ->where('date_field', $dateField)
            ->when($watch->exists, fn ($query) => $query->whereKeyNot($watch->getKey()))
            ->exists();
    }
}
