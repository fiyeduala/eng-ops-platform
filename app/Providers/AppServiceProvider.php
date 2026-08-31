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
use App\Engines\Workflow\AssigneeResolver;
use App\Events\AnnouncementPublished;
use App\Events\ApprovalDecided;
use App\Events\ApprovalRequested;
use App\Events\DocumentApproved;
use App\Events\DocumentRevisionRequested;
use App\Events\MeetingCancelled;
use App\Events\MeetingInvited;
use App\Events\MeetingMinutesCirculated;
use App\Events\MeetingRescheduled;
use App\Events\MemoIssued;
use App\Events\TaskAssigned;
use App\Events\ChangeRequestDecided;
use App\Events\RfiAnswered;
use App\Events\SubmittalReviewed;
use App\Events\TransmittalIssued;
use App\Events\WorkflowStalled;
use App\Listeners\NotifyAnnouncementAudience;
use App\Listeners\NotifyApprovalAssignees;
use App\Listeners\NotifyApprovalOutcome;
use App\Listeners\NotifyChangeRequestDecided;
use App\Listeners\NotifyDocumentApproved;
use App\Listeners\NotifyDocumentRevisionRequested;
use App\Listeners\NotifyMeetingCancelled;
use App\Listeners\NotifyMeetingMinutes;
use App\Listeners\NotifyMeetingParticipants;
use App\Listeners\NotifyMeetingRescheduled;
use App\Listeners\NotifyMemoRecipients;
use App\Listeners\NotifyRfiAnswered;
use App\Listeners\NotifySubmittalReviewed;
use App\Listeners\NotifyTaskAssignee;
use App\Listeners\NotifyTransmittalIssued;
use App\Listeners\NotifyWorkflowStalled;
use App\Listeners\RecordDocumentSignature;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Drawing;
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
use App\Models\Rfi;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Signature;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Submittal;
use App\Models\SubmittalItem;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\Template;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use App\Models\WorkflowStageInstance;
use App\Policies\AnnouncementPolicy;
use App\Policies\ApprovalPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ChangeRequestPolicy;
use App\Policies\DeliverablePolicy;
use App\Policies\DocumentCategoryPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentVersionPolicy;
use App\Policies\DrawingPolicy;
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
use App\Policies\RfiPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\SignaturePolicy;
use App\Policies\StatusPolicy;
use App\Policies\StatusTransitionPolicy;
use App\Policies\SubmittalItemPolicy;
use App\Policies\SubmittalPolicy;
use App\Policies\TaskChecklistItemPolicy;
use App\Policies\TaskDependencyPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TemplatePolicy;
use App\Policies\TransmittalItemPolicy;
use App\Policies\TransmittalPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkflowInstancePolicy;
use App\Policies\WorkflowPolicy;
use App\Policies\WorkflowStageConditionPolicy;
use App\Policies\WorkflowStageInstancePolicy;
use App\Policies\WorkflowStagePolicy;
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

        // Shared for a different reason: a custom assignee rule registered by a tenant must be
        // visible to the workflow engine AND to the builder that lists what can be chosen. Two
        // instances would mean a rule that resolves at runtime but cannot be selected in the UI.
        $this->app->singleton(AssigneeResolver::class);
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

            'document_category' => DocumentCategory::class,
            'document' => Document::class,
            'document_version' => DocumentVersion::class,

            'workflow' => Workflow::class,
            'workflow_stage' => WorkflowStage::class,
            'workflow_stage_condition' => WorkflowStageCondition::class,
            'workflow_instance' => WorkflowInstance::class,
            'workflow_stage_instance' => WorkflowStageInstance::class,
            'approval' => Approval::class,
            'signature' => Signature::class,

            'transmittal' => Transmittal::class,
            'transmittal_item' => TransmittalItem::class,
            'template' => Template::class,

            // --- Phase 4: engineering delivery ---------------------------------------
            // These keys are not decoration. They are what a workflow definition stores in
            // `entity_type`, what an auto-action stores in `record`, and what an activity entry
            // stores in its subject columns — so renaming one silently breaks every route and
            // every timeline that already referenced it.
            'deliverable' => Deliverable::class,
            'drawing' => Drawing::class,
            'rfi' => Rfi::class,
            'submittal' => Submittal::class,
            'submittal_item' => SubmittalItem::class,
            'change_request' => ChangeRequest::class,
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

            // --- Phase 3: documents --------------------------------------------------
            // DocumentVersionPolicy delegates every ability to the parent document, so there is
            // one definition of who may see a document and revisions cannot drift onto an older
            // answer. The register (category) is configuration and takes `manage` alone.
            Document::class => DocumentPolicy::class,
            DocumentCategory::class => DocumentCategoryPolicy::class,
            DocumentVersion::class => DocumentVersionPolicy::class,

            // --- Phase 3: workflow ---------------------------------------------------
            // Definitions are governed by `workflow.*`; the records travelling through them are
            // governed by whether you can see the SUBJECT. That split is the reason a workflow
            // administrator cannot read the approval history of a confidential record just
            // because they configured the route it took.
            Workflow::class => WorkflowPolicy::class,
            WorkflowStage::class => WorkflowStagePolicy::class,
            WorkflowStageCondition::class => WorkflowStageConditionPolicy::class,
            WorkflowInstance::class => WorkflowInstancePolicy::class,
            WorkflowStageInstance::class => WorkflowStageInstancePolicy::class,

            // Approvals and signatures are evidence: readable, never writable, by anyone (§59).
            Approval::class => ApprovalPolicy::class,
            Signature::class => SignaturePolicy::class,

            // --- Phase 3: issue and house style --------------------------------------
            Transmittal::class => TransmittalPolicy::class,
            TransmittalItem::class => TransmittalItemPolicy::class,
            Template::class => TemplatePolicy::class,

            // --- Phase 4: engineering delivery ---------------------------------------
            // Five registers governed by five permission modules the catalogue already declares.
            // DrawingPolicy is the one worth reading: a register line points at a document, and
            // opening that document asks the DOCUMENT's policy as well, so the register cannot be
            // used as a way round document confidentiality.
            Deliverable::class => DeliverablePolicy::class,
            Drawing::class => DrawingPolicy::class,
            Rfi::class => RfiPolicy::class,
            Submittal::class => SubmittalPolicy::class,
            SubmittalItem::class => SubmittalItemPolicy::class,
            ChangeRequest::class => ChangeRequestPolicy::class,
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

            // --- workflow ------------------------------------------------------------
            // The engine NEVER sends a notification itself. It dispatches events and these
            // listeners decide who hears about them, which is what lets the engine stay
            // module-agnostic: adding purchase requests to the workflow later needs no new
            // notification code at all (§78).
            ApprovalRequested::class => [
                NotifyApprovalAssignees::class,
            ],
            // The signature is written BEFORE the owner is told, so a failure to record the
            // evidence takes the whole decision down with it inside the engine's transaction
            // rather than leaving a notified approval nobody signed.
            ApprovalDecided::class => [
                RecordDocumentSignature::class,
                NotifyApprovalOutcome::class,
            ],

            // A stage that resolved to nobody halts the route rather than auto-approving. This
            // is the message that turns that halt into somebody's problem instead of a document
            // silently sitting still for a fortnight.
            WorkflowStalled::class => [
                NotifyWorkflowStalled::class,
            ],

            // --- documents -----------------------------------------------------------
            DocumentApproved::class => [
                NotifyDocumentApproved::class,
            ],
            DocumentRevisionRequested::class => [
                NotifyDocumentRevisionRequested::class,
            ],

            // Internal only — the addressee of a transmittal is usually outside this system
            // entirely, and has no account for the access engine to reason about.
            TransmittalIssued::class => [
                NotifyTransmittalIssued::class,
            ],

            // --- engineering ---------------------------------------------------------
            // Three moments, one shape: somebody has been waiting and the answer arrived.
            // Nothing is announced when a query is raised or a package is sent, because the
            // person on the other end of those is very often outside this system entirely —
            // the same reason a transmittal notifies only the issuing side.
            RfiAnswered::class => [
                NotifyRfiAnswered::class,
            ],
            SubmittalReviewed::class => [
                NotifySubmittalReviewed::class,
            ],
            ChangeRequestDecided::class => [
                NotifyChangeRequestDecided::class,
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
