<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\Employee;
use App\Models\TrainingRecord;
use App\Models\TrainingRequirement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each job requires, and the matrix that reads it against what people actually hold.
 *
 * THE MATRIX IS TWO QUERIES, NOT ONE PER PERSON. Requirements are loaded once — there are tens of
 * them — and the certificates for everybody on the screen are loaded once more, keyed by person and
 * course. Everything after that is arithmetic in PHP. Written the obvious way, with a query per
 * employee per course, a department of fifty against twelve courses is six hundred queries and a
 * screen nobody opens twice (§89).
 *
 * THE MATRIX NEVER WIDENS ACCESS. It is handed a collection of employees the caller has ALREADY
 * scoped, and it reads certificates only for those people. A training gap is a fact about somebody's
 * competence, and a matrix that quietly listed the whole company would be a §61 hole dressed up as a
 * report.
 *
 * A MISSING CERTIFICATE HAS NO ROW, which is the entire reason `training_requirements` exists. The
 * HSE register can only answer questions about certificates that were issued; "who has never done
 * their confined-space training" is answerable only by starting from what the job demands.
 *
 * SOMEBODY WITH NO SIGN-IN SHOWS AS MISSING EVERYTHING, and that is correct rather than convenient:
 * the HSE register records certificates against an account, so a new starter whose sign-in has not
 * been created yet genuinely has nothing recorded. The matrix says so instead of hiding them.
 */
