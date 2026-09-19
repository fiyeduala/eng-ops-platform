<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Organization;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\User;
use App\Support\PermissionCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The status and transition editor (Phase 7, Part 1).
 *
 * THIS SCREEN IS THE MOST DANGEROUS ONE IN THE PLATFORM and these tests exist in proportion to
 * that. `status_transitions` is the whitelist StatusMachine reads before it moves anything; the
 * status keys are what service code names out loud when it moves a payroll run to `approved`. A
 * settings page that writes to both can, with a single careless edit, either break the engine
 * silently or hand an ordinary user the outcome an approval was there to confer.
 *
 * So the tests are grouped by the thing that would go wrong rather than by method:
 *
 *   - the register in the URL is not evidence (§84)
 *   - another company's configuration does not exist (§75)
 *   - a status the code depends on keeps its key, its pulse and its existence
 *   - a status a company invented is entirely its own
 *   - a move into a status an approval confers must name a permission — the Phase 6 exit criterion,
 *     defended against the Phase 7 editor
 *   - reading is not writing (§6)
 */
final class StatusConfigTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $admin;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $this->admin = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [
            $this->makeRole($this->org, 'Configurator', [
                'status.view' => 'organization',
                'status.manage' => 'organization',
            ]),
        ]);

        // Holds the baseline read, nothing more. Every write below must refuse this person, and
        // the page must still render for them — the vocabulary appears on every list in the
        // application and a 403 on reading it would be wrong.
        $this->reader = $this->makePerson($this->org, 'Bem Tarkighir', null, [
            $this->makeRole($this->org, 'Onlooker', ['status.view' => 'organization']),
        ]);
    }

    // ================================================================== reading

    #[Test]
    public function the_configuration_hub_offers_only_what_the_viewer_may_open(): void
    {
        $this->actingFresh($this->admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('Statuses &amp; transitions', false)
            // Holds no `setting.*`, no `organization.*`, no `role.*` — so those tiles are absent.
            // Hiding them is a courtesy (§96); the refusal that matters is on the route itself.
            ->assertDontSee('Roles &amp; permissions', false);
    }

    #[Test]
    public function the_editor_renders_a_registers_vocabulary_and_its_moves(): void
    {
        $this->actingFresh($this->admin)->get(route('configuration.statuses.show', 'task'))
            ->assertOk()
            ->assertSee('In progress')
            ->assertSee('Send for review')
            ->assertSee('task.verify');
    }

    #[Test]
    public function a_register_the_platform_does_not_have_is_not_a_register(): void
    {
        // §84. The segment reaches Str::plural() in StatusPolicy, so an arbitrary string here
        // would be configuration naming database tables.
        $this->actingFresh($this->admin)
            ->get(route('configuration.statuses.show', 'payroll_runs_or_so_i_claim'))
            ->assertNotFound();
    }

    // ================================================================== isolation

    #[Test]
    public function a_status_from_another_register_cannot_be_edited_through_this_one(): void
    {
        $meeting = $this->statusFor('meeting', 'scheduled');

        // Same company, real id, wrong register. Without the check the audit entry would name
        // `task` while a meeting status was being rewritten.
        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $meeting]), ['label' => 'Rewritten'])
            ->assertNotFound();

        $this->assertSame('Scheduled', $this->statusFor('meeting', 'scheduled')->label);
    }

    #[Test]
    public function another_companys_configuration_does_not_exist(): void
    {
        // §75. A second tenant, fully provisioned, with its own copy of the same vocabulary.
        $other = $this->makeOrganization('Brightwater Civil');
        $this->provisionConfiguration($other);

        $theirs = $this->withinOrganization($other, fn () => Status::query()
            ->forEntity('task')->where('key', 'in_progress')->firstOrFail());

        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $theirs]), ['label' => 'Taken over'])
            ->assertNotFound();

        $this->assertSame('In progress', $this->withinOrganization($other,
            fn () => Status::query()->whereKey($theirs->getKey())->firstOrFail())->label);
    }

    // ================================================================== built-in statuses

    #[Test]
    public function a_built_in_status_keeps_its_key_its_pulse_and_its_existence(): void
    {
        $done = $this->statusFor('task', 'done');
        $this->assertTrue($done->isSystem(), 'the migration did not mark a catalogue status as built in');

        // Rekeying it. Service code says moveTo($record, 'done') as a literal, and moveTo() looks
        // the key up among ACTIVE statuses and silently does nothing when it cannot find one.
        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $done]),
                ['label' => 'Done', 'key' => 'finished', 'is_active' => '1'])
            ->assertSessionHasErrors('configuration');

        // Switching it off, which fails in exactly the same silent way.
        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $done]), ['label' => 'Done'])
            ->assertSessionHasErrors('configuration');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.statuses.destroy', ['task', $done]))
            ->assertSessionHasErrors('configuration');

        $fresh = $this->statusFor('task', 'done');
        $this->assertSame('done', $fresh->key);
        $this->assertTrue($fresh->is_active, 'a built-in status was switched off');
    }

    #[Test]
    public function a_built_in_status_can_still_be_renamed_and_recoloured(): void
    {
        // This is what §73 actually promises. A company that calls it "Signed off" gets to say so,
        // and nothing in the application notices, because nothing in the application reads labels.
        $done = $this->statusFor('task', 'done');

        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $done]), [
                'label' => 'Signed off',
                'key' => 'done',
                'color' => 'brand',
                'is_active' => '1',
                'sort' => '90',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->statusFor('task', 'done');
        $this->assertSame('Signed off', $fresh->label);
        $this->assertSame('brand', $fresh->color);
        $this->assertSame('done', $fresh->key, 'the key moved while the label was being changed');
    }

    // ================================================================== a company's own statuses

    #[Test]
    public function a_company_can_add_retire_and_delete_a_status_of_its_own(): void
    {
        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.store', 'task'), [
                'label' => 'Awaiting client',
                'color' => 'warning',
            ])
            ->assertSessionHasNoErrors();

        $added = $this->statusFor('task', 'awaiting_client');
        $this->assertFalse($added->isSystem(), 'a status a company invented was marked as built in');
        $this->assertSame('warning', $added->color);
        $this->assertTrue($added->is_active);

        // Retired: still on every historical record, no longer offered.
        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.update', ['task', $added]),
                ['label' => 'Awaiting client', 'key' => 'awaiting_client'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->statusFor('task', 'awaiting_client')->is_active);

        $this->actingFresh($this->admin)
            ->delete(route('configuration.statuses.destroy', ['task', $added]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->withinOrganization($this->org,
            fn () => Status::query()->forEntity('task')->where('key', 'awaiting_client')->first()));
    }

    #[Test]
    public function the_organization_is_taken_from_the_actor_not_from_the_form(): void
    {
        // §74. The payload names somebody else's tenant, twice over, in the two spellings a
        // hand-built request would use.
        $other = $this->makeOrganization('Brightwater Civil');

        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.store', 'task'), [
                'label' => 'Planted',
                'organization_id' => $other->id,
                'organisation_id' => $other->id,
            ])
            ->assertSessionHasNoErrors();

        $planted = $this->statusFor('task', 'planted');
        $this->assertSame($this->org->id, $planted->organization_id);

        $this->assertSame(0, $this->withinOrganization($other,
            fn () => Status::query()->forEntity('task')->where('key', 'planted')->count()));
    }

    #[Test]
    public function a_status_with_records_sitting_in_it_cannot_be_deleted(): void
    {
        $project = $this->makeProject($this->org, $this->admin, 'Marina Bridge Rehabilitation');
        $task = $this->makeTask($this->org, $this->admin, 'Re-survey the eastern abutment', [
            'project_id' => $project->id,
        ]);

        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.store', 'task'), ['label' => 'Awaiting client'])
            ->assertSessionHasNoErrors();

        $added = $this->statusFor('task', 'awaiting_client');

        $this->withinOrganization($this->org, fn () => DB::table('tasks')
            ->where('id', $task->getKey())->update(['status_id' => $added->getKey()]));

        $this->actingFresh($this->admin)
            ->delete(route('configuration.statuses.destroy', ['task', $added]))
            ->assertSessionHasErrors('configuration');

        // Still there, because the alternative is a task whose state is a dangling foreign key.
        $this->assertNotNull($this->withinOrganization($this->org,
            fn () => Status::query()->forEntity('task')->where('key', 'awaiting_client')->first()));
    }

    // ================================================================== the workflow guard

    #[Test]
    public function a_move_into_a_status_an_approval_confers_must_name_a_permission(): void
    {
        // A company builds an approval route on tasks whose final act is to mark the task done.
        // From this moment `done` is not merely a status: it is an OUTCOME, the thing the approval
        // exists to confer.
        $this->makeWorkflow($this->org, $this->admin, 'Task sign-off', 'task', [[
            'name' => 'Supervisor sign-off',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $this->admin->id,
            'auto_actions' => [['verb' => 'set_status', 'status' => 'done']],
        ]]);

        $blocked = $this->statusFor('task', 'blocked');
        $done = $this->statusFor('task', 'done');

        // THE HOLE THIS CLOSES: an open move into `done` would let anyone holding ordinary task
        // edit rights reach the generic status endpoint and take the decision the sign-off existed
        // to take — with the approval engine never involved and therefore never complaining.
        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.transitions.store', 'task'), [
                'from_status_id' => $blocked->getKey(),
                'to_status_id' => $done->getKey(),
                'label' => 'Just close it',
            ])
            ->assertSessionHasErrors('configuration');

        $this->assertSame(0, $this->transitionCount($blocked->getKey(), $done->getKey()));

        // Named, it is allowed. The editor is not refusing the MOVE, it is refusing the move being
        // open to everybody — a company may absolutely say "a QA lead can close one by hand".
        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.transitions.store', 'task'), [
                'from_status_id' => $blocked->getKey(),
                'to_status_id' => $done->getKey(),
                'label' => 'Close without review',
                'permission_key' => 'task.verify',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->transitionCount($blocked->getKey(), $done->getKey()));
    }

    #[Test]
    public function the_engines_own_row_survives_the_guard(): void
    {
        // The reason the guard demands a permission rather than forbidding the row outright:
        // AutoActionRunner moves the subject THROUGH StatusMachine, so `review → done` has to
        // exist in the whitelist or the approval engine cannot do the thing it just decided to do.
        $this->makeWorkflow($this->org, $this->admin, 'Task sign-off', 'task', [[
            'name' => 'Supervisor sign-off',
            'sequence' => 1,
            'assignee_type' => 'user',
            'assignee_ref' => (string) $this->admin->id,
            'auto_actions' => [['verb' => 'set_status', 'status' => 'done']],
        ]]);

        $review = $this->statusFor('task', 'review');
        $done = $this->statusFor('task', 'done');

        $this->assertSame(1, $this->transitionCount($review->getKey(), $done->getKey()),
            'the seeded route the engine uses has gone missing');

        // And it cannot be quietly opened up afterwards, which is the easier mistake to make.
        $row = $this->withinOrganization($this->org, fn () => StatusTransition::query()
            ->forEntity('task')
            ->where('from_status_id', $review->getKey())
            ->where('to_status_id', $done->getKey())
            ->firstOrFail());

        $this->actingFresh($this->admin)
            ->put(route('configuration.statuses.transitions.update', ['task', $row]), [
                'from_status_id' => $review->getKey(),
                'to_status_id' => $done->getKey(),
                'label' => 'Verify and close',
                'permission_key' => '',
            ])
            ->assertSessionHasErrors('configuration');

        $this->assertSame('task.verify', $this->withinOrganization($this->org,
            fn () => StatusTransition::query()->whereKey($row->getKey())->firstOrFail())->permission_key);
    }

    #[Test]
    public function a_permission_nobody_could_ever_hold_cannot_gate_a_move(): void
    {
        // A mistyped key does not make the move stricter, it makes it impossible — and the
        // administrator who typed it has every reason to believe they secured it.
        $todo = $this->statusFor('task', 'todo');
        $blocked = $this->statusFor('task', 'blocked');

        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.transitions.store', 'task'), [
                'from_status_id' => $todo->getKey(),
                'to_status_id' => $blocked->getKey(),
                'permission_key' => 'tsak.varify',
            ])
            ->assertSessionHasErrors('permission_key');

        $this->assertContains('task.verify', PermissionCatalogue::keys());
        $this->assertSame(0, $this->transitionCount($todo->getKey(), $blocked->getKey()));
    }

    // ================================================================== ordering

    #[Test]
    public function reordering_touches_nothing_outside_the_register_in_the_url(): void
    {
        $meeting = $this->statusFor('meeting', 'scheduled');
        $before = (int) $meeting->sort;

        $ids = $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('task')->orderBy('sort')->pluck('id')->all());

        // The foreign id is threaded into the middle of an otherwise honest list, which is how
        // this would actually arrive (§84).
        array_splice($ids, 2, 0, [$meeting->getKey()]);

        $this->actingFresh($this->admin)
            ->post(route('configuration.statuses.reorder', 'task'), ['order' => $ids])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, (int) $this->statusFor('meeting', 'scheduled')->sort,
            'a status from another register was reordered by an id posted into this one');
    }

    // ================================================================== reading is not writing

    #[Test]
    public function reading_the_vocabulary_is_not_permission_to_rewrite_it(): void
    {
        $done = $this->statusFor('task', 'done');

        // The page renders: `status.view` is baseline, and the words appear on every list.
        $this->actingFresh($this->reader)->get(route('configuration.statuses.show', 'task'))->assertOk();

        // Every write refuses, at the route, before any policy or service is consulted (§6).
        $this->actingFresh($this->reader)
            ->post(route('configuration.statuses.store', 'task'), ['label' => 'Invented'])
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->put(route('configuration.statuses.update', ['task', $done]), ['label' => 'Rewritten'])
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->delete(route('configuration.statuses.destroy', ['task', $done]))
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->post(route('configuration.statuses.reorder', 'task'), ['order' => [$done->getKey()]])
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->post(route('configuration.statuses.transitions.store', 'task'),
                ['to_status_id' => $done->getKey()])
            ->assertForbidden();

        $this->assertSame('Done', $this->statusFor('task', 'done')->label);
    }

    // ================================================================== helpers

    private function statusFor(string $entityType, string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity($entityType)->where('key', $key)->firstOrFail());
    }

    private function transitionCount(int $fromId, int $toId): int
    {
        return $this->withinOrganization($this->org, fn () => StatusTransition::query()
            ->where('from_status_id', $fromId)
            ->where('to_status_id', $toId)
            ->count());
    }
}
