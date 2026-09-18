<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewItem;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * PHASE 6'S EXIT CRITERION, for appraisals: a finished review is signed off at approvals.decide,
 * the same endpoint a drawing and a leave request are decided at. There is no
 * performance-reviews.approve route, and the first test says so out loud.
 *
 * The three other things an appraisal module has to get right:
 *
 *   1. TWO HALVES, TWO AUTHORS. The subject writes the self-assessment and the manager writes the
 *      rating, and neither can write the other's — not because the form hides it, but because they
 *      are separate endpoints with separate policy checks (§84). A manager who could edit somebody's
 *      own words about their year would make the pair of columns meaningless.
 *   2. RESTRICTED BOTH WAYS. A review is confidential by default. The subject reads their own — the
 *      one person who must never be locked out of it — and a manager from another department reads
 *      nothing, however senior. The reviewer's reach comes from a record ACL written when the
 *      review is opened, not from widening anybody's scope.
 *   3. THE SUBJECT DOES NOT SEE THE RATING BEFORE IT IS SIGNED OFF. A half-written manager comment
 *      is not a decision and must not read as one (§68).
 */
final class PerformanceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $finance;

    private User $hr;           // Amaka: runs the round, reads everything

    private User $head;         // Tobi: head of Engineering, signs off his managers' reviews

    private User $manager;      // Ifeanyi: reports to Tobi, reviews Chuka

    private User $employee;     // Chuka: reports to Ifeanyi, is reviewed

    private User $outsider;     // Halima: a manager in Finance with every performance permission
    //         her own department could want, and no business here

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 08:00:00');

        $this->org = $this->makeOrganization('Meridian Appraisals');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, fn () => app(OrganizationProvisioner::class)->seedPerformanceReviewWorkflow($this->org));

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Department);
        $this->finance = $this->makeUnit($this->org, 'Finance', OrgUnitType::Department);

        $hrRole = $this->makeRole($this->org, 'HR Manager', [
            'employee.view' => 'organization',
            'performance.view' => 'organization', 'performance.create' => 'organization',
            'performance.edit' => 'organization', 'performance.delete' => 'organization',
            'performance.manage' => 'organization', 'performance.view_confidential' => 'organization',
            'org_unit.view' => 'organization', 'user.view' => 'organization',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        $headRole = $this->makeRole($this->org, 'Head of Engineering', [
            'employee.view' => 'department',
            'performance.view' => 'department', 'performance.manage' => 'department',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        // A line manager. `submit` at `own` and nothing else — everything they reach on somebody
        // else's review comes from the ACL row written when the review was opened.
        $managerRole = $this->makeRole($this->org, 'Line Manager', [
            'employee.view' => 'department',
            'performance.view' => 'own', 'performance.submit' => 'own',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        $employeeRole = $this->makeRole($this->org, 'Engineer', [
            'employee.view' => 'own',
            'performance.view' => 'own', 'performance.submit' => 'own',
        ]);

        // The §75/gate-5 fixture: a full performance grant in a department this review is not in.
        $outsiderRole = $this->makeRole($this->org, 'Finance Manager', [
            'employee.view' => 'department',
            'performance.view' => 'department', 'performance.manage' => 'department',
            'performance.submit' => 'department',
        ]);

        $this->hr = $this->makePerson($this->org, 'Amaka Nwosu', null, [$hrRole]);
        $this->head = $this->makePerson($this->org, 'Tobi Ajayi', $this->engineering, [$headRole]);
        $this->manager = $this->makePerson($this->org, 'Ifeanyi Eze', $this->engineering, [$managerRole], $this->head->employee);
        $this->employee = $this->makePerson($this->org, 'Chuka Madu', $this->engineering, [$employeeRole], $this->manager->employee);
        $this->outsider = $this->makePerson($this->org, 'Halima Bello', $this->finance, [$outsiderRole]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function a_review_is_signed_off_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('performance-reviews.approve'), 'A review must not have its own approve route.');
        $this->assertFalse(Route::has('review-cycles.approve'));

        $review = $this->reviewWithManagerHalfDone();
        $items = $this->itemsOf($review);

        $this->actingFresh($this->manager)
            ->post(route('performance-reviews.manager', $review), [
                'items' => [
                    $items[0]->id => ['manager_score' => 4, 'manager_comment' => 'Ran the package properly.'],
                    $items[1]->id => ['manager_score' => 4, 'manager_comment' => 'Stops the job when it needs stopping.'],
                ],
                'overall_rating' => 4,
                'manager_comments' => 'A strong year on the Bonny tie-in.',
                'send' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $review = $this->reload($review);
        $this->assertSame('submitted', $review->statusKey());

        // The stage goes to the REVIEWER'S own line manager — the record's requester is Ifeanyi, so
        // the ordinary `supervisor` stage resolves Tobi with no special case anywhere.
        $stage = $this->pendingStage('performance_review', $review);
        $this->assertTrue($stage->isAssignedTo($this->head));

        // Neither the person being reviewed nor the person who wrote it decides it.
        $this->actingFresh($this->employee)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();
        $this->actingFresh($this->manager)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($this->head)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('completed', $this->reload($review)->statusKey());
    }

    // ================================================================== two halves, two authors

    #[Test]
    public function the_manager_cannot_write_the_self_assessment_and_the_employee_cannot_write_the_rating(): void
    {
        $review = $this->openedReview();
        $items = $this->itemsOf($review);

        // The subject's half is the subject's. The manager is not admitted to it even while the
        // review sits with the employee and the manager is the named reviewer.
        $this->actingFresh($this->manager)
            ->post(route('performance-reviews.self', $review), [
                'items' => [$items[0]->id => ['self_score' => 5, 'self_comment' => 'He did well, I expect.']],
                'self_comments' => 'Written for him.',
            ])
            ->assertForbidden();

        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.self', $review), [
                'items' => [$items[0]->id => ['self_score' => 4, 'self_comment' => 'Led the tie-in package.']],
                'self_comments' => 'A busy year.',
                'send' => 1,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $review = $this->reload($review);
        $this->assertSame('manager_review', $review->statusKey());
        $this->assertSame('A busy year.', $review->self_comments);

        // And now the other way: the employee cannot put a rating on their own review.
        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.manager', $review), ['overall_rating' => 5])
            ->assertForbidden();

        $this->assertNull($this->reload($review)->overall_rating);

        // The self-assessment is frozen once sent — a second bite is refused by the service, not by
        // a disabled button.
        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.self', $review), ['self_comments' => 'Actually, let me redo that.'])
            ->assertForbidden();

        $this->assertSame('A busy year.', $this->reload($review)->self_comments);
    }

    #[Test]
    public function a_score_outside_the_cycles_own_scale_is_refused(): void
    {
        $review = $this->openedReview();
        $items = $this->itemsOf($review);

        // The cycle below runs 1–5. Six is not a high score, it is not a score.
        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.self', $review), [
                'items' => [$items[0]->id => ['self_score' => 6]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertNull($this->itemsOf($review)[0]->self_score);
    }

    #[Test]
    public function a_review_cannot_be_sent_for_sign_off_without_an_overall_rating(): void
    {
        $review = $this->reviewWithManagerHalfDone();

        $this->actingFresh($this->manager)
            ->post(route('performance-reviews.manager', $review), [
                'manager_comments' => 'Good year.',
                'send' => 1,
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame('manager_review', $this->reload($review)->statusKey());
    }

    // ================================================================== restricted both ways

    #[Test]
    public function the_subject_reads_their_own_restricted_review_and_an_outside_manager_reads_nothing(): void
    {
        $review = $this->openedReview();

        // Restricted by default, and the person it is about is the one who must never be locked out.
        $this->assertSame(Confidentiality::Restricted, $review->confidentiality);
        $this->actingFresh($this->employee)->get(route('performance-reviews.show', $review))->assertOk();

        // The reviewer reaches it through the ACL row written when it was opened, not through scope.
        $this->actingFresh($this->manager)->get(route('performance-reviews.show', $review))->assertOk();

        // Halima runs Finance and holds every performance permission her department could want.
        // None of it reaches an engineer's appraisal.
        $this->actingFresh($this->outsider)->get(route('performance-reviews.show', $review))->assertForbidden();
        $this->actingFresh($this->outsider)->get(route('performance-reviews.index'))->assertOk()->assertDontSee($review->number);

        // And she cannot write to it either — a forbidden read is not the only thing standing there.
        $this->actingFresh($this->outsider)
            ->post(route('performance-reviews.manager', $review), ['overall_rating' => 1])
            ->assertForbidden();
    }

    #[Test]
    public function the_subject_does_not_see_the_rating_until_it_has_been_signed_off(): void
    {
        $review = $this->reviewWithManagerHalfDone();
        $items = $this->itemsOf($review);
        $scores = [
            'items' => [
                $items[0]->id => ['manager_score' => 2],
                $items[1]->id => ['manager_score' => 3],
            ],
        ];

        $this->actingFresh($this->manager)
            ->post(route('performance-reviews.manager', $review), $scores + [
                'overall_rating' => 2,
                'manager_comments' => 'Needs to close out his punch lists.',
            ])
            ->assertSessionHasNoErrors();

        // Written, saved, and not yet a decision.
        $this->actingFresh($this->employee)
            ->get(route('performance-reviews.show', $review))
            ->assertOk()
            ->assertDontSee('Needs to close out his punch lists')
            ->assertSee('Not yet signed off');

        $this->actingFresh($this->manager)->post(route('performance-reviews.manager', $review), $scores + [
            'overall_rating' => 2,
            'manager_comments' => 'Needs to close out his punch lists.',
            'send' => 1,
        ])->assertSessionHasNoErrors();

        $stage = $this->pendingStage('performance_review', $this->reload($review));
        $this->actingFresh($this->head)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->actingFresh($this->employee)
            ->get(route('performance-reviews.show', $review))
            ->assertOk()
            ->assertSee('Needs to close out his punch lists');

        // Acknowledging says "I have read this", not "I agree with it".
        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.acknowledge', $review), ['acknowledgement_comments' => 'I do not accept the second point.'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->reload($review)->acknowledged_at);

        // And nobody else acknowledges on their behalf.
        $second = $this->reload($review);
        $this->actingFresh($this->manager)->post(route('performance-reviews.acknowledge', $second))->assertForbidden();
    }

    #[Test]
    public function a_review_from_another_company_is_not_found(): void
    {
        $review = $this->openedReview();

        $other = $this->makeOrganization('Other Engineering');
        $this->provisionConfiguration($other);
        $stranger = $this->makePerson($other, 'Sani Musa', null, [
            $this->makeRole($other, 'HR', [
                'performance.view' => 'organization', 'performance.manage' => 'organization',
                'performance.view_confidential' => 'organization',
            ]),
        ]);

        $this->actingFresh($stranger)->get(route('performance-reviews.show', $review))->assertNotFound();
        $this->actingFresh($stranger)->post(route('performance-reviews.manager', $review), ['overall_rating' => 5])->assertNotFound();
    }

    // ================================================================== the screens

    #[Test]
    public function every_performance_screen_renders(): void
    {
        $cycle = $this->openCycle();
        $review = $this->withinOrganization($this->org, fn () => PerformanceReview::query()->where('employee_id', $this->employee->employee->id)->firstOrFail());

        $this->actingFresh($this->hr)->get(route('review-cycles.index'))->assertOk()->assertSee('Mid-year 2026');
        $this->actingFresh($this->hr)->get(route('review-cycles.show', $cycle))->assertOk()->assertSee('Technical judgement');
        $this->actingFresh($this->hr)->get(route('review-cycles.create'))->assertOk();
        $this->actingFresh($this->hr)->get(route('review-cycles.edit', $cycle))->assertOk();

        $this->actingFresh($this->hr)->get(route('performance-reviews.index'))->assertOk();
        $this->actingFresh($this->hr)->get(route('performance-reviews.show', $review))->assertOk();
        $this->actingFresh($this->hr)->get(route('performance-reviews.edit', $review))->assertOk();

        $this->actingFresh($this->employee)->get(route('performance-reviews.mine'))->assertOk()->assertSee('Mid-year 2026');
        $this->actingFresh($this->manager)->get(route('performance-reviews.mine'))->assertOk()->assertSee('Chuka Madu');
    }

    // ================================================================== fixtures

    private function openCycle(): ReviewCycle
    {
        $this->actingFresh($this->hr)
            ->post(route('review-cycles.store'), [
                'name' => 'Mid-year 2026',
                'period_start' => '2026-01-01',
                'period_end' => '2026-06-30',
                'rating_min' => 1,
                'rating_max' => 5,
                'instructions' => 'Score against evidence, not impressions.',
                'competencies' => [
                    ['label' => 'Technical judgement', 'kind' => 'competency', 'weight' => 2],
                    ['label' => 'Safety leadership', 'kind' => 'value', 'weight' => 1],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $cycle = $this->withinOrganization($this->org, fn () => ReviewCycle::query()->latest('id')->firstOrFail());

        $this->actingFresh($this->hr)
            ->post(route('review-cycles.open', $cycle), ['org_units' => [$this->engineering->id]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => $cycle->fresh(['status']));
    }

    /** Chuka's review, open and sitting with him. */
    private function openedReview(): PerformanceReview
    {
        $this->openCycle();

        return $this->withinOrganization($this->org, fn () => PerformanceReview::query()
            ->where('employee_id', $this->employee->employee->id)
            ->firstOrFail()
            ->load('status'));
    }

    /** The same review, with the self-assessment sent and the manager holding it. */
    private function reviewWithManagerHalfDone(): PerformanceReview
    {
        $review = $this->openedReview();
        $items = $this->itemsOf($review);

        $this->actingFresh($this->employee)
            ->post(route('performance-reviews.self', $review), [
                'items' => [
                    $items[0]->id => ['self_score' => 4, 'self_comment' => 'Led the tie-in package.'],
                    $items[1]->id => ['self_score' => 5, 'self_comment' => 'No lost-time incidents.'],
                ],
                'self_comments' => 'A busy year.',
                'send' => 1,
            ])
            ->assertSessionHasNoErrors();

        return $this->reload($review);
    }

    /** @return array<int,PerformanceReviewItem> */
    private function itemsOf(PerformanceReview $review): array
    {
        return $this->withinOrganization($this->org, fn () => $review->items()->orderBy('sequence')->get()->values()->all());
    }

    private function reload(PerformanceReview $review): PerformanceReview
    {
        return $this->withinOrganization($this->org, fn () => $review->fresh(['status', 'items', 'cycle']));
    }

    private function pendingStage(string $type, Model $subject): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', $type)->where('subject_id', $subject->getKey()))
            ->firstOrFail());
    }
}
