<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Engines\Workflow\EscalationService;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §25: the ladder that makes `sla_hours` mean something, and each rung must reach a named person.
 *
 * WHAT MAKES THIS WORTH A FILE OF ITS OWN is that every failure mode of an escalation is silence.
 * A stage that is late and escalates to nobody looks identical, from every angle available after
 * the fact, to a stage that is not late: no exception, no log line, no bounced mail. The row even
 * says it worked — `last_escalated_level` is stamped whether or not anybody was reachable, which is
 * deliberate and right (otherwise level 3 never arrives) and which also means the database cannot
 * tell you afterwards whether the notification went anywhere.
 *
 * So the assertions below are about WHO, by name, at each of the three rungs. Non-emptiness is not
 * enough: level 2 resolving to the assignee again, or level 3 resolving to everybody, would both
 * satisfy "somebody was told" while disabling the ladder.
 *
 * LEVEL 3 IS THE ONE THIS FILE WAS REALLY WRITTEN FOR. It resolves through `holdersOf()`, which
 * asks `whereHas('permissions', fn ($q) => $q->where('permissions.key', ...))`. That column is
 * `key`. It is NOT `name`. A closure inside whereHas resolves an unqualified column against the
 * inner table first and then, finding nothing, against the OUTER one — so `where('name', ...)`
 * there is valid SQL that compares a role's TITLE with a permission string, matches nobody, and
 * never errors. Every stage in the system would then sit at level 2 forever. Kemi, below, is
 * discovered only through that query, and she holds a role whose name resembles no permission.
 *
 * The cast:
 *
 *   Tunde   Lead Engineer, stage 1's assignee          level 1
 *   Yusuf   Director, and Tunde's supervisor           level 2
 *   Ngozi   Document Controller, wrote the document    level 2, as the owner being held up
 *   Kemi    "Operations Desk" — holds approval.reassign  level 3, by permission and nothing else
 *   Dayo    same permission, no document reach         nobody, ever (§68)
 */
