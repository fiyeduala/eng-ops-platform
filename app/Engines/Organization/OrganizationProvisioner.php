<?php

declare(strict_types=1);

namespace App\Engines\Organization;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\NumberGenerator;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\DocumentCategory;
use App\Models\LeaveType;
use App\Models\NumberingScheme;
use App\Models\OptionList;
use App\Models\OptionListItem;
use App\Models\Organization;
use App\Models\ProjectRole;
use App\Models\ProjectType;
use App\Models\Role;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Support\Enums\Confidentiality;
use App\Support\RoleTemplates;
use App\Support\StatusCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything a new tenant needs before its first user logs in (sections 5, 74).
 *
 * A company that signs up must arrive at a working system, not an empty database — but every
 * single thing created here is editable. The role templates, the setting defaults and the unit
 * labels are opinions, not structure.
 */
class OrganizationProvisioner
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function provision(array $attributes): Organization
    {
        return DB::transaction(function () use ($attributes) {
            $organization = Organization::create([
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? Str::slug($attributes['name']),
                'legal_name' => $attributes['legal_name'] ?? $attributes['name'],
                'industry' => $attributes['industry'] ?? null,
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address' => $attributes['address'] ?? null,
                'country' => $attributes['country'] ?? null,
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'currency' => $attributes['currency'] ?? 'USD',
                'is_active' => true,
                'activated_at' => now(),
            ]);

            // Provisioning runs outside a request, so nothing has set the tenant yet. Everything
            // below creates tenant-scoped rows, which the global scope will attribute to whatever
            // context is current — so it has to be this organization, explicitly.
            $this->context->within($organization, function () use ($organization) {
                $this->seedRoles($organization);
                $this->seedSettings($organization);
                $this->seedStatuses($organization);
                $this->seedNumberingSchemes($organization);
                $this->seedOptionLists($organization);
                $this->seedProjectCatalogue($organization);

                // Order matters: the registers point at the approval route, so the route has to
                // exist first. A register created with a null workflow_id would be silently
                // unrouted, and nobody would find out until the first document was submitted.
                $workflow = $this->seedDocumentWorkflow($organization);
                $this->seedDocumentRegisters($organization, $workflow);

                $this->seedLeaveWorkflow($organization);
                $this->seedLeaveTypes($organization);
                $this->seedTimesheetWorkflow($organization);
                $this->seedOvertimeWorkflow($organization);
                $this->seedVacancyWorkflow($organization);
                $this->seedChecklistTemplates($organization);
                $this->seedPerformanceReviewWorkflow($organization);
                $this->seedEmployeeMovementWorkflow($organization);
                $this->seedPayrollWorkflow($organization);
            });

            return $organization->refresh();
        });
    }

    /**
     * Create the starter roles. Idempotent: re-running adds newly published templates without
     * overwriting grants an administrator has since edited.
     *
     * @return array<string,Role> slug => role
     */
    public function seedRoles(Organization $organization, bool $overwriteExisting = false): array
    {
        $roles = [];

        foreach (RoleTemplates::all() as $slug => $template) {
            $existing = Role::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('slug', $slug)
                ->first();

            if ($existing !== null && ! $overwriteExisting) {
                $roles[$slug] = $existing;

                continue;
            }

            $role = $existing ?? new Role;
            $role->organization_id = $organization->id;
            $role->slug = $slug;
            $role->name = $template['name'];
            $role->description = $template['description'];
            $role->level = $template['level'];
            // is_system stays false on every template: §5 requires that an administrator can
            // rename, re-scope or delete any of them.
            $role->is_system = false;
            $role->is_active = true;
            $role->save();

            $role->syncGrants(RoleTemplates::expand($template['grants']));

            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * Behavioural defaults. Every one of these is a decision a company might make differently,
     * which is exactly why none of them is a constant in code.
     */
    public function seedSettings(Organization $organization): void
    {
        $now = now();

        $defaults = [
            // Short code used by numbering patterns that lead with the company, e.g. MER/ENG/26/007.
            // Blank by default: an empty token collapses cleanly, whereas a guess derived from the
            // legal name would end up printed on a drawing.
            'org.short_code' => '',

            // --- structure labels: a company may call a Division a "Directorate" -----------
            'org.label.division' => 'Division',
            'org.label.department' => 'Department',
            'org.label.section' => 'Section',
            'org.label.team' => 'Team',

            // --- working pattern ---------------------------------------------------------
            'work.week_starts_on' => 'monday',
            'work.working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'work.day_start' => '08:00',
            'work.day_end' => '17:00',
            'work.hours_per_day' => 8,

            // --- attendance and timesheets ----------------------------------------------
            'attendance.capture_location' => false,   // opt-in: a phone's position is personal data
            'timesheet.max_hours_per_day' => 24,

            // --- workflow behaviour ------------------------------------------------------
            'workflow.escalate_after_days' => 3,
            'workflow.reminder_after_days' => 1,
            'workflow.allow_delegation' => true,
            'workflow.auto_approve_on_timeout' => false,   // never default to approving silently
            'workflow.require_rejection_reason' => true,

            // --- documents ---------------------------------------------------------------
            'document.numbering_format' => '{PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}',
            'document.revision_scheme' => 'alpha',          // alpha (A,B,C) | numeric (1,2,3)
            'document.require_approval_before_issue' => true,
            'document.max_upload_mb' => 25,
            'document.retain_superseded' => true,

            // --- engineering registers ---------------------------------------------------
            // Blank lists on purpose: OptionList falls back to the model constants, so a new
            // company gets a sensible vocabulary without a settings row that pretends somebody
            // chose it. The moment an administrator edits one, the setting starts to exist.
            'engineering.deliverable_types' => '',
            'engineering.drawing_purposes' => '',
            'engineering.submittal_types' => '',
            'engineering.submittal_review_codes' => '',
            'engineering.rfi_response_days' => 14,
            'engineering.submittal_review_days' => 14,

            // --- HSE registers -----------------------------------------------------------
            // Blank for the same reason as the engineering lists: an unset vocabulary falls back
            // to the model constants, and the setting starts existing the moment a safety manager
            // decides their company says something different. The numbers are not lists and do
            // need a value, because a blank warning window would mean "warn about nothing".
            'hse_incident.categories' => '',
            'hse_incident.severities' => '',
            'hse_incident.involvements' => '',
            'hse_incident.treatments' => '',
            'investigation.methods' => '',
            'corrective_action.types' => '',
            'corrective_action.priorities' => '',
            'corrective_action.effectiveness' => '',
            'hse_observation.types' => '',
            'hse_observation.categories' => '',
            'hse_observation.risk_levels' => '',
            'risk_assessment.types' => '',
            'risk_assessment.default_validity_months' => 12,
            'hse_inspection.types' => '',
            'hse_inspection.results' => '',
            'training_course.categories' => '',
            'hse_training.results' => '',
            'hse_training.expiry_warning_days' => 60,
            'permit.expiry_warning_hours' => 4,

            // --- quality registers -------------------------------------------------------
            'quality_inspection.types' => '',
            'quality_inspection.results' => '',
            'quality_inspection.item_results' => '',
            'quality_inspection.notice_days' => 2,
            'ncr.severities' => '',
            'ncr.categories' => '',
            'ncr.sources' => '',
            'ncr.dispositions' => '',
            'ncr.response_days' => 14,

            // --- commercial registers ----------------------------------------------------
            // Blank means "use the constants on the model". A company that has never opened
            // Settings still gets a usable list of vendor categories; a company that has
            // opened it once owns the list from then on and we never overwrite their words.
            'vendor.types' => '',
            'vendor.categories' => '',
            'vendor.prequalification_warning_days' => 60,
            'client.types' => '',
            'client.industries' => '',
            'contract.types' => '',
            'contract.default_retention_percent' => '5',
            'contract.expiry_warning_days' => 90,

            // --- procurement -------------------------------------------------------------
            // Both separations default to ON. A company that needs to turn one off can, and the
            // override is recorded where it is used — but the safe posture must be the one a
            // company gets without knowing the setting exists.
            'procurement.rfq_required_above' => '',
            'procurement.minimum_quotations' => 3,
            'procurement.separate_requester_and_approver' => true,
            'procurement.separate_buyer_and_receiver' => true,
            'procurement.evaluation_criteria' => '',
            'procurement.score_out_of' => 10,
            'purchase_request.priorities' => '',
            'purchase_order.payment_terms' => '30 days from invoice',
            'goods_receipt.conditions' => '',

            // --- inventory ---------------------------------------------------------------
            // The same posture as procurement above: both separations on, because the company that
            // most needs them is the company that has never heard of them. `reserve_on_approval` is
            // on for a different reason — a store that approves without reserving will promise the
            // same forty metres of cable to three sites and be right every time it checks.
            'inventory.separate_approver_and_issuer' => true,
            'inventory.separate_counter_and_adjuster' => true,
            'inventory.reserve_on_approval' => true,
            'inventory.notify_below_reorder_level' => true,
            'stock_item.categories' => '',
            'warehouse.types' => '',
            'material_request.priorities' => '',

            // --- assets & fleet ----------------------------------------------------------
            // The authoriser requirement defaults ON for the same reason the two separations above
            // do: the company that most needs it is the company that has never considered it. The
            // odometer requirement defaults on because a fuel log without a reading can never
            // contribute to a consumption figure — it is a receipt, not a record.
            'asset.require_authoriser_on_transfer' => true,
            'asset.maintenance_warning_days' => 30,
            'asset.categories' => '',
            'asset_maintenance.types' => '',
            'vehicle.types' => '',
            'vehicle.fuel_types' => '',
            'vehicle_document.types' => '',
            // Wider than it feels, and deliberately so: an insurance renewal takes weeks in most
            // places, and a warning that arrives with a fortnight left is a warning about a vehicle
            // that is going to be off the road.
            'vehicle_document.expiry_warning_days' => 60,
            'fuel_log.odometer_required' => true,

            // --- finance -----------------------------------------------------------------
            // Both separations on, for the reason procurement's are. No tax code is provisioned:
            // which taxes exist and at what rate is the company's to say, not ours (§47).
            'finance.separate_claimant_and_approver' => true,
            'finance.separate_payment_approver_and_payer' => true,
            'expense.categories' => '',
            'income.categories' => '',
            'payment.methods' => '',
            'petty_cash.single_disbursement_limit' => '',

            // --- the visitor book --------------------------------------------------------
            // Every one of these is a company rule, which is why none of them is a constant (§47).
            // The escort default is ON for contractors and OFF for everybody else, which is the
            // posture almost every site actually operates: a supplier's fitter walking into a plant
            // room unaccompanied is the thing site inductions exist to prevent, and a client
            // arriving for a meeting is not.
            'visitor.require_host' => true,
            'visitor.contractor_requires_escort' => true,
            'visitor.capture_id_document' => false,
            // Zero means an induction never lapses. 365 is a common site rule rather than a correct
            // one, and it is here to be changed.
            'visitor.induction_validity_days' => 365,
            // The end-of-day sweep. It exists because badges do not get handed back, and a roll call
            // that still shows forty people on site at three in the morning is a roll call nobody
            // will trust at nine. What it writes is marked `auto_closed`, so a departure somebody
            // witnessed is never confused with one the clock assumed.
            'visitor.auto_close_enabled' => true,
            'visitor.auto_close_at' => '23:00',
            'visitor.purpose_types' => '',

            // --- notifications -----------------------------------------------------------
            'notify.channels' => ['database', 'mail'],
            'notify.digest_hour' => 7,
            'notify.escalation_to_supervisor' => true,

            // --- executive reporting -----------------------------------------------------
            'report.daily_executive_hour' => 7,
            'report.daily_executive_enabled' => true,

            // --- security ----------------------------------------------------------------
            'security.password_min_length' => 12,
            'security.password_expiry_days' => 0,           // 0 = never; forced rotation is
            'security.session_idle_minutes' => 120,         // widely counter-productive
            'security.max_login_attempts' => 5,
            'security.lockout_minutes' => 15,
            'security.require_two_factor_for_sensitive' => false,
        ];

        $rows = [];

        foreach ($defaults as $key => $value) {
            $rows[] = [
                'organization_id' => $organization->id,
                'key' => $key,
                'value' => json_encode($value),
                'group' => explode('.', $key)[0],
                'type' => match (true) {
                    is_bool($value) => 'boolean',
                    is_int($value) => 'integer',
                    is_array($value) => 'array',
                    default => 'string',
                },
                'is_editable' => true,
                'is_encrypted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('settings')->upsert($rows, ['organization_id', 'key'], ['updated_at']);
    }

    /**
     * Starting status vocabularies and the moves between them (§63).
     *
     * Idempotent, and non-destructive in both directions: a status the company has renamed keeps
     * its label, and a transition the company deleted stays deleted. Re-running this must never
     * quietly restore a move an administrator deliberately removed.
     */
    public function seedStatuses(Organization $organization): void
    {
        foreach (StatusCatalogue::all() as $entityType => $definition) {
            $ids = [];
            $sort = 0;

            foreach ($definition['statuses'] as $key => $status) {
                $existing = Status::query()
                    ->where('entity_type', $entityType)
                    ->where('key', $key)
                    ->first();

                if ($existing !== null) {
                    $ids[$key] = $existing->id;
                    $sort += 10;

                    continue;
                }

                $ids[$key] = Status::create([
                    'organization_id' => $organization->id,
                    'entity_type' => $entityType,
                    'key' => $key,
                    'label' => $status['label'],
                    'color' => $status['color'],
                    'sort' => $sort += 10,
                    'is_initial' => $status['initial'] ?? false,
                    'is_terminal' => $status['terminal'] ?? false,
                    'is_active' => true,
                    // It came from the catalogue, so service code may move records into it by key.
                    // The configuration editor lets it be relabelled but not rekeyed, deleted or
                    // deactivated — see the migration that added this column.
                    'is_system' => true,
                ])->id;
            }

            $order = 0;

            foreach ($definition['transitions'] as $transition) {
                [$from, $to, $label, $permission] = $transition;
                $order += 10;

                // A transition naming a status this tenant has since deleted is skipped rather
                // than created against a dangling id.
                if (! isset($ids[$to]) || ($from !== null && ! isset($ids[$from]))) {
                    continue;
                }

                $fromId = $from === null ? null : $ids[$from];

                $exists = StatusTransition::query()
                    ->where('entity_type', $entityType)
                    ->where('to_status_id', $ids[$to])
                    ->where(fn ($q) => $fromId === null
                        ? $q->whereNull('from_status_id')
                        : $q->where('from_status_id', $fromId))
                    ->exists();

                if ($exists) {
                    continue;
                }

                StatusTransition::create([
                    'organization_id' => $organization->id,
                    'entity_type' => $entityType,
                    'from_status_id' => $fromId,
                    'to_status_id' => $ids[$to],
                    'label' => $label,
                    'permission_key' => $permission,
                    'requires_comment' => $transition[4] ?? false,
                    'sort' => $order,
                ]);
            }
        }
    }

    /**
     * A numbering scheme per module (§64).
     *
     * NumberGenerator provisions one on demand if it finds none, so this is a convenience rather
     * than a requirement — but it means the Phase 7 numbering screen shows the company its own
     * patterns on the first visit, instead of an empty page and numbers appearing from nowhere.
     */
    public function seedNumberingSchemes(Organization $organization): void
    {
        foreach (NumberGenerator::defaults() as $entityType => $default) {
            $exists = NumberingScheme::query()->where('entity_type', $entityType)->exists();

            if ($exists) {
                continue;
            }

            NumberingScheme::create([
                'organization_id' => $organization->id,
                'entity_type' => $entityType,
                'label' => Str::headline($entityType).' numbering',
                'prefix' => $default['prefix'],
                'pattern' => $default['pattern'],
                'reset_period' => $default['reset_period'],
                'padding' => $default['padding'],
                'start_at' => 1,
                'is_active' => true,
            ]);
        }
    }

    /**
     * A starter vocabulary for the things this platform has no opinion about (§63, §73).
     *
     * NOT MARKED `is_system`, and that is the decision. A system list is one whose key CODE names
     * as a literal, and nothing in this application names a discipline — so a company that works
     * only in marine civils must be free to delete the mechanical entries, or the whole list. These
     * are a starting point, not a schema.
     *
     * Disciplines earn their place because `document.numbering_format` ships with {DISCIPLINE} in
     * it: without a list to draw from, that token is a promise the platform cannot keep on the
     * first day. The rest is what a multidiscipline engineering firm actually uses, which is the
     * §92 standard — realistic and fictional, never a row called "Option 1".
     */
    public function seedOptionLists(Organization $organization): void
    {
        $lists = [
            'disciplines' => [
                'name' => 'Disciplines',
                'description' => 'The engineering disciplines your work is divided between.',
                'items' => [
                    'civil' => 'Civil', 'structural' => 'Structural', 'mechanical' => 'Mechanical',
                    'electrical' => 'Electrical', 'instrumentation' => 'Instrumentation',
                    'process' => 'Process', 'architectural' => 'Architectural',
                    'geotechnical' => 'Geotechnical', 'multidiscipline' => 'Multidiscipline',
                ],
            ],
            'document_classes' => [
                'name' => 'Document classes',
                'description' => 'What kind of document this is, in the words your clients use.',
                'items' => [
                    'drawing' => 'Drawing', 'specification' => 'Specification',
                    'calculation' => 'Calculation', 'report' => 'Report',
                    'datasheet' => 'Datasheet', 'procedure' => 'Procedure',
                    'schedule' => 'Schedule', 'model' => 'Model',
                ],
            ],
        ];

        foreach ($lists as $key => $definition) {
            if (OptionList::query()->where('key', $key)->exists()) {
                continue;
            }

            $list = OptionList::create([
                'organization_id' => $organization->id,
                'key' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => false,
                'is_active' => true,
            ]);

            $sort = 0;

            foreach ($definition['items'] as $value => $label) {
                OptionListItem::create([
                    'organization_id' => $organization->id,
                    'option_list_id' => $list->id,
                    'value' => $value,
                    'label' => $label,
                    'sort' => $sort++,
                    'is_active' => true,
                ]);
            }
        }
    }

    /**
     * Project types and project roles (§13, §14).
     *
     * `project_roles` is what makes a person a Technical Lead on one project and an ordinary
     * engineer on another without touching their personnel record — so a starter catalogue is
     * seeded, and every entry in it is editable.
     */
    public function seedProjectCatalogue(Organization $organization): void
    {
        $types = [
            'EPC' => 'Engineering, procurement and construction',
            'Design' => 'Design and engineering services only',
            'Construction' => 'Construction and installation',
            'Maintenance' => 'Maintenance, shutdown and turnaround',
            'Consultancy' => 'Advisory and studies',
            'Internal' => 'Internal initiative, not client-facing',
        ];

        foreach ($types as $name => $description) {
            ProjectType::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'description' => $description, 'is_active' => true],
            );
        }

        // `is_lead` marks the roles a workflow rule of type `project_role` may resolve to when it
        // says "the lead of this project" — configuration, not a hard-coded title check.
        $roles = [
            ['Project Manager', true],
            ['Project Director', true],
            ['Technical Lead', true],
            ['Discipline Lead', true],
            ['Engineer', false],
            ['Designer', false],
            ['Site Supervisor', false],
            ['HSE Officer', false],
            ['QA/QC Officer', false],
            ['Procurement Officer', false],
            ['Cost Controller', false],
            ['Planner', false],
            ['Document Controller', false],
            ['Client Representative', false],
            ['Team Member', false],
        ];

        $sort = 0;

        foreach ($roles as [$name, $isLead]) {
            ProjectRole::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'is_lead' => $isLead, 'sort' => $sort += 10, 'is_active' => true],
            );
        }
    }

    /**
     * The starter approval route for documents (§26, §31).
     *
     * PUBLISHED, not drafted. A new tenant whose only workflow is a draft would submit its first
     * document into nothing at all — the register would point at a route that refuses to pick up
     * live records, and the author would see a document sitting in "submitted" with no approver and
     * no explanation. A working system on day one means a published route on day one.
     *
     * The shape is the ordinary two-step every document control procedure describes: somebody
     * technically responsible checks it, somebody accountable approves it. Both stages are entirely
     * editable, and so is the fact that there are two of them — this is an opinion about what most
     * companies do, not structure.
     *
     * STAGE ONE RESOLVES TO THE HEAD OF THE DOCUMENT'S OWN UNIT rather than to a named role,
     * because that is the one assignment that is correct before anybody has been given a role. A
     * seeded route pointing at a role nobody holds resolves to nobody and stalls, which is a poor
     * way for a new tenant to learn how the workflow engine works.
     */
    public function seedDocumentWorkflow(Organization $organization): ?Workflow
    {
        $existing = Workflow::query()
            ->where('entity_type', 'document')
            ->where('name', 'Document approval')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $workflow = Workflow::create([
            'organization_id' => $organization->id,
            'name' => 'Document approval',
            'description' => 'The default route a document takes when it is submitted: a technical '
                .'check by the head of the originating unit, then approval. Edit it, add stages, or '
                .'replace it entirely — nothing here is fixed.',
            'entity_type' => 'document',
            'trigger' => 'submitted',
            'version' => 1,
            'is_default' => true,
            'is_active' => true,
            'published_at' => now(),
        ]);

        WorkflowStage::create([
            'organization_id' => $organization->id,
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Technical review',
            'instructions' => 'Check the content is technically correct and complete before it goes '
                .'for approval. Returning it sends it back to the author with your comment.',
            'assignee_type' => 'org_unit_head',
            'assignee_ref' => null,
            'action_type' => 'review',
            'quorum' => WorkflowStage::QUORUM_ANY,
            'sla_hours' => 24,
            'on_reject' => WorkflowStage::ON_REJECT_OWNER,
            'allow_delegate' => true,
            'allow_self_approval' => false,
            'is_active' => true,
        ]);

        /*
         * The role is looked up rather than assumed, and its ABSENCE changes the stage rather than
         * being written as an empty reference. `assignee_ref` is resolved with whereKey() at
         * runtime, so a blank or stale id resolves to nobody — a route that saves cleanly, passes
         * every check, and then stalls the first real document days later with no visible cause.
         * Falling back to the unit head keeps the seeded route working on a tenant whose roles were
         * customised before its first document.
         */
        $approverRole = Role::query()->where('slug', 'engineering-manager')->value('id');

        WorkflowStage::create([
            'organization_id' => $organization->id,
            'workflow_id' => $workflow->id,
            'sequence' => 2,
            'name' => 'Approval',
            'instructions' => 'Approving issues the revision. After that it is read only for '
                .'everybody, and a change means a new revision.',
            'assignee_type' => $approverRole !== null ? 'role' : 'org_unit_head',
            'assignee_ref' => $approverRole !== null ? (string) $approverRole : null,
            'action_type' => 'approve',
            'quorum' => WorkflowStage::QUORUM_ANY,
            'sla_hours' => 48,
            'on_reject' => WorkflowStage::ON_REJECT_OWNER,
            'allow_delegate' => true,
            'allow_self_approval' => false,
            'is_active' => true,
        ]);

        return $workflow;
    }

    /**
     * The default leave route: one stage, the requester's line manager.
     *
     * THE SAME ENGINE AS A DOCUMENT (Phase 6's exit criterion). This is an ordinary workflow row an
     * administrator edits in the ordinary workflow builder — add an HR stage, a condition on
     * `days`, a second approver for long absences — and nothing about leave changes in code.
     */
    public function seedLeaveWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'leave_request',
            'Leave approval',
            'The route a leave request takes when it is submitted: the requester\'s line manager decides. '
                .'Add stages or conditions to suit how the company approves leave.',
            'Check the dates work for the team. Returning the request sends it back to the requester to '
                .'change; rejecting it closes it.',
        );
    }

    /** The default timesheet route — the same single line-manager stage, edited in the same builder. */
    public function seedTimesheetWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'timesheet',
            'Timesheet approval',
            'The route a weekly timesheet takes when it is submitted: the employee\'s line manager confirms '
                .'the hours. Add a project manager stage or a condition on `total_hours` if the company needs one.',
            'Check the hours and projects match the week. Return the sheet to have it corrected; reject it '
                .'only if it should not be paid at all.',
        );
    }

    /** The default overtime route. Asked for before it is worked, so the SLA is short. */
    public function seedOvertimeWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'overtime_request',
            'Overtime approval',
            'The route an overtime request takes when it is submitted: the employee\'s line manager decides '
                .'whether the extra hours are needed.',
            'Approve only overtime the work needs. The hours actually worked are recorded against the '
                .'request afterwards and cannot exceed what you approve.',
            slaHours: 24,
        );
    }

    /**
     * One published route with a single supervisor stage, created only if the company has none by
     * that name — so re-running provisioning never undoes an administrator's edits.
     */
    private function seedLineManagerWorkflow(
        Organization $organization,
        string $entityType,
        string $name,
        string $description,
        string $instructions,
        int $slaHours = 48,
    ): ?Workflow {
        $existing = Workflow::query()
            ->where('entity_type', $entityType)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $workflow = Workflow::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'description' => $description,
            'entity_type' => $entityType,
            'trigger' => 'submitted',
            'version' => 1,
            'is_default' => true,
            'is_active' => true,
            'published_at' => now(),
        ]);

        WorkflowStage::create([
            'organization_id' => $organization->id,
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Line manager',
            'instructions' => $instructions,
            'assignee_type' => 'supervisor',
            'assignee_ref' => null,
            'action_type' => 'approve',
            'quorum' => WorkflowStage::QUORUM_ANY,
            'sla_hours' => $slaHours,
            'on_reject' => WorkflowStage::ON_REJECT_TERMINATE,
            'allow_delegate' => true,
            'allow_self_approval' => false,
            'is_active' => true,
        ]);

        return $workflow;
    }

    /**
     * Starter leave types. Opinions, not entitlements (§47): every number here is a row HR edits.
     */
    /**
     * The default vacancy route.
     *
     * A requisition is a spending decision taken before anybody is interviewed, so it is routed like
     * everything else. The SLA is long on purpose: a head being agreed is rarely urgent, and a
     * 24-hour clock on it would fill the overdue report with approvals nobody is late on.
     */
    public function seedVacancyWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'vacancy',
            'Vacancy approval',
            'The route a vacancy takes when it is submitted: the requester\'s line manager agrees to the '
                .'head. Add a finance or executive stage, or a condition on `openings`, if the company '
                .'wants larger requests decided higher up.',
            'Approving this opens the vacancy for applications and commits the company to the salary. '
                .'Returning it sends it back to be changed; rejecting it closes the request.',
            slaHours: 120,
        );
    }

    /**
     * The default performance review route.
     *
     * SIGN-OFF IS AN APPROVAL LIKE ANY OTHER, which is the whole point of putting it here. A finished
     * appraisal goes to the reviewer's own line manager — the grandparent of the person being
     * reviewed — because a rating nobody above the reviewer ever looks at is how inconsistent
     * marking survives a whole company. The stage is `supervisor` and the record's requester is the
     * reviewer, so the engine resolves exactly that person with no special case.
     *
     * The SLA is long because sign-off queues behind a busy manager's own reviews, and a 48-hour
     * clock during appraisal season would put the entire round on the overdue report.
     */
    public function seedPerformanceReviewWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'performance_review',
            'Performance review sign-off',
            'The route a completed appraisal takes: the reviewer\'s own manager signs off the rating before '
                .'it goes back to the employee. Add an HR stage, or a condition on `overall_rating` so only '
                .'the highest and lowest marks are checked, if the company wants that.',
            'Check the rating is supported by what is written and is consistent with the rest of your area. '
                .'Returning it sends it back to the reviewer with the self-assessment intact; rejecting it '
                .'closes the review unsigned.',
            slaHours: 120,
        );
    }

    /**
     * The default movement route.
     *
     * A promotion or transfer changes the org chart, so it is decided before it is applied and never
     * the other way round. Approval does NOT write the new position on the day it is approved — the
     * record waits for its effective date and the nightly sweep applies it then. That separation is
     * why the SLA can be generous: a June transfer approved in April is not late.
     */
    public function seedEmployeeMovementWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'employee_movement',
            'Movement approval',
            'The route a promotion, transfer or secondment takes when it is submitted: the requester\'s line '
                .'manager agrees to the change. Add an HR or executive stage, or a condition on `kind`, if '
                .'promotions should be decided higher up than transfers.',
            'Approving agrees the change. It is written onto the personnel record on its effective date, not '
                .'today, so check the date as carefully as the position.',
            slaHours: 120,
        );
    }

    /**
     * The default payroll route.
     *
     * THIS IS THE ONE THAT PROVES THE POINT. Payroll is the register a platform is most tempted to
     * give its own approve button — it is money, it is monthly, and it feels special. It gets the
     * same route as everything else: submitted, assigned, decided at `approvals.decide`. There is no
     * `payroll-runs.approve`, and a company that wants two signatures on the wage bill adds a stage
     * here rather than waiting for us to build one.
     *
     * The assignee is the submitter's own line manager, which in the small company this platform is
     * built for is the person who signs the payments anyway. A company that wants finance and then
     * the board adds those stages; the engine does not care how many there are.
     *
     * The SLA is short. Unlike an appraisal, a payroll approval has a deadline nobody sets and
     * everybody knows: pay day. A run sitting unapproved is the one overdue approval that should
     * shout.
     */
    public function seedPayrollWorkflow(Organization $organization): ?Workflow
    {
        return $this->seedLineManagerWorkflow(
            $organization,
            'payroll_run',
            'Payroll approval',
            'The route a calculated payroll run takes before anybody is paid. Add a finance stage, a second '
                .'signature, or a condition on the net total if larger runs should be agreed higher up.',
            'Check the totals against the month you expected, and check the skipped payslips — each one is '
                .'somebody who will not be paid. Approving locks the run: nothing in it can be edited '
                .'afterwards, and a mistake found later is corrected by a new run rather than by changing '
                .'this one.',
            slaHours: 24,
        );
    }

    /**
     * Starting joining and leaving lists — OPINIONS, owned by the company from the first edit (§47).
     *
     * Deliberately short and deliberately generic. A construction company will add boots, a site
     * induction and a medical; a consultancy will add a mailbox and a client confidentiality form,
     * and neither list belongs in the application. What the platform provides is a list that is not
     * empty on day one, because an empty Settings screen teaches people the feature does not work.
     *
     * The exit list is the one that matters: "return the laptop" is a step somebody ticks, while
     * whether the laptop actually came back is asked of the asset register by
     * EmployeeExitService::blockers(). The list is the conversation; the register is the answer.
     */
    public function seedChecklistTemplates(Organization $organization): void
    {
        $templates = [
            ['onboarding', 'Standard onboarding', 'What a new starter needs in their first week.', [
                ['Sign and return the employment contract', true],
                ['Collect identity documents and qualifications', true],
                ['Create the sign-in and assign a role', false],
                ['Issue equipment and record it in the asset register', true],
                ['Health, safety and site induction', true],
                ['Introduce the line manager and the team', false],
                ['Agree the first objectives', false],
            ]],
            ['exit', 'Standard exit', 'What has to happen before somebody\'s last day.', [
                ['Acknowledge the resignation or notice in writing', false],
                ['Hand over live work and files', true],
                ['Return all company equipment', true],
                ['Return keys, passes and fuel cards', true],
                ['Settle outstanding expenses and advances', true],
                ['Exit interview', false],
                ['Confirm the final address and bank details for the last payment', false],
            ]],
        ];

        foreach ($templates as [$kind, $name, $description, $items]) {
            if (ChecklistTemplate::withTrashed()->where('organization_id', $organization->id)->where('name', $name)->exists()) {
                continue;
            }

            $template = ChecklistTemplate::create([
                'organization_id' => $organization->id,
                'kind' => $kind,
                'name' => $name,
                'description' => $description,
                'is_default' => true,
                'is_active' => true,
            ]);

            foreach ($items as $sequence => [$label, $requiresNote]) {
                ChecklistTemplateItem::create([
                    'organization_id' => $organization->id,
                    'checklist_template_id' => $template->id,
                    'label' => $label,
                    'sequence' => $sequence,
                    'requires_note' => $requiresNote,
                ]);
            }
        }
    }

    public function seedLeaveTypes(Organization $organization): void
    {
        $types = [
            ['ANNUAL', 'Annual leave', '20.00', '5.00', null, 0, true],
            ['SICK', 'Sick leave', '10.00', '0.00', null, 0, true],
            ['UNPAID', 'Unpaid leave', null, '0.00', null, 0, false],
        ];

        foreach ($types as $sort => [$code, $name, $days, $carry, $max, $notice, $paid]) {
            if (LeaveType::withTrashed()->where('code', $code)->exists()) {
                continue;
            }

            LeaveType::create([
                'organization_id' => $organization->id,
                'code' => $code,
                'name' => $name,
                'days_per_year' => $days,
                'carry_over_limit' => $carry,
                'max_consecutive_days' => $max,
                'min_notice_days' => $notice,
                'is_paid' => $paid,
                'requires_approval' => true,
                'allow_half_days' => true,
                'is_active' => true,
                'sort' => $sort,
            ]);
        }
    }

    /**
     * The starter document registers (§27, §28).
     *
     * Six, chosen because they are the ones an engineering organization has on its first day and
     * would otherwise create by hand in the same shape. The differences between them are the point:
     * drawings revise in letters with a P-series for drafts and hold uploaded CAD files, procedures
     * revise in numbers and are written in the editor, and correspondence received is uncontrolled
     * and approved by nobody — because nobody approves an incoming letter.
     *
     * `allowed_extensions` NARROWS, and only where narrowing is meaningful. Left null, the register
     * inherits the organization-wide list; FileVault treats null as "use the default", never as
     * "allow anything", and refuses executables whatever any of this says (§67).
     */
    public function seedDocumentRegisters(Organization $organization, ?Workflow $workflow = null): void
    {
        $routed = $workflow?->getKey();

        $registers = [
            [
                'name' => 'Drawings',
                'code' => 'DWG',
                'description' => 'Design and construction drawings. Revisions run in letters, with a '
                    .'P-series for drafts circulated before the first issue.',
                'revision_scheme' => 'alpha',
                'draft_revision_prefix' => 'P',
                'default_kind' => 'file',
                'allowed_extensions' => ['pdf', 'dwg', 'dxf', 'dwf', 'rvt', 'ifc'],
                'requires_approval' => true,
                'is_controlled' => true,
            ],
            [
                'name' => 'Specifications',
                'code' => 'SPEC',
                'description' => 'Technical specifications and datasheets.',
                'revision_scheme' => 'alpha',
                'draft_revision_prefix' => 'P',
                'default_kind' => 'native',
                'allowed_extensions' => null,
                'requires_approval' => true,
                'is_controlled' => true,
            ],
            [
                'name' => 'Procedures',
                'code' => 'PROC',
                'description' => 'Management system procedures, work instructions and forms. '
                    .'Numbered revisions, because that is what most quality manuals reference.',
                'revision_scheme' => 'numeric',
                'draft_revision_prefix' => null,
                'default_kind' => 'native',
                'allowed_extensions' => null,
                'requires_approval' => true,
                'is_controlled' => true,
            ],
            [
                'name' => 'Method statements',
                'code' => 'MS',
                'description' => 'Method statements and risk assessments issued for site work.',
                'revision_scheme' => 'alpha',
                'draft_revision_prefix' => 'P',
                'default_kind' => 'native',
                'allowed_extensions' => null,
                'requires_approval' => true,
                'is_controlled' => true,
            ],
            [
                'name' => 'Correspondence received',
                'code' => 'CORR',
                'description' => 'Letters, notices and emails received from clients, consultants and '
                    .'authorities. Filed, never approved — nobody approves an incoming letter.',
                'revision_scheme' => 'numeric',
                'draft_revision_prefix' => null,
                'default_kind' => 'file',
                'allowed_extensions' => ['pdf', 'msg', 'eml', 'doc', 'docx', 'jpg', 'png'],
                'requires_approval' => false,
                'is_controlled' => false,
            ],
            [
                'name' => 'Vendor documents',
                'code' => 'VEN',
                'description' => 'Documents supplied by vendors and subcontractors. Held as received, '
                    .'so they are uncontrolled here even when the vendor controls them at their end.',
                'revision_scheme' => 'alpha',
                'draft_revision_prefix' => null,
                'default_kind' => 'file',
                'allowed_extensions' => null,
                'requires_approval' => false,
                'is_controlled' => false,
            ],
        ];

        $sort = 0;

        foreach ($registers as $register) {
            DocumentCategory::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'slug' => Str::slug($register['name'])],
                [
                    'name' => $register['name'],
                    'code' => $register['code'],
                    'description' => $register['description'],
                    'revision_scheme' => $register['revision_scheme'],
                    'draft_revision_prefix' => $register['draft_revision_prefix'],
                    'allowed_extensions' => $register['allowed_extensions'],
                    'default_confidentiality' => Confidentiality::Internal->value,
                    'default_kind' => $register['default_kind'],
                    // Only routed registers get the workflow. A register that approves nothing must
                    // not carry a route, or "requires approval: no" and "route: Document approval"
                    // would both be true on the same screen and one of them would be a lie.
                    'workflow_id' => $register['requires_approval'] ? $routed : null,
                    'is_controlled' => $register['is_controlled'],
                    'requires_approval' => $register['requires_approval'],
                    'sort' => $sort += 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
