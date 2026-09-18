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
use App\Events\AssetCustodyTransferred;
use App\Events\AssetMaintenanceDue;
use App\Events\ChangeRequestDecided;
use App\Events\CorrectiveActionAssigned;
use App\Events\DocumentApproved;
use App\Events\DocumentRevisionRequested;
use App\Events\ExpenseDecided;
use App\Events\ExpenseSubmitted;
use App\Events\GoodsReceiptConfirmed;
use App\Events\GoodsReceiptDisputed;
use App\Events\IncidentReported;
use App\Events\InvestigationAssigned;
use App\Events\MaterialRequestDecided;
use App\Events\MaterialRequestSubmitted;
use App\Events\MeetingCancelled;
use App\Events\MeetingInvited;
use App\Events\MeetingMinutesCirculated;
use App\Events\MeetingRescheduled;
use App\Events\MemoIssued;
use App\Events\NcrDispositioned;
use App\Events\NcrIssued;
use App\Events\PaymentAwaitingAction;
use App\Events\PermitIssued;
use App\Events\PermitSuspended;
use App\Events\PurchaseOrderDecided;
use App\Events\PurchaseOrderIssued;
use App\Events\PurchaseOrderSubmitted;
use App\Events\PurchaseRequestDecided;
use App\Events\PurchaseRequestSubmitted;
use App\Events\QualityInspectionCompleted;
use App\Events\QualityInspectionRequested;
use App\Events\RfiAnswered;
use App\Events\RfqAwarded;
use App\Events\RfqIssued;
use App\Events\StockFellBelowReorderLevel;
use App\Events\SubmittalReviewed;
use App\Events\TaskAssigned;
use App\Events\TransmittalIssued;
use App\Events\VehicleDocumentExpiring;
use App\Events\WorkflowStalled;
use App\Listeners\NotifyAnnouncementAudience;
use App\Listeners\NotifyApprovalAssignees;
use App\Listeners\NotifyApprovalOutcome;
use App\Listeners\NotifyAssetCustodyTransferred;
use App\Listeners\NotifyAssetMaintenanceDue;
use App\Listeners\NotifyChangeRequestDecided;
use App\Listeners\NotifyCorrectiveActionAssigned;
use App\Listeners\NotifyDocumentApproved;
use App\Listeners\NotifyDocumentRevisionRequested;
use App\Listeners\NotifyExpenseDecided;
use App\Listeners\NotifyExpenseSubmitted;
use App\Listeners\NotifyGoodsReceiptConfirmed;
use App\Listeners\NotifyGoodsReceiptDisputed;
use App\Listeners\NotifyIncidentReported;
use App\Listeners\NotifyInvestigationAssigned;
use App\Listeners\NotifyMaterialRequestDecided;
use App\Listeners\NotifyMaterialRequestSubmitted;
use App\Listeners\NotifyMeetingCancelled;
use App\Listeners\NotifyMeetingMinutes;
use App\Listeners\NotifyMeetingParticipants;
use App\Listeners\NotifyMeetingRescheduled;
use App\Listeners\NotifyMemoRecipients;
use App\Listeners\NotifyNcrDispositioned;
use App\Listeners\NotifyNcrIssued;
use App\Listeners\NotifyPaymentAwaitingAction;
use App\Listeners\NotifyPermitIssued;
use App\Listeners\NotifyPermitSuspended;
use App\Listeners\NotifyPurchaseOrderDecided;
use App\Listeners\NotifyPurchaseOrderIssued;
use App\Listeners\NotifyPurchaseOrderSubmitted;
use App\Listeners\NotifyPurchaseRequestDecided;
use App\Listeners\NotifyPurchaseRequestSubmitted;
use App\Listeners\NotifyQualityInspectionCompleted;
use App\Listeners\NotifyQualityInspectionRequested;
use App\Listeners\NotifyRfiAnswered;
use App\Listeners\NotifyRfqAwarded;
use App\Listeners\NotifyRfqIssued;
use App\Listeners\NotifyStockBelowReorderLevel;
use App\Listeners\NotifySubmittalReviewed;
use App\Listeners\NotifyTaskAssignee;
use App\Listeners\NotifyTransmittalIssued;
use App\Listeners\NotifyVehicleDocumentExpiring;
use App\Listeners\NotifyWorkflowStalled;
use App\Listeners\RecordDocumentSignature;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Applicant;
use App\Models\ApplicantStage;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\AssetCustodyTransfer;
use App\Models\AssetMaintenance;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\ChangeRequest;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Contract;
use App\Models\CorrectiveAction;
use App\Models\CostCenter;
use App\Models\Deliverable;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Drawing;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Models\EmployeeExit;
use App\Models\EmployeeMovement;
use App\Models\EmployeePayComponent;
use App\Models\EmployeeSalary;
use App\Models\Expense;
use App\Models\Follower;
use App\Models\FuelLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Models\HseObservation;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\Income;
use App\Models\Investigation;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\Memo;
use App\Models\MemoRecipient;
use App\Models\Ncr;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NumberingScheme;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\OvertimeRequest;
use App\Models\PayComponent;
use App\Models\PayComponentBand;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewItem;
use App\Models\Permit;
use App\Models\PermitPrecaution;
use App\Models\PermitType;
use App\Models\PettyCashAccount;
use App\Models\PettyCashTransaction;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectDepartment;
use App\Models\ProjectMember;
use App\Models\ProjectRole;
use App\Models\ProjectType;
use App\Models\PublicHoliday;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationScore;
use App\Models\RecordPermission;
use App\Models\ReviewCycle;
use App\Models\ReviewCycleCompetency;
use App\Models\Rfi;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqVendor;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentHazard;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Signature;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\Submittal;
use App\Models\SubmittalItem;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\TaxCode;
use App\Models\Template;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\ToolboxTalk;
use App\Models\ToolboxTalkAttendee;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\TrainingRequirement;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\Visitor;
use App\Models\Warehouse;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use App\Models\WorkflowStageInstance;
use App\Policies\AnnouncementPolicy;
use App\Policies\ApplicantPolicy;
use App\Policies\ApprovalPolicy;
use App\Policies\AssetCustodyTransferPolicy;
use App\Policies\AssetMaintenancePolicy;
use App\Policies\AssetPolicy;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BudgetPolicy;
use App\Policies\ChangeRequestPolicy;
use App\Policies\ChecklistTemplatePolicy;
use App\Policies\ClientContactPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ContractPolicy;
use App\Policies\CorrectiveActionPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\DeliverablePolicy;
use App\Policies\DisciplinaryCasePolicy;
use App\Policies\DocumentCategoryPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentVersionPolicy;
use App\Policies\DrawingPolicy;
use App\Policies\EmployeeChecklistPolicy;
use App\Policies\EmployeeExitPolicy;
use App\Policies\EmployeeMovementPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeeSalaryPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FollowerPolicy;
use App\Policies\FuelLogPolicy;
use App\Policies\GoodsReceiptItemPolicy;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\HseInspectionItemPolicy;
use App\Policies\HseInspectionPolicy;
use App\Policies\HseObservationPolicy;
use App\Policies\IncidentPersonPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\IncomePolicy;
use App\Policies\InvestigationPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\LeaveSettingsPolicy;
use App\Policies\LocationPolicy;
use App\Policies\MaterialRequestItemPolicy;
use App\Policies\MaterialRequestPolicy;
use App\Policies\MeetingDecisionPolicy;
use App\Policies\MeetingParticipantPolicy;
use App\Policies\MeetingPolicy;
use App\Policies\MemoPolicy;
use App\Policies\MemoRecipientPolicy;
use App\Policies\NcrPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\NumberingSchemePolicy;
use App\Policies\NumberSequencePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\OrgUnitPolicy;
use App\Policies\OvertimeRequestPolicy;
use App\Policies\PayComponentPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PayslipPolicy;
use App\Policies\PerformanceReviewPolicy;
use App\Policies\PermitPolicy;
use App\Policies\PermitPrecautionPolicy;
use App\Policies\PermitTypePolicy;
use App\Policies\PettyCashAccountPolicy;
use App\Policies\PettyCashTransactionPolicy;
use App\Policies\PositionPolicy;
use App\Policies\ProjectCostPolicy;
use App\Policies\ProjectDepartmentPolicy;
use App\Policies\ProjectMemberPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectRolePolicy;
use App\Policies\ProjectTypePolicy;
use App\Policies\PurchaseOrderItemPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseRequestItemPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\QualityInspectionItemPolicy;
use App\Policies\QualityInspectionPolicy;
use App\Policies\QuotationItemPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\QuotationScorePolicy;
use App\Policies\ReviewCyclePolicy;
use App\Policies\RfiPolicy;
use App\Policies\RfqItemPolicy;
use App\Policies\RfqPolicy;
use App\Policies\RfqVendorPolicy;
use App\Policies\RiskAssessmentHazardPolicy;
use App\Policies\RiskAssessmentPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingPolicy;
use App\Policies\SignaturePolicy;
use App\Policies\StatusPolicy;
use App\Policies\StatusTransitionPolicy;
use App\Policies\StockBalancePolicy;
use App\Policies\StockItemPolicy;
use App\Policies\StockTransactionPolicy;
use App\Policies\SubmittalItemPolicy;
use App\Policies\SubmittalPolicy;
use App\Policies\TaskChecklistItemPolicy;
use App\Policies\TaskDependencyPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaxCodePolicy;
use App\Policies\TemplatePolicy;
use App\Policies\TimesheetPolicy;
use App\Policies\ToolboxTalkAttendeePolicy;
use App\Policies\ToolboxTalkPolicy;
use App\Policies\TrainingCoursePolicy;
use App\Policies\TrainingRecordPolicy;
use App\Policies\TrainingRequirementPolicy;
use App\Policies\TransmittalItemPolicy;
use App\Policies\TransmittalPolicy;
use App\Policies\UserPolicy;
use App\Policies\VacancyPolicy;
use App\Policies\VehicleDocumentPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\VendorContactPolicy;
use App\Policies\VendorPolicy;
use App\Policies\VisitorPolicy;
use App\Policies\WarehousePolicy;
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

            // --- Phase 4: HSE and quality --------------------------------------------
            // `investigation` and `corrective_action` carry more weight than the rest: they are the
            // two keys a workflow auto-action stores when an administrator configures "an incident
            // spawns an investigation and a corrective action". That configuration is data in a
            // customer's database, so these two strings are effectively public API — changing them
            // would break routes that already exist in the field (§32).
            'hse_incident' => Incident::class,
            'incident_person' => IncidentPerson::class,
            'investigation' => Investigation::class,
            'corrective_action' => CorrectiveAction::class,
            'hse_observation' => HseObservation::class,
            'hse_risk' => RiskAssessment::class,
            'risk_assessment_hazard' => RiskAssessmentHazard::class,
            'hse_inspection' => HseInspection::class,
            'hse_inspection_item' => HseInspectionItem::class,
            'toolbox_talk' => ToolboxTalk::class,
            'toolbox_talk_attendee' => ToolboxTalkAttendee::class,
            'permit_type' => PermitType::class,
            'permit' => Permit::class,
            'permit_precaution' => PermitPrecaution::class,
            'training_course' => TrainingCourse::class,
            'hse_training' => TrainingRecord::class,
            'quality_inspection' => QualityInspection::class,
            'quality_inspection_item' => QualityInspectionItem::class,
            'ncr' => Ncr::class,

            // --- Phase 5: the commercial foundation -----------------------------------
            // `vendor` and `client` are the keys Phase 4 wrote its `party` morph against before
            // either table existed. Every RFI, submittal and transmittal raised since then stores
            // a NULL party_type and a typed party_name; from here on a user can pick a real record
            // instead, and nothing already written has to move (§90).
            'vendor' => Vendor::class,
            'vendor_contact' => VendorContact::class,
            'client' => Client::class,
            'client_contact' => ClientContact::class,
            'contract' => Contract::class,

            // --- Phase 5: the procurement chain ---------------------------------------
            // The line-item models are here as well as the headers, and not for tidiness: the audit
            // engine writes a morph row for every model it touches, so a quotation line edited
            // during evaluation is as much a subject as the quotation is. A missing key here is not
            // a cosmetic gap — it is an exception thrown on save, which is how this list was found
            // to be short in the first place.
            'purchase_request' => PurchaseRequest::class,
            'purchase_request_item' => PurchaseRequestItem::class,
            'rfq' => Rfq::class,
            'rfq_item' => RfqItem::class,
            'rfq_vendor' => RfqVendor::class,
            'quotation' => Quotation::class,
            'quotation_item' => QuotationItem::class,
            'quotation_score' => QuotationScore::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_item' => PurchaseOrderItem::class,
            'goods_receipt' => GoodsReceipt::class,
            'goods_receipt_item' => GoodsReceiptItem::class,

            // --- Phase 5: inventory ----------------------------------------------------
            // `stock_transaction` is in this list even though nothing morphs TO it, because the
            // audit engine morphs FROM everything it touches and a ledger row is the most
            // audit-relevant object in the module. The balance is here for the same reason: a
            // stocktake adjustment writes to it, and "who changed the count" must be answerable.
            'warehouse' => Warehouse::class,
            'stock_item' => StockItem::class,
            'stock_balance' => StockBalance::class,
            'stock_transaction' => StockTransaction::class,
            'material_request' => MaterialRequest::class,
            'material_request_item' => MaterialRequestItem::class,

            // --- Phase 5: assets and fleet ---------------------------------------------
            // `asset_custody_transfer` is listed although it is append-only and can never be
            // edited. That is not a contradiction: the audit engine writes a morph row on CREATE
            // too, and the creation of a handover is precisely the event somebody will want to
            // trace back to a user and an IP six months later. A model absent from this map throws
            // on save, so an unlisted append-only model is a module that cannot write at all.
            'asset' => Asset::class,
            'asset_custody_transfer' => AssetCustodyTransfer::class,
            'asset_maintenance' => AssetMaintenance::class,
            'vehicle' => Vehicle::class,
            'fuel_log' => FuelLog::class,
            'vehicle_document' => VehicleDocument::class,

            // --- Phase 5: operational finance ----------------------------------------
            'project_cost' => ProjectCost::class,
            'cost_center' => CostCenter::class,
            'budget' => Budget::class,
            'budget_line' => BudgetLine::class,
            'tax_code' => TaxCode::class,
            'expense' => Expense::class,
            'income' => Income::class,
            'payment' => Payment::class,
            'petty_cash_account' => PettyCashAccount::class,
            'petty_cash_transaction' => PettyCashTransaction::class,

            'leave_type' => LeaveType::class,
            'public_holiday' => PublicHoliday::class,
            'leave_balance' => LeaveBalance::class,
            'leave_request' => LeaveRequest::class,
            'attendance_record' => AttendanceRecord::class,
            'timesheet' => Timesheet::class,
            'timesheet_entry' => TimesheetEntry::class,
            'overtime_request' => OvertimeRequest::class,

            // --- Phase 6: recruitment, onboarding and exit -----------------------------
            // `vacancy` earns its place twice over: it is the subject a workflow instance points
            // at, so it is what `entity_type` holds on the requisition route every tenant is
            // provisioned with, and it is what an activity entry stores when the route is decided.
            // A requisition whose key were missing here could not be submitted at all.
            'vacancy' => Vacancy::class,
            'applicant' => Applicant::class,
            'applicant_stage' => ApplicantStage::class,
            'checklist_template' => ChecklistTemplate::class,
            'checklist_template_item' => ChecklistTemplateItem::class,
            'employee_checklist' => EmployeeChecklist::class,
            'employee_checklist_item' => EmployeeChecklistItem::class,
            'employee_exit' => EmployeeExit::class,

            // --- Phase 6, part 4: development, movement and conduct --------------------
            'training_requirement' => TrainingRequirement::class,
            'review_cycle' => ReviewCycle::class,
            'review_cycle_competency' => ReviewCycleCompetency::class,
            // Both of these are the subject of a workflow instance as well as an audit entry, so
            // their absence here would make a review impossible to send for sign-off and a
            // promotion impossible to submit at all.
            'performance_review' => PerformanceReview::class,
            'performance_review_item' => PerformanceReviewItem::class,
            'employee_movement' => EmployeeMovement::class,
            'disciplinary_case' => DisciplinaryCase::class,
            // --- Phase 6, part 5: payroll ----------------------------------------------
            // EVERY ONE OF THESE IS AUDITABLE, and the morph map is required, so a model missing
            // from this list cannot be saved at all — the audit entry throws before the row is
            // written. That is the behaviour we want and the reason the map is strict: a payroll
            // table that wrote rows with no audit trail would be far worse than one that refuses.
            'pay_component' => PayComponent::class,
            'pay_component_band' => PayComponentBand::class,
            // The three that hold what somebody earns and where it is sent. Their audit entries are
            // the record of who changed a salary or a bank account and when — which is the evidence
            // any payroll fraud investigation starts from (§59).
            'employee_salary' => EmployeeSalary::class,
            'employee_pay_component' => EmployeePayComponent::class,
            'employee_bank_account' => EmployeeBankAccount::class,
            // A payroll run is the subject of a workflow instance like any other routed record.
            // Without this line a run could be calculated and never sent anywhere, which is the one
            // failure mode payroll cannot have.
            'payroll_run' => PayrollRun::class,
            'payslip' => Payslip::class,
            'payslip_line' => PayslipLine::class,
            // The visitor book. It is not workflow-routed, but it is the register most likely to be
            // read back months later by somebody asking who was on site the day something happened,
            // so its audit trail matters more than most, not less.
            'visitor' => Visitor::class,
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

            // --- Phase 4: HSE and quality --------------------------------------------
            // The line policies are not filler. IncidentPersonPolicy::viewMedical() is a SECOND
            // question layered on top of `view` — an injury, a body part and a treatment are
            // medical facts about a named person, and being able to open the incident is not the
            // same as being entitled to read them (§68). PermitPrecautionPolicy and
            // ToolboxTalkAttendeePolicy exist for the same reason in reverse: confirming a
            // precaution and signing for a talk are acts, and they are gated on who you are rather
            // than only on what you can see.
            Incident::class => IncidentPolicy::class,
            IncidentPerson::class => IncidentPersonPolicy::class,
            Investigation::class => InvestigationPolicy::class,
            CorrectiveAction::class => CorrectiveActionPolicy::class,
            HseObservation::class => HseObservationPolicy::class,
            RiskAssessment::class => RiskAssessmentPolicy::class,
            RiskAssessmentHazard::class => RiskAssessmentHazardPolicy::class,
            HseInspection::class => HseInspectionPolicy::class,
            HseInspectionItem::class => HseInspectionItemPolicy::class,
            ToolboxTalk::class => ToolboxTalkPolicy::class,
            ToolboxTalkAttendee::class => ToolboxTalkAttendeePolicy::class,
            PermitType::class => PermitTypePolicy::class,
            Permit::class => PermitPolicy::class,
            PermitPrecaution::class => PermitPrecautionPolicy::class,
            TrainingCourse::class => TrainingCoursePolicy::class,
            TrainingRecord::class => TrainingRecordPolicy::class,
            QualityInspection::class => QualityInspectionPolicy::class,
            QualityInspectionItem::class => QualityInspectionItemPolicy::class,
            Ncr::class => NcrPolicy::class,

            // --- Phase 5: the commercial foundation -----------------------------------
            // VendorPolicy carries two abilities the base class cannot express: `viewBankDetails`,
            // because where a supplier's money goes is a different question from who the supplier
            // is (payment redirection fraud is a quiet edit by somebody who legitimately holds
            // vendor.edit), and `blacklist`, because barring a company from every future order is
            // a commercial decision rather than a field change. The two contact policies delegate
            // to their parents for the same reason the task children do: a contact must never be
            // readable by somebody the vendor or client is not.
            Vendor::class => VendorPolicy::class,
            VendorContact::class => VendorContactPolicy::class,
            Client::class => ClientPolicy::class,
            ClientContact::class => ClientContactPolicy::class,
            Contract::class => ContractPolicy::class,

            // --- Phase 5: the procurement chain ---------------------------------------
            // Seven of these extend LineItemPolicy, which delegates every ability to the parent
            // record: a line has no reach of its own, and giving it one is how a form that posts
            // somebody else's order line ends up authorised. QuotationScorePolicy is the
            // exception worth reading — nobody may edit another person's mark, including the
            // organization owner, because an evaluation a manager can adjust is a signature
            // collection rather than an evaluation.
            PurchaseRequest::class => PurchaseRequestPolicy::class,
            PurchaseRequestItem::class => PurchaseRequestItemPolicy::class,
            Rfq::class => RfqPolicy::class,
            RfqItem::class => RfqItemPolicy::class,
            RfqVendor::class => RfqVendorPolicy::class,
            Quotation::class => QuotationPolicy::class,
            QuotationItem::class => QuotationItemPolicy::class,
            QuotationScore::class => QuotationScorePolicy::class,
            PurchaseOrder::class => PurchaseOrderPolicy::class,
            PurchaseOrderItem::class => PurchaseOrderItemPolicy::class,
            GoodsReceipt::class => GoodsReceiptPolicy::class,
            GoodsReceiptItem::class => GoodsReceiptItemPolicy::class,

            // Phase 5, part 3 — inventory. StockTransaction has a policy that permits no write of
            // any kind: the model itself refuses edits, and the policy says the same thing at the
            // HTTP door so a missing route is not the only thing standing between a curious user
            // and a ledger row.
            Warehouse::class => WarehousePolicy::class,
            StockItem::class => StockItemPolicy::class,
            StockBalance::class => StockBalancePolicy::class,
            StockTransaction::class => StockTransactionPolicy::class,
            MaterialRequest::class => MaterialRequestPolicy::class,
            MaterialRequestItem::class => MaterialRequestItemPolicy::class,

            // Phase 5, part 4 — assets and fleet. AssetCustodyTransferPolicy declares
            // `$module = 'asset'` rather than a module of its own, matching
            // AssetCustodyTransfer::permissionModule(): the right to read who has had a machine is
            // the right to read the machine, and splitting it into two permissions would create
            // combinations ("may see the history but not the asset") that mean nothing. Like the
            // stock ledger, it permits no write after the fact — the model throws, and the policy
            // refuses at the door so nobody meets that exception as a 500.
            Asset::class => AssetPolicy::class,
            AssetCustodyTransfer::class => AssetCustodyTransferPolicy::class,
            AssetMaintenance::class => AssetMaintenancePolicy::class,
            Vehicle::class => VehiclePolicy::class,
            FuelLog::class => FuelLogPolicy::class,
            VehicleDocument::class => VehicleDocumentPolicy::class,

            // Phase 5, part 5 — source-backed finance ledger.
            ProjectCost::class => ProjectCostPolicy::class,
            CostCenter::class => CostCenterPolicy::class,
            Budget::class => BudgetPolicy::class,
            BudgetLine::class => BudgetPolicy::class,
            TaxCode::class => TaxCodePolicy::class,
            Expense::class => ExpensePolicy::class,
            Income::class => IncomePolicy::class,
            Payment::class => PaymentPolicy::class,
            PettyCashAccount::class => PettyCashAccountPolicy::class,
            PettyCashTransaction::class => PettyCashTransactionPolicy::class,
            LeaveType::class => LeaveSettingsPolicy::class,
            PublicHoliday::class => LeaveSettingsPolicy::class,
            LeaveBalance::class => LeaveSettingsPolicy::class,
            LeaveRequest::class => LeaveRequestPolicy::class,
            AttendanceRecord::class => AttendanceRecordPolicy::class,
            Timesheet::class => TimesheetPolicy::class,
            OvertimeRequest::class => OvertimeRequestPolicy::class,
            Vacancy::class => VacancyPolicy::class,
            Applicant::class => ApplicantPolicy::class,
            // An interview step is reached through the applicant it belongs to, and the applicant
            // policy is what decides whether that person's file may be opened at all.
            ApplicantStage::class => ApplicantPolicy::class,
            ChecklistTemplate::class => ChecklistTemplatePolicy::class,
            ChecklistTemplateItem::class => ChecklistTemplatePolicy::class,
            EmployeeChecklist::class => EmployeeChecklistPolicy::class,
            EmployeeChecklistItem::class => EmployeeChecklistPolicy::class,
            EmployeeExit::class => EmployeeExitPolicy::class,

            // --- Phase 6, part 4 -------------------------------------------------------
            TrainingRequirement::class => TrainingRequirementPolicy::class,
            ReviewCycle::class => ReviewCyclePolicy::class,
            // A competency line is reached through its cycle, and a scored item through the review
            // it belongs to — which is what decides whether that person's assessment may be opened
            // at all. Pointing them at their parent's policy is what stops an item route being a
            // way to read a restricted review one line at a time (§94).
            ReviewCycleCompetency::class => ReviewCyclePolicy::class,
            PerformanceReview::class => PerformanceReviewPolicy::class,
            PerformanceReviewItem::class => PerformanceReviewPolicy::class,
            EmployeeMovement::class => EmployeeMovementPolicy::class,
            DisciplinaryCase::class => DisciplinaryCasePolicy::class,

            // Payroll. A band is part of its component and a payslip line is part of its payslip, so
            // each shares the parent's policy — a rate table that could be read on its own terms
            // would be a way round the component's own gate.
            PayComponent::class => PayComponentPolicy::class,
            PayComponentBand::class => PayComponentPolicy::class,
            EmployeeSalary::class => EmployeeSalaryPolicy::class,
            EmployeePayComponent::class => EmployeeSalaryPolicy::class,
            EmployeeBankAccount::class => EmployeeSalaryPolicy::class,
            PayrollRun::class => PayrollRunPolicy::class,
            Payslip::class => PayslipPolicy::class,
            PayslipLine::class => PayslipPolicy::class,

            Visitor::class => VisitorPolicy::class,
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

            // --- HSE -----------------------------------------------------------------
            // NOTHING HERE SPAWNS A RECORD. Phase 4's exit criterion is that an incident produces
            // an investigation and a corrective action THROUGH CONFIGURATION ALONE, so that work
            // belongs to workflow auto-actions an administrator sets up against a status — not to a
            // listener bolted onto IncidentReported, which would hard-code one company's process
            // and leave every other company unable to change it (§32, §73, §90). These listeners
            // only tell people.
            IncidentReported::class => [
                NotifyIncidentReported::class,
            ],
            InvestigationAssigned::class => [
                NotifyInvestigationAssigned::class,
            ],
            CorrectiveActionAssigned::class => [
                NotifyCorrectiveActionAssigned::class,
            ],

            // Permits are the one register where a notification failing to arrive is a safety
            // matter rather than an inconvenience, which is why both keys are mandatory in the
            // catalogue and cannot be muted per user.
            PermitIssued::class => [
                NotifyPermitIssued::class,
            ],
            PermitSuspended::class => [
                NotifyPermitSuspended::class,
            ],

            // --- quality -------------------------------------------------------------
            // Both halves of the sentence, unlike the engineering registers above: an inspection is
            // requested BY somebody inside this system and attended BY somebody inside it, so
            // unlike an RFI or a transmittal there is a real internal audience at both ends.
            QualityInspectionRequested::class => [
                NotifyQualityInspectionRequested::class,
            ],
            QualityInspectionCompleted::class => [
                NotifyQualityInspectionCompleted::class,
            ],
            NcrIssued::class => [
                NotifyNcrIssued::class,
            ],
            NcrDispositioned::class => [
                NotifyNcrDispositioned::class,
            ],

            // --- procurement ---------------------------------------------------------
            // The chain announces itself at the four points where somebody downstream is left
            // waiting: a requisition needing a decision, an order needing one, an order reaching
            // the supplier, and goods turning up (or turning up wrong). The steps in between —
            // an enquiry closing, a quotation being scored — are visible on the register and are
            // nobody's interruption.
            PurchaseRequestSubmitted::class => [
                NotifyPurchaseRequestSubmitted::class,
            ],
            PurchaseRequestDecided::class => [
                NotifyPurchaseRequestDecided::class,
            ],
            ExpenseSubmitted::class => [
                NotifyExpenseSubmitted::class,
            ],
            ExpenseDecided::class => [
                NotifyExpenseDecided::class,
            ],
            PaymentAwaitingAction::class => [
                NotifyPaymentAwaitingAction::class,
            ],
            RfqIssued::class => [
                NotifyRfqIssued::class,
            ],
            RfqAwarded::class => [
                NotifyRfqAwarded::class,
            ],
            PurchaseOrderSubmitted::class => [
                NotifyPurchaseOrderSubmitted::class,
            ],
            PurchaseOrderDecided::class => [
                NotifyPurchaseOrderDecided::class,
            ],
            PurchaseOrderIssued::class => [
                NotifyPurchaseOrderIssued::class,
            ],
            GoodsReceiptConfirmed::class => [
                NotifyGoodsReceiptConfirmed::class,
            ],
            GoodsReceiptDisputed::class => [
                NotifyGoodsReceiptDisputed::class,
            ],
            MaterialRequestSubmitted::class => [
                NotifyMaterialRequestSubmitted::class,
            ],
            MaterialRequestDecided::class => [
                NotifyMaterialRequestDecided::class,
            ],
            StockFellBelowReorderLevel::class => [
                NotifyStockBelowReorderLevel::class,
            ],

            // Phase 5, part 4 — assets and fleet. Two of these three are raised by a scheduled
            // sweep rather than by a person, which is why their listeners resolve no causer: the
            // system noticed, and putting the scheduler's account against the notice would be a
            // name in the audit trail against a decision that name did not make.
            AssetCustodyTransferred::class => [
                NotifyAssetCustodyTransferred::class,
            ],
            AssetMaintenanceDue::class => [
                NotifyAssetMaintenanceDue::class,
            ],
            VehicleDocumentExpiring::class => [
                NotifyVehicleDocumentExpiring::class,
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
