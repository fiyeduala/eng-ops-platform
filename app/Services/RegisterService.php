<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The create/update half of every Phase 4 register, written once.
 *
 * WHAT THIS EXISTS TO PREVENT. Twelve registers each writing their own `create()` is twelve chances
 * to forget one of four things, and each omission is a different silent bug:
 *
 *   - forget `organization_id` and the row belongs to nobody, which the global scope then hides
 *     from everybody including the person who just created it;
 *   - forget the number and the register has a blank column its users identify records by;
 *   - forget the initial status and every status filter skips the row;
 *   - forget to strip `organization_id` out of the submitted attributes, and a posted form field
 *     moves a record into another tenant (§75, §84).
 *
 * The last one is the reason `safeAttributes()` is a DENY list applied in the service rather than a
 * `$fillable` on the model. Models are filled from a dozen places — seeders, factories, imports,
 * the workflow engine — and only some of them are HTTP. The service is the one place every
 * user-originated write passes through.
 *
 * WHAT SUBCLASSES ADD is the interesting half: submitting, answering, coding, deciding, closing.
 * Nothing here knows about any of that on purpose — a base class that grew a `submit()` would
 * immediately need a flag for the registers that do not have one.
 */
abstract class RegisterService
{
    public function __construct(
        protected readonly NumberGenerator $numbers,
        protected readonly StatusMachine $statuses,
        protected readonly ActivityRecorder $activity,
    ) {}

    /**
     * Column listings, keyed by table. See stampConfidentiality().
     *
     * @var array<string,array<string,int>>
     */
    private static array $columnCache = [];

    /** The catalogue key: `deliverable`, `rfi`, `hse_incident`. Drives numbering and statuses. */
    abstract protected function entityType(): string;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /**
     * Open a new record on this register.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Model
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $class = $this->modelClass();

            /** @var Model $record */
            $record = new $class($this->safeAttributes($attributes));

            // Set after filling, never from the payload. See the class docblock.
            $record->setAttribute('organization_id', $actor->organization_id);
            $record->setAttribute('created_by', $actor->getKey());
            $record->setAttribute('number', $this->resolveNumber($attributes));
            $record->setAttribute('status_id', $this->statuses->initialFor($this->entityType())?->id);

            // Classification is stamped here, never left to the column default. The database would
            // apply `internal` on insert quite happily, but the model IN MEMORY would then carry no
            // `confidentiality` key at all — and everything downstream reads the model, not the row.
            // Two things were quietly wrong because of that: the notification gate skips a subject
            // whose classification it cannot see (§68), and a register that copies one record's
            // classification onto another — SubmittalService::resubmit() — copied a null onto a
            // NOT NULL column and took the whole resubmittal down with it.
            $this->stampConfidentiality($record);

            $this->beforeCreate($record, $attributes, $actor);

            $record->save();

            $this->activity->record(
                $record,
                'created',
                $this->creationNarrative($record, $actor),
                ['number' => $record->getAttribute('number')],
                $actor,
                $this->projectIdOf($record),
            );

            $this->afterCreate($record, $attributes, $actor);

