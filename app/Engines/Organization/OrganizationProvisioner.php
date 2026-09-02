<?php

declare(strict_types=1);

namespace App\Engines\Organization;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\NumberGenerator;
use App\Models\DocumentCategory;
use App\Models\NumberingScheme;
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
                $this->seedProjectCatalogue($organization);

                // Order matters: the registers point at the approval route, so the route has to
                // exist first. A register created with a null workflow_id would be silently
                // unrouted, and nobody would find out until the first document was submitted.
                $workflow = $this->seedDocumentWorkflow($organization);
                $this->seedDocumentRegisters($organization, $workflow);
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
