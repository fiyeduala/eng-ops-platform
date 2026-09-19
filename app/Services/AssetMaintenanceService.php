<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Status;
use App\Models\User;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Servicing, inspections, repairs — planned and otherwise, in one register.
 *
 * ONE TABLE FOR BOTH KINDS, and the flag between them is `is_planned` rather than two registers.
 * The argument is what a reader actually wants: "what has this machine cost us to keep running" and
 * "when was it last inspected" are questions across BOTH, and a split would make every honest answer
 * a union of two queries that drift apart. A breakdown is not a different kind of record from a
 * service; it is a service nobody scheduled.
 *
 * WHICH IS WHY A JOB CAN BE DUE BY DATE OR BY ODOMETER OR BY NEITHER. A statutory inspection falls
 * due on a date, a truck's service falls due at 20,000km, and a breakdown was due the moment it
 * broke. `AssetMaintenance::isDue()` answers all three and this service never second-guesses it.
 *
 * COMPLETION MOVES THE ASSET, NOT JUST THE JOB. `start()` puts the machine under maintenance and
 * `complete()` gives it back — because the state of the asset is what the storeman, the planner and
 * the person trying to book it out all read, and a completed job beside an asset still marked under
 * maintenance is a register nobody trusts twice. The asset returns to `idle` or `in_service`
 * depending on whether anybody actually has it, which is the same rule custody uses.
 *
 * "OVERDUE" IS A QUERY, NOT A STATUS COLUMN, and nothing here writes it anywhere. There is no sweep
 * in this service any more either: warning about a due date is now the expiry engine's job, driven
 * by a watch the company configured rather than by a method somebody wrote for this register and
 * for no other (see ExpirySweeper, and AssetMaintenance::expiryAlsoTell() for the part this module
 * still owns — that the custodian of the machine is told, which only the asset knows).
 *
 * The property that mattered survived the move intact: a scheduler that stops running costs the
 * company its WARNINGS and can never make an overdue inspection look current, because every screen
 * compares a date to today.
 */
