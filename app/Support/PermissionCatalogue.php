<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The complete, authoritative permission vocabulary (docs/06-rbac-matrix.md §1).
 *
 * Permissions are a GLOBAL catalogue; the grants are per-organization. That split is what lets
 * every company invent its own roles without a developer touching code, while still guaranteeing
 * that `document.approve` means the same thing everywhere — which the workflow engine, the
 * notification filter and the audit trail all depend on.
 *
 * Adding a permission here and re-running the catalogue seeder is the ONLY supported way to
 * introduce one. A permission that exists in a policy but not here is a permission no
 * administrator can ever grant, which in a fail-closed system means a dead feature.
 */
final class PermissionCatalogue
{
    /** Actions almost every record module needs. */
    private const CRUD = ['view', 'create', 'edit', 'delete', 'export'];

    /**
     * module => [group, label, actions]
     *
     * `actions` is CRUD plus whatever the module genuinely adds. Resist adding actions "for
     * symmetry": every key here is a checkbox a real administrator has to understand.
     *
     * @return array<string,array{group:string,label:string,actions:array<int,string>}>
     */
    public static function modules(): array
    {
        return [
            // ------------------------------------------------------------- administration
            'organization' => ['Administration', 'Company Profile', ['view', 'edit', 'configure']],
            'org_unit' => ['Administration', 'Organization Structure', ['view', 'create', 'edit', 'delete', 'reorganize']],
            'position' => ['Administration', 'Positions', self::CRUD],
            'location' => ['Administration', 'Locations', self::CRUD],
            'user' => ['Administration', 'User Accounts', [...self::CRUD, 'manage', 'impersonate', 'reset_password']],
            'role' => ['Administration', 'Roles & Permissions', [...self::CRUD, 'manage', 'assign']],
            'setting' => ['Administration', 'System Settings', ['view', 'manage']],
            'custom_field' => ['Administration', 'Custom Fields', ['view', 'manage']],
            'template' => ['Administration', 'Document Templates', ['view', 'manage']],
            'numbering' => ['Administration', 'Numbering Schemes', ['view', 'manage']],
            'status' => ['Administration', 'Statuses & Transitions', ['view', 'manage']],
            'escalation' => ['Administration', 'Escalation Rules', ['view', 'manage']],
            'audit_log' => ['Administration', 'Audit Trail', ['view', 'export']],

            // ------------------------------------------------------------- workflow & forms
            'workflow' => ['Workflow', 'Workflow Definitions', ['view', 'create', 'edit', 'delete', 'manage', 'publish']],
            'approval' => ['Workflow', 'Approval Centre', ['view', 'act', 'delegate', 'reassign']],
            // `review` is what somebody does to a SUBMISSION; `manage` and `publish` are what
            // somebody does to a DEFINITION. Folding the first into either of the others would
            // mean the supervisor who signs off a scaffold sheet could also rewrite the sheet —
            // which is a door to a different room, and the kind an administrator grants without
            // reading because the label said the same word.
            'form' => ['Workflow', 'Custom Forms', [...self::CRUD, 'manage', 'publish', 'submit', 'review']],

            // ------------------------------------------------------------- projects
            'project' => ['Projects', 'Projects', [...self::CRUD, 'manage', 'archive', 'view_financials', 'view_confidential']],
            'project_member' => ['Projects', 'Project Team', ['view', 'assign', 'remove', 'manage']],
            'task' => ['Projects', 'Tasks', [...self::CRUD, 'manage', 'assign', 'reassign', 'complete', 'verify']],
            'meeting' => ['Projects', 'Meetings', [...self::CRUD, 'manage', 'record_minutes', 'circulate']],
            'daily_report' => ['Projects', 'Daily Site Reports', [...self::CRUD, 'approve']],

            // ------------------------------------------------------------- documents
            'document' => ['Documents', 'Documents', [
                ...self::CRUD, 'manage', 'submit', 'review', 'approve', 'reject',
                'sign', 'download', 'print', 'archive', 'restore', 'share', 'view_confidential',
            ]],
            // Categories are the document register's configuration: they decide the revision
            // scheme, the upload rules and which workflow a new document enters. That is
            // administration of the module rather than work within it, so `manage` is the only
            // write action — there is no meaningful "edit a category but not create one".
            'document_category' => ['Documents', 'Document Categories', ['view', 'manage']],
            'transmittal' => ['Documents', 'Transmittals', [...self::CRUD, 'manage', 'issue', 'acknowledge']],
            'memo' => ['Documents', 'Memos', [...self::CRUD, 'issue']],
            'announcement' => ['Documents', 'Announcements', [...self::CRUD, 'publish']],

            // ------------------------------------------------------------- engineering
            'deliverable' => ['Engineering', 'Deliverables', [...self::CRUD, 'submit', 'review', 'approve', 'reject']],
            'drawing' => ['Engineering', 'Drawings', [...self::CRUD, 'submit', 'review', 'approve', 'reject', 'download']],
            'rfi' => ['Engineering', 'RFIs', [...self::CRUD, 'submit', 'respond', 'close']],
            'submittal' => ['Engineering', 'Submittals', [...self::CRUD, 'submit', 'review', 'approve', 'reject']],
            'change_request' => ['Engineering', 'Change Requests', [...self::CRUD, 'submit', 'review', 'approve', 'reject']],

            // ------------------------------------------------------------- HSE
            'hse_incident' => ['HSE', 'Incidents', [...self::CRUD, 'manage', 'investigate', 'close', 'view_confidential']],
            'hse_risk' => ['HSE', 'Risk Assessments', [...self::CRUD, 'manage', 'approve']],
            'hse_inspection' => ['HSE', 'Inspections', [...self::CRUD, 'manage', 'close']],
            'hse_observation' => ['HSE', 'Observations', [...self::CRUD, 'manage', 'close']],
            'toolbox_talk' => ['HSE', 'Toolbox Talks', [...self::CRUD, 'manage']],
            'permit' => ['HSE', 'Permits to Work', [...self::CRUD, 'manage', 'submit', 'review', 'approve', 'reject', 'close']],
            'corrective_action' => ['HSE', 'Corrective Actions', [...self::CRUD, 'manage', 'assign', 'verify', 'close']],
            'hse_training' => ['HSE', 'HSE Training', [...self::CRUD, 'manage']],

            // ------------------------------------------------------------- quality
            'quality_inspection' => ['Quality', 'Quality Inspections', [...self::CRUD, 'manage', 'approve']],
            'ncr' => ['Quality', 'Non-Conformance Reports', [...self::CRUD, 'manage', 'submit', 'review', 'approve', 'close']],

            // ------------------------------------------------------------- commercial
            'client' => ['Commercial', 'Clients', [...self::CRUD, 'manage']],
            // `bank` is separate from `edit` because payment redirection fraud is a quiet change
            // to an account number by somebody who legitimately holds `edit`. Reading and writing
            // the bank block are the SAME permission on purpose: splitting them would produce the
            // arrangement where somebody may replace a number they were never allowed to check.
            'vendor' => ['Commercial', 'Vendors', [...self::CRUD, 'manage', 'evaluate', 'blacklist', 'bank']],
            'contract' => ['Commercial', 'Contracts', [...self::CRUD, 'manage', 'approve', 'view_confidential']],

            // ------------------------------------------------------------- procurement
            'purchase_request' => ['Procurement', 'Purchase Requests', [...self::CRUD, 'manage', 'submit', 'review', 'approve', 'reject']],
            'rfq' => ['Procurement', 'Requests for Quotation', [...self::CRUD, 'manage', 'issue']],
            // `quotation.view_confidential` is not optional garnish: the column defaults to
            // `confidential`, and Gate 5 lets a confidential record through only for somebody
            // holding this or named on an explicit ACL row. Without it in the catalogue the
            // register would be unopenable by anybody at all, including the buyer who typed it in.
            // It is separate from `quotation.view` so that "may read the enquiry" and "may read the
            // prices" can be given to different people while a tender is live.
            'quotation' => ['Procurement', 'Quotations', [...self::CRUD, 'manage', 'compare', 'view_confidential']],
            'purchase_order' => ['Procurement', 'Purchase Orders', [...self::CRUD, 'manage', 'submit', 'approve', 'reject', 'issue']],
            'goods_receipt' => ['Procurement', 'Goods Receipts', [...self::CRUD, 'manage', 'approve']],

            // ------------------------------------------------------------- inventory
            'material_request' => ['Inventory', 'Material Requests', [...self::CRUD, 'manage', 'submit', 'approve', 'issue']],
            'warehouse' => ['Inventory', 'Warehouses', [...self::CRUD, 'manage', 'suspend']],
            'stock_item' => ['Inventory', 'Stock Catalogue', [...self::CRUD, 'manage']],

            /*
             * NO `edit` AND NO `delete` ON `stock`, and their absence is the catalogue saying what
             * the ledger is. A movement cannot be amended by anybody at any grant level (§59), so
             * an ability to amend one would be a permission that grants nothing — and a permission
             * that grants nothing is one an administrator ticks believing it does.
             *
             * `adjust` is the stocktake: changing the count without a movement having happened, and
             * the one act in inventory that creates stock from nothing. It is separated from
             * `create` because the person who receives deliveries all day should not also be able
             * to write the shortfall off at the end of the month.
             *
             * `reverse` is separated from `adjust` for the same reason in the other direction: the
             * correction of somebody else's mistake is a supervisor's act, and it is the one that
             * leaves the clearest trail.
             */
            'stock' => ['Inventory', 'Stock Movements', ['view', 'create', 'manage', 'adjust', 'transfer', 'reverse', 'view_value']],

            /*
             * ------------------------------------------------------------- assets & fleet
             *
             * `transfer` IS NOT `edit`, and separating them is the whole control on an asset
             * register. Correcting a serial number and handing a generator to somebody else are
             * different acts with different consequences: the first is a typo, the second is the
             * moment responsibility for a quarter of a million naira of plant changes hands. A
             * store clerk needs the first every week and should never have the second.
             *
             * `dispose` IS SEPARATED FROM `delete` FOR THE SAME REASON, in the direction that
             * matters more. An asset row is never deleted — a disposed generator is most of what an
             * auditor asks about — so `delete` here is the ordinary "this record should not exist"
             * ability, while `dispose` records that a real thing left the company. Giving the second
             * to whoever holds the first is how plant walks off site with paperwork behind it.
             *
             * THERE IS NO `asset_custody_transfer` MODULE. The trail is append-only and is governed
             * by `asset.transfer` and `asset.view` — see AssetCustodyTransfer::permissionModule().
             * A module of its own would offer an administrator `asset_custody_transfer.edit`, an
             * ability the model throws on, which is a permission that grants nothing and reads as
             * though it grants everything.
             */
            'asset' => ['Assets', 'Assets', [...self::CRUD, 'manage', 'assign', 'transfer', 'dispose']],
            'asset_maintenance' => ['Assets', 'Asset Maintenance', [...self::CRUD, 'manage', 'complete']],
            'vehicle' => ['Fleet', 'Vehicles', [...self::CRUD, 'manage', 'assign']],
            // `fuel_log`, not `fuel`: every other key in this catalogue is the snake-cased model
            // name, because that is what Scopeable::permissionModule() derives when nothing
            // overrides it. A key that does not match its model is a `visibleTo()` call checking a
            // permission nobody was ever granted, and it fails closed and silently.
            'fuel_log' => ['Fleet', 'Fuel Records', [...self::CRUD, 'manage']],
            // The scans are private files (§67) and the expiry is the reason the register exists.
            // `renew` is separate from `create` because renewing writes a NEW row while the lapsed
            // one keeps its dates — "were we insured on the day of the accident" is asked about
            // last year — and the person who files certificates is not always the person who buys
            // them.
            'vehicle_document' => ['Fleet', 'Vehicle Documents', [...self::CRUD, 'manage', 'renew']],

            // ------------------------------------------------------------- people
            'employee' => ['People', 'Employees', [...self::CRUD, 'manage', 'view_contact', 'view_confidential']],
            'attendance' => ['People', 'Attendance', [...self::CRUD, 'manage', 'approve']],
            'timesheet' => ['People', 'Timesheets', [...self::CRUD, 'manage', 'submit', 'approve']],
            // Pre-approval for extra hours. Deciding it is acting on its workflow stage, as with
            // leave and timesheets, so there is no `approve` here to grant by mistake.
            'overtime' => ['People', 'Overtime', [...self::CRUD, 'manage', 'submit']],
            'leave' => ['People', 'Leave', [...self::CRUD, 'manage', 'submit', 'approve', 'reject']],
            // Vacancies, applicants and interview stages are one module. `hire` is separate from
            // `manage` on purpose: taking somebody on CREATES a personnel record and a sign-in, and
            // the person who schedules interviews is not automatically the person who may do that.
            // A vacancy is decided on its workflow stage, as leave and timesheets are, so there is
            // no `approve` here to grant by mistake.
            // `view_confidential` is what an applicant's file is actually read through: an
            // application carries somebody's name, contact details and what an interviewer thought
            // of them, so it is stored restricted and is reachable from inside the hiring
            // department or by a recruiter holding this. Without it a central recruiter with no
            // department of their own would see an empty list (§68).
            'recruitment' => ['People', 'Recruitment', [...self::CRUD, 'manage', 'submit', 'hire', 'view_confidential']],
            // Joining and leaving lists. The templates are configuration; a person's own copy is a
            // record, and both live under this one module.
            'checklist' => ['People', 'Onboarding & exit checklists', [...self::CRUD, 'manage']],
            // Leaving. `clear` is what says a leaver holds nothing outstanding, and `complete` is
            // what ends the employment AND switches the sign-in off — one act, one permission,
            // because the two coming apart is how a leaver keeps their access for three weeks.
            'employee_exit' => ['People', 'Exits', [...self::CRUD, 'manage', 'clear', 'complete', 'view_confidential']],
            // A review is signed off on its workflow stage, exactly as leave, timesheets and
            // vacancies are, so there is no `approve` here for an administrator to grant by mistake
            // — it would be a permission that appears to decide reviews and decides nothing.
            // `view_confidential` is what a central HR team reads the register through: a review is
            // stored restricted, so without it somebody sitting in no department sees an empty list.
            'performance' => ['People', 'Performance', [...self::CRUD, 'manage', 'submit', 'view_confidential']],
            // Promotions and transfers. `apply` IS SEPARATE FROM `manage` because applying a
            // movement rewrites a personnel record — somebody's job, department and reporting line —
            // and the clerk who raises the paperwork is not automatically the person who may do
            // that. There is no `approve`: a movement is decided on its workflow stage.
            'employee_movement' => ['People', 'Promotions & transfers', [...self::CRUD, 'manage', 'submit', 'apply', 'view_confidential']],
            // Conduct. EVERY action here is marked sensitive below, because every one of them
            // touches an allegation against a named person that may turn out to be untrue.
            // `investigate` and `decide` are separate from each other on principle: the person who
            // looks into what happened must not be the person who decides the sanction, and a
            // company that wants them to be the same person grants both deliberately.
            'disciplinary' => ['People', 'Disciplinary', [...self::CRUD, 'manage', 'investigate', 'decide', 'view_confidential']],
            'visitor' => ['People', 'Visitors', [...self::CRUD, 'manage', 'check_in', 'check_out']],

            // ------------------------------------------------------------- finance
            'budget' => ['Finance', 'Budgets', [...self::CRUD, 'manage', 'approve']],
            'expense' => ['Finance', 'Expenses', [...self::CRUD, 'manage', 'submit', 'approve', 'reject']],
            // `issue` is separate from `create`: drafting an invoice line is clerical, telling a
            // client they owe money is not, and the second is what makes the figure receivable.
            'income' => ['Finance', 'Income', [...self::CRUD, 'manage', 'issue']],
            // `pay` IS NOT `approve`. Approving says the company owes it; paying says the money has
            // left the bank. The person who can do both alone is the person every payment-fraud
            // case turns out to have been.
            'payment' => ['Finance', 'Payments', [...self::CRUD, 'manage', 'approve', 'pay']],
            // The cash tin. `disburse` and `replenish` are separate because the custodian hands out
            // cash every day and should never be the person who decides how much goes back in.
            'petty_cash' => ['Finance', 'Petty Cash', [...self::CRUD, 'manage', 'disburse', 'replenish']],
            // Rates are company data (§47). Nothing in the application knows any country's tax.
            'tax_code' => ['Finance', 'Tax Codes', self::CRUD],
            // `run` is producing the figures and `approve` is deciding them, and they are separate
            // because one person doing both is how a payroll fraud goes unnoticed. `delete` reaches
            // only a run that has never been approved — an approved one is locked for everybody,
            // administrators included, and is corrected by a new run rather than tidied away (§59).
            // `view_confidential` is what guards salaries and bank details: `view` alone is the
            // permission almost everybody holds at scope `own`, reaching their own payslips.
            'payroll' => ['Finance', 'Payroll', ['view', 'create', 'edit', 'delete', 'export', 'run', 'approve', 'view_confidential']],

            // ------------------------------------------------------------- intelligence
            'report' => ['Intelligence', 'Reports', ['view', 'create', 'edit', 'delete', 'export', 'schedule']],
            'kpi' => ['Intelligence', 'KPIs', ['view', 'manage', 'export']],
            'dashboard' => ['Intelligence', 'Dashboards', ['view', 'view_executive', 'view_department']],
        ];
    }

