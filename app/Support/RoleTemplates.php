<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Enums\AccessScope;

/**
 * Starting-point roles handed to every new organization (docs/06-rbac-matrix.md §3).
 *
 * These are EXAMPLES, not fixtures. Every one is created with is_system = false so an
 * administrator may rename it, re-scope its grants, or delete it outright — because §5 requires
 * that a company with an org chart nothing like this one can still model itself exactly.
 *
 * Grant syntax: 'permission-pattern' => 'scope'
 *   'document.approve'  exact key
 *   'document.*'        every action on one module
 *   '*.view'            one action across every module
 * Patterns expand against PermissionCatalogue, so a role can never grant a permission that does
 * not exist, and a new module's permissions do not silently join an existing role.
 *
 * Read the deliberate ABSENCES as carefully as the grants. No role below holds everything: the
 * Managing Director cannot run payroll or change system settings, the Office Administrator has no
 * finance reach, and both "administrator" roles are pinned to a single unit or project (§10).
 */
final class RoleTemplates
{
    /**
     * Grants every starter role begins with.
     *
     * Rationale: an Engineer who holds engineering permissions but no `employee.view` cannot open
     * their own profile, see their own leave balance or find their own payslip — the resolver is
     * fail-closed, so "not granted" means "nothing", not "just their own". Rather than repeat
     * these lines in thirty-four templates and eventually forget one, every template starts here.
     *
     * Every grant is at `own` scope, so this widens nobody's reach beyond themselves. It is merged
     * with the LOSING side of the broader-scope rule in expand(), so a template that grants
     * employee.view at department scope keeps department scope.
     *
     * This is a starting point, not a floor. The grants land in ordinary editable rows, so an
     * administrator who genuinely wants a role with no self-service can remove them.
     *
     * @var array<string,string>
     */
    private const BASELINE = [
        'employee.view' => 'own',        // my own personnel record
        'user.view' => 'own',            // my own account and sign-in details
        'leave.view' => 'own',
        'leave.create' => 'own',
        'leave.submit' => 'own',
        'timesheet.view' => 'own',
        'timesheet.create' => 'own',
        'timesheet.submit' => 'own',
        'attendance.view' => 'own',
        'expense.view' => 'own',
        'expense.create' => 'own',
        'expense.submit' => 'own',
        'task.view' => 'assigned',       // tasks given to me
        'task.complete' => 'assigned',
        'announcement.view' => 'organization',

        // Communication addressed to me personally (§18, §19). At `assigned` scope, which for these
        // two modules means the distribution list and the participant list — so this grants sight of
        // memos somebody sent me and meetings somebody invited me to, and nothing else.
        //
        // Without these the modules are unusable for most of the company in a way that looks like a
        // bug rather than a policy: several templates below grant memo.create or meeting.manage but
        // no matching `.view`, which would let a site administrator write a memo and then be unable
        // to open it. A person who is sent a memo can plainly be shown it; a person who is expected
        // in a room can plainly be shown the appointment. The permissions that decide who ELSE sees
        // them — memo.issue, meeting.circulate — are deliberately absent.
        'memo.view' => 'assigned',
        'meeting.view' => 'assigned',

        'dashboard.view' => 'own',

        // Company reference data. These are labels the interface cannot render without: the org
        // chart behind "who do I report to", the site on my own attendance record, the job title
        // on a colleague's profile card, the name of a role someone holds. Reading them is
        // organization-wide because narrowing a label is meaningless — see
        // Scopeable::accessOrganizationWideAbilities(). The permissions that CHANGE any of them
        // (org_unit.edit, location.create, role.edit) are deliberately absent.
        'org_unit.view' => 'organization',
        'location.view' => 'organization',
        'position.view' => 'organization',
        'role.view' => 'organization',
        'status.view' => 'organization',   // the words "In progress" and "Blocked"
    ];

    /**
     * @return array<string,array{name:string,level:int,description:string,pin:?string,grants:array<string,string>}>
     */
    public static function all(): array
    {
        $templates = self::templates();

        foreach ($templates as $slug => $template) {
            // `+` keeps the LEFT side on duplicate keys, so the template's own grants must be on
            // the left: a role that already says `employee.view => department` must not be pulled
            // back to `own`. Where the collision is between a baseline key and a template PATTERN
            // (`*.view`), both survive to expand(), which resolves it to the broader scope.
            $templates[$slug]['grants'] = $template['grants'] + self::BASELINE;
        }

        return $templates;
    }