            return $record;
        });
    }

    /**
     * Give a record an explicit classification when the payload did not carry a usable one.
     *
     * COLUMN-GATED, NOT MODEL-GATED. Most registers have `confidentiality` and a few — the ones
     * whose visibility is decided entirely by a parent record — do not, and the base class is not
     * the place to keep a list of which is which. The listing is cached per table, so this costs
     * one query per table per request rather than one per record written.
     *
     * A null already in the attribute array is treated as absent on purpose: the column is NOT
     * NULL everywhere it exists, so a null is never a classification anybody chose. It is what
     * arrives when one record copies another's — see SubmittalService::resubmit().
     */
    private function stampConfidentiality(Model $record): void
    {
        if (($record->getAttributes()['confidentiality'] ?? null) !== null) {
            return;
        }

        $table = $record->getTable();

        if (! isset(self::$columnCache[$table])) {
            self::$columnCache[$table] = array_flip(
                $record->getConnection()->getSchemaBuilder()->getColumnListing($table)
            );
        }

        if (isset(self::$columnCache[$table]['confidentiality'])) {
            // The model decides, not this service. Nearly every register starts internal; the
            // contract register starts confidential, and saying so on the model means every path
            // that creates one — seeder, import, another module — gets the same answer as the
            // form does. See Scopeable::defaultConfidentiality().
            $record->setAttribute('confidentiality', method_exists($record, 'defaultConfidentiality')
                ? $record->defaultConfidentiality()
                : Confidentiality::Internal);
        }
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        $record->fill($this->safeAttributes($attributes));

        // The one register whose identifier belongs to the user rather than to the generator. See
        // `allowsSuppliedNumber()` — this is opt-in, and off everywhere else.
        if ($this->allowsSuppliedNumber() && filled($attributes['number'] ?? null)) {
            $record->setAttribute('number', trim((string) $attributes['number']));
        }

        // Nothing to say and nothing to record. Writing an activity entry for a save that changed
        // no columns fills a record's timeline with noise that hides the entries that matter.
        if (! $record->isDirty()) {
            return $record;
        }

        $changed = array_keys($record->getDirty());

        $record->save();

        $this->activity->record(
            $record,
            'updated',
            $actor->name.' updated '.$this->label($record).'.',
            // Field NAMES only, never values. The audit engine keeps the before/after pair under
            // its own access rules; an activity entry is read by anyone who can see the record,
            // and a changed confidentiality or a redacted title would leak through it (§59, §68).
            ['fields' => $changed],
            $actor,
            $this->projectIdOf($record),
        );

        return $record;
    }

    // ================================================================== hooks

    /**
     * Tokens the numbering pattern may interpolate — {PROJECT}, {DISCIPLINE}.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,string>
     */
    protected function numberTokens(array $attributes): array
    {
        return [];
    }

    /**
     * Which numbering SERIES this record draws from — usually the register itself.
     *
     * Split from `entityType()` because the two answer different questions and one register
     * answers them differently. An RFI, a technical query and a site query share one table, one
     * status vocabulary and one permission module, and must NOT share a number: engineers quote
     * "TQ-2026-0031" and mean a specific document. The numbering engine provisions a scheme for an
     * entity type it has not seen, so a new series costs nothing and an administrator can give it
     * its own pattern afterwards.
     *
     * @param  array<string,mixed>  $attributes  the submitted attributes, so the series can depend
     *                                           on what is being created
     */
    protected function numberingEntityType(array $attributes): string
    {
        return $this->entityType();
    }

    /**
     * The identifier this record will carry.
     *
     * Generated by default, because a register whose numbers people type is a register with
     * duplicates and gaps in it. The hook exists for the one case where that is genuinely wrong:
     * drawing numbers are dictated by a project's numbering convention and are frequently assigned
     * by the client, so the generator supplies a suggestion and the user overrides it.
     *
     * Even then the number never arrives through `fill()` — `safeAttributes()` strips it in every
     * register without exception. It is read here, from the raw payload, by a service that has
     * opted in and behind a per-tenant unique index that is the actual guarantee.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function resolveNumber(array $attributes): string
    {
        if ($this->allowsSuppliedNumber() && filled($attributes['number'] ?? null)) {
            return trim((string) $attributes['number']);
        }

        return $this->numbers->next(
            $this->numberingEntityType($attributes),
            $this->numberTokens($attributes),
        );
    }

    /** Whether a user may name this record themselves. False everywhere but the drawing register. */
    protected function allowsSuppliedNumber(): bool
    {
        return false;
    }

    /** @param array<string,mixed> $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void {}

    /** @param array<string,mixed> $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void {}

    protected function creationNarrative(Model $record, User $actor): string
    {
        return $actor->name.' opened '.$this->label($record).'.';
    }

    // ================================================================== helpers

    /**
     * Move the record to a named status without going through the transition graph.
     *
     * FOR SERVICE-DRIVEN MOVES ONLY — "answering an RFI sets it to answered" — where the move is a
     * consequence of an action the policy already authorized, not a button somebody pressed. A
     * status change a USER chooses must go through StatusMachine::transition(), which checks the
     * configured graph and the transition's own permission. Bypassing that here would let any
     * caller reach any status.
     *
     * Silently does nothing when the tenant has deleted the status, because a company that removed
     * "answered" from its vocabulary has said it does not use it — and refusing to record the
     * answer would be a worse answer than leaving the status where it was.
     */
    protected function moveTo(Model $record, string $statusKey): void
    {
        $status = $this->statuses->statusesFor($this->entityType())->firstWhere('key', $statusKey);

        if ($status !== null) {
            $record->setAttribute('status_id', $status->getKey());
        }
    }

    protected function label(Model $record): string
    {
        return method_exists($record, 'displayName')
            ? $record->displayName()
            : class_basename($record).' #'.$record->getKey();
    }

    protected function projectIdOf(Model $record): ?int
    {
        $value = $record->getAttribute('project_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Strip everything a submitted form has no business setting.
     *
     * A deny list, and deliberately a generous one: identity, tenancy, numbering, authorship,
     * timestamps and every outcome column a register has. Outcome columns are the subtle half —
     * `answered_at`, `reviewed_by`, `decided_at` are written by the methods that perform those
     * acts, after the policy has authorized them, and a form that could set them directly would
     * skip the check entirely.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function safeAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(array_merge([
            'id', 'organization_id', 'number', 'created_by',
            'created_at', 'updated_at', 'deleted_at',
        ], $this->protectedAttributes())));
    }

    /**
     * Columns this register writes itself. Status is here for every register; outcome columns are
     * added by subclasses.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return ['status_id'];
    }
}
