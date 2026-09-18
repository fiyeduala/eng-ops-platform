<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Workflow;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * PHASE 6'S EXIT CRITERION, for vacancies: a requisition is decided at approvals.decide, the same
 * endpoint a drawing is approved through. There is no vacancies.approve route, and the first
 * assertion below says so out loud — a second door would let a recruiter open a head nobody agreed
 * to while every screen still looked right.
 *
 * Plus the three things that make a recruitment register safe to keep personal data in: the CV is a
 * private file served only after the record is authorised (§67), an application is not visible to
 * everybody who can see the requisition (§68), and hiring writes exactly one person however many
 * times the button is pressed.
 */
final class RecruitmentTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private User $manager;      // Kemi: line manager, decides the requisition

    private User $recruiter;    // Funke: HR, reports to Kemi, sees applications and may hire

    private User $viewer;       // Ada: may see requisitions, no sight of the people who applied

    private User $stranger;     // Uche: no recruitment permission at all

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->org = $this->makeOrganization('Meridian Hiring');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, fn () => app(OrganizationProvisioner::class)->seedVacancyWorkflow($this->org));

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Department);

        $managerRole = $this->makeRole($this->org, 'Engineering Manager', [
            'employee.view' => 'organization',
            'recruitment.view' => 'organization',
            'recruitment.create' => 'organization',
            'recruitment.submit' => 'organization',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        // Everything recruitment has, including the confidential grant that makes an application
        // readable at all, and `hire` — which is separate on purpose and is checked below.
        $recruiterRole = $this->makeRole($this->org, 'HR', [
            'employee.view' => 'organization', 'employee.create' => 'organization',
            'recruitment.view' => 'organization', 'recruitment.create' => 'organization',
            'recruitment.edit' => 'organization', 'recruitment.delete' => 'organization',
            'recruitment.submit' => 'organization', 'recruitment.manage' => 'organization',
            'recruitment.hire' => 'organization', 'recruitment.view_confidential' => 'organization',
            'position.view' => 'organization', 'org_unit.view' => 'organization',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        // The §68 fixture: sight of the requisition, and nothing else.
        $viewerRole = $this->makeRole($this->org, 'Board Observer', [
            'recruitment.view' => 'organization',
            'employee.view' => 'own',
        ]);

        $this->manager = $this->makePerson($this->org, 'Kemi Adeyemi', $this->engineering, [$managerRole]);
        $this->recruiter = $this->makePerson($this->org, 'Funke Balogun', null, [$recruiterRole], $this->manager->employee);
        $this->viewer = $this->makePerson($this->org, 'Ada Obi', null, [$viewerRole]);
        $this->stranger = $this->makePerson($this->org, 'Uche Nnaji', $this->engineering, [
            $this->makeRole($this->org, 'Site Engineer', ['employee.view' => 'own']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function a_vacancy_is_decided_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('vacancies.approve'), 'A vacancy must not have its own approve route.');
        $this->assertFalse(Route::has('applicants.approve'));

        $vacancy = $this->draftVacancy();
        $this->assertSame('draft', $vacancy->statusKey());

        $this->actingFresh($this->recruiter)->post(route('vacancies.submit', $vacancy))->assertSessionHasNoErrors();
        $vacancy = $this->reload($vacancy);
        $this->assertSame('submitted', $vacancy->statusKey());

        $stage = $this->pendingStage('vacancy', $vacancy);
        $this->assertTrue($stage->isAssignedTo($this->manager));

        // Neither the person who asked for the head nor a bystander decides it.
        $this->actingFresh($this->recruiter)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();
        $this->actingFresh($this->stranger)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        // Approved means OPEN FOR APPLICATIONS. That is the whole point of the status name.
        $this->assertSame('open', $this->reload($vacancy)->statusKey());
    }

    #[Test]
    public function an_unapproved_head_cannot_quietly_acquire_a_shortlist(): void
    {
        $vacancy = $this->draftVacancy();

        $this->actingFresh($this->recruiter)
            ->post(route('vacancies.applicants.store', $vacancy), ['first_name' => 'Ola', 'last_name' => 'Ade'])
            ->assertSessionHasErrors('cv');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Applicant::query()->count()));

        // Nor once it has been withdrawn.
        $vacancy = $this->openVacancy($vacancy);
        $this->actingFresh($this->recruiter)
            ->post(route('vacancies.close', $vacancy), ['reason' => 'Budget pulled.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('closed', $this->reload($vacancy)->statusKey());

        $this->actingFresh($this->recruiter)
            ->post(route('vacancies.applicants.store', $vacancy), ['first_name' => 'Ola', 'last_name' => 'Ade'])
            ->assertSessionHasErrors('cv');
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Applicant::query()->count()));
    }

    #[Test]
    public function an_approved_head_is_frozen_and_the_hired_count_is_never_typed(): void
    {
        $vacancy = $this->openVacancy();

        // Raising the openings on a head that has already been agreed, through the edit form.
        $this->actingFresh($this->recruiter)
            ->put(route('vacancies.update', $vacancy), ['title' => 'Site Engineer', 'openings' => 9])
            ->assertForbidden();

        // And the counter itself, posted while the record was still a draft.
        $draft = $this->draftVacancy('Planner');
        $this->actingFresh($this->recruiter)
            ->put(route('vacancies.update', $draft), ['title' => 'Planner', 'openings' => 1, 'hired_count' => 7, 'status_id' => 99])
            ->assertSessionHasNoErrors();

        $draft = $this->reload($draft);
        $this->assertSame(0, (int) $draft->hired_count);
        $this->assertSame('draft', $draft->statusKey());
    }

    // ================================================================== the CV

    #[Test]
    public function the_cv_is_private_and_is_handed_over_only_after_the_record_is_authorised(): void
    {
        Storage::fake('local');

        $applicant = $this->applicant(cv: UploadedFile::fake()->create('ola-adeyinka-cv.pdf', 40, 'application/pdf'));

        $this->assertTrue($applicant->hasCv());
        $this->assertStringContainsString('organizations/'.$this->org->id.'/applicants/', (string) $applicant->cv_path);
        // Nothing about the person is in the stored path — the uploaded name is kept as data.
        $this->assertStringNotContainsString('ola', mb_strtolower((string) $applicant->cv_path));
        $this->assertSame('ola-adeyinka-cv.pdf', $applicant->cv_name);
        $this->assertTrue(Storage::disk('local')->exists((string) $applicant->cv_path));

        // Nobody reaches the file without the record.
        $this->actingFresh($this->stranger)->get(route('applicants.cv', $applicant))->assertForbidden();
        $this->actingFresh($this->viewer)->get(route('applicants.cv', $applicant))->assertForbidden();

        $response = $this->actingFresh($this->recruiter)->get(route('applicants.cv', $applicant));
        $response->assertOk();
        // The name offered to the browser is built here, never echoed back from the upload.
        $disposition = mb_strtolower((string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(mb_strtolower($applicant->number), $disposition);
        $this->assertStringNotContainsString('ola-adeyinka-cv', $disposition);

        // A row that points at a file which is no longer there is a 404, not a stack trace.
        Storage::disk('local')->delete((string) $applicant->cv_path);
        $this->actingFresh($this->recruiter)->get(route('applicants.cv', $applicant))->assertNotFound();
    }

    #[Test]
    public function a_cv_that_is_not_a_document_is_refused(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant();

        $this->actingFresh($this->recruiter)
            ->post(route('applicants.cv.attach', $applicant), ['cv' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php')])
            ->assertSessionHasErrors('cv');

        $this->assertFalse($this->reloadApplicant($applicant)->hasCv());
    }

    // ================================================================== who sees an application

    #[Test]
    public function seeing_a_requisition_is_not_seeing_the_people_who_applied(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant(['email' => 'ola.adeyinka@example.test', 'phone' => '+234 802 000 0000']);
        $vacancy = $this->reload($applicant->vacancy);

        // The observer holds recruitment.view, and reads the requisition happily.
        $this->actingFresh($this->viewer)->get(route('vacancies.show', $vacancy))
            ->assertOk()
            ->assertSee('Site Engineer')
            ->assertDontSee('Ola Adeyinka');

        $this->actingFresh($this->viewer)->get(route('applicants.index'))->assertOk()->assertDontSee('Ola Adeyinka');
        $this->actingFresh($this->viewer)->get(route('applicants.show', $applicant))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('applicants.show', $applicant))->assertForbidden();

        // The register searches a name and a reference, and nothing else. A box that matched the
        // email address would confirm, by its result count alone, that a named person had applied.
        $this->actingFresh($this->recruiter)->get(route('applicants.index', ['q' => 'Adeyinka']))->assertOk()->assertSee('Ola Adeyinka');
        $this->actingFresh($this->recruiter)->get(route('applicants.index', ['q' => 'ola.adeyinka@example.test']))
            ->assertOk()->assertDontSee('Ola Adeyinka');
        $this->actingFresh($this->recruiter)->get(route('applicants.index', ['q' => '+234 802 000 0000']))
            ->assertOk()->assertDontSee('Ola Adeyinka');
    }

    #[Test]
    public function an_interview_note_is_not_repeated_into_the_timeline(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant();

        $this->actingFresh($this->recruiter)
            ->post(route('applicants.stages.store', $applicant), ['name' => 'Technical interview', 'interviewer_id' => $this->manager->employee->id])
            ->assertSessionHasNoErrors();

        $stage = $this->withinOrganization($this->org, fn () => $applicant->stages()->firstOrFail());
        $this->assertSame('screening', $this->reloadApplicant($applicant)->statusKey());

        $this->actingFresh($this->recruiter)
            ->post(route('applicant-stages.record', $stage), [
                'outcome' => 'passed',
                'score' => 4,
                'comments' => 'Weak on foundations, strong on temporary works.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('interview', $this->reloadApplicant($applicant)->statusKey());

        // The outcome is on the timeline; what the interviewer wrote is not.
        $this->actingFresh($this->recruiter)->get(route('applicants.show', $applicant))
            ->assertOk()
            ->assertSee('Technical interview')
            ->assertSee('Weak on foundations, strong on temporary works.');

        $entries = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', $applicant->getMorphClass())->pluck('description')->implode(' '));
        $this->assertStringNotContainsString('Weak on foundations', $entries);

        // An interviewer who is not in the actor's own reach is refused outright.
        $other = $this->makeOrganization('Kestrel Hiring');
        $this->provisionConfiguration($other);
        $outsider = $this->makePerson($other, 'Bode Kestrel', null, []);
        $this->actingFresh($this->recruiter)
            ->post(route('applicants.stages.store', $applicant), ['name' => 'Second', 'interviewer_id' => $outsider->employee->id])
            ->assertForbidden();
    }

    // ================================================================== hiring

    #[Test]
    public function hiring_writes_one_person_however_many_times_the_button_is_pressed(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant();

        // Not before an offer is on the record.
        $this->actingFresh($this->recruiter)
            ->post(route('applicants.hire', $applicant), ['employee_no' => 'E-2026-001', 'hired_on' => '2026-04-01'])
            ->assertForbidden();

        $this->actingFresh($this->stranger)->post(route('applicants.offer', $applicant))->assertForbidden();
        $this->actingFresh($this->recruiter)->post(route('applicants.offer', $applicant))->assertSessionHasNoErrors();
        $this->assertSame('offered', $this->reloadApplicant($applicant)->statusKey());

        // `hire` is its own permission: the line manager holds recruitment.view and submit, and is
        // stopped at the door.
        $this->actingFresh($this->manager)
            ->post(route('applicants.hire', $applicant), ['employee_no' => 'E-2026-001', 'hired_on' => '2026-04-01'])
            ->assertForbidden();

        $payload = [
            'employee_no' => 'E-2026-001',
            'hired_on' => '2026-04-01',
            'org_unit_id' => $this->engineering->id,
            'supervisor_id' => $this->manager->employee->id,
        ];

        $this->actingFresh($this->recruiter)->post(route('applicants.hire', $applicant), $payload)->assertSessionHasNoErrors();

        // The second click — the double submit this guards against.
        $this->actingFresh($this->recruiter)->post(route('applicants.hire', $applicant), $payload)->assertForbidden();

        $employees = $this->withinOrganization($this->org, fn () => Employee::query()->where('employee_no', 'E-2026-001')->get());
        $this->assertCount(1, $employees);
        $this->assertSame('Ola', $employees[0]->first_name);
        $this->assertSame($this->engineering->id, (int) $employees[0]->org_unit_id);
        // A personnel record is not a sign-in. Deciding what somebody may reach is a separate act.
        $this->assertNull($employees[0]->user_id);

        $applicant = $this->reloadApplicant($applicant);
        $this->assertSame('hired', $applicant->statusKey());
        $this->assertSame($employees[0]->id, (int) $applicant->hired_employee_id);

        // One head asked for, one head taken: the vacancy closes itself, and is not editable after.
        $vacancy = $this->reload($applicant->vacancy);
        $this->assertSame(1, (int) $vacancy->hired_count);
        $this->assertSame('filled', $vacancy->statusKey());

        // A hired application is a record of what happened, not a form.
        $this->actingFresh($this->recruiter)
            ->put(route('applicants.update', $applicant), ['first_name' => 'Olu', 'last_name' => 'Adeyinka'])
            ->assertForbidden();
    }

    #[Test]
    public function a_second_head_cannot_be_taken_against_a_filled_vacancy(): void
    {
        Storage::fake('local');
        $first = $this->applicant();
        $second = $this->applicant(['first_name' => 'Bisi', 'last_name' => 'Okonkwo'], vacancy: $first->vacancy);

        foreach ([$first, $second] as $applicant) {
            $this->actingFresh($this->recruiter)->post(route('applicants.offer', $applicant))->assertSessionHasNoErrors();
        }

        $this->actingFresh($this->recruiter)
            ->post(route('applicants.hire', $first), ['employee_no' => 'E-2026-010', 'hired_on' => '2026-04-01'])
            ->assertSessionHasNoErrors();

        // The head is gone, so the second offer cannot be converted however it is posted.
        $this->actingFresh($this->recruiter)
            ->post(route('applicants.hire', $second), ['employee_no' => 'E-2026-011', 'hired_on' => '2026-04-01'])
            ->assertSessionHasErrors('employee_no');

        $this->assertSame(1, $this->withinOrganization($this->org, fn () => Employee::query()->where('employee_no', 'like', 'E-2026-01%')->count()));
    }

    // ================================================================== tenancy and screens

    #[Test]
    public function another_tenant_gets_a_404(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant(cv: UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'));
        $vacancy = $applicant->vacancy;

        $other = $this->makeOrganization('Kestrel Hiring');
        $this->provisionConfiguration($other);
        $outsider = $this->makePerson($other, 'Ada Kestrel', null, [
            $this->makeRole($other, 'HR', [
                'employee.view' => 'organization',
                'recruitment.view' => 'organization', 'recruitment.edit' => 'organization',
                'recruitment.manage' => 'organization', 'recruitment.hire' => 'organization',
                'recruitment.view_confidential' => 'organization', 'recruitment.submit' => 'organization',
            ]),
        ]);

        $this->actingFresh($outsider)->get(route('vacancies.show', $vacancy))->assertNotFound();
        $this->actingFresh($outsider)->get(route('vacancies.edit', $vacancy))->assertNotFound();
        $this->actingFresh($outsider)->post(route('vacancies.submit', $vacancy))->assertNotFound();
        $this->actingFresh($outsider)->get(route('applicants.show', $applicant))->assertNotFound();
        $this->actingFresh($outsider)->get(route('applicants.cv', $applicant))->assertNotFound();
        $this->actingFresh($outsider)->post(route('applicants.offer', $applicant))->assertNotFound();
        $this->actingFresh($outsider)->get(route('applicants.index'))->assertOk()->assertDontSee('Ola Adeyinka');
        $this->actingFresh($outsider)->get(route('vacancies.index', ['view' => 'all']))->assertOk()->assertDontSee('Site Engineer');
    }

    #[Test]
    public function without_a_route_a_vacancy_is_not_submitted(): void
    {
        $this->withinOrganization($this->org, fn () => Workflow::query()->where('entity_type', 'vacancy')->update(['is_active' => false]));

        $vacancy = $this->draftVacancy();
        $this->actingFresh($this->recruiter)->post(route('vacancies.submit', $vacancy))->assertSessionHasErrors('status_id');
        $this->assertSame('draft', $this->reload($vacancy)->statusKey());
    }

    #[Test]
    public function every_recruitment_screen_renders(): void
    {
        Storage::fake('local');
        $applicant = $this->applicant(cv: UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'));
        $vacancy = $this->reload($applicant->vacancy);
        $draft = $this->draftVacancy('Planner');

        $this->actingFresh($this->recruiter);
        $this->get(route('vacancies.index'))->assertOk()->assertSee($vacancy->number);
        $this->get(route('vacancies.create'))->assertOk();
        $this->get(route('vacancies.edit', $draft))->assertOk();
        $this->get(route('vacancies.show', $vacancy))->assertOk()->assertSee('Ola Adeyinka');
        $this->get(route('vacancies.applicants.create', $vacancy))->assertOk();
        $this->get(route('applicants.index'))->assertOk()->assertSee($applicant->number);
        $this->get(route('applicants.show', $applicant))->assertOk()->assertSee('cv.pdf');
        $this->get(route('applicants.edit', $applicant))->assertOk();

        // The requisition screen offers the inbox, never an approve button of its own.
        $awaiting = $this->submittedVacancy();
        $this->actingFresh($this->manager);
        $this->get(route('vacancies.show', $awaiting))->assertOk()->assertSee('Decide in My Approvals');
    }

    // ================================================================== helpers

    private function draftVacancy(string $title = 'Site Engineer'): Vacancy
    {
        $this->actingFresh($this->recruiter)
            ->post(route('vacancies.store'), [
                'title' => $title,
                'openings' => 1,
                'org_unit_id' => $this->engineering->id,
                'summary' => 'Setting out, temporary works and daily records on the Lekki site.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => Vacancy::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function submittedVacancy(string $title = 'Quantity Surveyor'): Vacancy
    {
        $vacancy = $this->draftVacancy($title);
        $this->actingFresh($this->recruiter)->post(route('vacancies.submit', $vacancy))->assertSessionHasNoErrors();

        return $this->reload($vacancy);
    }

    /** A vacancy through its route, approved by the line manager — the only way one opens. */
    private function openVacancy(?Vacancy $vacancy = null): Vacancy
    {
        $vacancy ??= $this->draftVacancy();

        if ($vacancy->statusKey() === 'draft') {
            $this->actingFresh($this->recruiter)->post(route('vacancies.submit', $vacancy))->assertSessionHasNoErrors();
        }

        $this->actingFresh($this->manager)
            ->post(route('approvals.decide', $this->pendingStage('vacancy', $vacancy)), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        return $this->reload($vacancy);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function applicant(array $attributes = [], ?Vacancy $vacancy = null, ?UploadedFile $cv = null): Applicant
    {
        $vacancy ??= $this->openVacancy();

        $payload = ['first_name' => 'Ola', 'last_name' => 'Adeyinka', ...$attributes];

        if ($cv !== null) {
            $payload['cv'] = $cv;
        }

        $this->actingFresh($this->recruiter)
            ->post(route('vacancies.applicants.store', $vacancy), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => Applicant::query()->latest('id')->firstOrFail()->load(['status', 'vacancy.status']));
    }

    private function reload(Vacancy $vacancy): Vacancy
    {
        return $this->withinOrganization($this->org, fn () => $vacancy->fresh(['status']));
    }

    private function reloadApplicant(Applicant $applicant): Applicant
    {
        return $this->withinOrganization($this->org, fn () => $applicant->fresh(['status', 'vacancy.status']));
    }

    private function pendingStage(string $type, Model $subject): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', $type)->where('subject_id', $subject->getKey()))
            ->firstOrFail());
    }
}
