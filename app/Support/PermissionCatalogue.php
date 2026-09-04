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
            'form' => ['Workflow', 'Custom Forms', [...self::CRUD, 'manage', 'publish', 'submit']],

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

            // ------------------------------------------------------------- assets & fleet
            'asset' => ['Assets', 'Assets', [...self::CRUD, 'manage', 'assign', 'transfer', 'dispose']],
            'asset_maintenance' => ['Assets', 'Asset Maintenance', [...self::CRUD, 'manage', 'complete']],
            'vehicle' => ['Fleet', 'Vehicles', [...self::CRUD, 'manage', 'assign']],
            'fuel' => ['Fleet', 'Fuel Records', [...self::CRUD, 'manage', 'approve']],

            // ------------------------------------------------------------- people
            'employee' => ['People', 'Employees', [...self::CRUD, 'manage', 'view_contact', 'view_confidential']],
            'attendance' => ['People', 'Attendance', [...self::CRUD, 'manage', 'approve']],
            'timesheet' => ['People', 'Timesheets', [...self::CRUD, 'manage', 'submit', 'approve']],
            'leave' => ['People', 'Leave', [...self::CRUD, 'manage', 'submit', 'approve', 'reject']],
            'recruitment' => ['People', 'Recruitment', [...self::CRUD, 'manage']],
            'performance' => ['People', 'Performance', [...self::CRUD, 'manage', 'approve', 'view_confidential']],
            'visitor' => ['People', 'Visitors', [...self::CRUD, 'manage', 'check_in', 'check_out']],

            // ------------------------------------------------------------- finance
            'budget' => ['Finance', 'Budgets', [...self::CRUD, 'manage', 'approve']],
            'expense' => ['Finance', 'Expenses', [...self::CRUD, 'manage', 'submit', 'approve', 'reject']],
            'income' => ['Finance', 'Income', [...self::CRUD, 'manage']],
            'payment' => ['Finance', 'Payments', [...self::CRUD, 'manage', 'approve']],
            'payroll' => ['Finance', 'Payroll', ['view', 'create', 'edit', 'export', 'run', 'approve', 'view_confidential']],

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
            $module === 'performance' && $action === 'view_confidential' => true,
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