final class EscalationLadderTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private Role $leadEngineer;

    private User $adaeze;

    private User $ngozi;

    private User $tunde;

    private User $yusuf;

    private User $kemi;

    private User $dayo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);

        $this->leadEngineer = $this->makeRole($this->org, 'Lead Engineer', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
            'approval.act' => 'organization',
        ]);

        $director = $this->makeRole($this->org, 'Director', [
            'document.view' => 'organization',
            'approval.view' => 'organization',
        ]);

        $administrator = $this->makeRole($this->org, 'Process Administrator', [
            'workflow.view' => 'organization',
            'workflow.create' => 'organization',
            'workflow.edit' => 'organization',
            'workflow.manage' => 'organization',
            'workflow.publish' => 'organization',
            'document_category.view' => 'organization',
            'document_category.manage' => 'organization',
            'role.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        // The name is deliberately nothing like a permission key. `holdersOf()` must find this role
        // by what it GRANTS; a query that accidentally compared `roles.name` with
        // "approval.reassign" would match it never, and a fixture role called "Approval Reassigner"
        // would have hidden that by making the wrong query look plausible.
        $operations = $this->makeRole($this->org, 'Operations Desk', [
            'approval.reassign' => 'organization',
            'approval.view' => 'organization',
            'document.view' => 'organization',
        ]);

        // Same authority over approvals, no reach over documents at all. §68 decides this one, not
        // the ladder: he can unstick the stage but may not be told what is stuck.
        $blindOperations = $this->makeRole($this->org, 'Approvals Clerk', [
            'approval.reassign' => 'organization',
            'approval.view' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Obi', $this->engineering, [$administrator]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danladi', $this->engineering, [$director]);

        // Tunde reports to Yusuf. Level 2 walks user -> employee -> supervisor -> employee -> user,
        // and a break anywhere along it escalates to the owner alone and looks like a design choice.
        $this->tunde = $this->makePerson(
            $this->org,
            'Tunde Bello',
            $this->civil,
            [$this->leadEngineer],
            supervisor: $this->employeeOf($this->yusuf),
        );

        $this->kemi = $this->makePerson($this->org, 'Kemi Alabi', $this->engineering, [$operations]);
        $this->dayo = $this->makePerson($this->org, 'Dayo Martins', $this->engineering, [$blindOperations]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => true,
            'draft_revision_prefix' => 'P',
        ]);
    }

    // ================================================================== the three rungs

    #[Test]
    public function level_one_reaches_the_people_the_stage_is_waiting_on(): void
    {
        $stage = $this->overdueStage();

        $this->assertTrue($this->escalate($stage));

        $this->assertNotified($this->tunde, 'level 1 must reach the assignee; nobody else can act.');
        $this->assertNotNotified($this->yusuf);
        $this->assertNotNotified($this->ngozi);
        $this->assertNotNotified($this->kemi);

        $this->assertSame(1, (int) $stage->refresh()->last_escalated_level);
    }

    /**
     * Level 2: the assignee's supervisor, and the person actually being held up.
     *
     * Two different queries, both silent when wrong. The supervisor comes off the reporting line;
     * the owner comes off `workflowOwnerId()`, which falls through owner_id, created_by and user_id
     * and returns null for a record with none of them.
     */
    #[Test]
    public function level_two_reaches_the_supervisor_and_the_owner_of_the_record(): void
    {
        $stage = $this->overdueStage();

        $this->escalate($stage);
        $this->clearNotifications();

        $this->assertTrue($this->escalate($this->pushDue($stage, hours: 48)));

        $this->assertNotified($this->yusuf, "level 2 must reach the assignee's supervisor.");
        $this->assertNotified($this->ngozi, 'level 2 must reach the person whose record is stuck.');
        $this->assertNotNotified($this->tunde, 'level 2 is a wider circle, not the same one again.');
        $this->assertNotNotified($this->kemi);

        $this->assertSame(2, (int) $stage->refresh()->last_escalated_level);
    }

    /**
     * Level 3: whoever can actually unstick it, found by PERMISSION.
     *
     * The assertion that matters is `assertNotified($this->kemi)`. She is reachable through exactly
     * one query in the application — the `permissions.key` subquery in `holdersOf()` — and nothing
     * else in the suite calls it. If that column name is ever wrong, this is the only assertion
     * anywhere that turns red instead of a system that quietly stops chasing overdue approvals.
     */
    #[Test]
    public function level_three_reaches_whoever_holds_the_permission_to_reassign(): void
    {
        $stage = $this->overdueStage();

        $this->escalate($stage);
        $this->escalate($this->pushDue($stage, hours: 48));
        $this->clearNotifications();

        $this->assertTrue($this->escalate($this->pushDue($stage, hours: 96)));

        $this->assertNotified($this->kemi,
            'level 3 reached nobody holding approval.reassign. `holdersOf()` matches on '
            .'`permissions.key`, and an unqualified `name` there compares a role title with a '
            .'permission string, matches nothing, and never errors.');

        $this->assertSame(3, (int) $stage->refresh()->last_escalated_level);
    }

    /**
     * §68 survives the ladder, and gets stricter as it widens.
     *
     * Dayo holds `approval.reassign` and would be told at level 3 by the audience. He cannot read
     * the document, so the dispatcher removes him. The escalation being narrower than intended is
     * the correct outcome: the alternative is leaking a confidential title to whoever happens to
     * hold an operational permission.
     */
    #[Test]
    public function a_wider_circle_is_still_not_told_about_a_record_it_cannot_read(): void
    {
        $stage = $this->overdueStage();

        $this->escalate($stage);
        $this->escalate($this->pushDue($stage, hours: 48));
        $this->escalate($this->pushDue($stage, hours: 96));

        $this->assertNotNotified($this->dayo,
            'somebody who cannot open the document was told that it is late (§68).');
    }

    // ================================================================== the ladder is a ladder

    #[Test]
    public function a_stage_that_is_not_yet_due_is_not_escalated(): void
    {
        $stage = $this->currentStage($this->submitDocument());

        $this->assertNotNull($stage->due_at, 'the stage has no due time, so nothing here is testing anything.');
        $this->assertTrue($stage->due_at->isFuture());

        $this->assertFalse($this->escalate($stage));

        // Zero rather than null: the column opens at the bottom of the ladder, so "never escalated"
        // and "escalated to level 0" are the same state and there is no third one to confuse it with.
        $this->assertSame(0, (int) $stage->refresh()->last_escalated_level);
        $this->assertSame(0, $this->notificationCount());
    }

    /**
     * Four days late still moves one rung.
     *
     * Firing 1, 2 and 3 in the same minute would put three notices about one document on one desk
     * and tell nobody anything the first did not. The point of a ladder is that it is climbed.
     */
    #[Test]
    public function a_stage_that_is_very_late_still_climbs_only_one_rung_per_run(): void
    {
        $stage = $this->pushDue($this->overdueStage(), hours: 240);

        $this->assertTrue($this->escalate($stage));
        $this->assertSame(1, (int) $stage->refresh()->last_escalated_level);

        $this->assertTrue($this->escalate($stage->refresh()));
        $this->assertSame(2, (int) $stage->refresh()->last_escalated_level);
    }

    /**
     * Idempotence, which is the whole design.
     *
     * A cron that fires twice, a retried job, or an administrator running the command by hand must
     * not produce two reminders. Getting this wrong corrupts nothing — it trains people to ignore
     * the notification, which disables the feature far more thoroughly than a crash would.
     */
    #[Test]
    public function running_the_same_level_twice_does_not_notify_twice(): void
    {
        $stage = $this->overdueStage();

        $this->assertTrue($this->escalate($stage));
        $sent = $this->notificationCount();

        $this->assertFalse($this->escalate($stage->refresh()),
            'the same level fired a second time within its step.');

        $this->assertSame($sent, $this->notificationCount());
    }

    #[Test]
    public function a_stage_is_not_escalated_past_the_top_of_the_ladder(): void
    {
        $stage = $this->pushDue($this->overdueStage(), hours: 240);

        $this->escalate($stage);
        $this->escalate($stage->refresh());
        $this->escalate($stage->refresh());

        $this->assertSame(3, (int) $stage->refresh()->last_escalated_level);
        $this->assertFalse($this->escalate($stage->refresh()));
    }

    /**
     * The level is stamped even when the send reached nobody.
     *
     * Not an edge case — it is why level 3 ever arrives. If a level that resolved to nobody were
     * left unstamped, a stage whose only assignee had left the company would retry level 1 nightly
     * and never climb to the people who could reassign it.
     */
    #[Test]
    public function a_level_that_reached_nobody_is_still_recorded_as_tried(): void
    {
        $stage = $this->overdueStage();

        // Everyone on the rung is gone. Level 1 now resolves to an account that cannot receive.
        $this->tunde->forceFill(['is_active' => false])->save();

        $this->assertTrue($this->escalate($stage));
        $this->assertSame(0, $this->notificationCount());
        $this->assertSame(1, (int) $stage->refresh()->last_escalated_level,
            'a level that reached nobody was left unstamped, so the ladder can never climb past it.');
    }

    // ================================================================== what the notice says

    /**
     * The subject line names the record and says nothing about it.
     *
     * It is the one part of a notification that survives into inbox previews, phone lock screens
     * and mail archives, and an escalation is by construction reaching people further and further
     * from the work. "May see it" and "should have its title on their lock screen" are not the
     * same claim.
     */
    #[Test]
    public function the_notice_carries_an_identifier_and_not_the_contents(): void
    {
        $stage = $this->overdueStage();
        $this->escalate($stage);

        $notice = Notification::query()->withoutGlobalScopes()
            ->where('user_id', $this->tunde->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('approval.overdue', $notice->key);
        $this->assertStringContainsString('Overdue approval', (string) $notice->title);
        $this->assertStringNotContainsString('Shore below', (string) $notice->title.(string) $notice->body,
            'the escalation quoted the document, which is the one thing it may not do.');
    }

    // ================================================================== the sweep

    /**
     * The nightly pass, which is the only caller in production.
     *
     * Run through `sweep()` rather than `escalate()` so the tenant loop is exercised: a sweep is
     * the easiest place in a codebase to write one unscoped query across every company (§75), and
     * it runs unattended at three in the morning where nobody would see the result.
     */
    #[Test]
    public function the_nightly_sweep_finds_the_overdue_stage_and_escalates_it(): void
    {
        $stage = $this->overdueStage();

        $this->assertSame(1, app(EscalationService::class)->sweep());

        $this->assertNotified($this->tunde);
        $this->assertSame(1, (int) $stage->refresh()->last_escalated_level);
    }

    #[Test]
    public function the_sweep_leaves_a_stage_that_is_not_overdue_alone(): void
    {
        $this->submitDocument();

        $this->assertSame(0, app(EscalationService::class)->sweep());
        $this->assertSame(0, $this->notificationCount());
    }

    // ================================================================== helpers

    private function escalate(WorkflowStageInstance $stage): bool
    {
        return $this->withinOrganization($this->org, fn () => app(EscalationService::class)->escalate($stage));
    }

    /** A submitted document sitting on stage 1, with that stage already past its due time. */
    private function overdueStage(): WorkflowStageInstance
    {
        return $this->pushDue($this->currentStage($this->submitDocument()), hours: 1);
    }

    /**
     * Move a stage's due time into the past.
     *
     * Written rather than travelling the clock because the ladder compares each level against
     * `due_at` plus a multiple of the step, and a test that moved `now()` instead would also move
     * the `last_escalated_at` stamps it is trying to reason about.
     */
    private function pushDue(WorkflowStageInstance $stage, int $hours): WorkflowStageInstance
    {
        DB::table('workflow_stage_instances')
            ->where('id', $stage->getKey())
            ->update(['due_at' => now()->subHours($hours)]);

        return $stage->refresh();
    }

    private function submitDocument(): WorkflowInstance
    {
        $workflow = $this->buildRoute();

        $this->procedures->forceFill(['workflow_id' => $workflow->getKey()])->save();

        $document = $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring procedure', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
        ]);

        $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertSessionHasNoErrors();

        $instance = WorkflowInstance::withoutGlobalScopes()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->whereNull('completed_at')
            ->latest('id')
            ->first();

        $this->assertNotNull($instance, 'submitting produced no workflow instance: the route was not picked up.');

        return $instance;
    }

    /**
     * One stage, with an SLA, built through the same endpoints the builder screen posts to.
     *
     * `sla_hours` is the whole precondition of this file: a stage without one gets a null `due_at`,
     * can never be overdue, and every test here would pass by never escalating anything.
     */
    private function buildRoute(): Workflow
    {
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Procedure approval',
                'entity_type' => 'document',
                'is_default' => '1',
            ])->assertSessionHasNoErrors();

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Procedure approval')->firstOrFail();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), [
                'name' => 'Technical review',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $this->leadEngineer->getKey(),
                'action_type' => 'approve',
                'quorum' => 'all',
                'on_reject' => 'return_to_owner',
                'sla_hours' => '48',
                'allow_delegate' => '1',
                'allow_self_approval' => '0',
                'is_active' => '1',
            ])->assertSessionHasNoErrors();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertSessionHasNoErrors();

        return $workflow->refresh();
    }

    private function currentStage(WorkflowInstance $instance): WorkflowStageInstance
    {
        return WorkflowStageInstance::withoutGlobalScopes()
            ->whereKey($instance->current_stage_instance_id)
            ->firstOrFail();
    }

    private function assertNotified(User $person, string $because = ''): void
    {
        $this->assertTrue($this->wasNotified($person),
            $because !== '' ? $because : $person->name.' was not told, and silence is what this file is about.');
    }

    private function assertNotNotified(User $person, string $because = ''): void
    {
        $this->assertFalse($this->wasNotified($person),
            $because !== '' ? $because : $person->name.' was told and should not have been.');
    }

    private function wasNotified(User $person): bool
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $person->getKey())
            ->where('key', 'approval.overdue')
            ->exists();
    }

    private function notificationCount(): int
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('key', 'approval.overdue')
            ->count();
    }

    private function clearNotifications(): void
    {
        Notification::query()->withoutGlobalScopes()->delete();
    }

    private function employeeOf(User $user): Employee
    {
        return Employee::query()->withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }
}
