<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Configuration\SettingsRepository;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Models\Visitor;
use App\Services\VisitorService;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The visitor book — Phase 6's stated exception, and the tests that hold it to what it claims.
 *
 * EVERY OTHER REGISTER IN THIS PHASE PROVES THE SAME THING: no approve route, decided at
 * approvals.decide. This one proves something different and has to prove it deliberately, because
 * "we skipped the workflow here" is exactly what a shortcut looks like from the outside. So the
 * first test asserts BOTH halves: there is no approve route AND there is no submit route, the model
 * refuses to route at all, and the thing standing in for an approval is a permission at the desk
 * that a person who may book a visitor does not thereby hold.
 *
 * The rest is what a gate log has to get right or not exist:
 *
 *   1. THE ROLL CALL IS THE PRODUCT. It counts heads rather than rows, it shows only who is actually
 *      inside, and it is not corrupted by a record going back on site after it closed.
 *   2. THE STATE MACHINE IS REAL. Unlike every other register in Phase 6, this one's status is not a
 *      consequence of a workflow closing — somebody presses a button with a person in front of them,
 *      and the machine has to refuse the moves that make no sense.
 *   3. NOBODY REACHES ANOTHER TENANT'S VISITORS, and nobody reaches a site they are not scoped to
 *      (§75, §94).
 *   4. THE IDENTITY DOCUMENT IS NOT READABLE from the record, the search or the JSON (§67).
 */
