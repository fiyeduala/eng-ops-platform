<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Applicant;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Budget;
use App\Models\ChangeRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CorrectiveAction;
use App\Models\Deliverable;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\HseInspection;
use App\Models\HseObservation;
use App\Models\Incident;
use App\Models\Income;
use App\Models\Investigation;
use App\Models\LeaveRequest;
use App\Models\MaterialRequest;
use App\Models\Meeting;
use App\Models\Memo;
use App\Models\Ncr;
use App\Models\Payment;
use App\Models\PerformanceReview;
use App\Models\Permit;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\QualityInspection;
use App\Models\Quotation;
use App\Models\Rfi;
use App\Models\Rfq;
use App\Models\RiskAssessment;
use App\Models\StockItem;
use App\Models\Submittal;
use App\Models\Task;
use App\Models\ToolboxTalk;
use App\Models\TrainingRecord;
use App\Models\Vacancy;
use App\Models\Vendor;
use App\Models\Visitor;
use Illuminate\Support\Str;

/**
 * The registers a company may add its own fields to (§73).
 *
 * WHY THIS IS A LIST AND NOT A SCAN. It would be possible to discover this by walking the models
 * directory for everything using HasCustomFields, and it would be wrong in a way that only shows
 * up in production: a register appears on the configuration screen the moment somebody adds a
 * trait, whether or not its create form renders the fields or its service saves them. An
 * administrator would define a field, fill it in, save, and find it empty — with nothing anywhere
 * saying why. A line in this file is a statement that the whole path works end to end.
 *
 * WHAT IS NOT HERE, DELIBERATELY. Line items — a purchase-order line, a timesheet entry, an
 * inspection item — are not registers; they belong to a parent and are edited as a grid, and a
 * per-line custom field is a repeating-table problem that the form builder (Part 3) solves
 * properly. Configuration records (statuses, roles, option lists) are not here either: a custom
 * field on a status is a field on the configuration of a field.
 *
 * `entity_type` is the SAME KEY the status tables, the numbering schemes and the permission
 * catalogue use. One vocabulary across all five engines is what makes "configure a new company"
 * a single mental model rather than four.
 */
final class CustomFieldCatalogue
{
    /**
     * entity_type => [group, label, model class]
     *
     * The label is what an administrator reads; nothing branches on it. The model class is what
     * the value table stores in `subject_type` — via the morph map, never the FQCN.
     *
     * @return array<string,array{0:string,1:string,2:class-string}>
     */
    public static function all(): array
    {
        return [
            // ------------------------------------------------------------- projects
            'project' => ['Projects', 'Projects', Project::class],
            'task' => ['Projects', 'Tasks', Task::class],
            'deliverable' => ['Projects', 'Deliverables', Deliverable::class],
            'meeting' => ['Projects', 'Meetings', Meeting::class],
            'change_request' => ['Projects', 'Change Requests', ChangeRequest::class],

            // ------------------------------------------------------------- documents
            'document' => ['Documents', 'Documents', Document::class],
            'drawing' => ['Documents', 'Drawings', Drawing::class],
            'memo' => ['Documents', 'Memos', Memo::class],

            // ------------------------------------------------------------- engineering
            'rfi' => ['Engineering', 'RFIs', Rfi::class],
            'submittal' => ['Engineering', 'Submittals', Submittal::class],

            // ------------------------------------------------------------- hse
            'hse_incident' => ['HSE', 'Incidents', Incident::class],
            'hse_observation' => ['HSE', 'Observations', HseObservation::class],
            'hse_inspection' => ['HSE', 'Inspections', HseInspection::class],
            'hse_risk' => ['HSE', 'Risk Assessments', RiskAssessment::class],
            'permit' => ['HSE', 'Work Permits', Permit::class],
            'toolbox_talk' => ['HSE', 'Toolbox Talks', ToolboxTalk::class],
            'investigation' => ['HSE', 'Investigations', Investigation::class],

            // ------------------------------------------------------------- quality
            'ncr' => ['Quality', 'Non-Conformance Reports', Ncr::class],
            'quality_inspection' => ['Quality', 'Quality Inspections', QualityInspection::class],
            'corrective_action' => ['Quality', 'Corrective Actions', CorrectiveAction::class],

            // ------------------------------------------------------------- procurement
            'purchase_request' => ['Procurement', 'Purchase Requests', PurchaseRequest::class],
            'purchase_order' => ['Procurement', 'Purchase Orders', PurchaseOrder::class],
            'rfq' => ['Procurement', 'Requests for Quotation', Rfq::class],
            'quotation' => ['Procurement', 'Quotations', Quotation::class],
            'goods_receipt' => ['Procurement', 'Goods Receipts', GoodsReceipt::class],
            'material_request' => ['Procurement', 'Material Requests', MaterialRequest::class],
            'vendor' => ['Procurement', 'Vendors', Vendor::class],

            // ------------------------------------------------------------- commercial
            'client' => ['Commercial', 'Clients', Client::class],
            'contract' => ['Commercial', 'Contracts', Contract::class],

            // ------------------------------------------------------------- finance
            'budget' => ['Finance', 'Budgets', Budget::class],
            'expense' => ['Finance', 'Expenses', Expense::class],
            'income' => ['Finance', 'Income', Income::class],
            'payment' => ['Finance', 'Payments', Payment::class],

            // ------------------------------------------------------------- people
            'employee' => ['People', 'Employees', Employee::class],
            'applicant' => ['People', 'Applicants', Applicant::class],
            'vacancy' => ['People', 'Vacancies', Vacancy::class],
            'leave_request' => ['People', 'Leave Requests', LeaveRequest::class],
            'disciplinary_case' => ['People', 'Disciplinary Cases', DisciplinaryCase::class],
            'performance_review' => ['People', 'Performance Reviews', PerformanceReview::class],
            'employee_exit' => ['People', 'Exits', EmployeeExit::class],
            'hse_training' => ['People', 'Training Records', TrainingRecord::class],

            // ------------------------------------------------------------- assets
            'asset' => ['Assets', 'Assets', Asset::class],
            'asset_maintenance' => ['Assets', 'Maintenance Jobs', AssetMaintenance::class],
            'stock_item' => ['Assets', 'Stock Items', StockItem::class],
            'visitor' => ['Assets', 'Visitors', Visitor::class],
        ];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function supports(string $entityType): bool
    {
        return array_key_exists($entityType, self::all());
    }

    /**
     * What an administrator calls this register.
     *
     * Falls back to a humanised key rather than throwing, because a field row may outlive the
     * catalogue entry that created it — a register withdrawn in a later version leaves its fields
     * behind, and a configuration screen that fatals is worse than one that says "Rfq".
     */
    public static function label(string $entityType): string
    {
        return self::all()[$entityType][1]
            ?? Str::of($entityType)->replace('_', ' ')->title()->toString();
    }

    public static function group(string $entityType): string
    {
        return self::all()[$entityType][0] ?? 'Other';
    }

    /** @return class-string|null */
    public static function modelClass(string $entityType): ?string
    {
        return self::all()[$entityType][2] ?? null;
    }

    /**
     * The catalogue arranged for a picker, grouped the way the register menu is.
     *
     * @return array<string,array<string,string>> group => [entity_type => label]
     */
    public static function grouped(): array
    {
        $out = [];

        foreach (self::all() as $entityType => [$group, $label]) {
            $out[$group][$entityType] = $label;
        }

        return $out;
    }
}
