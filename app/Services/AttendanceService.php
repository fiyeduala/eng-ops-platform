<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Clocking in and out, and correcting it afterwards.
 *
 * THE TIME IS THE SERVER'S. A clock-in carries no timestamp from the browser — a phone set to the
 * wrong time zone, or a request replayed an hour later, would otherwise decide when somebody
 * arrived (§84). Corrections are a separate, audited act with a reason.
 *
 * ONE OPEN SHIFT PER PERSON, checked under a lock on the personnel row so a double tap on a slow
 * connection cannot open two.
 */
class AttendanceService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
    ) {}

    public function capturesLocation(): bool
    {
        return $this->settings->bool('attendance.capture_location', false);
    }

    public function openShift(Employee $employee): ?AttendanceRecord
    {
        return AttendanceRecord::query()->where('employee_id', $employee->getKey())->open()->latest('clock_in_at')->first();
    }

    /**
     * @param  array{latitude?:mixed,longitude?:mixed,project_id?:?int,location_id?:?int,notes?:?string}  $details
     */
    public function clockIn(User $actor, array $details = []): AttendanceRecord
    {
        $employee = $actor->employee;

        if ($employee === null) {
            throw new \DomainException('Your account is not linked to a personnel record, so there is nobody to clock in.');
        }

        return DB::transaction(function () use ($actor, $employee, $details) {
            Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (($open = $this->openShift($employee)) !== null) {
                throw new \DomainException('You are already clocked in since '.$open->clock_in_at->format('D j M, H:i').'. Clock out first.');
            }

            $now = now();
            $record = new AttendanceRecord([
                'employee_id' => $employee->getKey(),
                'user_id' => $actor->getKey(),
                'org_unit_id' => $employee->org_unit_id,
                'project_id' => $details['project_id'] ?? null,
                'location_id' => $details['location_id'] ?? null,
                'work_date' => $now->toDateString(),
                'clock_in_at' => $now,
                'source' => AttendanceRecord::SOURCE_SELF,
                'notes' => $details['notes'] ?? null,
                ...$this->position('clock_in', $details),
            ]);
            $record->organization_id = $actor->organization_id;
            $record->created_by = $actor->getKey();
            $record->save();

            $this->activity->record($record, 'clocked_in', $actor->name.' clocked in.', [], $actor, $record->project_id);

            return $record;
        });
    }

    /** @param  array{latitude?:mixed,longitude?:mixed}  $details */
    public function clockOut(User $actor, array $details = []): AttendanceRecord
    {
        $employee = $actor->employee;

        if ($employee === null) {
            throw new \DomainException('Your account is not linked to a personnel record.');
        }

        return DB::transaction(function () use ($actor, $employee, $details) {
            Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $record = $this->openShift($employee);

            if ($record === null) {
                throw new \DomainException('You are not clocked in.');
            }

            $record->fill($this->position('clock_out', $details));
            $record->clock_out_at = now();
            $record->minutes = $this->minutesBetween($record->clock_in_at, $record->clock_out_at);
            $record->updated_by = $actor->getKey();
            $record->save();

            $this->activity->record($record, 'clocked_out', $actor->name.' clocked out after '.$record->hoursLabel().'.', [], $actor, $record->project_id);

            return $record;
        });
    }

    /**
     * Correct a shift's times. The reason is kept on the row; the times it replaced, in the audit log.
     */
    public function adjust(AttendanceRecord $record, CarbonImmutable $in, ?CarbonImmutable $out, string $reason, User $actor): AttendanceRecord
    {
        if (blank($reason)) {
            throw new \DomainException('Say why the times are being corrected.');
        }

        $this->assertShift($record->employee_id, $in, $out, $record->getKey());

        return DB::transaction(function () use ($record, $in, $out, $reason, $actor) {
            $before = [
                'clock_in_at' => $record->clock_in_at?->toDateTimeString(),
                'clock_out_at' => $record->clock_out_at?->toDateTimeString(),
            ];

            $record->forceFill([
                'work_date' => $in->toDateString(),
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'minutes' => $out === null ? null : $this->minutesBetween($in, $out),
                'adjusted_by' => $actor->getKey(),
                'adjusted_at' => now(),
                'adjustment_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->activity->record(
                $record,
                'adjusted',
                $actor->name.' corrected the times on '.$record->displayName().'.',
                ['from' => $before, 'to' => ['clock_in_at' => $in->toDateTimeString(), 'clock_out_at' => $out?->toDateTimeString()], 'reason' => $reason],
                $actor,
                $record->project_id,
            );

            return $record;
        });
    }

    /** A shift somebody forgot to clock, entered afterwards by a supervisor or HR. */
    public function recordManual(Employee $employee, CarbonImmutable $in, CarbonImmutable $out, string $reason, User $actor): AttendanceRecord
    {
        if (blank($reason)) {
            throw new \DomainException('Say why this shift is being entered by hand.');
        }

        $this->assertShift($employee->getKey(), $in, $out);

        return DB::transaction(function () use ($employee, $in, $out, $reason, $actor) {
            $record = new AttendanceRecord([
                'employee_id' => $employee->getKey(),
                'user_id' => $employee->user_id,
                'org_unit_id' => $employee->org_unit_id,
                'work_date' => $in->toDateString(),
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'minutes' => $this->minutesBetween($in, $out),
                'source' => AttendanceRecord::SOURCE_MANUAL,
                'adjusted_by' => $actor->getKey(),
                'adjusted_at' => now(),
                'adjustment_reason' => $reason,
            ]);
            $record->organization_id = $employee->organization_id;
            $record->created_by = $actor->getKey();
            $record->save();

            $this->activity->record($record, 'created', $actor->name.' entered a shift for '.$record->displayName().' by hand.', ['reason' => $reason], $actor);

            return $record;
        });
    }

    /** Whether a closed shift is long enough to be worth a second look. */
    public function isLong(AttendanceRecord $record): bool
    {
        return $record->minutes !== null && $record->minutes > $this->settings->int('work.hours_per_day', 8) * 60 * 2;
    }

    private function assertShift(int $employeeId, CarbonImmutable $in, ?CarbonImmutable $out, ?int $except = null): void
    {
        if ($in->isFuture()) {
            throw new \DomainException('A shift cannot start in the future.');
        }

        if ($out !== null && $out->lte($in)) {
            throw new \DomainException('The shift must end after it starts.');
        }

        if ($out !== null && $in->diffInHours($out) > 24) {
            throw new \DomainException('A single shift cannot be longer than 24 hours.');
        }

        if ($out !== null && $out->isFuture()) {
            throw new \DomainException('A shift cannot end in the future.');
        }

        $end = $out ?? CarbonImmutable::now();

        $overlap = AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where('clock_in_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $in))
            ->exists();

        if ($overlap) {
            throw new \DomainException('That overlaps another shift for the same person.');
        }
    }

    private function minutesBetween(\DateTimeInterface $in, \DateTimeInterface $out): int
    {
        return max(0, intdiv($out->getTimestamp() - $in->getTimestamp(), 60));
    }

    /**
     * Coordinates, only when the company collects them and only when both halves are sensible.
     *
     * @param  array<string,mixed>  $details
     * @return array<string,string|null>
     */
    private function position(string $prefix, array $details): array
    {
        $lat = $details['latitude'] ?? null;
        $lng = $details['longitude'] ?? null;

        if (! $this->capturesLocation() || ! is_numeric($lat) || ! is_numeric($lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return [];
        }

        return [
            $prefix.'_latitude' => number_format((float) $lat, 7, '.', ''),
            $prefix.'_longitude' => number_format((float) $lng, 7, '.', ''),
        ];
    }
}
