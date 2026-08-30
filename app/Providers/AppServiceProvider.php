<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Engines\Access\AccessResolver;
use App\Engines\Access\ActorProfile;
use App\Engines\Access\OrganizationContext;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\AuditContext;
use App\Engines\Audit\AuditLogger;
use App\Engines\Configuration\SettingsRepository;
use App\Events\AnnouncementPublished;
use App\Events\MeetingCancelled;
use App\Events\MeetingInvited;
use App\Events\MeetingMinutesCirculated;
use App\Events\MeetingRescheduled;
use App\Events\MemoIssued;
use App\Events\TaskAssigned;
use App\Listeners\NotifyAnnouncementAudience;
use App\Listeners\NotifyMeetingCancelled;
use App\Listeners\NotifyMeetingMinutes;
use App\Listeners\NotifyMeetingParticipants;
use App\Listeners\NotifyMeetingRescheduled;
use App\Listeners\NotifyMemoRecipients;
use App\Listeners\NotifyTaskAssignee;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Follower;
use App\Models\Location;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\Memo;
use App\Models\MemoRecipient;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NumberingScheme;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectDepartment;
use App\Models\ProjectMember;
use App\Models\ProjectRole;
use App\Models\ProjectType;
use App\Models\RecordPermission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\FollowerPolicy;
use App\Policies\LocationPolicy;
use App\Policies\MeetingDecisionPolicy;
use App\Policies\MeetingParticipantPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\MemoPolicy;
use App\Policies\MemoRecipientPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\NumberingSchemePolicy;
use App\Policies\NumberSequencePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrgUnitPolicy;
use App\Policies\PositionPolicy;
use App\Policies\ProjectDepartmentPolicy;
use App\Policies\ProjectMemberPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectRolePolicy;
use App\Policies\ProjectTypePolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\StatusPolicy;
use App\Policies\StatusTransitionPolicy;
use App\Policies\TaskChecklistItemPolicy;
use App\Policies\TaskDependencyPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped singletons. Every one of these memoizes per request; sharing the
        // instance is what keeps a page that authorizes forty records from re-walking the org
        // tree forty times.
        $this->app->singleton(OrganizationContext::class);
        $this->app->singleton(AuditContext::class);
        $this->app->singleton(ActorProfile::class);
        $this->app->singleton(AccessResolver::class);
        $this->app->singleton(ScopeCompiler::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(SettingsRepository::class);
    }

    public function boot(): void
    {
        $this->configureAuthentication();
        $this->configureModels();
        $this->configureMorphMap();
        $this->configurePolicies();
        $this->configureBlade();
        $this->configureGates();
        $this->configureEventListeners();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * The user provider that resolves the session's user without the tenant scope.
     *
     * config/auth.php names this driver rather than 'eloquent'. See App\Auth\TenantAwareUserProvider
     * for why authentication is the one query that cannot be tenant-scoped, and why that is narrow.
     */
    private function configureAuthentication(): void
    {
        Auth::provider('tenant_eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });
    }

    private function configureModels(): void
    {
        // Lazy loading is not a performance nuisance here — it is a correctness risk. A relation
        // loaded implicitly inside a Blade loop bypasses the eager-loaded, scope-filtered query
        // the controller built, which is exactly how an authorization-filtered list starts
        // rendering rows it filtered out. Fail loudly outside production instead.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Stable morph keys.
     *
     * Polymorphic columns appear in audit_logs, activity_logs and record_permissions — all of
     * which are permanent. Storing class names there would mean a namespace refactor silently
     * orphans years of audit history and, worse, detaches record ACL rows from their records.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'organization' => Organization::class,
            'org_unit' => OrgUnit::class,
            'user' => User::class,
            'employee' => Employee::class,
            'position' => Position::class,
            'location' => Location::class,
            'role' => Role::class,
            'setting' => Setting::class,
            'record_permission' => RecordPermission::class,
            'audit_log' => AuditLog::class,
            'activity_log' => ActivityLog::class,

            'status' => Status::class,
            'status_transition' => StatusTransition::class,
            'numbering_scheme' => NumberingScheme::class,
            'number_sequence' => NumberSequence::class,

            'project' => Project::class,
            'project_type' => ProjectType::class,
            'project_role' => ProjectRole::class,
            'project_department' => ProjectDepartment::class,
            'project_member' => ProjectMember::class,

            'task' => Task::class,
            'task_checklist_item' => TaskChecklistItem::class,
            'task_dependency' => TaskDependency::class,
            'follower' => Follower::class,

            'notification' => Notification::class,
            'notification_preference' => NotificationPreference::class,

            'memo' => Memo::class,
            'memo_recipient' => MemoRecipient::class,
            'announcement' => Announcement::class,
            'meeting' => Meeting::class,
            'meeting_participant' => MeetingParticipant::class,
            'meeting_decision' => MeetingDecision::class,
        ]);
    }

    /**
     * Bind every model to its policy explicitly, and refuse to guess.
     *
     * Laravel's convention-based discovery would silently fall back to "no policy" for a model
     * whose policy is missing or misnamed — and "no policy" means Gate::allows() returns false
     * for a guest but a controller that forgot to authorize simply proceeds. Listing the pairs
     * here makes an unprotected model a visible omission in one file rather than an absence
     * nobody notices.
     *
     * Gate::guessPolicyNamesUsing below turns a missing entry into an explicit failure in
     * development, so adding a model without a policy cannot reach production quietly.
     */
    private function configurePolicies(): void
    {
        $policies = [
            Organization::class => OrganizationPolicy::class,
            OrgUnit::class => OrgUnitPolicy::class,
            Employee::class => EmployeePolicy::class,
            User::class => UserPolicy::class,
            Position::class => PositionPolicy::class,
            Location::class => LocationPolicy::class,
            Role::class => RolePolicy::class,
            Setting::class => SettingPolicy::class,
            AuditLog::class => AuditLogPolicy::class,

            // --- Engine 5: configuration ---------------------------------------------
            Status::class => StatusPolicy::class,
            StatusTransition::class => StatusTransitionPolicy::class,
            NumberingScheme::class => NumberingSchemePolicy::class,
            NumberSequence::class => NumberSequencePolicy::class,

            // --- Phase 2: projects ---------------------------------------------------
            Project::class => ProjectPolicy::class,
            ProjectType::class => ProjectTypePolicy::class,
            ProjectRole::class => ProjectRolePolicy::class,
            ProjectDepartment::class => ProjectDepartmentPolicy::class,
            ProjectMember::class => ProjectMemberPolicy::class,

            // --- Phase 2: tasks ------------------------------------------------------
            // The last three have no permissions of their own; their policies delegate to
            // TaskPolicy or to the followed record's policy. They are listed anyway, because a
            // model without an entry here is a model Gate::guessPolicyNamesUsing throws on — and
            // that is exactly the alarm we want if one is ever addressed directly.
            Task::class => TaskPolicy::class,
            TaskChecklistItem::class => TaskChecklistItemPolicy::class,
            TaskDependency::class => TaskDependencyPolicy::class,
            Follower::class => FollowerPolicy::class,

            // --- Phase 2: notifications ----------------------------------------------
            // One policy for both, because both answer the same one-line question: is this
            // row yours? There is no scope and no administrator override on purpose — see
            // NotificationPolicy.
            Notification::class => NotificationPolicy::class,
            NotificationPreference::class => NotificationPolicy::class,

            // --- Phase 2: communication ----------------------------------------------
            // The three child models have no permissions of their own — a recipient row, a
            // participant row and a decision line all borrow their parent's, the same
            // arrangement as a task's checklist. They are listed for the same reason the
            // task children are: an unpoliced model is an unauthorized one.
            Memo::class => MemoPolicy::class,
            MemoRecipient::class => MemoRecipientPolicy::class,
            Announcement::class => AnnouncementPolicy::class,
            Meeting::class => MeetingPolicy::class,
            MeetingParticipant::class => MeetingParticipantPolicy::class,
            MeetingDecision::class => MeetingDecisionPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        if (! $this->app->isProduction()) {
            Gate::guessPolicyNamesUsing(function (string $model): array {
                // Scoped to our own models on purpose. Laravel asks the guesser about all sorts
                // of classes; only an App\Models class reaching here is a genuine omission.
                if (str_starts_with($model, 'App\\Models\\')) {
                    throw new \RuntimeException(
                        "No policy registered for {$model}. Add it to "
                        .'AppServiceProvider::configurePolicies() — every model reachable from a '
                        .'controller needs one, because an unpoliced model is an unauthorized one.'
                    );
                }

                return [];
            });
        }
    }

    /**
     * Events to listeners, written out rather than discovered.
     *
     * bootstrap/app.php does not call withEvents(), so Laravel's convention-based listener discovery
     * is not running — and that is the intended arrangement, not an omission. Discovery decides what
     * the application does by scanning a directory, which means a file added, renamed or moved
     * silently changes who gets told what. Notifications are exactly the wrong place for that: a
     * listener registered twice sends everything twice, and one that stops being found fails
     * completely silently.
     *
     * Same reasoning as configurePolicies() above — the map lives in one readable file, and an
     * absence is visible.
     */
    private function configureEventListeners(): void
    {
        $listeners = [
            TaskAssigned::class => [
                NotifyTaskAssignee::class,
            ],

            // --- communication -------------------------------------------------------
            // One listener per event rather than one per audience. Each of these decides who
            // the message is AIMED at; whether any given candidate is actually told is
            // NotificationDispatcher's authorization filter, which none of them can skip.
            MemoIssued::class => [
                NotifyMemoRecipients::class,
            ],
            AnnouncementPublished::class => [
                NotifyAnnouncementAudience::class,
            ],
            MeetingInvited::class => [
                NotifyMeetingParticipants::class,
            ],
            MeetingRescheduled::class => [
                NotifyMeetingRescheduled::class,
            ],
            MeetingCancelled::class => [
                NotifyMeetingCancelled::class,
            ],

            // Minutes notify on CIRCULATION and never on recording — see the listener for
            // why announcing a draft would be a disclosure of the thing being withheld.
            MeetingMinutesCirculated::class => [
                NotifyMeetingMinutes::class,
            ],
        ];

        foreach ($listeners as $event => $handlers) {
            foreach ($handlers as $handler) {
                Event::listen($event, $handler);
            }
        }
    }

    private function configureBlade(): void
    {
        // Gate 2 in a template. Deliberately named differently from @can so it is obvious at a
        // glance which checks are permission-only and which are record-aware — hiding a button
        // is a usability affordance, never the authorization itself (section 6).
        Blade::if('permission', function (string ...$permissions) {
            $user = auth()->user();

            return $user !== null && app(AccessResolver::class)->hasAny($user, ...$permissions);
        });

        Blade::if('allPermissions', function (string ...$permissions) {
            $user = auth()->user();

            return $user !== null && app(AccessResolver::class)->hasAll($user, ...$permissions);
        });
    }

    private function configureGates(): void
    {
        // Note on denial logging: it is NOT done with Gate::after, because @can in a template
        // fires the same gate and a single page renders dozens of them. Logging there would bury
        // real probing under thousands of "this button is hidden" entries. Only a thrown
        // AuthorizationException — an action actually attempted and refused — is recorded, in
        // bootstrap/app.php.

        Gate::define('access-admin', fn (User $user) => app(AccessResolver::class)
            ->hasAny($user, 'organization.configure', 'role.manage', 'user.manage')
            ? Response::allow()
            : Response::deny('Administration is restricted.'));
    }
}
