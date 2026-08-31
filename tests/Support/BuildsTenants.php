<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Organization\OrgUnitService;
use App\Models\Announcement;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\Meeting;
use App\Models\Memo;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Role;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Services\AnnouncementService;
use App\Services\DocumentService;
use App\Services\DocumentVersionService;
use App\Services\MeetingService;
use App\Services\MemoService;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Services\WorkflowDefinitionService;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\DocumentKind;
use App\Support\Enums\OrgUnitType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures for the access tests.
 *
 * Deliberately small and hand-built rather than a call to DemoSeeder: these tests are about who
 * can see what, and a fixture whose shape is visible in the test file is the only way to read an
 * assertion about reach and know whether it is right.
 *
 * Everything is created inside an explicit organization context. Gate 1 fails closed, so a model
 * created with no context bound would be written with organization_id 0 and then be invisible to
 * everybody — which would make every test pass for the wrong reason.
 */
trait BuildsTenants
{
    protected function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }

    /**
     * Run a callback as if serving a request inside this organization.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function withinOrganization(Organization $organization, callable $callback): mixed
    {
        return $this->context()->within($organization, $callback);
    }

    /**
     * Sign in, discarding whatever session the previous actor left behind.
     *
     * An access test walks three or four people through the same page in one method, and the session
     * carries a hash of the signed-in account's password (AuthenticateSession, bootstrap/app.php).
     * Reusing it across actors logs the second one straight back out — a 302 to /login, which is
     * indistinguishable at a glance from the 403 these tests exist to tell apart from success, and
     * which quietly turns an `assertRedirect()` on a write into a passing assertion about a request
     * that never ran.
     */
    protected function actingFresh(User $user): static
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    protected function makeOrganization(string $name): Organization
    {
        return $this->context()->withoutScope(fn () => Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'locale' => 'en',
            'is_active' => true,
        ]));
    }

    /**
     * One unit, with its materialized path maintained by the service rather than by hand — the
     * path IS the visibility mechanism, so a fixture that computed it independently would be
     * testing the fixture.
     */
    protected function makeUnit(
        Organization $organization,
        string $name,
        OrgUnitType $type,
        ?OrgUnit $parent = null,
    ): OrgUnit {
        return $this->withinOrganization($organization, fn () => app(OrgUnitService::class)->create([
            'name' => $name,
            'type' => $type->value,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ]));
    }

    /**
     * A role carrying the given grants.
     *
     * @param  array<string,string|array<int,string>>  $grants  permission key => scope(s)
     */
    protected function makeRole(Organization $organization, string $name, array $grants): Role
    {
        return $this->withinOrganization($organization, function () use ($organization, $name, $grants) {
            $role = Role::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4),
                'level' => 50,
                'is_system' => false,
                'is_active' => true,
            ]);

            $role->syncGrants($grants);

            return $role;
        });
    }

    /**
     * A person: the login account, the personnel record that places them on the org chart, and
     * their roles. All three, because reach is computed from the employee record — an account with
     * no employee has no unit, and therefore no organizational reach at all.
     *
     * @param  array<int,Role|array{0:Role,1:OrgUnit}>  $roles  a role, or a role pinned to a unit
     */
    protected function makePerson(
        Organization $organization,
        string $name,
        ?OrgUnit $unit = null,
        array $roles = [],
        ?Employee $supervisor = null,
    ): User {
        return $this->withinOrganization($organization, function () use ($organization, $name, $unit, $roles, $supervisor) {
            // Assigned attribute by attribute rather than mass-assigned. `is_super_admin`,
            // `must_change_password` and `password_changed_at` are deliberately absent from the
            // model's fillable list — none of them may ever be set from request input — and a
            // fixture that widened $fillable to be convenient would delete that protection for
            // the whole application.
            $user = new User;
            $user->organization_id = $organization->id;
            $user->name = $name;
            $user->email = Str::slug($name).'-'.Str::random(5).'@example.test';
            $user->password = Hash::make('correct-horse-battery-staple');
            $user->is_active = true;
            $user->is_super_admin = false;
            $user->must_change_password = false;
            $user->password_changed_at = now();
            $user->save();

            $employee = Employee::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'employee_no' => 'E'.Str::upper(Str::random(6)),
                'first_name' => Str::before($name, ' ') ?: $name,
                'last_name' => Str::after($name, ' ') ?: 'Person',
                'org_unit_id' => $unit?->id,
                'supervisor_id' => $supervisor?->id,
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'is_active' => true,
            ]);

            foreach ($roles as $entry) {
                [$role, $pin] = is_array($entry) ? $entry : [$entry, null];

                $user->roles()->attach($role->id, [
                    'scope_type' => $pin ? 'org_unit' : null,
                    'scope_id' => $pin?->id,
                ]);
            }

            $organization->bumpPermissionsVersion();

            // Relations are read back through the access engine on the next request; loading them
            // here keeps a stale null from a fixture step masquerading as "no reach".
            return $user->fresh(['roles', 'employee']);
        });
    }

    /**
     * The configuration a project needs to exist: statuses, the moves between them, numbering
     * schemes, and the project type/role catalogues.
     *
     * Called through OrganizationProvisioner rather than reproduced here, because a fixture that
     * invented its own status vocabulary would let StatusMachine's whitelist drift from the one
     * every real tenant gets. Idempotent, so a test may call it more than once.
     */
    protected function provisionConfiguration(Organization $organization): void
    {
        $this->withinOrganization($organization, function () use ($organization) {
            $provisioner = app(OrganizationProvisioner::class);

            $provisioner->seedStatuses($organization);
            $provisioner->seedNumberingSchemes($organization);
            $provisioner->seedProjectCatalogue($organization);
        });

        app(StatusMachine::class)->flush();
    }

    /**
     * A project, created through ProjectService so the number, the opening status and the manager's
     * own membership row come from the same code path the controller uses. A project assembled with
     * Project::create() in a fixture would have no number, no status and — crucially for the access
     * tests — no team, which is the axis half of them are about.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeProject(
        Organization $organization,
        User $actor,
        string $name,
        array $attributes = [],
    ): Project {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(ProjectService::class)->create([
            'name' => $name,
            'confidentiality' => Confidentiality::Internal,
            'priority' => 'normal',
            'health' => 'on_track',
            'is_active' => true,
            ...$attributes,
        ], $actor));
    }

    /**
     * A task, created through TaskService for the same reason projects are: the number, the opening
     * status, the assignment stamps and the creator's follower row all come from one code path. A
     * task built with Task::create() would have no status at all, which would make every transition
     * test pass for the wrong reason.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeTask(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Task {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(TaskService::class)->create([
            'title' => $title,
            'confidentiality' => Confidentiality::Internal,
            'priority' => 'normal',
            ...$attributes,
        ], $actor));
    }

    /**
     * A memo, still a draft, optionally addressed to people.
     *
     * Left as a draft on purpose. Issuing is the act that makes a memo visible to anybody but its
     * author, so a fixture that issued silently would hide the draft curtain from every test that
     * used it. A test that wants an issued memo says so, through issueMemo().
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,User|int>  $recipients
     */
    protected function makeMemo(
        Organization $organization,
        User $actor,
        string $subject,
        array $attributes = [],
        array $recipients = [],
    ): Memo {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, function () use ($actor, $subject, $attributes, $recipients) {
            $memo = app(MemoService::class)->create([
                'subject' => $subject,
                'body' => 'For the attention of the distribution list.',
                'confidentiality' => Confidentiality::Internal,
                ...$attributes,
            ], $actor);

            if ($recipients !== []) {
                app(MemoService::class)->address(
                    $memo,
                    array_map(fn ($r) => (int) ($r instanceof User ? $r->getKey() : $r), $recipients),
                    $actor,
                );
            }

            return $memo->refresh();
        });
    }

    /**
     * Issue a draft memo — the publication act, through the state machine rather than by writing
     * `issued_at` directly, because the column and the status have to agree and only one code path
     * makes sure they do.
     */
    protected function issueMemo(Organization $organization, Memo $memo, User $actor): Memo
    {
        return $this->moveTo($organization, 'memo', $memo, 'issued', $actor);
    }

    /**
     * An announcement, still a draft. Same reasoning as makeMemo().
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeAnnouncement(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Announcement {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(AnnouncementService::class)->create([
            'title' => $title,
            'body' => 'Please note the following.',
            'urgency' => 'normal',
            ...$attributes,
        ], $actor));
    }

    protected function publishAnnouncement(Organization $organization, Announcement $announcement, User $actor): Announcement
    {
        return $this->moveTo($organization, 'announcement', $announcement, 'published', $actor);
    }

    /**
     * A meeting, with an invitation list.
     *
     * Defaults to next week so it is upcoming, which is the register's default view — a fixture
     * dated in the past would be missing from the list the test is most likely to assert on.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,User|int>  $participants
     */
    protected function makeMeeting(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
        array $participants = [],
    ): Meeting {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, function () use ($actor, $title, $attributes, $participants) {
            $meeting = app(MeetingService::class)->create([
                'title' => $title,
                'agenda' => 'Matters arising, progress, and the look-ahead.',
                'starts_at' => now()->addWeek()->setTime(10, 0),
                'ends_at' => now()->addWeek()->setTime(11, 30),
                'location' => 'Meeting Room 2',
                'confidentiality' => Confidentiality::Internal,
                ...$attributes,
            ], $actor);

            if ($participants !== []) {
                app(MeetingService::class)->invite(
                    $meeting,
                    array_map(fn ($p) => (int) ($p instanceof User ? $p->getKey() : $p), $participants),
                    $actor,
                );
            }

            return $meeting->refresh();
        });
    }

    /**
     * Move any record with a status onto a named status, through the machine.
     *
     * Fixtures reach for this rather than updating `status_id` themselves: the transition is where
     * the publication stamp, the event and the timeline entry are written, and a test whose setup
     * skipped it would be asserting against a state the application cannot actually produce.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $record
     * @return TModel
     */
    protected function moveTo(
        Organization $organization,
        string $entityType,
        $record,
        string $statusKey,
        User $actor,
        ?string $comment = null,
    ) {
        return $this->withinOrganization($organization, function () use ($entityType, $record, $statusKey, $actor, $comment) {
            $to = Status::query()
                ->forEntity($entityType)
                ->where('key', $statusKey)
                ->firstOrFail();

            $service = match ($entityType) {
                'memo' => app(MemoService::class),
                'announcement' => app(AnnouncementService::class),
                'meeting' => app(MeetingService::class),
                default => throw new \InvalidArgumentException($entityType.' has no fixture transition.'),
            };

            return $service->transition($record, $to, $actor, $comment)->refresh();
        });
    }

    // ================================================================== documents (Phase 3)

    /**
     * A register: the configuration that decides how documents in it are numbered, revised,
     * approved and defaulted.
     *
     * Created directly rather than through a service because DocumentCategory has no service — it
     * is configuration, written by the controller from a validated request. What matters for the
     * tests is that the flags a document then inherits are visible in the test file.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeDocumentCategory(
        Organization $organization,
        string $name,
        array $attributes = [],
    ): DocumentCategory {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => DocumentCategory::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'code' => Str::upper(Str::substr(Str::slug($name), 0, 4)),
            'revision_scheme' => 'alpha',
            'default_confidentiality' => Confidentiality::Internal->value,
            'default_kind' => DocumentKind::Native->value,
            'is_controlled' => true,
            'requires_approval' => true,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * A document, raised through DocumentService so it gets its number, its opening status and the
     * defaults its register carries. A Document::create() in a fixture would have none of those,
     * and every lifecycle assertion below it would be testing a state the application cannot reach.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeDocument(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Document {
        $this->provisionConfiguration($organization);

        return $this->withinOrganization($organization, fn () => app(DocumentService::class)->create([
            'title' => $title,
            'kind' => DocumentKind::Native->value,
            'confidentiality' => Confidentiality::Internal->value,
            ...$attributes,
        ], $actor));
    }

    /**
     * A draft revision with authored content.
     *
     * Note what this does NOT do: it does not make the revision current and it does not approve it.
     * Both of those are things the workflow does, and a fixture that did them silently would hide
     * the half of the module the tests exist to check.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeNativeVersion(
        Organization $organization,
        Document $document,
        User $actor,
        string $html = '<p>The procedure text.</p>',
        array $attributes = [],
    ): DocumentVersion {
        return $this->withinOrganization($organization, fn () => app(DocumentVersionService::class)->createNative(
            $document,
            ['content_html' => $html, 'change_summary' => 'First issue.', ...$attributes],
            $actor,
        ));
    }

    // ================================================================== workflow (Phase 3)

    /**
     * A published, single-stage approval route.
     *
     * Published on purpose: an unpublished definition is invisible to the engine, so a fixture that
     * forgot this step would produce tests where "nothing was routed" passes for the wrong reason.
     * The stage names a ROLE by id, which is also the shape the assignee_ref regression test below
     * pins down.
     *
     * @param  array<int,array<string,mixed>>  $stages
     */
    protected function makeWorkflow(
        Organization $organization,
        User $actor,
        string $name,
        string $entityType,
        array $stages,
        array $attributes = [],
    ): Workflow {
        return $this->withinOrganization($organization, function () use ($actor, $name, $entityType, $stages, $attributes) {
            $definitions = app(WorkflowDefinitionService::class);

            $workflow = $definitions->create([
                'name' => $name,
                'entity_type' => $entityType,
                'is_default' => true,
                ...$attributes,
            ], $actor);

            foreach ($stages as $stage) {
                $definitions->addStage($workflow, [
                    'action_type' => 'approve',
                    'quorum' => 'all',
                    'on_reject' => 'return_to_owner',
                    'allow_delegate' => true,
                    'allow_self_approval' => false,
                    'is_active' => true,
                    ...$stage,
                ], $actor);
            }

            return $definitions->publish($workflow->refresh(), $actor)->refresh();
        });
    }
}
