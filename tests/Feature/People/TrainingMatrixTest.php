<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Services\TrainingRequirementService;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The training matrix: who is required to hold what, and who is short.
 *
 * Three things have to be true or the screen is worse than not having it:
 *
 *   1. THE EXPIRY MATHS. A certificate that lapsed yesterday inside its grace period is still a
 *      certificate; one that lapsed outside it is a gap. A failed course is not a certificate at
 *      all. Getting this wrong sends somebody up a derrick on a ticket that expired in March.
 *   2. A BLANK CELL IS NOT A GREEN ONE. Where a requirement does not apply to a person there is no
 *      cell at all — "not asked" and "compliant" are different answers, and the matrix must not
 *      collapse them.
 *   3. THE MATRIX OBEYS AUTHORIZATION (§61). The rows are the people the reader may already see.
 *      A supervisor with `employee.view => department` gets their department and nobody else, and
 *      a training screen must not become the one place in the platform that lists the whole
 *      company's staff.
 */
final class TrainingMatrixTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $operations;

    private OrgUnit $finance;

    private User $hse;          // Zainab: HSE, reads the whole company's matrix

    private User $supervisor;   // Bayo: Operations only

    private User $rigger;       // Emeka: a rigger, has his own page and nothing else

    private Position $riggerPosition;

    private TrainingCourse $rigging;

    private TrainingCourse $induction;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 08:00:00');

        $this->org = $this->makeOrganization('Meridian Training');
        $this->provisionConfiguration($this->org);

        $this->operations = $this->makeUnit($this->org, 'Operations', OrgUnitType::Department);
        $this->finance = $this->makeUnit($this->org, 'Finance', OrgUnitType::Department);

        $hseRole = $this->makeRole($this->org, 'HSE Manager', [
            'employee.view' => 'organization',
            'hse_training.view' => 'organization', 'hse_training.create' => 'organization',
            'hse_training.edit' => 'organization', 'hse_training.delete' => 'organization',
            'hse_training.manage' => 'organization',
            'org_unit.view' => 'organization', 'position.view' => 'organization',
        ]);

        // The §61 fixture: everything the HSE manager has, narrowed to one department.
        $supervisorRole = $this->makeRole($this->org, 'Operations Supervisor', [
            'employee.view' => 'department',
            'hse_training.view' => 'department',
            'org_unit.view' => 'organization',
        ]);

        $riggerRole = $this->makeRole($this->org, 'Rigger', [
            'employee.view' => 'own',
            'hse_training.view' => 'own',
        ]);

        $this->hse = $this->makePerson($this->org, 'Zainab Yusuf', null, [$hseRole]);
        $this->supervisor = $this->makePerson($this->org, 'Bayo Ogun', $this->operations, [$supervisorRole]);
        $this->rigger = $this->makePerson($this->org, 'Emeka Obi', $this->operations, [$riggerRole]);
        $this->makePerson($this->org, 'Ngozi Udo', $this->finance, [
            $this->makeRole($this->org, 'Accounts Clerk', ['employee.view' => 'own']),
        ]);

        $this->withinOrganization($this->org, function () {
            $this->riggerPosition = Position::create([
                'organization_id' => $this->org->id,
                'title' => 'Rigger',
                'is_active' => true,
            ]);

            $this->rigging = TrainingCourse::create([
                'organization_id' => $this->org->id,
                'name' => 'Rigging and slinging',
                'code' => 'RIG-1',
                'validity_months' => 24,
                'is_active' => true,
            ]);

            $this->induction = TrainingCourse::create([
                'organization_id' => $this->org->id,
                'name' => 'Site induction',
                'code' => 'IND-1',
                'validity_months' => null,   // does not expire
                'is_active' => true,
            ]);

            // Emeka is the rigger; the requirement below reaches him through the position.
            $this->rigger->employee->forceFill(['position_id' => $this->riggerPosition->id])->save();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the maths

    #[Test]
    public function a_certificate_inside_its_grace_period_is_not_a_gap_and_one_outside_it_is(): void
    {
        $requirement = $this->requireOfEveryone($this->rigging, graceDays: 30);

        // The ticket ran out three weeks ago. Inside a thirty-day grace period, so the refresher is
        // booked and the person is not yet a finding — but they are not simply "held" either.
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2026-05-25');

        $this->assertSame('expiring', $this->stateFor($this->rigger->employee, $requirement));
        $this->assertSame(0, $this->gapsFor($this->rigger->employee));

        // The same certificate against a requirement that allows no grace is a gap today.
        $this->withinOrganization($this->org, fn () => $requirement->forceFill(['grace_days' => 0])->save());

        $this->assertSame('expired', $this->stateFor($this->rigger->employee, $requirement));
        $this->assertSame(1, $this->gapsFor($this->rigger->employee));
    }

    #[Test]
    public function a_certificate_that_never_expires_is_held_forever_and_a_failed_course_is_not_held_at_all(): void
    {
        $requirement = $this->requireOfEveryone($this->induction);

        $this->certificate($this->rigger->employee, $this->induction, expires: null);
        $this->assertSame('held', $this->stateFor($this->rigger->employee, $requirement));
        $this->assertSame(0, $this->gapsFor($this->rigger->employee));

        // Sitting a course is not passing it. A failure stays in the register and closes nothing.
        $failed = $this->requireOfEveryone($this->rigging);
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2028-01-01', result: 'Failed');

        $this->assertSame('missing', $this->stateFor($this->rigger->employee, $failed));
        $this->assertSame(1, $this->gapsFor($this->rigger->employee));
    }

    #[Test]
    public function a_certificate_expiring_soon_is_flagged_before_it_lapses(): void
    {
        $requirement = $this->requireOfEveryone($this->rigging);

        // Six weeks out — inside the default ninety-day horizon, and still perfectly valid today.
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2026-07-27');

        $this->assertSame('expiring', $this->stateFor($this->rigger->employee, $requirement));
        $this->assertSame(0, $this->gapsFor($this->rigger->employee), 'A warning is not a gap. Somebody who still holds the ticket is compliant.');
    }

    #[Test]
    public function a_renewal_supersedes_the_lapsed_row_rather_than_sitting_beside_it(): void
    {
        $requirement = $this->requireOfEveryone($this->rigging);

        $this->certificate($this->rigger->employee, $this->rigging, expires: '2026-01-31');
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2028-01-31');

        $this->assertSame('held', $this->stateFor($this->rigger->employee, $requirement));
        $this->assertSame(0, $this->gapsFor($this->rigger->employee));
    }

    // ================================================================== not asked is not compliant

    #[Test]
    public function a_requirement_that_does_not_apply_produces_no_cell_at_all(): void
    {
        $this->requireOfPosition($this->rigging, $this->riggerPosition);

        $riggerCells = $this->cellsFor($this->rigger->employee);
        $supervisorCells = $this->cellsFor($this->supervisor->employee);

        $this->assertCount(1, $riggerCells, 'The rigger is asked for the rigging ticket.');
        $this->assertSame([], $supervisorCells, 'Bayo is not a rigger. No cell — not an empty green one.');
        $this->assertSame(0, $this->gapsFor($this->supervisor->employee));
    }

    #[Test]
    public function a_retired_requirement_leaves_the_matrix_and_the_certificates_on_file_untouched(): void
    {
        $requirement = $this->requireOfEveryone($this->rigging);
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2028-01-31');

        $this->withinOrganization($this->org, fn () => $requirement->forceFill(['is_active' => false])->save());

        $this->assertSame([], $this->cellsFor($this->rigger->employee));
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => TrainingRecord::query()->count()));
    }

    // ================================================================== §61

    #[Test]
    public function the_matrix_shows_only_the_people_the_reader_may_already_see(): void
    {
        $this->requireOfEveryone($this->rigging);

        $this->actingFresh($this->hse)
            ->get(route('training-requirements.matrix'))
            ->assertOk()
            ->assertSee('Emeka Obi')
            ->assertSee('Ngozi Udo');

        // Bayo runs Operations. Finance is not his to read here or anywhere else.
        $this->actingFresh($this->supervisor)
            ->get(route('training-requirements.matrix'))
            ->assertOk()
            ->assertSee('Emeka Obi')
            ->assertDontSee('Ngozi Udo');

        // And the rigger, who holds `hse_training.view => own`, gets himself and nobody else.
        $this->actingFresh($this->rigger)
            ->get(route('training-requirements.matrix'))
            ->assertOk()
            ->assertDontSee('Ngozi Udo');
    }

    #[Test]
    public function only_somebody_who_manages_training_may_write_a_requirement(): void
    {
        $this->actingFresh($this->supervisor)->get(route('training-requirements.create'))->assertForbidden();

        $this->actingFresh($this->supervisor)
            ->post(route('training-requirements.store'), [
                'training_course_id' => $this->rigging->id,
                'scope' => TrainingRequirement::SCOPE_ORGANIZATION,
                'is_mandatory' => 1,
                'grace_days' => 0,
                'is_active' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => TrainingRequirement::query()->count()));

        $this->actingFresh($this->hse)
            ->post(route('training-requirements.store'), [
                'training_course_id' => $this->rigging->id,
                'scope' => TrainingRequirement::SCOPE_ORGANIZATION,
                'is_mandatory' => 1,
                'grace_days' => 14,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, $this->withinOrganization($this->org, fn () => TrainingRequirement::query()->count()));
    }

    // ================================================================== the screens

    #[Test]
    public function every_training_screen_renders(): void
    {
        $requirement = $this->requireOfPosition($this->rigging, $this->riggerPosition);
        $this->certificate($this->rigger->employee, $this->rigging, expires: '2026-07-27');

        $this->actingFresh($this->hse)->get(route('training-requirements.index'))->assertOk()->assertSee('Rigging and slinging');
        $this->actingFresh($this->hse)->get(route('training-requirements.matrix'))->assertOk();
        $this->actingFresh($this->hse)->get(route('training-requirements.create'))->assertOk();
        $this->actingFresh($this->hse)->get(route('training-requirements.edit', $requirement))->assertOk();

        // "My training" carries no permission of its own — it is the one screen everybody has.
        $this->actingFresh($this->rigger)->get(route('training-requirements.mine'))->assertOk()->assertSee('Rigging and slinging');
        $this->actingFresh($this->supervisor)->get(route('training-requirements.mine'))->assertOk()->assertSee('Nothing required');
    }

    // ================================================================== fixtures

    private function requireOfEveryone(TrainingCourse $course, int $graceDays = 0): TrainingRequirement
    {
        return $this->withinOrganization($this->org, fn () => TrainingRequirement::create([
            'organization_id' => $this->org->id,
            'training_course_id' => $course->id,
            'scope' => TrainingRequirement::SCOPE_ORGANIZATION,
            'is_mandatory' => true,
            'grace_days' => $graceDays,
            'is_active' => true,
        ]));
    }

    private function requireOfPosition(TrainingCourse $course, Position $position): TrainingRequirement
    {
        return $this->withinOrganization($this->org, fn () => TrainingRequirement::create([
            'organization_id' => $this->org->id,
            'training_course_id' => $course->id,
            'scope' => TrainingRequirement::SCOPE_POSITION,
            'position_id' => $position->id,
            'is_mandatory' => true,
            'grace_days' => 0,
            'is_active' => true,
        ]));
    }

    private function certificate(Employee $employee, TrainingCourse $course, ?string $expires, ?string $result = null): TrainingRecord
    {
        return $this->withinOrganization($this->org, fn () => TrainingRecord::create([
            'organization_id' => $this->org->id,
            'number' => 'TRN-'.str_pad((string) (TrainingRecord::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'training_course_id' => $course->id,
            'user_id' => $employee->user_id,
            'person_name' => $employee->fullName(),
            'completed_on' => '2026-01-01',
            'expires_on' => $expires,
            'result' => $result,
        ]));
    }

    /** @return array<int,array<string,mixed>> */
    private function cellsFor(Employee $employee): array
    {
        return $this->withinOrganization($this->org, function () use ($employee) {
            $service = app(TrainingRequirementService::class);

            return $service->forEmployee($employee->fresh(['position', 'orgUnit']), $service->activeRequirements());
        });
    }

    private function stateFor(Employee $employee, TrainingRequirement $requirement): string
    {
        foreach ($this->cellsFor($employee) as $cell) {
            if ($cell['requirement']->id === $requirement->id) {
                return $cell['state'];
            }
        }

        return 'no cell';
    }

    private function gapsFor(Employee $employee): int
    {
        return $this->withinOrganization($this->org, function () use ($employee) {
            $service = app(TrainingRequirementService::class);
            $rows = $service->matrix(collect([$employee->fresh(['position', 'orgUnit'])]), $service->activeRequirements());

            return $rows[0]['gaps'];
        });
    }
}
