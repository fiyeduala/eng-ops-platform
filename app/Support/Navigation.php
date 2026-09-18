<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Access\AccessResolver;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar (§96), built from permissions rather than from roles.
 *
 * Two rules make this maintainable as the application grows to sixty modules:
 *
 *   1. An item is listed only if the viewer holds at least one of its permissions. This is Gate 2
 *      and Gate 2 only — a usability affordance. It is NEVER the authorization: the route still
 *      carries `permission:` middleware and the controller still consults a policy (§6). Hiding a
 *      link that a hand-typed URL would still open is the exact mistake §84 warns about.
 *   2. A section with no visible items does not render its heading. Otherwise a field engineer
 *      sees five empty headings and concludes the system is broken.
 *
 * Items pointing at routes that do not exist yet are skipped rather than fataling, so this table
 * can describe the finished navigation while the phases land one at a time.
 */
class Navigation
{
    public function __construct(
        private readonly AccessResolver $resolver,
    ) {}

    /**
     * @return array<int,array{label:string,items:array<int,array{label:string,route:string,url:string,icon:string,active:bool}>}>
     */
    public function for(User $user): array
    {
        $sections = [];

        foreach ($this->definition() as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;   // the phase that adds it has not landed yet
                }

                $permissions = (array) ($item['permission'] ?? []);

                if ($permissions !== [] && ! $this->resolver->hasAny($user, ...$permissions)) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'url' => route($item['route']),
                    'icon' => $item['icon'],
                    'active' => $this->isActive($item['active'] ?? $item['route']),
                ];
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Route-name pattern(s) that light this item up, e.g. 'org-units.*'.
     */
    private function isActive(string|array $patterns): bool
    {
        return request()->routeIs(...(array) $patterns);
    }