class TrainingRequirementService
{
    /** Held, but due to lapse inside this many days. A display default, overridable per call. */
    public const DEFAULT_HORIZON_DAYS = 60;

    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Say that a job, a department or the whole company needs a course.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes, User $actor): TrainingRequirement
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $requirement = new TrainingRequirement($this->safe($attributes));

            $requirement->organization_id = $actor->organization_id;   // never from input (§74)
            $requirement->created_by = $actor->getKey();

            $this->checkShape($requirement);
            $this->refuseDuplicate($requirement);
            $requirement->save();

            $this->activity->record(
                $requirement,
                'created',
                $actor->name.' required '.($requirement->course?->name ?? 'a course').' for '.$requirement->targetLabel().'.',
                [],
                $actor,
            );

            return $requirement;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(TrainingRequirement $requirement, array $attributes, User $actor): TrainingRequirement
    {
        return DB::transaction(function () use ($requirement, $attributes, $actor) {
            $requirement->fill($this->safe($attributes));
            $requirement->updated_by = $actor->getKey();

            $this->checkShape($requirement);
            $this->refuseDuplicate($requirement);
            $requirement->save();

            $this->activity->record($requirement, 'updated', $actor->name.' changed a training requirement.', [], $actor);

            return $requirement;
        });
    }

    public function delete(TrainingRequirement $requirement, User $actor): void
    {
        DB::transaction(function () use ($requirement, $actor) {
            $this->activity->record($requirement, 'deleted', $actor->name.' removed a training requirement.', [], $actor);
            $requirement->delete();
        });
    }

    /**
     * The matrix: every person, every requirement that lands on them, and where they stand.
     *
     * @param  Collection<int,Employee>  $employees  ALREADY scoped by the caller. See the docblock.
     * @param  Collection<int,TrainingRequirement>  $requirements
     * @return array<int,array{employee:Employee,cells:array<int,array<string,mixed>>,gaps:int}>
     */
    public function matrix(Collection $employees, Collection $requirements, int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $courseIds = $requirements->pluck('training_course_id')->filter()->unique()->values()->all();
        $held = $this->certificatesFor($employees, $courseIds);

        $rows = [];

        foreach ($employees as $employee) {
            $cells = [];
            $gaps = 0;

            foreach ($requirements as $requirement) {
                if (! $requirement->appliesTo($employee)) {
                    continue;
                }

                $record = $held[$employee->user_id][$requirement->training_course_id] ?? null;
                $state = $this->stateOf($record, $requirement, $horizonDays);

                if ($requirement->is_mandatory && in_array($state, ['missing', 'expired'], true)) {
                    $gaps++;
                }

                $cells[] = [
                    'requirement' => $requirement,
                    'course' => $requirement->course,
                    'record' => $record,
                    'state' => $state,
                    'expires_on' => $record?->expires_on,
                ];
            }

            $rows[] = ['employee' => $employee, 'cells' => $cells, 'gaps' => $gaps];
        }

        return $rows;
    }

    /**
     * The requirements that land on one person — used by their personnel page and by "my training".
     *
     * @param  Collection<int,TrainingRequirement>  $requirements
     * @return array<int,array<string,mixed>>
     */
    public function forEmployee(Employee $employee, Collection $requirements, int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $rows = $this->matrix(collect([$employee]), $requirements, $horizonDays);

        return $rows === [] ? [] : $rows[0]['cells'];
    }

    /**
     * Every active requirement, with its course, in one query.
     *
     * @return Collection<int,TrainingRequirement>
     */
    public function activeRequirements(): Collection
    {
        return TrainingRequirement::query()
            ->active()
            ->with(['course', 'position', 'orgUnit'])
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->get()
            ->sortBy(fn (TrainingRequirement $r) => [$r->course?->sort_order ?? 0, (string) $r->course?->name])
            ->values();
    }

    // ================================================================== internals

    /**
     * The most recent certificate each person holds for each required course.
     *
     * "Most recent" is by expiry where there is one and by completion where there is not — a
     * renewal is a NEW row (see TrainingRecordService::renew), so the register accumulates and the
     * matrix must read the top of each pile rather than the first row it meets.
     *
     * @param  Collection<int,Employee>  $employees
     * @param  array<int,int>  $courseIds
     * @return array<int,array<int,TrainingRecord>>
     */
    private function certificatesFor(Collection $employees, array $courseIds): array
    {
        $userIds = $employees->pluck('user_id')->filter()->unique()->values()->all();

        if ($userIds === [] || $courseIds === []) {
            return [];
        }

        $records = TrainingRecord::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('training_course_id', $courseIds)
            // Sitting the course is not the same as passing it. A failure is kept in the register —
            // it is part of the person's history, and the retake is read against it — but it must
            // not close a gap on the matrix, or somebody who failed their rigging assessment would
            // show green on the screen a supervisor uses to decide who goes up the derrick.
            ->where(function ($q) {
                $q->whereNull('result')->orWhereNotIn('result', ['Failed', 'Not completed']);
            })
            ->orderBy('completed_on')
            ->orderBy('expires_on')
            ->get();

        $byPerson = [];

        foreach ($records as $record) {
            $existing = $byPerson[$record->user_id][$record->training_course_id] ?? null;

            if ($existing === null || $this->supersedes($record, $existing)) {
                $byPerson[$record->user_id][$record->training_course_id] = $record;
            }
        }

        return $byPerson;
    }

    private function supersedes(TrainingRecord $candidate, TrainingRecord $current): bool
    {
        // A certificate that never expires beats a dated one: it is the stronger claim, and a site
        // induction with no expiry should not be shadowed by a lapsed refresher of the same course.
        if ($candidate->expires_on === null) {
            return true;
        }

        if ($current->expires_on === null) {
            return false;
        }

        return $candidate->expires_on->greaterThan($current->expires_on);
    }

    /** held | expiring | expired | missing. See the class docblock for what grace_days buys. */
    private function stateOf(?TrainingRecord $record, TrainingRequirement $requirement, int $horizonDays): string
    {
        if ($record === null) {
            return 'missing';
        }

        if ($record->expires_on === null) {
            return 'held';
        }

        $today = now()->startOfDay();
        $lapsed = $record->expires_on->copy()->addDays($requirement->grace_days);

        if ($lapsed->isBefore($today)) {
            return 'expired';
        }

        if ($record->expires_on->isBefore($today->copy()->addDays($horizonDays))) {
            return 'expiring';
        }

        return 'held';
    }

    private function checkShape(TrainingRequirement $requirement): void
    {
        if (! in_array($requirement->scope, TrainingRequirement::SCOPES, true)) {
            throw new \DomainException('Say whether this applies to a position, a department or everybody.');
        }

        if ($requirement->training_course_id === null) {
            throw new \DomainException('Choose the course that is required.');
        }

        // The target has to exist for the scope that was chosen. A requirement scoped to a position
        // with no position lands on nobody, and reads on screen as though it lands on everybody.
        if ($requirement->scope === TrainingRequirement::SCOPE_POSITION && $requirement->position_id === null) {
            throw new \DomainException('Choose the position this course is required for.');
        }

        if ($requirement->scope === TrainingRequirement::SCOPE_ORG_UNIT && $requirement->org_unit_id === null) {
            throw new \DomainException('Choose the department this course is required for.');
        }

        // And the other targets are cleared, so a row cannot carry a stale position from before
        // somebody widened it to the whole company.
        if ($requirement->scope !== TrainingRequirement::SCOPE_POSITION) {
            $requirement->position_id = null;
        }

        if ($requirement->scope !== TrainingRequirement::SCOPE_ORG_UNIT) {
            $requirement->org_unit_id = null;
        }

        if ($requirement->grace_days < 0 || $requirement->grace_days > 365) {
            throw new \DomainException('A grace period runs from 0 to 365 days.');
        }
    }

    /**
     * One requirement per course per target.
     *
     * Enforced here rather than by a unique index: the index would have to span two nullable
     * columns, and in both MySQL and SQLite a unique index treats two nulls as different — so the
     * "everybody needs this" rows, which are exactly the ones a company duplicates, are the ones it
     * would not catch.
     */
    private function refuseDuplicate(TrainingRequirement $requirement): void
    {
        $exists = TrainingRequirement::query()
            ->where('training_course_id', $requirement->training_course_id)
            ->where('scope', $requirement->scope)
            ->when($requirement->position_id !== null,
                fn ($q) => $q->where('position_id', $requirement->position_id),
                fn ($q) => $q->whereNull('position_id'))
            ->when($requirement->org_unit_id !== null,
                fn ($q) => $q->where('org_unit_id', $requirement->org_unit_id),
                fn ($q) => $q->whereNull('org_unit_id'))
            ->when($requirement->exists, fn ($q) => $q->whereKeyNot($requirement->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException('That course is already required for '.$requirement->targetLabel().'.');
        }
    }

    /**
     * A deny list, applied in the service. See RegisterService for why it is not `$fillable`.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safe(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }
}