    /**
     * Flat definitions ready for insertion.
     *
     * @return array<int,array{key:string,module:string,action:string,group:string,label:string,is_sensitive:bool}>
     */
    public static function definitions(): array
    {
        $rows = [];

        foreach (self::modules() as $module => [$group, $label, $actions]) {
            foreach ($actions as $action) {
                $rows[] = [
                    'key' => $module.'.'.$action,
                    'module' => $module,
                    'action' => $action,
                    'group' => $group,
                    'label' => self::describe($label, $action),
                    'is_sensitive' => self::isSensitive($module, $action),
                ];
            }
        }

        return $rows;
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Permissions that grant reach into money, personal data or the audit trail itself.
     *
     * Marked so the role editor can warn before granting them, and so the daily executive report
     * can surface who was given one. This is a UI affordance, not an enforcement point.
     */
    public static function isSensitive(string $module, string $action): bool
    {
        if (str_starts_with($action, 'view_confidential')) {
            return true;
        }

        return match (true) {
            $module === 'payroll',
            $module === 'audit_log',
            $module === 'role' && in_array($action, ['manage', 'assign', 'edit'], true),
            $module === 'user' && in_array($action, ['impersonate', 'reset_password', 'manage'], true),
            $module === 'setting' && $action === 'manage',
            $module === 'employee' && $action === 'view_confidential',
            // Where a supplier's money goes. The single most valuable permission in the platform to
            // an attacker, and the one an administrator is most likely to grant without thinking.
            $module === 'vendor' && $action === 'bank',
            // Hiring writes a personnel record — a person the platform will let be supervised,
            // paid and rostered; completing an exit switches a sign-in off. Both change who the
            // company consists of, which is why they sit here beside impersonation and password
            // resets rather than being treated as ordinary HR admin.
            $module === 'recruitment' && $action === 'hire',
            $module === 'employee_exit' && $action === 'complete',
            $module === 'performance' && $action === 'view_confidential',
            // Applying a movement rewrites somebody's job, department and reporting line — and the
            // reporting line is what the approval engine routes on, so it is a permission that can
            // quietly redirect whose inbox approvals land in.
            $module === 'employee_movement' && $action === 'apply',
            // The whole module. A disciplinary case is an allegation against a named person which
            // the company may itself conclude is untrue, and there is no action on it — not even
            // `view` — that an administrator should grant without being warned.
            $module === 'disciplinary' => true,
            default => false,
        };
    }

    /**
     * The human sentence for one permission, e.g. "Approve documents". Public because the role
     * editor renders the same wording the seeder stored, rather than inventing a second vocabulary.
     */
    public static function describe(string $label, string $action): string
    {
        $verb = match ($action) {
            'view' => 'View',
            'view_confidential' => 'View confidential',
            'view_contact' => 'View contact details',
            'view_financials' => 'View financials',
            'view_executive' => 'View executive',
            'view_department' => 'View department',
            'create' => 'Create',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'export' => 'Export',
            'manage' => 'Fully manage',
            'record_minutes' => 'Record minutes for',
            'reset_password' => 'Reset passwords for',
            'bank' => 'View and change bank details for',
            'blacklist' => 'Blacklist',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        return $verb.' '.lcfirst($label);
    }
}