class AssetMaintenanceService extends RegisterService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly OptionList $options,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'asset_maintenance';
    }

    protected function modelClass(): string
    {
        return AssetMaintenance::class;
    }

    /**
     * `reference`, not `number` — MNT-2026-0001 is written on a job card.
     *
     * The column is named for what a fitter calls it rather than for the base class's convention,
     * which is why the deny list below has to name it explicitly: `safeAttributes()` strips
     * `number` in every register, and would happily let a posted `reference` through.
     */
    protected function numberColumn(): ?string
    {
        return 'reference';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'reference', 'started_at', 'completed_at',
            'downtime_hours',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var AssetMaintenance $record */
        $record->maintenance_type = $this->type($attributes['maintenance_type'] ?? null, $actor);

        if ($record->is_planned === null) {
            $record->is_planned = true;
        }

        // A breakdown that is being recorded after the fact is not "scheduled", and leaving it in
        // the initial status would put every repair in the planner's list of things to arrange.
        if ($record->is_planned === false && $record->due_on === null) {
            $record->due_on = now()->toDateString();
        }
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var AssetMaintenance $record */
        $record->loadMissing('asset');

        return $actor->name.' raised '.$record->reference.' — '.$record->title
            .' on '.($record->asset?->displayName() ?? 'an asset').'.';
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        if (array_key_exists('maintenance_type', $attributes)) {
            $attributes['maintenance_type'] = $this->type($attributes['maintenance_type'], $actor);
        }

        return parent::update($record, $attributes, $actor);
    }

    // ================================================================== the job

    /**
     * The machine comes off the job and the work starts.
     *
     * The asset moves to `under_maintenance` here, and custody is deliberately NOT touched: the
     * fitter having the machine on a bench is not a change of custodian, it is the same custodian's
     * machine being worked on. Custody is who is answerable for it, which does not change because
     * it is in the workshop.
     */
    public function start(AssetMaintenance $maintenance, User $actor, ?int $odometer = null): AssetMaintenance
    {
        return $this->withLockedJob($maintenance, function () use ($maintenance, $actor, $odometer) {
            if ($maintenance->statusKey() === 'in_progress') {
                return $maintenance;
            }

            if ($maintenance->isClosed()) {
                throw new \DomainException(
                    $maintenance->reference.' is closed. Reopen it before recording work against it.'
                );
            }

            $maintenance->forceFill(array_filter([
                'started_at' => $maintenance->started_at ?? now(),
                'odometer' => $odometer,
            ], fn ($v) => $v !== null));

            $this->moveTo($maintenance, 'in_progress');
            $maintenance->save();

            $maintenance->loadMissing('asset');

            if (($asset = $maintenance->asset) !== null && ! in_array($asset->statusKey(), ['retired', 'lost'], true)) {
                $this->moveAssetTo($asset, 'under_maintenance');
            }

            $this->activity->record(
                $maintenance,
                'started',
                $actor->name.' started work on '.$maintenance->reference.'.',
                array_filter(['odometer' => $odometer]),
                $actor,
            );

            return $maintenance;
        });
    }

    /**
     * The work is signed off and the machine goes back to work.
     *
     * GATED ON `asset_maintenance.complete` AT EVERY DOOR — the policy, the status graph and the
     * controller — because closing a maintenance record is the statement "this is safe to use
     * again", and it is what an insurer, an inspector and an accident investigation all read
     * afterwards. The person who plans services from a desk is not the person who can say that.
     *
     * Costs are recorded here rather than typed onto the record beforehand, for the same reason
     * `last_purchase_price` is written by the receipt chain: what a job cost is known when it is
     * finished.
     *
     * @param  array{labour_cost?:float|null,parts_cost?:float|null,odometer?:int|null,findings?:string|null,downtime_hours?:float|null,vendor_id?:int|null}  $outcome
     */
    public function complete(AssetMaintenance $maintenance, array $outcome, User $actor): AssetMaintenance
    {
        return $this->withLockedJob($maintenance, function () use ($maintenance, $outcome, $actor) {
            if ($maintenance->statusKey() === 'completed') {
                return $maintenance;
            }

            if ($maintenance->statusKey() !== 'in_progress') {
                throw new \DomainException('Start or resume the job before certifying it complete.');
            }
            $completedAt = now();

            // Derived rather than typed where it can be. A fitter who has already told us when the
            // machine came in and when it went out should not also have to do the subtraction, and
            // a figure the system computed is one nobody rounded in their own favour.
            $downtime = $outcome['downtime_hours'] ?? null;

            if ($downtime === null && $maintenance->started_at !== null) {
                $downtime = round($maintenance->started_at->diffInMinutes($completedAt) / 60, 2);
            }

            $maintenance->forceFill(array_filter([
                'completed_at' => $completedAt,
                'started_at' => $maintenance->started_at ?? $completedAt,
                'downtime_hours' => $downtime,
                'labour_cost' => $outcome['labour_cost'] ?? null,
                'parts_cost' => $outcome['parts_cost'] ?? null,
                'odometer' => $outcome['odometer'] ?? null,
                'findings' => $outcome['findings'] ?? null,
                'vendor_id' => $outcome['vendor_id'] ?? null,
                'performed_by' => $maintenance->performed_by ?? $actor->getKey(),
            ], fn ($v) => $v !== null));

            $this->moveTo($maintenance, 'completed');
            $maintenance->save();

            $maintenance->loadMissing('asset');
            $asset = $maintenance->asset;

            // Only if the machine is still where this job put it. An asset somebody retired or
            // reported lost while it sat in the workshop must not be quietly returned to service by
            // a fitter closing a job card.
            $this->restoreAssetIfReady($asset);

            // The odometer on a completed job is a real reading taken by a real person, so it feeds
            // the vehicle's own counter — through FleetService, which refuses a reading that goes
            // backwards. §90: one fact, recorded once, visible from both registers.
            if ($asset?->is_vehicle && ($outcome['odometer'] ?? null) !== null) {
                $asset->loadMissing('vehicle');

                if ($asset->vehicle !== null) {
                    app(FleetService::class)->recordOdometer($asset->vehicle, (int) $outcome['odometer']);
                }
            }

            $this->activity->record(
                $maintenance,
                'completed',
                $actor->name.' completed '.$maintenance->reference.'.',
                array_filter([
                    'downtime_hours' => $downtime === null ? null : (string) $downtime,
                    'odometer' => $outcome['odometer'] ?? null,
                ]),
                $actor,
            );

            return $maintenance;
        });
    }

    // ================================================================== the sweep

    /** Keep generic transitions, timestamps and asset availability in the same transaction. */
    public function changeStatus(AssetMaintenance $maintenance, Status $to, User $actor, ?string $comment): AssetMaintenance
    {
        return $this->withLockedJob($maintenance, function () use ($maintenance, $to, $actor, $comment) {
            if ($to->key === 'completed') {
                throw new \DomainException('Use Certify complete to record the outcome of the work.');
            }

            $this->statuses->transition($maintenance, $to, $actor, $comment);
            $maintenance->setRelation('status', $to);

            if ($to->key === 'in_progress') {
                $maintenance->forceFill([
                    'started_at' => $maintenance->completed_at !== null ? now() : ($maintenance->started_at ?? now()),
                    'completed_at' => null,
                    'downtime_hours' => null,
                ])->save();
                if ($maintenance->asset !== null && ! in_array($maintenance->asset->statusKey(), ['retired', 'lost'], true)) {
                    $this->moveAssetTo($maintenance->asset, 'under_maintenance');
                }
            } elseif ($to->is_terminal) {
                $this->restoreAssetIfReady($maintenance->asset);
            }

            return $maintenance;
        });
    }

    private function withLockedJob(AssetMaintenance $maintenance, callable $action): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $action) {
            // Always lock the asset before its job: two different jobs must serialize too.
            $assetId = AssetMaintenance::query()->whereKey($maintenance->getKey())->value('asset_id');
            $asset = Asset::query()->with('status')->whereKey($assetId)->lockForUpdate()->firstOrFail();
            AssetMaintenance::query()->whereKey($maintenance->getKey())->lockForUpdate()->firstOrFail();
            $maintenance->refresh();
            $maintenance->setRelation('asset', $asset);

            return $action();
        });
    }

    private function restoreAssetIfReady(?Asset $asset): void
    {
        if ($asset === null || $asset->statusKey() !== 'under_maintenance') {
            return;
        }

        $active = AssetMaintenance::query()->where('asset_id', $asset->id)
            ->whereNotNull('started_at')->whereNull('completed_at')
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))->exists();

        if (! $active) {
            $this->moveAssetTo($asset, $asset->custodian_id === null && $asset->project_id === null ? 'idle' : 'in_service');
        }
    }

    // ================================================================== helpers

    /** Always one of the company's own words (§84). */
    private function type(mixed $value, User $actor): ?string
    {
        $allowed = $this->options->keys(
            'asset_maintenance.types',
            AssetMaintenance::DEFAULT_TYPES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        return $slug !== null && in_array($slug, $allowed, true) ? $slug : null;
    }

    /**
     * Move the ASSET, in another register's vocabulary, and reset its relation with the key.
     *
     * `moveTo()` is deliberately pinned to this service's own `entityType()`, so moving the machine
     * a job card is about needs its own lookup. Null when the company has removed the status from
     * its vocabulary, which is written as a null `status_id` — the same "the company said it does
     * not use this" answer `moveTo()` gives, rather than an exception over a configuration choice.
     *
     * THE RELATION IS RESET ALONG WITH THE KEY, for the reason {@see RegisterService::moveTo()}
     * spells out and for one this service makes sharper. `start()` and `complete()` are two calls
     * against the same in-memory objects, and complete()'s guard — return the machine to service
     * ONLY if it is still where this job put it — is written as `statusKey() === 'under_maintenance'`.
     * Writing `status_id` without the relation left that guard answering with the status the asset
     * held BEFORE the job started, so it never matched: every completed service left its machine
     * parked in `under_maintenance` for good, and the register said the yard was in the workshop.
     */
    private function moveAssetTo(Asset $asset, string $key): void
    {
        $status = $this->statuses->statusesFor('asset')->firstWhere('key', $key);

        $asset->forceFill(['status_id' => $status?->getKey()])->save();
        $asset->setRelation('status', $status);
    }

    /** Kept for the odometer-triggered half of `isDue()`; see the class docblock. */
    public function dueFor(Asset $asset): ?AssetMaintenance
    {
        return $asset->nextMaintenanceDue();
    }
}