final class VisitorTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private Organization $other;

    private OrgUnit $engineering;

    private User $reception;   // Grace: books, admits and signs out, across the company

    private User $engineer;    // Kelechi: books visitors, and cannot open the gate

    private User $guard;       // Sunday: admits and signs out, and cannot book

    private User $stranger;    // Ada, at the other company

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 09:00:00');

        $this->org = $this->makeOrganization('Meridian Visitors');
        $this->provisionConfiguration($this->org);
        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Department);

        $receptionRole = $this->makeRole($this->org, 'Receptionist', [
            'employee.view' => 'organization', 'org_unit.view' => 'organization',
            'visitor.view' => 'organization', 'visitor.create' => 'organization',
            'visitor.edit' => 'organization', 'visitor.delete' => 'organization',
            'visitor.manage' => 'organization',
            'visitor.check_in' => 'organization', 'visitor.check_out' => 'organization',
        ]);

        // The separation this register turns on: Kelechi can tell reception somebody is coming. He
        // cannot let them in.
        $engineerRole = $this->makeRole($this->org, 'Engineer', [
            'employee.view' => 'own',
            'visitor.view' => 'own', 'visitor.create' => 'organization', 'visitor.edit' => 'own',
        ]);

        // And the other half: Sunday works the gate and has no business booking anybody.
        $guardRole = $this->makeRole($this->org, 'Gate control', [
            'employee.view' => 'organization',
            'visitor.view' => 'organization',
            'visitor.check_in' => 'organization', 'visitor.check_out' => 'organization',
        ]);

        $this->reception = $this->makePerson($this->org, 'Grace Aminu', null, [$receptionRole]);
        $this->engineer = $this->makePerson($this->org, 'Kelechi Nnaji', $this->engineering, [$engineerRole]);
        $this->guard = $this->makePerson($this->org, 'Sunday Oduya', null, [$guardRole]);

        $this->other = $this->makeOrganization('Kestrel Visitors');
        $this->provisionConfiguration($this->other);
        $strangerRole = $this->makeRole($this->other, 'Receptionist', [
            'visitor.view' => 'organization', 'visitor.create' => 'organization',
            'visitor.check_in' => 'organization', 'visitor.check_out' => 'organization',
        ]);
        $this->stranger = $this->makePerson($this->other, 'Ada Bello', null, [$strangerRole]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the stated exception

    #[Test]
    public function a_visit_is_not_routed_and_the_gate_is_a_permission_rather_than_an_inbox(): void
    {
        // Neither half exists. The absent approve route is what every Phase 6 register asserts; the
        // absent submit route is what makes this one the exception rather than an oversight.
        $this->assertFalse(Route::has('visitors.approve'), 'A visit must not have an approve route.');
        $this->assertFalse(Route::has('visitors.submit'), 'A visit is not submitted for approval.');

        $visit = $this->book();

        // The model says so as well, so a company cannot configure a route for visitors and then
        // watch a receptionist wait on an inbox.
        $this->assertFalse($this->reload($visit)->requiresWorkflow());

        // Kelechi booked it and cannot open the gate. That refusal IS the control on this register.
        $this->actingFresh($this->engineer)
            ->post(route('visitors.check-in', $visit), [])
            ->assertForbidden();

        $this->actingFresh($this->guard)
            ->post(route('visitors.check-in', $visit), ['party_size' => 1, 'badge_number' => 'V-14'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('on_site', $this->reload($visit)->statusKey());
    }

    #[Test]
    public function somebody_who_works_the_gate_cannot_book_a_visitor(): void
    {
        $this->actingFresh($this->guard)
            ->post(route('visitors.store'), $this->bookingPayload())
            ->assertForbidden();
    }

    // ================================================================== the state machine

    #[Test]
    public function the_moves_that_make_no_sense_are_refused(): void
    {
        $visit = $this->book();

        // Cannot sign out somebody who never arrived.
        $this->actingFresh($this->reception)
            ->post(route('visitors.check-out', $visit))
            ->assertForbidden();

        $this->checkIn($visit);

        // Cannot sign in twice, cannot refuse entry to somebody already inside, cannot cancel a
        // visit that is happening.
        $this->actingFresh($this->reception)->post(route('visitors.check-in', $visit), [])->assertForbidden();
        $this->actingFresh($this->reception)->post(route('visitors.refuse', $visit), ['reason' => 'No'])->assertForbidden();
        $this->actingFresh($this->reception)->post(route('visitors.cancel', $visit), ['reason' => 'No'])->assertForbidden();

        $this->actingFresh($this->reception)
            ->post(route('visitors.check-out', $visit))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $visit = $this->reload($visit);
        $this->assertSame('departed', $visit->statusKey());
        $this->assertNotNull($visit->checked_out_at);
        $this->assertFalse($visit->auto_closed);

        // AND A DEPARTED VISIT IS CLOSED FOR GOOD. Somebody back after lunch is a second visit, not
        // a reopened one — the roll call has to stay readable.
        $this->actingFresh($this->reception)->post(route('visitors.check-in', $visit))->assertForbidden();
        $this->actingFresh($this->reception)->post(route('visitors.check-out', $visit))->assertForbidden();
        $this->actingFresh($this->reception)
            ->put(route('visitors.update', $visit), $this->bookingPayload(['full_name' => 'Somebody Else']))
            ->assertForbidden();
    }

    #[Test]
    public function a_visit_that_happened_cannot_be_deleted(): void
    {
        $visit = $this->book();

        // A booking nobody turned up to is just a mistake, and may go.
        $this->actingFresh($this->reception)->delete(route('visitors.destroy', $visit))->assertRedirect();

        $arrived = $this->book(['full_name' => 'Yemi Sokoto']);
        $this->checkIn($arrived);

        // One that happened is evidence, and deleting it would take somebody off a roll call they
        // belong on (§59).
        $this->actingFresh($this->reception)->delete(route('visitors.destroy', $arrived))->assertForbidden();
    }

    // ================================================================== the roll call

    #[Test]
    public function the_roll_call_counts_heads_and_shows_only_who_is_inside(): void
    {
        $alone = $this->book(['full_name' => 'Ibrahim Yusuf']);
        $delegation = $this->book(['full_name' => 'Marta Nowak', 'party_size' => 6]);
        $expected = $this->book(['full_name' => 'Never Arrives']);
        $gone = $this->book(['full_name' => 'Been And Left']);

        $this->checkIn($alone);
        $this->checkIn($delegation, ['party_size' => 6]);
        $this->checkIn($gone);
        $this->actingFresh($this->reception)->post(route('visitors.check-out', $gone))->assertRedirect();

        $response = $this->actingFresh($this->reception)->get(route('visitors.roll-call'))->assertOk();

        // Seven heads across two visits: one on their own and a delegation of six. A screen counting
        // rows would say two and be five people short at an assembly point.
        $this->assertSame(7, $response->viewData('headcount'));
        $this->assertSame(2, $response->viewData('onSite')->count());

        $names = $response->viewData('onSite')->pluck('full_name')->all();
        $this->assertContains('Ibrahim Yusuf', $names);
        $this->assertContains('Marta Nowak', $names);
        $this->assertNotContains('Never Arrives', $names);
        $this->assertNotContains('Been And Left', $names);

        // Unused, but it is the record that would corrupt the count if the scope read the status
        // instead of the timestamps.
        $this->assertSame('expected', $this->reload($expected)->statusKey());
    }

    #[Test]
    public function the_roll_call_is_open_to_anybody_who_may_read_the_register(): void
    {
        // Deliberately NOT behind `visitor.manage`. Sunday holds no administrative permission at all
        // and must still be able to find out who is inside.
        $this->actingFresh($this->guard)->get(route('visitors.roll-call'))->assertOk();

        $nobody = $this->makePerson($this->org, 'Tayo Bankole', null, [
            $this->makeRole($this->org, 'Storekeeper', ['employee.view' => 'own']),
        ]);

        $this->actingFresh($nobody)->get(route('visitors.roll-call'))->assertForbidden();
    }

    // ================================================================== the end-of-day sweep

    #[Test]
    public function the_sweep_closes_stragglers_and_says_that_nobody_watched_them_go(): void
    {
        $visit = $this->book(['full_name' => 'Forgot To Sign Out']);
        $this->checkIn($visit);

        $service = app(VisitorService::class);

        // Mid-afternoon, with a 23:00 cut-off: the most recent cut-off that has passed is LAST
        // night's, and this visitor arrived after it. Nothing to do.
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => $service->closeOutStragglers()));
        $this->assertTrue($this->reload($visit)->isOnSite());

        // Half past midnight. Today's cut-off has not arrived, so the sweep uses yesterday's 23:00 —
        // which is after this visitor walked in, so they are closed. A version that only ever looked
        // at today would leave them on the roll call for another full day.
        Carbon::setTestNow('2026-09-25 00:30:00');
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => $service->closeOutStragglers()));

        $visit = $this->reload($visit);
        $this->assertFalse($visit->isOnSite());
        $this->assertSame('departed', $visit->statusKey());
        $this->assertTrue($visit->auto_closed);
        // The time recorded is the cut-off, not the moment the sweep ran.
        $this->assertSame('2026-09-24 23:00:00', $visit->checked_out_at->toDateTimeString());

        // Safe to run twice.
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => $service->closeOutStragglers()));
    }

    #[Test]
    public function a_company_can_turn_the_sweep_off(): void
    {
        $visit = $this->book(['full_name' => 'Night Shift']);
        $this->checkIn($visit);

        $this->withinOrganization($this->org, function () {
            app(SettingsRepository::class)->set('visitor.auto_close_enabled', false, $this->org->id);
        });

        Carbon::setTestNow('2026-09-25 00:30:00');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => app(VisitorService::class)->closeOutStragglers()));
        $this->assertTrue($this->reload($visit)->isOnSite());
    }

    // ================================================================== company rules, not ours

    #[Test]
    public function an_escorted_contractor_cannot_be_signed_in_without_an_escort(): void
    {
        $visit = $this->book(['full_name' => 'Fitter From Outside', 'purpose_type' => 'contractor']);

        // The company's rule is on by default, so the escort requirement is applied at the gate even
        // though the booking never ticked it (§47).
        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $visit), ['party_size' => 1])
            ->assertSessionHasErrors('visitor');

        $this->assertSame('expected', $this->reload($visit)->statusKey());

        $escort = $this->employeeId($this->engineer);

        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $visit), ['party_size' => 1, 'escort_employee_id' => $escort])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('on_site', $this->reload($visit)->statusKey());

        // And a company that does not work that way turns it off.
        $this->withinOrganization($this->org, function () {
            app(SettingsRepository::class)->set('visitor.contractor_requires_escort', false, $this->org->id);
        });

        $second = $this->book(['full_name' => 'Second Fitter', 'purpose_type' => 'contractor']);

        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $second), ['party_size' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('on_site', $this->reload($second)->statusKey());
    }

    #[Test]
    public function the_induction_expiry_is_stamped_at_the_gate_and_does_not_move_afterwards(): void
    {
        $visit = $this->book(['full_name' => 'Inducted Today']);

        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $visit), ['party_size' => 1, 'induction_completed' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // 365 days by default, which is a common site rule rather than a correct one.
        $this->assertSame('2027-09-24', $this->reload($visit)->induction_valid_until->toDateString());

        // Tightening the rule next year must not retroactively make somebody uninducted on a day
        // they were let in — the expiry is copied onto the visit, never derived from the setting.
        $this->withinOrganization($this->org, function () {
            app(SettingsRepository::class)->set('visitor.induction_validity_days', 30, $this->org->id);
        });

        $this->assertSame('2027-09-24', $this->reload($visit)->induction_valid_until->toDateString());
    }

    // ================================================================== who may see what

    #[Test]
    public function neither_company_can_reach_the_others_visitors(): void
    {
        $ours = $this->book(['full_name' => 'Our Visitor']);

        // Ada holds every visitor permission her own company grants, and the id is right there in
        // the URL (§75, §94).
        $this->actingFresh($this->stranger)->get(route('visitors.show', $ours))->assertNotFound();
        $this->actingFresh($this->stranger)->post(route('visitors.check-in', $ours), [])->assertNotFound();
        $this->actingFresh($this->stranger)->post(route('visitors.check-out', $ours))->assertNotFound();

        $onSite = $this->actingFresh($this->stranger)->get(route('visitors.roll-call'))->assertOk();
        $this->assertSame(0, $onSite->viewData('headcount'));
    }

    #[Test]
    public function an_engineer_sees_the_visitors_who_came_to_see_him_and_nobody_elses(): void
    {
        $hisId = $this->employeeId($this->engineer);

        $his = $this->book(['full_name' => 'Here For Kelechi', 'host_employee_id' => $hisId]);
        $hers = $this->book(['full_name' => 'Here For Somebody Else']);

        $this->actingFresh($this->engineer)->get(route('visitors.show', $his))->assertOk();
        // `own` on this register means "I am the host", and it never inherits through a parent.
        $this->actingFresh($this->engineer)->get(route('visitors.show', $hers))->assertForbidden();

        $index = $this->actingFresh($this->engineer)->get(route('visitors.index'))->assertOk();
        $names = $index->viewData('visitors')->pluck('full_name')->all();
        $this->assertContains('Here For Kelechi', $names);
        $this->assertNotContains('Here For Somebody Else', $names);
    }

    // ================================================================== the identity document

    #[Test]
    public function the_identity_document_is_encrypted_and_never_handed_back(): void
    {
        $visit = $this->book(['full_name' => 'Carrying A Passport']);

        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $visit), [
                'party_size' => 1,
                'id_document_type' => 'Passport',
                'id_document_number' => 'A01234567',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = DB::table('visitors')->where('id', $visit->id)->value('id_document_number');

        $this->assertNotNull($stored);
        $this->assertNotSame('A01234567', $stored, 'The document number must not be readable in the column.');
        $this->assertSame('A01234567', $this->reload($visit)->id_document_number);

        // Hidden from array and JSON output, so it cannot leak through anything that serialises a
        // record (§67).
        $this->assertArrayNotHasKey('id_document_number', $this->reload($visit)->toArray());

        // And the record page never prints it, however it was reached.
        $this->actingFresh($this->reception)
            ->get(route('visitors.show', $visit))
            ->assertOk()
            ->assertDontSee('A01234567');

        // Searching by it finds nothing: a visitor book is not a place to look people up by
        // document number.
        $found = $this->actingFresh($this->reception)
            ->get(route('visitors.index', ['q' => 'A01234567', 'view' => 'all']))
            ->assertOk();

        $this->assertSame(0, $found->viewData('visitors')->total());
    }

    // ================================================================== helpers

    /**
     * A booking that would be accepted.
     *
     * The host is always filled in, because `visitor.require_host` is on by default and a payload
     * without one would be rejected before any of the rules under test ran.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Chidi Eze',
            'company_name' => 'Northwind Surveying',
            'party_size' => 1,
            'host_employee_id' => $this->employeeId($this->reception),
            'purpose_type' => 'meeting',
            'purpose' => 'Handing over the survey drawings',
            'org_unit_id' => $this->engineering->id,
            'requires_escort' => '0',
        ], $overrides);
    }

    private function employeeId(User $user): int
    {
        return $this->withinOrganization($this->org, fn () => $user->employee->id);
    }

    /** @param array<string,mixed> $overrides */
    private function book(array $overrides = []): Visitor
    {
        $this->actingFresh($this->reception)
            ->post(route('visitors.store'), $this->bookingPayload($overrides))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => Visitor::query()->latest('id')->firstOrFail());
    }

    /** @param array<string,mixed> $attributes */
    private function checkIn(Visitor $visitor, array $attributes = []): void
    {
        $this->actingFresh($this->reception)
            ->post(route('visitors.check-in', $visitor), $attributes + ['party_size' => $visitor->party_size ?? 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    private function reload(Visitor $visitor): Visitor
    {
        return $this->withinOrganization($this->org, fn () => $visitor->fresh(['status']));
    }
}
