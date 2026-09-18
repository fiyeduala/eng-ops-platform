<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Models\EmployeeExit;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PettyCashAccount;
use App\Models\User;
use App\Services\ChecklistService;
use App\Services\EmployeeExitService;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Joining and leaving: the checklist somebody is actually asked to work through, and the moment
 * their access stops.
 *
 * The four things this module has to get right, and one test each:
 *
 *   1. CLEARANCE IS ASKED OF THE REGISTERS. An exit cannot be cleared or completed while an asset
 *      is still in the leaver's custody or a petty cash float is still in their name — and the
 *      question is asked again at the moment of the decision, not read off a page somebody left
 *      open (§84).
 *   2. COMPLETING SWITCHES THE SIGN-IN OFF. The personnel record and `users.is_active` move
 *      together or not at all; the failure worth testing is the one where the company believes
 *      somebody has left while their password still works.
 *   3. A CHECKLIST IS A COPY. Editing the template afterwards does not rewrite what a named person
 *      was asked to do, and the list closes itself only when the last item is ticked.
 *   4. THE EXIT INTERVIEW IS NOT FOR THE DEPARTMENT. The leaver's own manager sees that they are
 *      going; what they said on the way out is stripped server-side without
 *      `employee_exit.view_confidential` (§68).
 */