    /**
     * @return array<string,array{name:string,level:int,description:string,pin:?string,grants:array<string,string>}>
     */
    private static function templates(): array
    {
        return [
            // ============================================================ administration
            'system-administrator' => [
                'name' => 'System Administrator',
                'level' => 100,
                'description' => 'Configures the platform: structure, roles, workflows and settings. Deliberately holds no approval authority over business records.',
                'pin' => null,
                'grants' => [
                    'organization.*' => 'organization',
                    'org_unit.*' => 'organization',
                    'position.*' => 'organization',
                    'location.*' => 'organization',
                    'user.*' => 'organization',
                    'role.*' => 'organization',
                    'setting.*' => 'organization',
                    'custom_field.*' => 'organization',
                    'template.*' => 'organization',
                    'numbering.*' => 'organization',
                    'status.*' => 'organization',
                    'escalation.*' => 'organization',
                    'workflow.*' => 'organization',
                    'form.*' => 'organization',
                    'audit_log.*' => 'organization',
                    'employee.view' => 'organization',
                    'project.view' => 'organization',
                ],
            ],

            // ============================================================ executive
            'managing-director' => [
                'name' => 'Managing Director / CEO',
                'level' => 99,
                'description' => 'Whole-company visibility and final approval authority. Note the absence of setting.manage and payroll.run — visibility is not the same as administration.',
                'pin' => null,
                'grants' => [
                    '*.view' => 'organization',
                    '*.export' => 'organization',
                    '*.approve' => 'organization',
                    '*.reject' => 'organization',
                    'project.view_financials' => 'organization',
                    'project.view_confidential' => 'organization',
                    'contract.view_confidential' => 'organization',
                    // THE ONE BUSINESS REGISTER THE MD WRITES TO, and it earns the exception.
                    // In a company of eight there is no finance manager and no procurement
                    // manager — the two templates that otherwise carry these — and the person
                    // who signs the contract is the person who runs the company. Without this
                    // the contract register is unreachable for a whole size of organization,
                    // which is the opposite of §73. Approval authority is unchanged: `*.approve`
                    // above already carried it, and a bigger company simply grants finance the
                    // same keys and leaves the MD approving rather than drafting.
                    'contract.create' => 'organization',
                    'contract.edit' => 'organization',
                    'contract.manage' => 'organization',
                    'hse_incident.view_confidential' => 'organization',
                    'dashboard.view_executive' => 'organization',
                    'kpi.view' => 'organization',
                    'report.create' => 'organization',
                    'approval.act' => 'organization',
                    // `create` and `edit` travel together, here and in every template below that
                    // grants either. A memo's distribution list is part of the document, so
                    // MemoPolicy::address() asks `memo.edit` — and a memo cannot be issued with
                    // nobody on it. A role holding `memo.create` and `memo.issue` but not
                    // `memo.edit` can therefore draft a memo, fail to address it, and then fail to
                    // send it: the module is unusable in a way that reads as a broken screen rather
                    // than as a deliberate limit. The same pairing holds for meetings, where
                    // MeetingPolicy::invite() asks `meeting.edit`.
                    //
                    // `delete` is here for the narrow case it exists for: a draft written by
                    // mistake. Both policies refuse to delete anything that has gone out.
                    'memo.create' => 'organization',
                    'memo.edit' => 'organization',
                    'memo.delete' => 'organization',
                    'memo.issue' => 'organization',
                    'announcement.create' => 'organization',
                    'announcement.edit' => 'organization',
                    'announcement.delete' => 'organization',
                    'announcement.publish' => 'organization',
                ],
            ],

            'executive-director' => [
                'name' => 'Executive Director / General Manager',
                'level' => 95,
                'description' => 'Cross-division oversight with approval authority over projects, procurement and expenditure.',
                'pin' => null,
                'grants' => [
                    '*.view' => 'organization',
                    'report.export' => 'organization',
                    'dashboard.view_executive' => 'organization',
                    'kpi.view' => 'organization',
                    'project.manage' => 'organization',
                    'project.view_financials' => 'organization',
                    'purchase_request.approve' => 'organization',
                    'purchase_order.approve' => 'organization',
                    'expense.approve' => 'organization',
                    'budget.approve' => 'organization',
                    'change_request.approve' => 'organization',
                    'contract.approve' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'operations-manager' => [
                'name' => 'Operations Manager',
                'level' => 90,
                'description' => 'Runs day-to-day delivery across every project and department.',
                'pin' => null,
                'grants' => [
                    'project.*' => 'organization',
                    'project_member.*' => 'organization',
                    'task.*' => 'organization',
                    'daily_report.*' => 'organization',
                    'meeting.*' => 'organization',
                    'document.view' => 'organization',
                    'document.approve' => 'organization',
                    'hse_incident.view' => 'organization',
                    'corrective_action.*' => 'organization',
                    // SOMEBODY HAS TO BE ABLE TO OPEN A CLIENT ACCOUNT. Every other template
                    // reads the client register and none of them may write to it, which leaves
                    // a register that can only ever be populated by the system administrator —
                    // the exact shape of workaround §90 exists to prevent. Operations owns the
                    // relationship, so operations owns the record.
                    'client.*' => 'organization',
                    'contract.view' => 'organization',
                    'contract.view_confidential' => 'organization',
                    'purchase_request.approve' => 'organization',
                    'material_request.approve' => 'organization',
                    'employee.view' => 'organization',
                    'dashboard.view' => 'organization',
                    'report.view' => 'organization',
                    'kpi.view' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            // ============================================================ project delivery
            'project-director' => [
                'name' => 'Project Director',
                'level' => 85,
                'description' => 'Owns a portfolio of projects end to end. Reach follows project membership, not the org chart.',
                'pin' => null,
                'grants' => [
                    'project.manage' => 'project',
                    'project.view_financials' => 'project',
                    'project_member.manage' => 'project',
                    'task.view' => 'project',
                    'task.create' => 'project',
                    'task.manage' => 'project',
                    'document.approve' => 'project',
                    'document.view' => 'project',
                    'deliverable.approve' => 'project',
                    'drawing.approve' => 'project',
                    'change_request.approve' => 'project',
                    'purchase_request.approve' => 'project',
                    // `edit` is what invites people (MeetingPolicy::invite) — see managing-director.
                    'meeting.create' => 'project',
                    'meeting.edit' => 'project',
                    'meeting.manage' => 'project',
                    'meeting.view' => 'project',
                    'meeting.record_minutes' => 'project',
                    'meeting.circulate' => 'project',
                    'daily_report.view' => 'project',
                    'report.view' => 'project',
                    'approval.act' => 'project',
                ],
            ],

            'project-manager' => [
                'name' => 'Project Manager',
                'level' => 80,
                'description' => 'Runs a project: team, schedule, documents, site reporting and procurement initiation.',
                'pin' => null,
                'grants' => [
                    'project.view' => 'project',
                    'project.edit' => 'project',
                    'project.manage' => 'project',
                    'project_member.assign' => 'project',
                    'project_member.view' => 'project',
                    // Who the job is for, and the contract it runs under. Both read-only: the
                    // person running the work is not the person who agrees its terms.
                    'client.view' => 'organization',
                    'contract.view' => 'project',
                    // `view` alongside `manage`, here and in every role below that administers
                    // tasks it does not personally hold. There is no implication rule — `manage`
                    // does not confer `view`, deliberately, since the two answer different
                    // questions — and the baseline only grants `task.view: assigned`. Without this
                    // line a project manager could reassign and close the work on their own job
                    // while the task register showed them nothing but their own two tasks.
                    'task.view' => 'project',

                    // `manage` and `assign` do not imply `create` — see the note above on
                    // memo.create/memo.edit. Without this the person who runs the job can reassign
                    // and close its work but never raise any, including the actions arising from
                    // their own meetings (MeetingController::raiseAction asks `task.create`).
                    'task.create' => 'project',
                    'task.manage' => 'project',
                    'task.assign' => 'project',
                    'document.view' => 'project',
                    'document.create' => 'project',
                    'document.review' => 'project',
                    'document.submit' => 'project',
                    'deliverable.review' => 'project',
                    'drawing.view' => 'project',
                    'rfi.*' => 'project',
                    'submittal.review' => 'project',
                    'change_request.submit' => 'project',
                    'meeting.create' => 'project',
                    'meeting.edit' => 'project',
                    'meeting.delete' => 'project',
                    'meeting.manage' => 'project',
                    'meeting.view' => 'project',
                    'meeting.record_minutes' => 'project',
                    'meeting.circulate' => 'project',
                    'daily_report.approve' => 'project',
                    'daily_report.view' => 'project',
                    'purchase_request.submit' => 'project',
                    'purchase_request.create' => 'project',
                    'material_request.create' => 'project',
                    'hse_incident.view' => 'project',
                    'quality_inspection.view' => 'project',
                    'ncr.view' => 'project',
                    'report.view' => 'project',
                    'approval.act' => 'project',
                ],
            ],

            'department-head' => [
                'name' => 'Department Head',
                'level' => 78,
                'description' => 'Full visibility of one department and everything beneath it, plus approval of its people and work.',
                'pin' => null,
                'grants' => [
                    '*.view' => 'department',
                    'task.create' => 'department',
                    'task.manage' => 'department',
                    'task.assign' => 'department',
                    'project_member.assign' => 'department',
                    'document.approve' => 'department',
                    'document.review' => 'department',
                    'leave.approve' => 'department',
                    'timesheet.approve' => 'department',
                    'attendance.manage' => 'department',
                    'expense.approve' => 'department',
                    'purchase_request.review' => 'department',
                    'employee.view_contact' => 'department',
                    'performance.manage' => 'department',
                    'report.export' => 'department',
                    'dashboard.view_department' => 'department',
                    'kpi.view' => 'department',
                    'approval.act' => 'department',
                ],
            ],

            // ============================================================ engineering
            'engineering-manager' => [
                'name' => 'Engineering Manager',
                'level' => 76,
                'description' => 'Technical authority for the engineering department: approves deliverables, drawings and technical documents.',
                'pin' => null,
                'grants' => [
                    'deliverable.*' => 'department',
                    'drawing.*' => 'department',
                    'submittal.*' => 'department',
                    'rfi.*' => 'department',
                    // Read-only, and company-wide rather than departmental. An RFI is addressed
                    // to a client or a vendor, so the party picker on it is unusable without
                    // this — and a counterparty register filtered to one department would hide
                    // most of the companies the department actually writes to.
                    'client.view' => 'organization',
                    'vendor.view' => 'organization',
                    'change_request.review' => 'department',
                    'document.approve' => 'department',
                    'document.view' => 'department',
                    'document.review' => 'department',
                    'task.view' => 'department',
                    'task.create' => 'department',
                    'task.manage' => 'department',
                    'task.assign' => 'department',
                    'project.view' => 'project',
                    'employee.view' => 'department',
                    'leave.approve' => 'department',
                    'meeting.create' => 'department',
                    'meeting.edit' => 'department',
                    'meeting.manage' => 'department',
                    'meeting.view' => 'department',
                    'meeting.record_minutes' => 'department',
                    'meeting.circulate' => 'department',
                    'report.view' => 'department',
                    'dashboard.view_department' => 'department',
                    'approval.act' => 'department',
                ],
            ],

            'lead-engineer' => [
                'name' => 'Lead Engineer',
                'level' => 70,
                'description' => 'Reviews the technical work of a team and answers technical queries.',
                'pin' => null,
                'grants' => [
                    'deliverable.review' => 'department',
                    'deliverable.view' => 'department',
                    'deliverable.create' => 'own',
                    'drawing.review' => 'department',
                    'drawing.view' => 'department',
                    'document.review' => 'department',
                    'document.view' => 'department',
                    'document.create' => 'own',
                    'submittal.review' => 'department',
                    'rfi.respond' => 'department',
                    'rfi.view' => 'department',
                    'task.view' => 'team',
                    'task.create' => 'team',
                    'task.assign' => 'team',
                    'task.manage' => 'team',
                    'project.view' => 'project',
                    'meeting.create' => 'team',
                    'meeting.edit' => 'team',
                    'meeting.view' => 'team',
                    'meeting.record_minutes' => 'team',
                    'daily_report.view' => 'team',
                    'approval.act' => 'department',
                ],
            ],

            'engineer' => [
                'name' => 'Engineer',
                'level' => 55,
                'description' => 'Produces technical work. Sees the department it belongs to, plus everything on the projects it is assigned to.',
                'pin' => null,
                'grants' => [
                    // The two axes, side by side. This pairing IS the access model: department
                    // reach on the org chart, UNION full project reach where they are a member.
                    'document.view' => 'department',
                    'project.view' => 'project',
                    'task.view' => 'project',
                    'document.create' => 'own',
                    'document.edit' => 'own',
                    'document.submit' => 'own',
                    'deliverable.create' => 'own',
                    'deliverable.edit' => 'own',
                    'deliverable.submit' => 'own',
                    'deliverable.view' => 'department',
                    'drawing.view' => 'department',
                    'drawing.create' => 'own',
                    'rfi.create' => 'project',
                    'rfi.view' => 'project',
                    'submittal.view' => 'project',
                    'task.edit' => 'assigned',
                    'task.complete' => 'assigned',
                    'daily_report.create' => 'own',
                    'daily_report.view' => 'project',
                    'meeting.view' => 'project',
                    'leave.submit' => 'own',
                    'leave.create' => 'own',
                    'timesheet.create' => 'own',
                    'timesheet.submit' => 'own',
                    'attendance.create' => 'own',
                    'material_request.create' => 'own',
                ],
            ],

            // ============================================================ HSE
            'hse-manager' => [
                'name' => 'HSE Manager',
                'level' => 76,
                'description' => 'Company-wide health, safety and environment authority. Incident reach is deliberately organization-wide: safety cannot be departmental.',
                'pin' => null,
                'grants' => [
                    'hse_incident.*' => 'organization',
                    'hse_risk.*' => 'organization',
                    'hse_inspection.*' => 'organization',
                    'hse_observation.*' => 'organization',
                    'toolbox_talk.*' => 'organization',
                    'permit.*' => 'organization',
                    'corrective_action.*' => 'organization',
                    'hse_training.*' => 'organization',
                    'project.view' => 'organization',
                    'employee.view' => 'organization',
                    'document.view' => 'organization',
                    'report.export' => 'organization',
                    'kpi.view' => 'organization',
                    'dashboard.view_department' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'hse-officer' => [
                'name' => 'HSE Officer',
                'level' => 60,
                'description' => 'Site-level safety: raises incidents, runs inspections and toolbox talks on assigned projects.',
                'pin' => null,
                'grants' => [
                    'hse_incident.create' => 'project',
                    'hse_incident.edit' => 'project',
                    'hse_incident.view' => 'project',
                    'hse_inspection.create' => 'project',
                    'hse_inspection.view' => 'project',
                    'hse_inspection.edit' => 'project',
                    'hse_observation.create' => 'project',
                    'hse_observation.view' => 'project',
                    'toolbox_talk.manage' => 'project',
                    'hse_risk.view' => 'project',
                    'hse_risk.create' => 'project',
                    'permit.review' => 'project',
                    'permit.view' => 'project',
                    'corrective_action.view' => 'project',
                    'corrective_action.create' => 'project',
                    'hse_training.view' => 'project',
                    'project.view' => 'project',
                    'employee.view' => 'project',
                    'daily_report.create' => 'own',
                    'attendance.create' => 'own',
                    'approval.act' => 'project',
                ],
            ],

            // ============================================================ quality
            'qa-qc-manager' => [
                'name' => 'QA/QC Manager',
                'level' => 76,
                'description' => 'Owns the quality system: inspections, non-conformances and verification of corrective actions.',
                'pin' => null,
                'grants' => [
                    'quality_inspection.*' => 'organization',
                    'ncr.*' => 'organization',
                    'corrective_action.verify' => 'organization',
                    'corrective_action.view' => 'organization',
                    'corrective_action.close' => 'organization',
                    'document.view' => 'organization',
                    'deliverable.view' => 'organization',
                    'drawing.view' => 'organization',
                    'project.view' => 'organization',
                    'vendor.evaluate' => 'organization',
                    'report.export' => 'organization',
                    'kpi.view' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'qa-qc-officer' => [
                'name' => 'QA/QC Officer',
                'level' => 60,
                'description' => 'Carries out inspections and raises non-conformances on assigned projects.',
                'pin' => null,
                'grants' => [
                    'quality_inspection.create' => 'project',
                    'quality_inspection.edit' => 'project',
                    'quality_inspection.view' => 'project',
                    'ncr.create' => 'project',
                    'ncr.edit' => 'project',
                    'ncr.view' => 'department',
                    'ncr.submit' => 'project',
                    'corrective_action.view' => 'project',
                    'document.view' => 'project',
                    'drawing.view' => 'project',
                    'project.view' => 'project',
                    'daily_report.create' => 'own',
                    'attendance.create' => 'own',
                ],
            ],

            // ============================================================ documents
            'document-controller' => [
                'name' => 'Document Controller',
                'level' => 65,
                'description' => 'Custodian of the document register: numbering, revisions, transmittals and archiving. Controls documents without approving their content.',
                'pin' => null,
                'grants' => [
                    'document.*' => 'organization',
                    'transmittal.*' => 'organization',
                    // Same reason as the engineering manager above: a transmittal is issued to
                    // somebody, and the register of who that can be is read-only reference data
                    // to the person issuing it.
                    'client.view' => 'organization',
                    'vendor.view' => 'organization',
                    'numbering.*' => 'organization',
                    'template.manage' => 'organization',
                    'template.view' => 'organization',
                    'drawing.view' => 'organization',
                    'drawing.edit' => 'organization',
                    'deliverable.view' => 'organization',
                    'submittal.view' => 'organization',
                    'project.view' => 'organization',
                    'memo.view' => 'organization',
                    'report.view' => 'organization',
                ],
            ],

            // ============================================================ people
            'hr-manager' => [
                'name' => 'HR Manager',
                'level' => 78,
                'description' => 'Owns the employee lifecycle, attendance, leave and payroll execution.',
                'pin' => null,
                'grants' => [
                    'employee.*' => 'organization',
                    'attendance.*' => 'organization',
                    'timesheet.*' => 'organization',
                    'leave.*' => 'organization',
                    'recruitment.*' => 'organization',
                    'performance.*' => 'organization',
                    'payroll.*' => 'organization',
                    'position.*' => 'organization',
                    'org_unit.view' => 'organization',
                    'user.view' => 'organization',
                    'report.export' => 'organization',
                    'dashboard.view_department' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'hr-officer' => [
                'name' => 'HR Officer',
                'level' => 60,
                'description' => 'Day-to-day HR administration. Deliberately excludes payroll and confidential performance records.',
                'pin' => null,
                'grants' => [
                    'employee.view' => 'organization',
                    'employee.create' => 'organization',
                    'employee.edit' => 'organization',
                    'employee.view_contact' => 'organization',
                    'attendance.view' => 'organization',
                    'attendance.edit' => 'organization',
                    'attendance.create' => 'organization',
                    'timesheet.view' => 'organization',
                    'leave.view' => 'organization',
                    'leave.create' => 'organization',
                    'recruitment.view' => 'organization',
                    'recruitment.edit' => 'organization',
                    'recruitment.create' => 'organization',
                    'position.view' => 'organization',
                    'org_unit.view' => 'organization',
                    'report.view' => 'organization',
                ],
            ],

            // ============================================================ finance
            'finance-manager' => [
                'name' => 'Finance Manager',
                'level' => 78,
                'description' => 'Controls budgets, expenditure, payments and financial reporting.',
                'pin' => null,
                'grants' => [
                    'budget.*' => 'organization',
                    'expense.*' => 'organization',
                    'income.*' => 'organization',
                    'payment.*' => 'organization',
                    // Finance registers the contract, because finance is who tracks what it is
                    // worth, what is retained against it and when the retention falls due.
                    'contract.create' => 'organization',
                    'contract.edit' => 'organization',
                    // `manage` is what records a contract as EXECUTED and what moves the
                    // retention. Approval is somebody else's — see executive-director — but
                    // filing the signed original and holding the money against it is finance's,
                    // and without this the contract graph has an approved state nobody on the
                    // default templates can move out of.
                    'contract.manage' => 'organization',
                    'contract.view' => 'organization',
                    'contract.view_confidential' => 'organization',
                    'contract.export' => 'organization',
                    // The account number is a payment instrument, not a contact detail, and it
                    // is masked for everybody without this. The people who pay suppliers are
                    // the people who need to read it (§67).
                    'vendor.bank' => 'organization',
                    'purchase_order.view' => 'organization',
                    'purchase_order.approve' => 'organization',
                    'purchase_request.view' => 'organization',
                    'vendor.view' => 'organization',
                    'client.view' => 'organization',
                    'project.view' => 'organization',
                    'project.view_financials' => 'organization',
                    'report.export' => 'organization',
                    'kpi.view' => 'organization',
                    'dashboard.view_department' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'finance-officer' => [
                'name' => 'Finance Officer',
                'level' => 58,
                'description' => 'Processes departmental expenditure and receipts. No payroll, no budget authority.',
                'pin' => null,
                'grants' => [
                    'expense.create' => 'department',
                    'expense.edit' => 'department',
                    'expense.view' => 'department',
                    'expense.submit' => 'department',
                    'income.create' => 'department',
                    'income.view' => 'department',
                    'payment.create' => 'department',
                    'payment.view' => 'department',
                    'budget.view' => 'department',
                    'vendor.view' => 'organization',
                    'purchase_order.view' => 'department',
                    'report.view' => 'department',
                ],
            ],

            // ============================================================ procurement
            'procurement-manager' => [
                'name' => 'Procurement Manager',
                'level' => 76,
                'description' => 'Owns sourcing end to end: requests, RFQs, vendor selection and purchase orders.',
                'pin' => null,
                'grants' => [
                    'purchase_request.*' => 'organization',
                    'rfq.*' => 'organization',
                    'quotation.*' => 'organization',
                    'purchase_order.*' => 'organization',
                    'goods_receipt.*' => 'organization',
                    'vendor.*' => 'organization',
                    // A subcontract IS a contract — the same register, held in the other
                    // direction. Procurement raises those; it reads the client-side ones
                    // because a back-to-back subcontract has to match the head contract.
                    'contract.create' => 'organization',
                    'contract.edit' => 'organization',
                    'contract.manage' => 'organization',
                    'contract.view' => 'organization',
                    'contract.view_confidential' => 'organization',
                    'client.view' => 'organization',
                    'material_request.view' => 'organization',
                    'inventory_item.view' => 'organization',
                    'budget.view' => 'organization',
                    'project.view' => 'organization',
                    'report.export' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'procurement-officer' => [
                'name' => 'Procurement Officer',
                'level' => 58,
                'description' => 'Prepares RFQs, collects quotations and drafts purchase orders for approval.',
                'pin' => null,
                'grants' => [
                    'purchase_request.view' => 'department',
                    'purchase_request.create' => 'department',
                    'rfq.create' => 'department',
                    'rfq.edit' => 'department',
                    'rfq.view' => 'department',
                    'rfq.issue' => 'department',
                    // SPELLED OUT, BECAUSE `manage` IMPLIES NOTHING ELSE. ScopedPolicy matches the
                    // exact ability or the '*' wildcard and nothing in between, so an officer
                    // holding only `quotation.manage` could not open the register whose whole
                    // description is that they collect it.
                    //
                    // `view_confidential` is the one that would go unnoticed: the quotations table
                    // defaults to `confidential`, so Gate 5 closes every row to anybody without
                    // this ability or an explicit ACL entry. The manager picks it up free through
                    // `quotation.*`; the officer, who is the person actually typing the prices in,
                    // has to be given it by name.
                    //
                    // `compare` is here because the officer prepares the comparison sheet. It is
                    // the manager who awards on it — that is `rfq.manage`, and it stays above.
                    'quotation.view' => 'department',
                    'quotation.create' => 'department',
                    'quotation.edit' => 'department',
                    'quotation.compare' => 'department',
                    'quotation.view_confidential' => 'department',
                    'quotation.manage' => 'department',
                    'purchase_order.create' => 'department',
                    'purchase_order.edit' => 'department',
                    'purchase_order.view' => 'department',
                    'purchase_order.submit' => 'department',
                    'vendor.view' => 'organization',
                    'vendor.create' => 'organization',
                    // CREATE WITHOUT EDIT IS A FROZEN REGISTER. The officer registers the supplier,
                    // chases the prequalification pack and submits it for assessment — all of which
                    // is `vendor.edit` — and would otherwise be unable to correct so much as a
                    // misspelt address on a row they wrote themselves. What stays out is
                    // `vendor.manage`: approving, suspending and blacklisting belong to the manager,
                    // so the person who brings a supplier in is never the person who passes them.
                    'vendor.edit' => 'organization',
                    'goods_receipt.view' => 'department',
                    'inventory_item.view' => 'organization',
                ],
            ],

            // ============================================================ stores
            'store-manager' => [
                'name' => 'Store Manager',
                'level' => 70,
                'description' => 'Runs warehousing and stock control across all locations.',
                'pin' => null,
                'grants' => [
                    'warehouse.*' => 'organization',
                    'stock.*' => 'organization',
                    'inventory_item.*' => 'organization',
                    'goods_receipt.*' => 'organization',
                    'material_request.*' => 'organization',
                    'asset.view' => 'organization',
                    'purchase_order.view' => 'organization',
                    'vendor.view' => 'organization',
                    'report.export' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'store-officer' => [
                'name' => 'Store Officer',
                'level' => 50,
                'description' => 'Receives goods and records stock movements at a single store.',
                'pin' => null,
                'grants' => [
                    'stock.create' => 'team',
                    'stock.view' => 'team',
                    'stock.edit' => 'team',
                    'goods_receipt.create' => 'team',
                    'goods_receipt.view' => 'team',
                    'material_request.view' => 'team',
                    'material_request.issue' => 'team',
                    'inventory_item.view' => 'organization',
                    'warehouse.view' => 'organization',
                    'attendance.create' => 'own',
                ],
            ],

            // ============================================================ assets & fleet
            'fleet-manager' => [
                'name' => 'Fleet Manager',
                'level' => 70,
                'description' => 'Manages vehicles, fuel consumption and planned maintenance.',
                'pin' => null,
                'grants' => [
                    'vehicle.*' => 'organization',
                    'fuel.*' => 'organization',
                    'asset.*' => 'organization',
                    'asset_maintenance.*' => 'organization',
                    'employee.view' => 'organization',
                    'project.view' => 'organization',
                    'report.export' => 'organization',
                    'approval.act' => 'organization',
                ],
            ],

            'driver' => [
                'name' => 'Driver',
                'level' => 30,
                'description' => 'Sees only the vehicle assigned to them and logs their own fuel and trips.',
                'pin' => null,
                'grants' => [
                    'vehicle.view' => 'assigned',
                    'fuel.create' => 'own',
                    'fuel.view' => 'own',
                    'task.view' => 'assigned',
                    'task.complete' => 'assigned',
                    'attendance.create' => 'own',
                    'leave.create' => 'own',
                    'leave.submit' => 'own',
                ],
            ],

            // ============================================================ administration (bounded)
            'office-administrator' => [
                'name' => 'Office Administrator',
                'level' => 55,
                'description' => 'Runs the office: visitors, meetings, memos and the staff directory. Holds no finance or payroll reach whatsoever.',
                'pin' => null,
                'grants' => [
                    'visitor.*' => 'organization',
                    'meeting.create' => 'organization',
                    'meeting.edit' => 'organization',
                    'meeting.delete' => 'organization',
                    'meeting.manage' => 'organization',
                    'meeting.view' => 'organization',
                    'meeting.record_minutes' => 'organization',
                    'meeting.circulate' => 'organization',
                    'memo.create' => 'organization',
                    'memo.edit' => 'organization',
                    'memo.delete' => 'organization',
                    'memo.view' => 'organization',
                    'memo.issue' => 'organization',
                    'announcement.create' => 'organization',
                    'announcement.edit' => 'organization',
                    'announcement.delete' => 'organization',

                    // Drafting a notice and putting it in front of the whole company are two
                    // decisions, and this role holds both — an office administrator who has to ask
                    // a director to press Publish on every canteen notice is a role nobody uses.
                    // Contrast site-administrator below, who drafts and hands up.
                    'announcement.publish' => 'organization',
                    'employee.view' => 'organization',
                    'employee.view_contact' => 'organization',
                    'document.view' => 'organization',
                    'location.view' => 'organization',
                    'org_unit.view' => 'organization',
                    'asset.view' => 'organization',
                ],
            ],

            'site-administrator' => [
                'name' => 'Site Administrator',
                'level' => 50,
                'description' => 'Administrative support at one site: visitors, attendance corrections and site reporting.',
                'pin' => null,
                'grants' => [
                    'visitor.manage' => 'project',
                    'visitor.view' => 'project',
                    'attendance.edit' => 'project',
                    'attendance.view' => 'project',
                    'daily_report.view' => 'project',
                    'daily_report.create' => 'project',
                    'memo.create' => 'project',
                    'memo.edit' => 'project',
                    'memo.view' => 'project',

                    // Deliberately no memo.issue and no announcement.publish. A site administrator
                    // drafts and hands up: putting a memo out over the site is the project manager's
                    // signature, not the office's. Compare office-administrator above, who holds
                    // both because there is nobody above them to hand a canteen notice to.
                    'meeting.create' => 'project',
                    'meeting.edit' => 'project',
                    'meeting.view' => 'project',
                    'meeting.record_minutes' => 'project',
                    'employee.view' => 'project',
                    'project.view' => 'project',
                    'document.view' => 'project',
                ],
            ],

            'project-administrator' => [
                'name' => 'Project Administrator',
                'level' => 62,
                'description' => 'Administrative authority inside ONE project. Assign this role with a project pin — unpinned it grants nothing, which is the intended failure mode.',
                'pin' => 'project',
                'grants' => [
                    'project.view' => 'project',
                    'project.edit' => 'project',
                    'project_member.manage' => 'project',
                    'task.view' => 'project',
                    'task.create' => 'project',
                    'task.manage' => 'project',
                    'meeting.create' => 'project',
                    'meeting.edit' => 'project',
                    'meeting.manage' => 'project',
                    'meeting.view' => 'project',
                    'meeting.record_minutes' => 'project',
                    'meeting.circulate' => 'project',
                    'document.view' => 'project',
                    'document.create' => 'project',
                    'daily_report.view' => 'project',
                    'visitor.manage' => 'project',
                    'report.view' => 'project',
                ],
            ],

            'department-administrator' => [
                'name' => 'Department Administrator',
                'level' => 62,
                'description' => 'Administrative authority inside ONE org unit. Assign with an org-unit pin; unpinned it reaches nothing.',
                'pin' => 'org_unit',
                'grants' => [
                    'employee.view' => 'department',
                    'employee.edit' => 'department',
                    'task.view' => 'department',
                    'task.create' => 'department',
                    'task.manage' => 'department',
                    'leave.view' => 'department',
                    'attendance.view' => 'department',
                    'timesheet.view' => 'department',
                    'document.view' => 'department',
                    'meeting.create' => 'department',
                    'meeting.edit' => 'department',
                    'meeting.manage' => 'department',
                    'meeting.view' => 'department',
                    'meeting.record_minutes' => 'department',
                    'meeting.circulate' => 'department',
                    'org_unit.view' => 'department',
                    'report.view' => 'department',
                ],
            ],

            'security-officer' => [
                'name' => 'Security Officer',
                'level' => 40,
                'description' => 'Gate control: visitors in and out, and attendance visibility.',
                'pin' => null,
                'grants' => [
                    'visitor.*' => 'organization',
                    'attendance.view' => 'organization',
                    'employee.view' => 'organization',
                    'vehicle.view' => 'organization',
                    'location.view' => 'organization',
                ],
            ],

            // ============================================================ baseline & external
            'general-employee' => [
                'name' => 'General Employee',
                'level' => 20,
                'description' => 'The baseline every member of staff holds. Sees their own records and what is assigned to them, and nothing else.',
                'pin' => null,
                'grants' => [
                    'task.view' => 'assigned',
                    'task.complete' => 'assigned',
                    'task.edit' => 'assigned',
                    'document.view' => 'assigned',
                    'meeting.view' => 'assigned',
                    'announcement.view' => 'organization',
                    'memo.view' => 'assigned',
                    'leave.create' => 'own',
                    'leave.submit' => 'own',
                    'leave.view' => 'own',
                    'attendance.create' => 'own',
                    'attendance.view' => 'own',
                    'timesheet.create' => 'own',
                    'timesheet.submit' => 'own',
                    'timesheet.view' => 'own',
                    'daily_report.create' => 'own',
                    'expense.create' => 'own',
                    'expense.submit' => 'own',
                    'employee.view' => 'own',
                    'hse_observation.create' => 'own',
                    'hse_incident.create' => 'own',
                ],
            ],

            'contractor' => [
                'name' => 'Contractor / Consultant',
                'level' => 25,
                'description' => 'External party working on specific projects. Reach is purely the project axis and time-bounded by their membership.',
                'pin' => null,
                'grants' => [
                    'project.view' => 'project',
                    'task.view' => 'assigned',
                    'task.complete' => 'assigned',
                    'document.view' => 'assigned',
                    'drawing.view' => 'assigned',
                    'rfi.create' => 'project',
                    'rfi.view' => 'project',
                    'daily_report.create' => 'own',
                    'meeting.view' => 'assigned',
                ],
            ],

            'client' => [
                'name' => 'Client',
                'level' => 15,
                'description' => 'Customer-side viewer. Sees only what has been explicitly transmitted or shared with them — reach is ACL-driven by design.',
                'pin' => null,
                'grants' => [
                    'project.view' => 'assigned',
                    'document.view' => 'assigned',
                    'document.download' => 'assigned',
                    'transmittal.view' => 'assigned',
                    'transmittal.acknowledge' => 'assigned',
                    'drawing.view' => 'assigned',
                    'submittal.view' => 'assigned',
                    'rfi.view' => 'assigned',
                    'meeting.view' => 'assigned',
                ],
            ],
        ];
    }

    /**
     * Expand a grant map's patterns into concrete permission keys.
     *
     * Two patterns can produce the same key at different scopes, and there are two distinct cases:
     *
     *   BOTH ORGANIZATIONAL — the broader wins. A role saying `*.view: organization` and
     *   `document.view: department` clearly does not mean document.view is the narrower of the two.
     *
     *   ONE PROJECT, ONE ORGANIZATIONAL — both are kept. The two axes are additive and
     *   independent (docs/04-access-model.md §8): "everything in my department" and "everything on
     *   my projects" are a union, not a contest. Collapsing them would silently delete reach —
     *   an engineer granted `task.view: project` alongside a department-wide `*.view` would lose
     *   sight of tasks on projects outside their department, and nothing would report it.
     *
     * @param  array<string,string>  $grants
     * @return array<string,array<int,string>> permission key => one or more scopes
     */
    public static function expand(array $grants): array
    {
        $catalogue = PermissionCatalogue::keys();

        /** @var array<string,array<string,AccessScope>> $resolved key => axis => scope */
        $resolved = [];

        foreach ($grants as $pattern => $scope) {
            $scope = $scope instanceof AccessScope ? $scope : AccessScope::from($scope);
            $axis = $scope->isOrganizational() ? 'org' : 'project';

            foreach (self::match($pattern, $catalogue) as $key) {
                $existing = $resolved[$key][$axis] ?? null;

                $resolved[$key][$axis] = $existing === null
                    ? $scope
                    : AccessScope::broadest($existing, $scope);
            }
        }

        return array_map(
            fn (array $byAxis) => array_values(array_map(fn (AccessScope $s) => $s->value, $byAxis)),
            $resolved,
        );
    }

    /**
     * @param  array<int,string>  $catalogue
     * @return array<int,string>
     */
    private static function match(string $pattern, array $catalogue): array
    {
        if (! str_contains($pattern, '*')) {
            return in_array($pattern, $catalogue, true) ? [$pattern] : [];
        }

        [$module, $action] = array_pad(explode('.', $pattern, 2), 2, '*');

        return array_values(array_filter($catalogue, function (string $key) use ($module, $action) {
            [$keyModule, $keyAction] = explode('.', $key, 2);

            return ($module === '*' || $module === $keyModule)
                && ($action === '*' || $action === $keyAction);
        }));
    }
}