    /**
     * The navigation table.
     *
     * Deliberately a plain array and not database-driven. Which modules exist is a property of
     * the deployed code; which of them a given person SEES is the configurable part, and that is
     * handled by the permission keys above. Making the menu itself editable would let an
     * administrator hide a module without removing anyone's access to it — the appearance of a
     * control rather than a control.
     *
     * @return array<int,array{label:string,items:array<int,array<string,mixed>>}>
     */
    private function definition(): array
    {
        return [
            [
                'label' => '',
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
                ],
            ],
            [
                'label' => 'Organization',
                'items' => [
                    ['label' => 'Org chart', 'route' => 'org-units.index', 'icon' => 'sitemap',
                        'permission' => 'org_unit.view', 'active' => 'org-units.*'],
                    ['label' => 'People', 'route' => 'employees.index', 'icon' => 'users',
                        'permission' => 'employee.view', 'active' => 'employees.*'],
                    ['label' => 'Positions', 'route' => 'positions.index', 'icon' => 'badge',
                        'permission' => 'position.view', 'active' => 'positions.*'],
                    ['label' => 'Locations', 'route' => 'locations.index', 'icon' => 'pin',
                        'permission' => 'location.view', 'active' => 'locations.*'],
                ],
            ],
            [
                // Phase 6. Self-service first: nearly everybody in the company books leave, and only
                // HR sets the rules it is booked against.
                'label' => 'People',
                'items' => [
                    ['label' => 'Leave', 'route' => 'leave.index', 'icon' => 'calendar',
                        'permission' => 'leave.view', 'active' => ['leave.index', 'leave.show', 'leave.create', 'leave.edit', 'leave.calendar']],
                    ['label' => 'Leave types', 'route' => 'leave-types.index', 'icon' => 'sliders',
                        'permission' => 'leave.manage', 'active' => 'leave-types.*'],
                    ['label' => 'Public holidays', 'route' => 'public-holidays.index', 'icon' => 'flag',
                        'permission' => 'leave.view', 'active' => 'public-holidays.*'],
                    ['label' => 'Leave balances', 'route' => 'leave-balances.index', 'icon' => 'coins',
                        'permission' => 'leave.manage', 'active' => 'leave-balances.*'],
                    ['label' => 'Attendance', 'route' => 'attendance.index', 'icon' => 'clock',
                        'permission' => 'attendance.view', 'active' => 'attendance.*'],
                    ['label' => 'Timesheets', 'route' => 'timesheets.index', 'icon' => 'clipboard',
                        'permission' => 'timesheet.view', 'active' => 'timesheets.*'],
                    ['label' => 'Overtime', 'route' => 'overtime.index', 'icon' => 'activity',
                        'permission' => 'overtime.view', 'active' => 'overtime.*'],

                    // Joining and leaving. Vacancies sit above applicants because an application
                    // only exists against one, and the lists sit above exits for the same reason.
                    ['label' => 'Vacancies', 'route' => 'vacancies.index', 'icon' => 'briefcase',
                        'permission' => 'recruitment.view', 'active' => 'vacancies.*'],
                    ['label' => 'Applicants', 'route' => 'applicants.index', 'icon' => 'user',
                        'permission' => 'recruitment.view', 'active' => ['applicants.*', 'applicant-stages.*']],
                    ['label' => 'Joining & leaving', 'route' => 'employee-checklists.index', 'icon' => 'check-square',
                        'permission' => 'checklist.view', 'active' => ['employee-checklists.*', 'checklist-items.*']],
                    ['label' => 'Checklists setup', 'route' => 'checklist-templates.index', 'icon' => 'sliders',
                        'permission' => 'checklist.manage', 'active' => 'checklist-templates.*'],
                    ['label' => 'Exits', 'route' => 'employee-exits.index', 'icon' => 'log-out',
                        'permission' => 'employee_exit.view', 'active' => 'employee-exits.*'],
                ],
            ],
            [
                'label' => 'Delivery',
                'items' => [
                    ['label' => 'Projects', 'route' => 'projects.index', 'icon' => 'briefcase',
                        'permission' => 'project.view', 'active' => 'projects.*'],
                    ['label' => 'Tasks', 'route' => 'tasks.index', 'icon' => 'check-square',
                        'permission' => 'task.view', 'active' => 'tasks.*'],
                ],
            ],
            [
                'label' => 'Communication',
                'items' => [
                    ['label' => 'Announcements', 'route' => 'announcements.index', 'icon' => 'megaphone',
                        'permission' => 'announcement.view', 'active' => 'announcements.*'],
                    ['label' => 'Memos', 'route' => 'memos.index', 'icon' => 'mail',
                        'permission' => 'memo.view', 'active' => 'memos.*'],
                    ['label' => 'Meetings', 'route' => 'meetings.index', 'icon' => 'users',
                        'permission' => 'meeting.view', 'active' => 'meetings.*'],

                    // The calendar has no permission of its own — it is a view over records that
                    // have theirs — so it is listed for anybody who deals in either source.
                    ['label' => 'Calendar', 'route' => 'calendar.index', 'icon' => 'calendar',
                        'permission' => ['meeting.view', 'task.view'], 'active' => 'calendar.*'],
                ],
            ],
            [
                'label' => 'Documents',
                'items' => [
                    ['label' => 'Documents', 'route' => 'documents.index', 'icon' => 'file-text',
                        'permission' => 'document.view', 'active' => 'documents.*'],
                    ['label' => 'Transmittals', 'route' => 'transmittals.index', 'icon' => 'download',
                        'permission' => 'transmittal.view', 'active' => 'transmittals.*'],

                    // The approvals inbox is listed for anyone who can act on OR merely watch an
                    // approval, because a reviewer with `approval.view` and no `approval.act` is a
                    // real role — the person who needs to see where a submission has got to.
                    ['label' => 'My approvals', 'route' => 'approvals.index', 'icon' => 'gavel',
                        'permission' => ['approval.view', 'approval.act'], 'active' => 'approvals.*'],
                ],
            ],
            [
                // The engineering registers. Ordered the way the work runs rather than
                // alphabetically: you plan deliverables, you draw them, you ask questions about
                // them, you send things for approval, and then somebody changes the scope.
                'label' => 'Engineering',
                'items' => [
                    ['label' => 'Deliverables', 'route' => 'deliverables.index', 'icon' => 'flag',
                        'permission' => 'deliverable.view', 'active' => 'deliverables.*'],
                    ['label' => 'Drawings', 'route' => 'drawings.index', 'icon' => 'pencil',
                        'permission' => 'drawing.view', 'active' => 'drawings.*'],
                    ['label' => 'RFIs & queries', 'route' => 'rfis.index', 'icon' => 'info',
                        'permission' => 'rfi.view', 'active' => 'rfis.*'],
                    ['label' => 'Submittals', 'route' => 'submittals.index', 'icon' => 'archive',
                        'permission' => 'submittal.view', 'active' => 'submittals.*'],
                    ['label' => 'Change requests', 'route' => 'change-requests.index', 'icon' => 'activity',
                        'permission' => 'change_request.view', 'active' => 'change-requests.*'],
                ],
            ],
            [
                // HSE, ordered by what a safety adviser opens most. Incidents first because that is
                // what the section is judged on; observations high up because the whole point of a
                // near-miss card is that reporting one takes thirty seconds; permits above training
                // because a control room refreshes "live now" all day.
                //
                // Investigations get their own entry even though every one of them is reached from
                // an incident, because "what is still under investigation" is a question asked
                // across the whole register rather than about one record. They are governed by
                // `hse_incident` throughout — an investigation is the incident's own paperwork.
                'label' => 'Health & safety',
                'items' => [
                    ['label' => 'Incidents', 'route' => 'incidents.index', 'icon' => 'alert',
                        'permission' => 'hse_incident.view', 'active' => 'incidents.*'],
                    ['label' => 'Investigations', 'route' => 'investigations.index', 'icon' => 'search',
                        'permission' => 'hse_incident.investigate', 'active' => 'investigations.*'],
                    ['label' => 'Corrective actions', 'route' => 'corrective-actions.index', 'icon' => 'target',
                        'permission' => 'corrective_action.view', 'active' => 'corrective-actions.*'],
                    ['label' => 'Observations', 'route' => 'hse-observations.index', 'icon' => 'info',
                        'permission' => 'hse_observation.view', 'active' => 'hse-observations.*'],
                    ['label' => 'Permits to work', 'route' => 'permits.index', 'icon' => 'lock',
                        'permission' => 'permit.view', 'active' => 'permits.*'],
                    ['label' => 'Risk assessments', 'route' => 'risk-assessments.index', 'icon' => 'shield',
                        'permission' => 'hse_risk.view', 'active' => 'risk-assessments.*'],
                    ['label' => 'Inspections', 'route' => 'hse-inspections.index', 'icon' => 'check-square',
                        'permission' => 'hse_inspection.view', 'active' => 'hse-inspections.*'],
                    ['label' => 'Toolbox talks', 'route' => 'toolbox-talks.index', 'icon' => 'users',
                        'permission' => 'toolbox_talk.view', 'active' => 'toolbox-talks.*'],
                    ['label' => 'Training records', 'route' => 'training-records.index', 'icon' => 'badge',
                        'permission' => 'hse_training.view', 'active' => 'training-records.*'],
                ],
            ],
            [
                'label' => 'Quality',
                'items' => [
                    ['label' => 'Inspection requests', 'route' => 'quality-inspections.index', 'icon' => 'check',
                        'permission' => 'quality_inspection.view', 'active' => 'quality-inspections.*'],
                    ['label' => 'Non-conformances', 'route' => 'ncrs.index', 'icon' => 'scroll',
                        'permission' => 'ncr.view', 'active' => 'ncrs.*'],
                ],
            ],
            [
                // Commercial. Clients before vendors because most people arrive here from a project
                // and the project has a client; contracts last because you reach one through the
                // party it is with far more often than you go looking for it by number.
                'label' => 'Commercial',
                'items' => [
                    ['label' => 'Clients', 'route' => 'clients.index', 'icon' => 'briefcase',
                        'permission' => 'client.view', 'active' => 'clients.*'],
                    ['label' => 'Vendors', 'route' => 'vendors.index', 'icon' => 'building',
                        'permission' => 'vendor.view', 'active' => 'vendors.*'],
                    ['label' => 'Contracts', 'route' => 'contracts.index', 'icon' => 'gavel',
                        'permission' => 'contract.view', 'active' => 'contracts.*'],
                ],
            ],
            [
                // Procurement, in the order the chain runs, because the order IS the mental model:
                // somebody asks, somebody shops, suppliers answer, we order, the goods turn up. A
                // person who has never used the module can read the sequence off the sidebar.
                //
                // Quotations sit between enquiries and orders even though most people reach one
                // through its enquiry, because a supplier chasing "did you get our quote" is
                // looking for a register, not for the enquiry it belonged to.
                'label' => 'Procurement',
                'items' => [
                    ['label' => 'Purchase requests', 'route' => 'purchase-requests.index', 'icon' => 'clipboard',
                        'permission' => 'purchase_request.view', 'active' => 'purchase-requests.*'],
                    ['label' => 'Enquiries (RFQ)', 'route' => 'rfqs.index', 'icon' => 'megaphone',
                        'permission' => 'rfq.view', 'active' => 'rfqs.*'],
                    ['label' => 'Quotations', 'route' => 'quotations.index', 'icon' => 'coins',
                        'permission' => 'quotation.view', 'active' => 'quotations.*'],
                    ['label' => 'Purchase orders', 'route' => 'purchase-orders.index', 'icon' => 'cart',
                        'permission' => 'purchase_order.view', 'active' => 'purchase-orders.*'],
                    ['label' => 'Goods receipts', 'route' => 'goods-receipts.index', 'icon' => 'truck',
                        'permission' => 'goods_receipt.view', 'active' => 'goods-receipts.*'],
                ],
            ],
            [
                // Inventory reads in the order a storekeeper's day runs: what we hold, what is on
                // the shelf, what somebody has asked for, and finally the ledger — which is put
                // last on purpose, because it is the answer to "what happened" rather than a place
                // anybody starts. It is also the only entry here gated on `stock.view` alone: the
                // value columns inside it are gated separately on `stock.view_value` (§68).
                'label' => 'Inventory',
                'items' => [
                    ['label' => 'Stock catalogue', 'route' => 'stock-items.index', 'icon' => 'archive',
                        'permission' => 'stock_item.view', 'active' => 'stock-items.*'],
                    ['label' => 'Stores', 'route' => 'warehouses.index', 'icon' => 'building',
                        'permission' => 'warehouse.view', 'active' => 'warehouses.*'],
                    ['label' => 'Material requests', 'route' => 'material-requests.index', 'icon' => 'clipboard',
                        'permission' => 'material_request.view', 'active' => 'material-requests.*'],
                    ['label' => 'Stock movements', 'route' => 'stock.index', 'icon' => 'truck',
                        'permission' => 'stock.view', 'active' => 'stock.*'],
                ],
            ],
            [
                // Assets and fleet are ONE group, not two, because a vehicle here is an asset with
                // an engine rather than a parallel register — the truck and the generator share a
                // custody history, an acquisition trail and a retirement. The fleet entries below
                // are the extra screens a vehicle needs and a generator does not: an odometer, a
                // fuel account and a wallet of documents that lapse.
                //
                // Maintenance is listed second rather than under each asset because the person who
                // opens it is a planner asking "what is due this month" across everything, not a
                // custodian looking at one machine. Vehicle documents get their own entry for the
                // same reason: the question is "what expires soon", which is a date query across
                // the fleet, and nobody wants to visit forty vehicles to ask it.
                'label' => 'Assets & fleet',
                'items' => [
                    ['label' => 'Asset register', 'route' => 'assets.index', 'icon' => 'archive',
                        'permission' => 'asset.view', 'active' => 'assets.*'],
                    ['label' => 'Maintenance', 'route' => 'asset-maintenances.index', 'icon' => 'target',
                        'permission' => 'asset_maintenance.view', 'active' => 'asset-maintenances.*'],
                    ['label' => 'Vehicles', 'route' => 'vehicles.index', 'icon' => 'truck',
                        'permission' => 'vehicle.view', 'active' => 'vehicles.*'],
                    ['label' => 'Fuel records', 'route' => 'fuel-logs.index', 'icon' => 'clipboard',
                        'permission' => 'fuel_log.view', 'active' => 'fuel-logs.*'],
                    ['label' => 'Vehicle documents', 'route' => 'vehicle-documents.index', 'icon' => 'file-text',
                        'permission' => 'vehicle_document.view', 'active' => 'vehicle-documents.*'],
                ],
            ],
            [
                'label' => 'Finance',
                'items' => [
                    ['label' => 'Project costs', 'route' => 'project-costs.index', 'icon' => 'coins',
                        'permission' => 'expense.view', 'active' => 'project-costs.*'],
                    ['label' => 'Budgets', 'route' => 'budgets.index', 'icon' => 'clipboard',
                        'permission' => 'budget.view', 'active' => 'budgets.*'],
                    ['label' => 'Cost centers', 'route' => 'cost-centers.index', 'icon' => 'building',
                        'permission' => 'budget.view', 'active' => 'cost-centers.*'],
                    ['label' => 'Expenses', 'route' => 'expenses.index', 'icon' => 'file-text',
                        'permission' => 'expense.view', 'active' => 'expenses.*'],
                    ['label' => 'Income', 'route' => 'incomes.index', 'icon' => 'activity',
                        'permission' => 'income.view', 'active' => 'incomes.*'],
                    ['label' => 'Payments', 'route' => 'payments.index', 'icon' => 'coins',
                        'permission' => 'payment.view', 'active' => 'payments.*'],
                    ['label' => 'Petty cash', 'route' => 'petty-cash.index', 'icon' => 'archive',
                        'permission' => 'petty_cash.view', 'active' => 'petty-cash.*'],
                    ['label' => 'Tax codes', 'route' => 'tax-codes.index', 'icon' => 'sliders',
                        'permission' => 'tax_code.view', 'active' => 'tax-codes.*'],
                ],
            ],
            [
                'label' => 'Administration',
                'items' => [
                    ['label' => 'User accounts', 'route' => 'users.index', 'icon' => 'key',
                        'permission' => ['user.view', 'user.manage'], 'active' => 'users.*'],
                    ['label' => 'Roles & permissions', 'route' => 'roles.index', 'icon' => 'shield',
                        'permission' => ['role.edit', 'role.manage', 'role.create'], 'active' => 'roles.*'],
                    ['label' => 'Company profile', 'route' => 'organization.edit', 'icon' => 'building',
                        'permission' => ['organization.view', 'organization.configure'], 'active' => 'organization.*'],
                    ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'sliders',
                        'permission' => ['setting.view', 'setting.manage'], 'active' => 'settings.*'],
                    ['label' => 'Workflows', 'route' => 'workflows.index', 'icon' => 'target',
                        'permission' => ['workflow.view', 'workflow.manage'], 'active' => 'workflows.*'],
                    ['label' => 'Document registers', 'route' => 'document-categories.index', 'icon' => 'archive',
                        'permission' => ['document_category.view', 'document_category.manage'],
                        'active' => 'document-categories.*'],
                    ['label' => 'Templates', 'route' => 'templates.index', 'icon' => 'file-text',
                        'permission' => ['template.view', 'template.manage'], 'active' => 'templates.*'],

                    // Two catalogues, listed here rather than beside the registers they feed,
                    // because both are administration: `permit_types.precaution_template` is the
                    // safety checklist copied onto every future permit, and a course's
                    // `validity_months` decides when every certificate of that kind lapses. Both
                    // are shown only to the `manage` holder, which is why their permission lists
                    // omit the matching `.view` — everybody with `.view` can already read a type's
                    // name on the records that use it, and does not need a settings screen for it.
                    ['label' => 'Permit types', 'route' => 'permit-types.index', 'icon' => 'lock',
                        'permission' => 'permit.manage', 'active' => 'permit-types.*'],
                    ['label' => 'Training courses', 'route' => 'training-courses.index', 'icon' => 'badge',
                        'permission' => 'hse_training.manage', 'active' => 'training-courses.*'],

                    ['label' => 'Audit trail', 'route' => 'audit.index', 'icon' => 'scroll',
                        'permission' => 'audit_log.view', 'active' => 'audit.*'],
                ],
            ],
        ];
    }
}