final class LifecycleTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $operations;

    private User $hr;           // Bisi: HR, no unit of her own, reads exits and interviews

    private User $technician;   // Tunde: Operations, ticks lists, may not untick

    private User $manager;      // Chidi: Operations, opens exits, never reads an interview

    private User $stranger;     // Yemi: none of this

    private Employee $leaver;   // Sade: the one going, holding a laptop and a petty cash tin

    private Employee $quitter;  // Ifeoma: going later, holding nothing

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->org = $this->makeOrganization('Meridian Lifecycle');
        $this->provisionConfiguration($this->org);

        $this->operations = $this->makeUnit($this->org, 'Operations', OrgUnitType::Department);

        $hrRole = $this->makeRole($this->org, 'HR Manager', [
            'employee.view' => 'organization', 'employee.edit' => 'organization',
            'checklist.view' => 'organization', 'checklist.create' => 'organization',
            'checklist.edit' => 'organization', 'checklist.delete' => 'organization',
            'checklist.manage' => 'organization',
            'employee_exit.view' => 'organization', 'employee_exit.create' => 'organization',
            'employee_exit.edit' => 'organization', 'employee_exit.delete' => 'organization',
            'employee_exit.clear' => 'organization', 'employee_exit.complete' => 'organization',
            'employee_exit.manage' => 'organization', 'employee_exit.view_confidential' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        // The person who actually hands over the laptop. Ticks are their job; unticking — saying a
        // thing recorded as done was not — is deliberately not.
        $technicianRole = $this->makeRole($this->org, 'IT Technician', [
            'employee.view' => 'organization',
            'checklist.view' => 'department', 'checklist.edit' => 'department',
        ]);

        // A department head: opens an exit for their own person and watches the clearance. Holds no
        // `view_confidential`, which is the whole of the §68 fixture below.
        $managerRole = $this->makeRole($this->org, 'Operations Manager', [
            'employee.view' => 'organization',
            'employee_exit.view' => 'organization', 'employee_exit.create' => 'organization',
            'employee_exit.edit' => 'organization',
            'checklist.view' => 'department',
        ]);

        $this->hr = $this->makePerson($this->org, 'Bisi Lawal', null, [$hrRole]);
        $this->technician = $this->makePerson($this->org, 'Tunde Alabi', $this->operations, [$technicianRole]);
        $this->manager = $this->makePerson($this->org, 'Chidi Eze', $this->operations, [$managerRole]);
        $this->stranger = $this->makePerson($this->org, 'Yemi Sanusi', $this->operations, [
            $this->makeRole($this->org, 'Storekeeper', ['employee.view' => 'own']),
        ]);

        $this->leaver = $this->makePerson($this->org, 'Sade Okonkwo', $this->operations)->employee;
        $this->quitter = $this->makePerson($this->org, 'Ifeoma Bello', $this->operations)->employee;

        // The company's own lists. Nothing about what a starter is given or a leaver hands back is
        // in code (§47), so even the fixture goes through the settings screen.
        $this->makeTemplate('onboarding', 'New starter', [
            ['label' => 'Issue the laptop and site phone', 'requires_note' => 1],
            ['label' => 'Site induction and HSE briefing'],
            ['label' => 'Add to the payroll register'],
        ]);

        $this->makeTemplate('exit', 'Leaver clearance', [
            ['label' => 'Collect the laptop and site phone', 'requires_note' => 1],
            ['label' => 'Hand over open site files'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the checklist is a copy

    #[Test]
    public function editing_the_template_afterwards_does_not_rewrite_what_somebody_was_asked_to_do(): void
    {
        $template = $this->template('onboarding');

        $checklist = $this->startChecklist($this->leaver, 'onboarding', $template);

        $this->assertSame(3, $checklist->items->count());
        $this->assertSame('Issue the laptop and site phone', $checklist->items->first()->label);
        $this->assertNull($checklist->completed_at);

        // The company changes its mind in March about what a starter is given.
        $this->actingFresh($this->hr)
            ->put(route('checklist-templates.update', $template), [
                'kind' => 'onboarding',
                'name' => 'New starter',
                'is_active' => 1,
                'items' => [['label' => 'Issue a desktop only']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, $this->withinOrganization($this->org, fn () => $template->items()->count()));

        // What Sade was actually asked to do in February is untouched — which is the only version
        // an audit, or an argument about whether somebody was ever inducted, is about.
        $items = $this->reloadChecklist($checklist)->items;
        $this->assertSame(3, $items->count());
        $this->assertSame('Issue the laptop and site phone', $items->first()->label);
    }

    #[Test]
    public function a_list_closes_itself_only_when_the_last_item_is_ticked_and_reopens_when_one_is_undone(): void
    {
        $checklist = $this->startChecklist($this->leaver, 'onboarding');
        $items = $checklist->items->values();

        // The first item demands a note. A tick without one is not recorded.
        $this->actingFresh($this->technician)
            ->post(route('checklist-items.tick', $items[0]))
            ->assertSessionHasErrors('note');

        $this->assertNull($this->reloadItem($items[0])->done_at);

        $this->actingFresh($this->technician)
            ->post(route('checklist-items.tick', $items[0]), ['note' => 'Laptop MER-114 and phone issued.'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->reloadItem($items[0])->done_at);
        $this->assertSame($this->technician->id, $this->reloadItem($items[0])->done_by);

        // Two of three done is not done.
        $this->actingFresh($this->technician)->post(route('checklist-items.tick', $items[1]))->assertSessionHasNoErrors();
        $this->assertNull($this->reloadChecklist($checklist)->completed_at);

        $this->actingFresh($this->technician)->post(route('checklist-items.tick', $items[2]))->assertSessionHasNoErrors();

        $closed = $this->reloadChecklist($checklist);
        $this->assertNotNull($closed->completed_at, 'The last tick closes the list.');
        $this->assertSame($this->technician->id, $closed->completed_by);

        // Undoing a tick is a different act from doing one, and the technician cannot do it.
        $this->actingFresh($this->technician)
            ->post(route('checklist-items.untick', $items[1]), ['reason' => 'It was not actually done.'])
            ->assertForbidden();

        $this->assertNotNull($this->reloadItem($items[1])->done_at);

        // Nor can HR undo one silently.
        $this->actingFresh($this->hr)
            ->post(route('checklist-items.untick', $items[1]))
            ->assertSessionHasErrors('reason');

        $this->actingFresh($this->hr)
            ->post(route('checklist-items.untick', $items[1]), ['reason' => 'The induction was never held.'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->reloadItem($items[1])->done_at);
        $this->assertNull($this->reloadChecklist($checklist)->completed_at, 'One open item reopens the list.');

        // And the reason is on the record's own timeline, where it cannot be edited away (§59).
        $reopened = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', 'employee_checklist')
            ->where('subject_id', $checklist->getKey())
            ->where('verb', 'item_reopened')
            ->firstOrFail());

        $this->assertSame('The induction was never held.', $reopened->properties['reason'] ?? null);
    }

    // ================================================================== clearance and the sign-in

    #[Test]
    public function an_exit_is_not_cleared_while_the_leaver_still_holds_something(): void
    {
        $laptop = $this->issueAsset('AST-0114', 'Dell Latitude site laptop', $this->leaver);
        $tin = $this->issueFloat('PC-OPS', $this->leaver, '45000.00');

        $exit = $this->openExit($this->leaver, ['last_working_day' => '2026-03-02']);
        $this->assertSame('notified', $exit->statusKey());

        // Opening an exit starts the leaving list from the company's own exit template.
        $this->assertNotNull($exit->employee_checklist_id);
        $this->assertSame(2, $this->withinOrganization($this->org, fn () => $exit->checklist->items()->count()));

        $this->actingFresh($this->hr)->post(route('employee-exits.clear', $exit))->assertSessionHasErrors('clearance');

        $message = (string) session('errors')->get('clearance')[0];
        $this->assertStringContainsString('AST-0114', $message);
        $this->assertStringContainsString('PC-OPS', $message);

        $this->assertNull($this->reloadExit($exit)->cleared_at);
        $this->assertSame('notified', $this->reloadExit($exit)->statusKey());

        // Completing is refused for the same reason, and does not touch the employment.
        $this->actingFresh($this->hr)->post(route('employee-exits.complete', $exit))->assertSessionHasErrors('clearance');
        $this->assertSame('active', $this->reloadEmployee($this->leaver)->employment_status);

        // The laptop comes back and the float is handed over.
        $this->release($laptop, $tin);

        $this->actingFresh($this->hr)->post(route('employee-exits.clear', $exit))->assertSessionHasNoErrors();

        $cleared = $this->reloadExit($exit);
        $this->assertSame('clearing', $cleared->statusKey());
        $this->assertNotNull($cleared->cleared_at);
        $this->assertSame($this->hr->id, $cleared->cleared_by);

        // §84: the clearance recorded ten minutes ago is not evidence about now. The laptop is
        // signed back out to them, and the completion is refused again.
        $this->withinOrganization($this->org, fn () => $laptop->forceFill(['custodian_id' => $this->leaver->user_id])->save());

        $this->actingFresh($this->hr)->post(route('employee-exits.complete', $exit))->assertSessionHasErrors('clearance');
        $this->assertSame('active', $this->reloadEmployee($this->leaver)->employment_status);
    }

    #[Test]
    public function completing_an_exit_ends_the_employment_and_switches_the_sign_in_off(): void
    {
        $exit = $this->openExit($this->leaver, ['last_working_day' => '2026-03-02']);

        $this->actingFresh($this->hr)->post(route('employee-exits.clear', $exit))->assertSessionHasNoErrors();
        $this->actingFresh($this->hr)->post(route('employee-exits.complete', $exit))->assertSessionHasNoErrors();

        $employee = $this->reloadEmployee($this->leaver);
        $this->assertSame('exited', $employee->employment_status);
        $this->assertSame('2026-03-02', $employee->exited_on->toDateString());
        $this->assertFalse((bool) $employee->is_active);

        // The one that matters: the account is off, not deleted — everything they ever approved
        // still points at it.
        $account = User::query()->withoutGlobalScopes()->findOrFail($this->leaver->user_id);
        $this->assertFalse((bool) $account->is_active);

        $completed = $this->reloadExit($exit);
        $this->assertSame('completed', $completed->statusKey());
        $this->assertNotNull($completed->access_revoked_at);

        // A completed exit is a record. Nobody edits or deletes it, whatever they hold.
        $this->actingFresh($this->hr)
            ->put(route('employee-exits.update', $completed), [
                'employee_id' => $this->leaver->id,
                'reason' => 'redundancy',
                'last_working_day' => '2026-03-02',
            ])
            ->assertForbidden();

        $this->actingFresh($this->hr)->delete(route('employee-exits.destroy', $completed))->assertForbidden();

        $this->assertSame('resignation', $this->reloadExit($completed)->reason);
    }

    #[Test]
    public function an_exit_is_not_completed_before_the_last_working_day(): void
    {
        $exit = $this->openExit($this->quitter, ['last_working_day' => '2026-04-30']);

        $this->actingFresh($this->hr)->post(route('employee-exits.clear', $exit))->assertSessionHasNoErrors();
        $this->actingFresh($this->hr)->post(route('employee-exits.complete', $exit))->assertSessionHasErrors('clearance');

        $this->assertStringContainsString('30 Apr 2026', (string) session('errors')->get('clearance')[0]);
        $this->assertSame('active', $this->reloadEmployee($this->quitter)->employment_status);
        $this->assertTrue((bool) User::query()->withoutGlobalScopes()->findOrFail($this->quitter->user_id)->is_active);
    }

    #[Test]
    public function nobody_opens_a_second_exit_for_the_same_person(): void
    {
        $this->openExit($this->leaver, ['last_working_day' => '2026-03-02']);

        $this->actingFresh($this->hr)
            ->post(route('employee-exits.store'), [
                'employee_id' => $this->leaver->id,
                'reason' => 'dismissal',
                'last_working_day' => '2026-03-31',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertSame(1, $this->withinOrganization($this->org, fn () => EmployeeExit::query()
            ->forEmployee($this->leaver->id)->count()));
    }

    // ================================================================== §68: the exit interview

    #[Test]
    public function the_exit_interview_is_not_written_or_read_by_the_department_they_are_leaving(): void
    {
        $said = 'She said the site manager overrides the method statements when the client is watching.';

        // The department head opens the exit and types the interview into the form anyway. The
        // field is hidden on their screen; hiding it is decoration, and this is the assertion that
        // the server drops it (§6, §84).
        $this->actingFresh($this->manager)
            ->post(route('employee-exits.store'), [
                'employee_id' => $this->leaver->id,
                'reason' => 'resignation',
                'last_working_day' => '2026-03-02',
                'interview_notes' => $said,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $exit = $this->latestExit();
        $this->assertNull($exit->interview_notes);

        // HR, who may read one, records it.
        $this->actingFresh($this->hr)
            ->put(route('employee-exits.update', $exit), [
                'employee_id' => $this->leaver->id,
                'reason' => 'resignation',
                'last_working_day' => '2026-03-02',
                'interview_notes' => $said,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($said, $this->reloadExit($exit)->interview_notes);

        // The head still sees that their own person is leaving — and not a word of what she said.
        $this->actingFresh($this->manager)->get(route('employee-exits.show', $exit))
            ->assertOk()
            ->assertSee('Sade')
            ->assertDontSee('method statements');

        $this->actingFresh($this->hr)->get(route('employee-exits.show', $exit))
            ->assertOk()
            ->assertSee('method statements', false);

        // An edit posted by the head does not quietly wipe what HR recorded, either.
        $this->actingFresh($this->manager)
            ->put(route('employee-exits.update', $exit), [
                'employee_id' => $this->leaver->id,
                'reason' => 'end_of_contract',
                'last_working_day' => '2026-03-02',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($said, $this->reloadExit($exit)->interview_notes);
        $this->assertSame('end_of_contract', $this->reloadExit($exit)->reason);

        // And somebody with no business here sees nothing at all.
        $this->actingFresh($this->stranger)->get(route('employee-exits.show', $exit))->assertForbidden();
        $this->actingFresh($this->stranger)->get(route('employee-exits.index'))->assertForbidden();
    }

    // ================================================================== §75

    #[Test]
    public function another_tenant_gets_a_404(): void
    {
        [$otherExit, $otherChecklist, $otherItem, $otherTemplate] = $this->otherTenant();

        $this->actingFresh($this->hr);

        $this->get(route('employee-exits.show', $otherExit))->assertNotFound();
        $this->get(route('employee-exits.edit', $otherExit))->assertNotFound();
        $this->post(route('employee-exits.clear', $otherExit))->assertNotFound();
        $this->post(route('employee-exits.complete', $otherExit))->assertNotFound();
        $this->get(route('employee-checklists.show', $otherChecklist))->assertNotFound();
        $this->post(route('checklist-items.tick', $otherItem), ['note' => 'x'])->assertNotFound();
        $this->get(route('checklist-templates.edit', $otherTemplate))->assertNotFound();

        // Nor do the lists leak the existence of any of it.
        $this->get(route('employee-exits.index'))->assertOk()->assertDontSee('Harbour');
        $this->get(route('employee-checklists.index'))->assertOk()->assertDontSee('Dayo');
        $this->get(route('checklist-templates.index'))->assertOk()->assertDontSee('Harbour offboarding');
    }

    // ================================================================== the screens

    #[Test]
    public function every_lifecycle_screen_renders(): void
    {
        $checklist = $this->startChecklist($this->leaver, 'onboarding');
        $exit = $this->openExit($this->quitter, ['last_working_day' => '2026-04-30']);
        $template = $this->template('exit');

        $this->actingFresh($this->hr);

        $this->get(route('employee-checklists.index'))->assertOk()->assertSee('Sade');
        $this->get(route('employee-checklists.show', $checklist))->assertOk()->assertSee('Site induction');
        $this->get(route('employee-exits.index'))->assertOk()->assertSee($exit->number);
        $this->get(route('employee-exits.create'))->assertOk();
        $this->get(route('employee-exits.show', $exit))->assertOk()->assertSee('Ifeoma');
        $this->get(route('employee-exits.edit', $exit))->assertOk();
        $this->get(route('checklist-templates.index'))->assertOk()->assertSee('Leaver clearance');
        $this->get(route('checklist-templates.create'))->assertOk();
        $this->get(route('checklist-templates.edit', $template))->assertOk()->assertSee('Hand over open site files');
    }

    // ================================================================== helpers

    /** @param  array<int,array<string,mixed>>  $items */
    private function makeTemplate(string $kind, string $name, array $items): ChecklistTemplate
    {
        $this->actingFresh($this->hr)
            ->post(route('checklist-templates.store'), [
                'kind' => $kind,
                'name' => $name,
                'is_default' => 1,
                'is_active' => 1,
                'items' => $items,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->template($kind);
    }

    private function template(string $kind): ChecklistTemplate
    {
        return $this->withinOrganization($this->org, fn () => ChecklistTemplate::query()
            ->ofKind($kind)->latest('id')->firstOrFail());
    }

    private function startChecklist(Employee $employee, string $kind, ?ChecklistTemplate $template = null): EmployeeChecklist
    {
        $this->actingFresh($this->hr)
            ->post(route('employees.checklists.store', $employee), [
                'kind' => $kind,
                'checklist_template_id' => ($template ?? $this->template($kind))->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => EmployeeChecklist::query()
            ->latest('id')->firstOrFail()->load('items'));
    }

    /** @param  array<string,mixed>  $attributes */
    private function openExit(Employee $employee, array $attributes = []): EmployeeExit
    {
        $this->actingFresh($this->hr)
            ->post(route('employee-exits.store'), [
                'employee_id' => $employee->id,
                'reason' => 'resignation',
                'notified_on' => '2026-02-20',
                'last_working_day' => '2026-03-31',
                ...$attributes,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->latestExit();
    }

    private function latestExit(): EmployeeExit
    {
        return $this->withinOrganization($this->org, fn () => EmployeeExit::query()
            ->latest('id')->firstOrFail()->load(['status', 'checklist.items']));
    }

    private function issueAsset(string $code, string $name, Employee $holder): Asset
    {
        return $this->withinOrganization($this->org, fn () => Asset::create([
            'organization_id' => $this->org->id,
            'code' => $code,
            'name' => $name,
            'custodian_id' => $holder->user_id,
            'org_unit_id' => $holder->org_unit_id,
        ]));
    }

    private function issueFloat(string $code, Employee $holder, string $balance): PettyCashAccount
    {
        return $this->withinOrganization($this->org, fn () => PettyCashAccount::create([
            'organization_id' => $this->org->id,
            'code' => $code,
            'name' => 'Operations float',
            'currency' => 'NGN',
            'custodian_id' => $holder->user_id,
            'balance' => $balance,
            'is_active' => true,
        ]));
    }

    private function release(Asset $asset, PettyCashAccount $tin): void
    {
        $this->withinOrganization($this->org, function () use ($asset, $tin) {
            $asset->forceFill(['custodian_id' => null])->save();
            $tin->forceFill(['custodian_id' => null])->save();
        });
    }

    /**
     * A second company with its own leaver, list and template. Built through the services rather
     * than the routes because nothing here is asserted about Harbour's own screens — only that
     * Meridian cannot reach any of it.
     *
     * @return array{0:EmployeeExit,1:EmployeeChecklist,2:EmployeeChecklistItem,3:ChecklistTemplate}
     */
    private function otherTenant(): array
    {
        $other = $this->makeOrganization('Harbour Marine');
        $this->provisionConfiguration($other);

        $unit = $this->makeUnit($other, 'Harbour Operations', OrgUnitType::Department);
        $actor = $this->makePerson($other, 'Dayo Martins', $unit);

        return $this->withinOrganization($other, function () use ($other, $actor, $unit) {
            $template = ChecklistTemplate::create([
                'organization_id' => $other->id,
                'kind' => 'exit',
                'name' => 'Harbour offboarding',
                'org_unit_id' => $unit->id,
                'is_default' => true,
                'is_active' => true,
            ]);

            $template->items()->create([
                'organization_id' => $other->id,
                'label' => 'Return the harbour pass',
                'sequence' => 1,
            ]);

            $checklist = app(ChecklistService::class)->start($actor->employee, 'exit', $template, [], $actor);

            $exit = app(EmployeeExitService::class)->open($actor->employee, [
                'reason' => 'resignation',
                'last_working_day' => '2026-03-31',
            ], $actor);

            return [$exit, $checklist, $checklist->items()->firstOrFail(), $template];
        });
    }

    private function reloadExit(EmployeeExit $exit): EmployeeExit
    {
        return $this->withinOrganization($this->org, fn () => $exit->fresh(['status', 'employee']));
    }

    private function reloadChecklist(EmployeeChecklist $checklist): EmployeeChecklist
    {
        return $this->withinOrganization($this->org, fn () => $checklist->fresh(['items']));
    }

    private function reloadItem(EmployeeChecklistItem $item): EmployeeChecklistItem
    {
        return $this->withinOrganization($this->org, fn () => $item->fresh());
    }

    private function reloadEmployee(Employee $employee): Employee
    {
        return $this->withinOrganization($this->org, fn () => $employee->fresh());
    }
}
