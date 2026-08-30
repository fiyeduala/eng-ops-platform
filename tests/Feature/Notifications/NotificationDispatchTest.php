<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Engines\Access\ActorProfile;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectTeamService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use App\Support\NotificationCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The notification engine, tested as a security boundary rather than as a mail feature (docs/10 §8).
 *
 * Every test here is a variation on one question: can a person be TOLD something they could not have
 * READ? That is §68, and it is the only interesting property of this subsystem — the mail transport,
 * the bell and the preferences screen are ordinary code, and none of them would matter if a subject
 * line could leak a confidential record's title to somebody who cannot open it.
 *
 * The fixture is the same shape as the task tests, on purpose. Reusing the department/project split
 * means an assertion here about who was notified can be read against an assertion there about who
 * could see the record, and the two must agree — a notification audience that disagrees with the
 * access engine is exactly the defect this file exists to catch.
 *
 *   Structural (dept)   Lade   `task.view:department` over Structural
 *   Structural (dept)   John   `task.view:assigned` and nothing wider
 *   HSE (dept)          David  `task.view:project`, on Alpha's team
 *   HSE (dept)          Tunde  `task.view:project`, on no project at all
 *   Delivery (dept)     Priya  project manager, project-scoped authority
 */
final class NotificationDispatchTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $structural;

    private OrgUnit $hse;

    private OrgUnit $delivery;

    private User $priya;

    private User $john;

    private User $lade;

    private User $david;

    private User $tunde;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->structural = $this->makeUnit($this->org, 'Structural', OrgUnitType::Department, $engineering);
        $this->hse = $this->makeUnit($this->org, 'HSE', OrgUnitType::Department);
        $this->delivery = $this->makeUnit($this->org, 'Project Delivery', OrgUnitType::Department);

        $managerRole = $this->makeRole($this->org, 'Project Manager', [
            'project.view' => 'project',
            'project_member.view' => 'project',
            'project_member.assign' => 'project',
            'task.view' => 'project',
            'task.create' => 'project',
            'task.edit' => 'project',
            'task.assign' => 'project',
            'task.reassign' => 'project',
            'user.view' => 'organization',
        ]);

        $engineerRole = $this->makeRole($this->org, 'Engineer', [
            'task.view' => 'assigned',
            'task.edit' => 'assigned',
            'project.view' => 'project',
        ]);

        $leadRole = $this->makeRole($this->org, 'Lead Engineer', [
            'task.view' => 'department',
            'task.edit' => 'department',
            'task.assign' => 'department',
            'task.reassign' => 'department',
        ]);

        $teamRole = $this->makeRole($this->org, 'Team Member', [
            'task.view' => 'project',
            'project.view' => 'project',
        ]);

        $this->priya = $this->makePerson($this->org, 'Priya Raman', $this->delivery, [$managerRole]);
        $this->john = $this->makePerson($this->org, 'John Adeyemi', $this->structural, [$engineerRole]);
        $this->lade = $this->makePerson($this->org, 'Lade Bakare', $this->structural, [$leadRole]);
        $this->david = $this->makePerson($this->org, 'David Nwosu', $this->hse, [$teamRole]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Salami', $this->hse, [$teamRole]);

        $this->alpha = $this->makeProject($this->org, $this->priya, 'Project Alpha', [
            'org_unit_id' => $this->delivery->id,
            'manager_id' => $this->priya->id,
        ]);

        foreach ([$this->john, $this->david] as $person) {
            $this->addToTeam($this->alpha, $person);
        }
    }

    // ------------------------------------------------------------------ §68: the whole point

    #[Test]
    public function a_person_who_cannot_read_the_record_is_not_told_about_it(): void
    {
        // Structural work. Lade reaches it on the org ladder; Tunde reaches nothing at all.
        $task = $this->task('Check the abutment reinforcement', [
            'org_unit_id' => $this->structural->id,
        ]);

        $told = $this->dispatch(
            $task,
            Audience::user($this->lade)->orUser($this->tunde)->orUser($this->david),
        );

        $this->assertSame(1, $told, 'Only the one person who can open the task should have been told.');

        $this->assertTrue($this->wasNotified($this->lade), 'Lade owns Structural and was not told.');
        $this->assertFalse($this->wasNotified($this->tunde), 'Tunde cannot open the task but was told about it.');
        $this->assertFalse($this->wasNotified($this->david), 'David is not in Structural but was told.');
    }

    #[Test]
    public function a_confidential_record_is_not_announced_to_the_people_it_is_hidden_from(): void
    {
        // Confidential needs an explicit ACL row or `task.view_confidential`. Lade's department
        // scope reaches the row on the ladder and is still not enough — which is the case a
        // notification engine that queried memberships instead of asking the policy would get wrong.
        $task = $this->task('Contractor termination assessment', [
            'org_unit_id' => $this->structural->id,
            'confidentiality' => Confidentiality::Confidential,
        ]);

        $told = $this->dispatch($task, Audience::orgUnit($this->structural));

        $this->assertSame(0, $told, 'A confidential task was announced to the whole department.');
        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_subject_line_never_leaves_the_system_for_a_restricted_record(): void
    {
        // Restricted, and Lade may read it: Structural is his own unit, so the confidentiality rule
        // is satisfied. He is a legitimate recipient — and the EMAIL is still cut back to the
        // record's number, because email leaves the building and the person reading it over his
        // shoulder on the train is not the person the policy approved.
        $task = $this->task('Disciplinary follow-up on the night shift', [
            'org_unit_id' => $this->structural->id,
            'confidentiality' => Confidentiality::Restricted,
        ]);

        $this->dispatch($task, Audience::user($this->lade));

        Mail::assertQueued(NotificationMail::class, function (NotificationMail $mail) use ($task) {
            $this->assertStringNotContainsString(
                'Disciplinary',
                $mail->subjectLine.' '.(string) $mail->bodyText,
                'The restricted task title was sent out by email.',
            );

            $this->assertStringContainsString((string) $task->number, $mail->subjectLine);

            return true;
        });

        // In-app is a different matter: the row sits behind the same login and the same policy, and
        // the list re-checks it on the way out. It keeps the real title.
        $row = $this->notificationFor($this->lade);
        $this->assertStringContainsString('Disciplinary follow-up', (string) $row->title);
    }

    // ------------------------------------------------------------------ audiences

    #[Test]
    public function a_project_audience_reaches_the_team_and_stops_there(): void
    {
        $task = $this->task('Compile the site safety file', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $told = $this->dispatch($task, Audience::project($this->alpha));

        // Priya (manager) and David (team, project scope) can open it. John is on the team but holds
        // `task.view:assigned`, and the task is on nobody — membership is not a grant.
        $this->assertTrue($this->wasNotified($this->priya));
        $this->assertTrue($this->wasNotified($this->david));
        $this->assertFalse($this->wasNotified($this->john), 'Membership was treated as a grant.');
        $this->assertFalse($this->wasNotified($this->tunde), 'Somebody outside the team was told.');
        $this->assertSame(2, $told);
    }

    #[Test]
    public function a_membership_that_has_ended_is_no_longer_an_audience(): void
    {
        $task = $this->task('Close out the temporary works file', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->withinOrganization($this->org, fn () => ProjectMember::query()
            ->where('project_id', $this->alpha->id)
            ->where('user_id', $this->david->id)
            ->update(['ends_on' => now()->subDay()->toDateString()]));

        app(ActorProfile::class)->flush();

        $this->dispatch($task, Audience::project($this->alpha));

        $this->assertFalse(
            $this->wasNotified($this->david),
            'A lapsed membership still received project notifications.',
        );
    }

    #[Test]
    public function the_person_who_did_it_is_not_told_they_did_it(): void
    {
        $task = $this->task('Review the lifting plan', [
            'org_unit_id' => $this->structural->id,
        ]);

        $told = $this->dispatch($task, Audience::orgUnit($this->structural), causer: $this->lade);

        $this->assertSame(0, $told);
        $this->assertFalse($this->wasNotified($this->lade));
    }

    #[Test]
    public function an_audience_cannot_reach_across_a_tenant_boundary(): void
    {
        // §75, at the one layer where it is easiest to forget: Audience runs on the query builder
        // rather than Eloquent, so the tenant is pinned by hand on every branch and a missed pin
        // would not be caught by the global scope.
        $other = $this->makeOrganization('Harbour Marine Services');
        $outsider = $this->makePerson($other, 'Ada Obi');

        $task = $this->task('Sign off the pile cap drawings', [
            'org_unit_id' => $this->structural->id,
        ]);

        $told = $this->dispatch($task, Audience::user($outsider)->orUser($this->lade));

        $this->assertSame(1, $told);
        $this->assertFalse($this->wasNotified($outsider), 'A notification crossed into another company.');
    }

    #[Test]
    public function a_disabled_account_is_dropped_before_anything_is_written(): void
    {
        $this->withinOrganization($this->org, fn () => User::query()
            ->whereKey($this->lade->id)
            ->update(['is_active' => false]));

        $task = $this->task('Update the structural calculation sheet', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->assertSame(0, $this->dispatch($task, Audience::user($this->lade)));
        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------ preferences

    #[Test]
    public function a_preference_can_silence_email_but_not_a_mandatory_notification(): void
    {
        // `task.assigned` is mandatory: the channel mix is the recipient's business, but the
        // in-app copy is not. Somebody who missed work they were handed would rightly say the
        // system never told them.
        $this->withinOrganization($this->org, fn () => NotificationPreference::query()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->lade->id,
            'notification_key' => 'task.assigned',
            'in_app' => false,
            'email' => false,
            'push' => false,
            'digest' => false,
        ]));

        $task = $this->task('Inspect the falsework', ['org_unit_id' => $this->structural->id]);

        $told = $this->dispatch($task, Audience::user($this->lade), key: 'task.assigned');

        $this->assertSame(1, $told);
        $this->assertTrue($this->wasNotified($this->lade), 'A mandatory notification was suppressed.');
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_preference_can_silence_a_discretionary_notification_entirely(): void
    {
        $this->withinOrganization($this->org, fn () => NotificationPreference::query()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->lade->id,
            'notification_key' => 'announcement.published',
            'in_app' => false,
            'email' => false,
            'push' => false,
            'digest' => false,
        ]));

        $task = $this->task('Notice about the car park', ['org_unit_id' => $this->structural->id]);

        $this->assertSame(
            0,
            $this->dispatch($task, Audience::user($this->lade), key: 'announcement.published'),
        );
    }

    #[Test]
    public function the_preferences_screen_only_ever_writes_your_own_rows(): void
    {
        // §84: a user_id posted by hand must change nothing. There is no such field in the form,
        // and the controller never reads one.
        $this->actingFresh($this->lade)
            ->put(route('notifications.preferences.update'), [
                'user_id' => $this->john->id,
                'preferences' => [
                    'announcement.published' => ['in_app' => '0', 'email' => '0'],
                ],
            ])
            ->assertRedirect(route('notifications.preferences'));

        $this->assertSame(
            0,
            NotificationPreference::query()->withoutGlobalScopes()->where('user_id', $this->john->id)->count(),
            'One person edited another person\'s notification settings.',
        );

        $this->assertSame(
            count(NotificationCatalogue::keys()),
            NotificationPreference::query()->withoutGlobalScopes()->where('user_id', $this->lade->id)->count(),
        );
    }

    // ------------------------------------------------------------------ reading them

    #[Test]
    public function the_list_hides_notifications_whose_record_you_have_since_lost(): void
    {
        $task = $this->task('Check the abutment reinforcement', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->dispatch($task, Audience::user($this->lade));

        $this->actingFresh($this->lade)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Check the abutment reinforcement');

        // The record moves to a department Lade has no reach over. The notification row is untouched
        // — it is history — but it must stop being shown.
        $this->withinOrganization($this->org, fn () => Task::query()
            ->whereKey($task->id)
            ->update(['org_unit_id' => $this->hse->id]));

        app(ActorProfile::class)->flush();

        $this->actingFresh($this->lade)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Check the abutment reinforcement');
    }

    #[Test]
    public function following_a_notification_is_authorized_at_the_moment_of_the_click(): void
    {
        $task = $this->task('Compile the site safety file', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->dispatch($task, Audience::user($this->david));

        $notification = $this->notificationFor($this->david);

        $this->actingFresh($this->david)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('tasks.show', $task));

        // Off the team, and the same stored link must now refuse rather than redirect into a 403.
        $this->withinOrganization($this->org, fn () => ProjectMember::query()
            ->where('project_id', $this->alpha->id)
            ->where('user_id', $this->david->id)
            ->update(['is_active' => false]));

        app(ActorProfile::class)->flush();

        $this->actingFresh($this->david)
            ->get(route('notifications.open', $notification))
            ->assertForbidden();
    }

    #[Test]
    public function one_person_cannot_open_or_read_another_person_s_notification(): void
    {
        $task = $this->task('Review the lifting plan', ['org_unit_id' => $this->structural->id]);

        $this->dispatch($task, Audience::user($this->lade));

        $notification = $this->notificationFor($this->lade);

        // John cannot open the task either, but that is not why this must fail: even if he could,
        // somebody else's bell is not his to read (§94, walking the ids).
        $this->actingFresh($this->john)
            ->get(route('notifications.open', $notification))
            ->assertForbidden();

        $this->actingFresh($this->john)
            ->post(route('notifications.read', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function marking_everything_read_stops_at_your_own_rows(): void
    {
        $task = $this->task('Check the abutment reinforcement', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->dispatch($task, Audience::user($this->lade)->orUser($this->john));

        // Only Lade can open it, so only Lade has a row — assign it to John as well so both do.
        $this->withinOrganization($this->org, fn () => Task::query()
            ->whereKey($task->id)->update(['assignee_id' => $this->john->id]));
        app(ActorProfile::class)->flush();
        $this->dispatch($task, Audience::user($this->john));

        $this->assertSame(1, $this->unreadCount($this->john));

        $this->actingFresh($this->lade)
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $this->unreadCount($this->lade));
        $this->assertSame(1, $this->unreadCount($this->john), 'Another person\'s bell was cleared.');
    }

    // ------------------------------------------------------------------ the listener

    #[Test]
    public function assigning_work_notifies_the_person_it_landed_on(): void
    {
        $task = $this->task('Inspect the falsework', [
            'project_id' => $this->alpha->id,
            'org_unit_id' => $this->delivery->id,
        ]);

        $this->actingFresh($this->priya)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->john->id])
            ->assertRedirect(route('tasks.show', $task));

        app(ActorProfile::class)->flush();

        $row = $this->notificationFor($this->john);

        $this->assertSame('task.assigned', $row->key);
        $this->assertStringContainsString((string) $task->number, (string) $row->title);
        $this->assertSame('/tasks/'.$task->id, $row->action_url);
        $this->assertSame((int) $this->priya->id, (int) $row->causer_id);
        $this->assertSame((int) $this->alpha->id, (int) $row->project_id);
    }

    #[Test]
    public function assigning_work_to_yourself_notifies_nobody(): void
    {
        $task = $this->task('Review the lifting plan', [
            'org_unit_id' => $this->structural->id,
        ]);

        $this->actingFresh($this->lade)
            ->post(route('tasks.assign', $task), ['assignee_id' => $this->lade->id])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Send one notification about a record, the way a listener would.
     *
     * Deliberately goes through the real dispatcher rather than a fake: every assertion in this file
     * is about a decision the dispatcher makes, so a double would test nothing.
     */
    private function dispatch(
        Task $subject,
        Audience $audience,
        string $key = 'task.assigned',
        ?User $causer = null,
    ): int {
        return $this->withinOrganization($this->org, fn () => app(NotificationDispatcher::class)->dispatch(
            notification: NotificationMessage::make(
                key: $key,
                title: (string) $subject->title,
                body: 'Something happened to '.$subject->number.'.',
                actionUrl: '/tasks/'.$subject->id,
            ),
            audience: $audience,
            subject: $subject,
            requiredAbility: 'view',
            causer: $causer,
        ));
    }

    private function wasNotified(User $person): bool
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $person->id)
            ->exists();
    }

    private function notificationFor(User $person): Notification
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $person->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function unreadCount(User $person): int
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $person->id)
            ->whereNull('read_at')
            ->count();
    }

    private function task(string $title, array $attributes = []): Task
    {
        $task = $this->makeTask($this->org, $this->priya, $title, $attributes);

        app(ActorProfile::class)->flush();

        return $task;
    }

    private function addToTeam(Project $project, User $person): void
    {
        $this->withinOrganization($this->org, fn () => app(ProjectTeamService::class)
            ->addMember($project, ['user_id' => $person->id], $this->priya));

        app(ActorProfile::class)->flush();
    }
}
