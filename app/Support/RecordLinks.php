<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;

/**
 * Where does a record live?
 *
 * The workflow engine is deliberately ignorant of what it is routing — a stage instance points at a
 * `subject` and never asks what class that is (§90). That ignorance is what lets one approvals inbox
 * serve documents, leave, purchases and timesheets, but it leaves exactly one gap: the inbox still
 * has to offer a link to the thing.
 *
 * Rather than let every generic screen grow its own `match ($subject::class)` — which is how five
 * screens end up with five slightly different ideas of where a Document lives, four of which are out
 * of date — the mapping lives here, once, keyed by the SAME morph aliases the database stores.
 *
 * TWO PROPERTIES WORTH KEEPING:
 *
 *   - It never throws. A record whose module has not been built yet, or which has been hard-deleted
 *     mid-flight, returns null and the caller renders plain text. A missing hyperlink is a small
 *     disappointment; a 500 on somebody's approval inbox is not.
 *
 *   - It is NOT an authorization check. This answers "where would that record be", not "may you open
 *     it". Callers ask the policy separately and only then use the URL — never the reverse, because
 *     a link is a disclosure of existence as much as the page behind it is (§61, §68).
 */
final class RecordLinks
{
    /**
     * Morph alias => route name. Every route named here takes the record as its only parameter.
     *
     * Keyed by alias rather than class so the map reads the same way the `*_type` columns do, and
     * so an entry for a module that has not been written yet is inert rather than a fatal
     * class-not-found.
     *
     * WRITTEN OUT RATHER THAN DERIVED, and the reason is in the list itself: `hse_incident` lives
     * at `incidents.show`, `hse_risk` at `risk-assessments.show`, `leave_request` at `leave.show`.
     * A convention that pluralised the alias would be right about sixty of these and silently
     * wrong about the rest, and "silently wrong" for a link means a 500 on somebody's approval
     * inbox. Every entry is checked against `Route::has()` before it is used, so a name that goes
     * stale degrades to plain text rather than to an exception.
     *
     * The registers a person SEARCHES are drawn from this map (SearchCatalogue), because a result
     * nobody can open is not a result. A new module joins both by adding one line here.
     *
     * @var array<string,string>
     */
    private const ROUTES = [
        // Work
        'project' => 'projects.show',
        'task' => 'tasks.show',
        'deliverable' => 'deliverables.show',
        'drawing' => 'drawings.show',
        'rfi' => 'rfis.show',
        'submittal' => 'submittals.show',
        'change_request' => 'change-requests.show',
        'transmittal' => 'transmittals.show',

        // Correspondence
        'memo' => 'memos.show',
        'announcement' => 'announcements.show',
        'meeting' => 'meetings.show',
        'document' => 'documents.show',
        'form' => 'forms.show',
        'form_submission' => 'form-submissions.show',
        'workflow' => 'workflows.show',

        // People
        'employee' => 'employees.show',
        'user' => 'users.show',
        'org_unit' => 'org-units.show',
        'employee_checklist' => 'employee-checklists.show',
        'employee_exit' => 'employee-exits.show',
        'employee_movement' => 'employee-movements.show',
        'disciplinary_case' => 'disciplinary-cases.show',
        'performance_review' => 'performance-reviews.show',
        'review_cycle' => 'review-cycles.show',
        'leave_request' => 'leave.show',
        'overtime_request' => 'overtime.show',
        'timesheet' => 'timesheets.show',
        'payroll_run' => 'payroll-runs.show',
        'payslip' => 'payslips.show',
        'applicant' => 'applicants.show',
        'vacancy' => 'vacancies.show',
        'hse_training' => 'training-records.show',

        // HSE and quality
        'hse_incident' => 'incidents.show',
        'investigation' => 'investigations.show',
        'corrective_action' => 'corrective-actions.show',
        'hse_observation' => 'hse-observations.show',
        'hse_risk' => 'risk-assessments.show',
        'hse_inspection' => 'hse-inspections.show',
        'toolbox_talk' => 'toolbox-talks.show',
        'permit' => 'permits.show',
        'ncr' => 'ncrs.show',
        'quality_inspection' => 'quality-inspections.show',

        // Commercial and money
        'client' => 'clients.show',
        'contract' => 'contracts.show',
        'vendor' => 'vendors.show',
        'quotation' => 'quotations.show',
        'rfq' => 'rfqs.show',
        'purchase_request' => 'purchase-requests.show',
        'purchase_order' => 'purchase-orders.show',
        'goods_receipt' => 'goods-receipts.show',
        'material_request' => 'material-requests.show',
        'budget' => 'budgets.show',
        'cost_center' => 'cost-centers.show',
        'expense' => 'expenses.show',
        'income' => 'incomes.show',
        'payment' => 'payments.show',
        'petty_cash_account' => 'petty-cash.show',

        // Assets and stores
        'asset' => 'assets.show',
        'asset_maintenance' => 'asset-maintenances.show',
        'stock_item' => 'stock-items.show',
        'warehouse' => 'warehouses.show',
        'visitor' => 'visitors.show',
    ];

    /**
     * A URL for this record, or null if there is nowhere sensible to send somebody.
     *
     * The route is checked for existence rather than assumed: this map outlives individual phases,
     * and a stale entry should degrade to plain text rather than take a page down.
     */
    public static function for(?Model $record): ?string
    {
        if ($record === null || $record->getKey() === null) {
            return null;
        }

        $route = self::ROUTES[self::alias($record)] ?? null;

        if ($route === null || ! Route::has($route)) {
            return null;
        }

        return route($route, $record);
    }

    /**
     * What kind of thing this is, in words a person recognises.
     *
     * Derived from the alias — "document_category" reads as "Document category" — so a module added
     * to the morph map gets a sensible label here without a second list to keep in step.
     */
    public static function label(?Model $record): string
    {
        if ($record === null) {
            return 'Record';
        }

        return ucfirst(str_replace('_', ' ', self::alias($record)));
    }

    /**
     * The route name for a morph alias, or null if that register has no page of its own.
     *
     * Exists so a caller holding an alias — a search catalogue, a report, an audit row — can ask
     * the same question a caller holding a record asks, without having to build a prototype of the
     * model first just to be told there is nowhere to send anybody.
     */
    public static function routeFor(string $alias): ?string
    {
        $route = self::ROUTES[$alias] ?? null;

        return $route !== null && Route::has($route) ? $route : null;
    }

    /** The morph alias, falling back to the class name for anything unmapped. */
    private static function alias(Model $record): string
    {
        $alias = array_search($record::class, Relation::morphMap(), true);

        return is_string($alias) ? $alias : class_basename($record);
    }
}
