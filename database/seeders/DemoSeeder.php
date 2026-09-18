<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Document\PdfRenderer;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Organization\OrgUnitService;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\Announcement;
use App\Models\Applicant;
use App\Models\Asset;
use App\Models\AttendanceRecord;
use App\Models\ChecklistTemplateItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\GoodsReceiptItem;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Meeting;
use App\Models\Memo;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PermitType;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\ProjectType;
use App\Models\PublicHoliday;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Rfi;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\Status;
use App\Models\StockItem;
use App\Models\Submittal;
use App\Models\Task;
use App\Models\TrainingCourse;
use App\Models\Transmittal;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageInstance;
use App\Services\AnnouncementService;
use App\Services\ApplicantService;
use App\Services\AssetMaintenanceService;
use App\Services\AssetService;
use App\Services\AttendanceService;
use App\Services\ChangeRequestService;
use App\Services\ChecklistService;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Services\CorrectiveActionService;
use App\Services\DeliverableService;
use App\Services\DocumentService;
use App\Services\DocumentVersionService;
use App\Services\DrawingService;
use App\Services\EmployeeExitService;
use App\Services\ExpenseService;
use App\Services\FleetService;
use App\Services\GoodsReceiptService;
use App\Services\HseInspectionService;
use App\Services\HseObservationService;
use App\Services\IncidentService;
use App\Services\IncomeService;
use App\Services\InvestigationService;
use App\Services\LeaveService;
use App\Services\MaterialRequestService;
use App\Services\MeetingService;
use App\Services\MemoService;
use App\Services\NcrService;
use App\Services\OvertimeService;
use App\Services\PaymentService;
use App\Services\PermitService;
use App\Services\PettyCashService;
use App\Services\ProjectService;
use App\Services\ProjectTeamService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\QualityInspectionService;
use App\Services\QuotationService;
use App\Services\RfiService;
use App\Services\RfqService;
use App\Services\RiskAssessmentService;
use App\Services\StockItemService;
use App\Services\StockService;
use App\Services\SubmittalService;
use App\Services\TaskChecklistService;
use App\Services\TaskDependencyService;
use App\Services\TaskService;
use App\Services\TaxCodeService;
use App\Services\TemplateService;
use App\Services\TimesheetService;
use App\Services\ToolboxTalkService;
use App\Services\TrainingRecordService;
use App\Services\TransmittalService;
use App\Services\VacancyService;
use App\Services\VehicleDocumentService;
use App\Services\VendorService;
use App\Services\WarehouseService;
use App\Services\WorkflowDefinitionService;
use App\Support\Enums\ChangeDisposition;
use App\Support\Enums\Decision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Realistic fictional demonstration data (section 92).
 *
 * TWO companies are seeded, and they are deliberately nothing alike:
 *
 *   Meridian Engineering & Infrastructure — an EPC contractor with a full four-level tree
 *     (Division › Department › Section › Team), matrix project staffing and a document-control
 *     function.
 *
 *   Kestrel Facilities Management — a flat organization with NO divisions and NO sections:
 *     departments hang straight off the root and teams hang straight off departments.
 *
 * That contrast is the point. If both companies work without a line of company-specific code,
 * section 73 is satisfied by demonstration rather than by assertion — and the pair gives the
 * isolation tests two genuinely different tenants to prove Gate 1 against.
 *
 * PASSWORDS: no password is hard-coded (section 92). A cryptographically random one is generated
 * per run and printed once, unless DEMO_PASSWORD is set in the environment for repeatable local
 * testing. Nothing here is safe to run against production, and DatabaseSeeder refuses to.
 */
class DemoSeeder extends Seeder
{
    private string $password;

    // Delivery services, resolved per tenant by bootDelivery(). Not constructor-injected: each of
    // them memoizes the configured status vocabulary of whichever organization is in context, and
    // one instance shared across both companies would file Kestrel's work under Meridian's
    // statuses — a bug that would look, from the outside, like the state machine being wrong.
    private ProjectService $projects;

    private ProjectTeamService $teams;

    private TaskService $tasks;

    private TaskChecklistService $checklists;

    private TaskDependencyService $dependencies;

    private MemoService $memos;

    private AnnouncementService $notices;

    private MeetingService $meetings;

    private StatusMachine $statuses;

    // The document half, resolved by bootDocuments() for the same reason as the block above: the
    // numbering engine, the revision sequencer and the status vocabulary are all per-tenant.
    private DocumentService $documents;

    private DocumentVersionService $revisions;

    private TransmittalService $transmittals;

    private TemplateService $templates;

    private WorkflowDefinitionService $routes;

    private WorkflowEngine $approvals;

    private PdfRenderer $pdfs;

    /** @var array<string,ProjectType> slug => type, for the tenant currently being seeded */
    private array $projectTypes = [];

    /** @var array<string,ProjectRole> slug => role, for the tenant currently being seeded */
    private array $projectRoles = [];

    public function __construct(
        private readonly OrganizationProvisioner $provisioner,
        private readonly OrgUnitService $units,
        private readonly OrganizationContext $context,
    ) {}

    public function run(): void
    {
        $this->password = (string) (env('DEMO_PASSWORD') ?: Str::password(20));

        $meridian = $this->seedMeridian();
        $kestrel = $this->seedKestrel();

        // The work each company is actually doing. Structure alone demonstrates nothing about a
        // system whose entire point is delivery, and an empty projects list is the first thing an
        // evaluator opens.
        $this->seedMeridianDelivery($meridian);
        $this->seedKestrelDelivery($kestrel);

        // What each company is SAYING to itself (§17, §18, §19). Seeded after delivery because
        // memos, notices and meetings are about the work: they name the projects, the departments
        // and the people the pass above created, and a meeting whose action item points at nothing
        // demonstrates the opposite of §90.
        $this->seedMeridianCommunications($meridian);
        $this->seedKestrelCommunications($kestrel);

        // What each company has WRITTEN DOWN, and who had to agree to it (§27–§38). Last of the
        // content passes because a document is about a project, is owned by a person, originates in
        // a department and is approved by whoever heads it — every one of which the passes above
        // created. The two companies deliberately route documents differently, and neither of them
        // needed a line of company-specific code to do it (§73).
        $this->seedMeridianDocuments($meridian);
        $this->seedKestrelDocuments($kestrel);

        // What each company is BUILDING, and what it is doing about safety and quality (§39–§58).
        // Last, because these registers reference everything above them: a deliverable belongs to a
        // project, a drawing carries a document, an inspection is attended by a named inspector and
        // an incident is reported against a site team. The two companies configure this half very
        // differently — Meridian routes incidents to its HSE manager and lets that review open the
        // investigation on its own, Kestrel configures no route at all and closes near misses by
        // hand — and the difference is entirely in their data.
        $this->seedMeridianEngineering($meridian);
        $this->seedMeridianHse($meridian);
        $this->seedMeridianQuality($meridian);
        $this->seedKestrelHse($kestrel);
        // Commercial before procurement, because it is the one that reaches back: it creates the
        // clients and contracts the earlier chapters have been naming in free text, then points the
        // projects and the correspondence at them. Running it earlier would leave nothing to link.
        $this->seedMeridianCommercial($meridian);
        $this->seedKestrelCommercial($kestrel);

        // Stores before procurement, and the order is forced by the code rather than chosen. A
        // delivery reaches a shelf only if the shelf already exists and the requisition line named
        // a catalogue item: GoodsReceiptService refuses a store that is not open at the moment the
        // receipt is written, and GoodsReceiptItem::stockItem() reads the catalogue reference back
        // through the order to the requisition. Seed the stores afterwards and the procurement
        // chapter below still runs — and quietly puts nothing on any shelf, which is precisely the
        // half-connected system §90 exists to forbid.
        $this->seedMeridianInventory($meridian);
        $this->seedKestrelInventory($kestrel);

        // Procurement after commercial, and it has to be: an enquiry goes to vendors that must
        // already exist, and an order is placed against a contract that must already have been
        // signed. This is the chapter where the five registers become one chain.
        $this->seedMeridianProcurement($meridian);
        $this->seedKestrelProcurement($kestrel);

        // Assets after procurement, and it has to be that way round for one row: Kestrel's air
        // handling unit is registered FROM the goods receipt that delivered it, so the delivery has
        // to exist before the asset can point at it. Everything else here could have been seeded
        // anywhere; that one joint is what fixes the order (§90).
        $this->seedMeridianAssets($meridian);
        $this->seedKestrelAssets($kestrel);

        // Finance last among the content chapters, because it is where the others are paid for: an
        // income is raised against a client contract, a payment is released against a purchase
        // order, and every approved claim and petty cash voucher lands on a project's cost line.
        $this->seedMeridianFinance($meridian);
        $this->seedKestrelFinance($kestrel);

        // People: public holidays and leave. Every decided request below went through the same
        // route engine as the documents above — the line manager acted on a stage, nothing more.
        // Week numbers are counted from 1 January, so the leave lands in this year however late
        // the demo is seeded; working days are Monday to Friday.
        $this->seedPeople($meridian, $this->context->within($meridian, fn () => $this->accountOf($this->staff(), 'MEI-0017')), [
            ['New Year\'s Day', now()->year.'-01-01', true],
            ['Workers\' Day', now()->year.'-05-01', true],
            ['Independence Day', now()->year.'-10-01', true],
            ['Christmas Day', now()->year.'-12-25', true],
            ['Boxing Day', now()->year.'-12-26', true],
        ], [
            ['MEI-0006', 'ANNUAL', 10, 5, 'approved', 'MEI-0005', 'Enjoy the break — Ibrahim covers the slab checks.'],
            ['MEI-0009', 'ANNUAL', 30, 4, 'pending', null, null],
            ['MEI-0006', 'SICK', 14, 2, 'approved', 'MEI-0005', 'Get well soon.'],
            ['MEI-0009', 'ANNUAL', 12, 5, 'rejected', 'MEI-0005', 'Pile tests are that week. Pick another week and I will approve it.'],
            ['MEI-0014', 'ANNUAL', 40, 3, 'draft', null, null],
        ]);
        $this->seedPeople($kestrel, $this->context->within($kestrel, fn () => $this->accountOf($this->staff(), 'KFM-008')), [
            ['New Year\'s Day', now()->year.'-01-01', true],
            ['Spring bank holiday', now()->year.'-05-25', false],
            ['Summer bank holiday', now()->year.'-08-31', false],
            ['Christmas Day', now()->year.'-12-25', true],
        ], [
            ['KFM-005', 'ANNUAL', 20, 5, 'approved', 'KFM-003', 'Approved. Erin is on call that week.'],
            ['KFM-006', 'ANNUAL', 33, 3, 'pending', null, null],
            ['KFM-006', 'UNPAID', 8, 2, 'rejected', 'KFM-003', 'Chiller changeover needs both technicians.'],
        ]);

        // Hours: the shifts people clocked, the weeks they booked against projects, and the overtime
        // they asked for beforehand. Last week's sheet has been decided, this week's is still open —
        // which is what a Monday morning actually looks like.
        $this->seedTime($meridian, $this->context->within($meridian, fn () => $this->accountOf($this->staff(), 'MEI-0017')), [
            ['MEI-0006', 2, 'approved', 'MEI-0005', 'Matches the site register. Approved.'],
            ['MEI-0006', 1, 'pending', null, null],
            ['MEI-0009', 1, 'pending', null, null],
            ['MEI-0014', 0, 'draft', null, null],
        ], [
            ['MEI-0006', 9, '3', 'approved', 'Concrete pour has to finish the same day.', 'MEI-0005', '2.5'],
            ['MEI-0009', 2, '4', 'pending', 'Pile test rig is only on site until Friday.', null, null],
            ['MEI-0009', 12, '5', 'rejected', 'Catching up on the reinforcement schedule.', 'MEI-0005', null],
        ]);
        $this->seedTime($kestrel, $this->context->within($kestrel, fn () => $this->accountOf($this->staff(), 'KFM-008')), [
            ['KFM-005', 2, 'approved', 'KFM-003', 'Approved.'],
            ['KFM-005', 1, 'pending', null, null],
            ['KFM-006', 1, 'draft', null, null],
        ], [
            ['KFM-006', 6, '2', 'approved', 'Chiller changeover has to run out of hours.', 'KFM-003', '2'],
            ['KFM-005', 3, '3', 'pending', 'Call-out for the tenant fit-out snag list.', null, null],
        ]);

        // Hiring, joining and leaving. After the time chapter because it reaches into what the
        // earlier passes built: a requisition is decided by the requester's line manager through the
        // same engine as a drawing, and an exit is blocked by the asset and petty cash registers
        // seeded above — Ruth Okafor's exit cannot clear precisely because her calibrated instrument
        // is still on her name in the asset register (§84).
        $this->seedMeridianHiring($meridian);
        $this->seedKestrelHiring($kestrel);

        // The rows above were written straight to the database, so nothing observed them happening.
        // A demo whose record timelines are all blank teaches the wrong lesson about Engine 4 — the
        // timeline is meant to be the first thing you see on a record, and an empty one looks like
        // a missing feature rather than a missing history.
        $this->seedHistory($meridian);
        $this->seedHistory($kestrel);

        $this->command?->newLine();
        $this->command?->info('Demo organizations created:');
        $this->command?->table(
            ['Organization', 'Structure', 'Units', 'People', 'Projects', 'Tasks', 'Memos/notices/meetings',
                'Docs/awaiting/issued', 'Clients/vendors/contracts', 'Sign in with'],
            [
                ['Meridian Engineering & Infrastructure', '4-level (division→team)',
                    ...$this->tally($meridian), 'a.okonkwo@meridian-eng.example'],
                ['Kestrel Facilities Management', 'flat (department→team)',
                    ...$this->tally($kestrel), 'r.calder@kestrel-fm.example'],
            ],
        );

        $this->command?->newLine();
        $this->command?->warn('Password for every demo account this run:  '.$this->password);
        $this->command?->line('It is generated randomly and shown only here. Re-seed to get a new one,');
        $this->command?->line('or set DEMO_PASSWORD in .env for a repeatable local value.');
    }

    /**
     * Unit and headcount totals for one tenant.
     *
     * Counted INSIDE that organization's context on purpose. Reading these relations from
     * outside a tenant context returns zero — not because the rows are missing, but because the
     * global scope compiles to `organization_id = 0` when no tenant is set. That is the engine
     * behaving correctly; a seeder summary that reported real numbers from nowhere would mean
     * the isolation guarantee had a hole in it.
     *
     * @return array{0:int,1:int,2:int,3:int,4:string,5:string,6:string}
     */
    private function tally(Organization $organization): array
    {
        return $this->context->within($organization, fn () => [
            $organization->orgUnits()->count(),
            $organization->employees()->count(),
            Project::query()->count(),
            Task::query()->count(),
            sprintf(
                '%d / %d / %d',
                Memo::query()->count(),
                Announcement::query()->count(),
                Meeting::query()->count(),
            ),
            sprintf(
                '%d / %d / %d',
                Document::query()->count(),
                // Decisions actually sitting in somebody's inbox, not routes in flight: a stage
                // whose assignee list emptied is a stalled route with nothing to act on, and
                // counting it here would promise the evaluator an approval that is not there.
                WorkflowStageInstance::query()->where('status', WorkflowStageInstance::STATUS_PENDING)->count(),
                Transmittal::query()->whereNotNull('issued_at')->count(),
            ),
            sprintf(
                '%d / %d / %d',
                Client::query()->count(),
                Vendor::query()->count(),
                Contract::query()->count(),
            ),
        ]);
    }

    // ================================================================= Meridian (deep tree)

    private function seedMeridian(): Organization
    {
        $organization = $this->provisioner->provision([
            'name' => 'Meridian Engineering & Infrastructure',
            'slug' => 'meridian-eng',
            'legal_name' => 'Meridian Engineering & Infrastructure Limited',
            'industry' => 'Engineering, Procurement & Construction',
            'email' => 'info@meridian-eng.example',
            'phone' => '+234 1 270 4400',
            'address' => '18 Ozumba Mbadiwe Avenue, Victoria Island',
            'country' => 'NG',
            'timezone' => 'Africa/Lagos',
            'currency' => 'NGN',
        ]);

        return $this->context->within($organization, function () use ($organization) {
            $locations = $this->locations($organization, [
                ['Head Office', 'HQ', 'office', 'Lagos', 6.4281, 3.4219, 150],
                ['Delta Terminal Project Site', 'SITE-DT', 'site', 'Warri', 5.5160, 5.7500, 800],
                ['Ikeja Fabrication Yard', 'YARD-IK', 'yard', 'Lagos', 6.6018, 3.3515, 400],
                ['Central Warehouse', 'WH-01', 'warehouse', 'Lagos', 6.5244, 3.3792, 200],
            ]);

            // --- structure: division › department › section › team ----------------------
            $operations = $this->unit($organization, 'division', 'Operations', 'OPS');
            $corporate = $this->unit($organization, 'division', 'Corporate Services', 'CORP');
            $supply = $this->unit($organization, 'division', 'Supply Chain', 'SUP');

            $engineering = $this->unit($organization, 'department', 'Engineering', 'ENG', $operations);
            $projects = $this->unit($organization, 'department', 'Project Management', 'PMO', $operations);
            $construction = $this->unit($organization, 'department', 'Construction', 'CON', $operations);
            $hse = $this->unit($organization, 'department', 'Health, Safety & Environment', 'HSE', $operations);
            $quality = $this->unit($organization, 'department', 'Quality Assurance', 'QA', $operations);

            $hr = $this->unit($organization, 'department', 'Human Resources', 'HR', $corporate);
            $finance = $this->unit($organization, 'department', 'Finance', 'FIN', $corporate);
            $it = $this->unit($organization, 'department', 'Information Technology', 'IT', $corporate);
            $admin = $this->unit($organization, 'department', 'Administration', 'ADM', $corporate);

            $procurement = $this->unit($organization, 'department', 'Procurement', 'PRC', $supply);
            $stores = $this->unit($organization, 'department', 'Stores & Warehousing', 'STR', $supply);
            $fleet = $this->unit($organization, 'department', 'Fleet & Logistics', 'FLT', $supply);

            $civil = $this->unit($organization, 'section', 'Civil & Structural', 'ENG-CS', $engineering);
            $mechanical = $this->unit($organization, 'section', 'Mechanical & Piping', 'ENG-MP', $engineering);
            $electrical = $this->unit($organization, 'section', 'Electrical & Instrumentation', 'ENG-EI', $engineering);
            $docControl = $this->unit($organization, 'section', 'Document Control', 'ENG-DC', $engineering);

            $structural = $this->unit($organization, 'team', 'Structural Design', 'ENG-CS-SD', $civil);
            $geotech = $this->unit($organization, 'team', 'Geotechnical', 'ENG-CS-GT', $civil);
            $piping = $this->unit($organization, 'team', 'Piping Design', 'ENG-MP-PD', $mechanical);
            $siteTeam = $this->unit($organization, 'team', 'Delta Terminal Site Team', 'CON-DT', $construction);

            $roles = Role::withoutGlobalScopes()->where('organization_id', $organization->id)
                ->get()->keyBy('slug');

            $positions = $this->positions($organization, [
                ['Managing Director', 'MD', null, 1, true],
                ['Operations Director', 'OPD', $operations, 2, true],
                ['Engineering Manager', 'ENGM', $engineering, 3, true],
                ['Project Manager', 'PM', $projects, 3, true],
                ['Lead Structural Engineer', 'LSE', $structural, 4, true],
                ['Structural Engineer', 'SE', $structural, 5, false],
                ['Piping Engineer', 'PE', $piping, 5, false],
                ['Electrical Engineer', 'EE', $electrical, 5, false],
                ['Document Controller', 'DC', $docControl, 5, false],
                ['HSE Manager', 'HSEM', $hse, 3, true],
                ['HSE Officer', 'HSEO', $hse, 5, false],
                ['QA/QC Manager', 'QAM', $quality, 3, true],
                ['QA/QC Inspector', 'QAI', $quality, 5, false],
                ['Site Manager', 'SM', $siteTeam, 4, true],
                ['HR Manager', 'HRM', $hr, 3, true],
                ['Finance Manager', 'FINM', $finance, 3, true],
                ['Procurement Manager', 'PRCM', $procurement, 3, true],
                ['Procurement Officer', 'PRCO', $procurement, 5, false],
                ['Stores Manager', 'STRM', $stores, 3, true],
                ['Store Officer', 'STRO', $stores, 5, false],
                ['Fleet Manager', 'FLTM', $fleet, 4, true],
                ['Driver', 'DRV', $fleet, 7, false],
                ['IT Administrator', 'ITA', $it, 4, false],
                ['Office Administrator', 'OFA', $admin, 5, false],
            ]);

            // --- people ------------------------------------------------------------------
            // Reporting lines are supervisor_id, an arbitrary-depth self-reference — never a
            // hard-coded chain (section 86). The tree below is four levels deep in places and
            // three in others, which is exactly how real companies look.
            $md = $this->person($organization, 'MEI-0001', 'Adaeze', 'Okonkwo', $operations, $positions['MD'],
                null, $locations['HQ'], $roles['managing-director'], 'a.okonkwo');

            $itAdmin = $this->person($organization, 'MEI-0002', 'Tobias', 'Renner', $it, $positions['ITA'],
                $md, $locations['HQ'], $roles['system-administrator'], 't.renner');

            $opsDirector = $this->person($organization, 'MEI-0003', 'Chinedu', 'Balogun', $operations, $positions['OPD'],
                $md, $locations['HQ'], $roles['operations-manager'], 'c.balogun');

            $engManager = $this->person($organization, 'MEI-0004', 'Farida', 'Yusuf', $engineering, $positions['ENGM'],
                $opsDirector, $locations['HQ'], $roles['engineering-manager'], 'f.yusuf');

            $leadEngineer = $this->person($organization, 'MEI-0005', 'Marcus', 'Adeyemi', $structural, $positions['LSE'],
                $engManager, $locations['HQ'], $roles['lead-engineer'], 'm.adeyemi');

            // Three engineers in three different sections. Each sees their own DEPARTMENT plus
            // their own PROJECTS — the two axes of section 8, side by side in one company.
            $this->person($organization, 'MEI-0006', 'Ngozi', 'Eze', $structural, $positions['SE'],
                $leadEngineer, $locations['HQ'], $roles['engineer'], 'n.eze');
            $this->person($organization, 'MEI-0007', 'Segun', 'Afolabi', $piping, $positions['PE'],
                $engManager, $locations['HQ'], $roles['engineer'], 's.afolabi');
            $this->person($organization, 'MEI-0008', 'Priya', 'Raghunathan', $electrical, $positions['EE'],
                $engManager, $locations['HQ'], $roles['engineer'], 'p.raghunathan');
            $this->person($organization, 'MEI-0009', 'Ibrahim', 'Sule', $geotech, $positions['SE'],
                $leadEngineer, $locations['HQ'], $roles['engineer'], 'i.sule');

            $this->person($organization, 'MEI-0010', 'Helen', 'Obi', $docControl, $positions['DC'],
                $engManager, $locations['HQ'], $roles['document-controller'], 'h.obi');

            $projectManager = $this->person($organization, 'MEI-0011', 'Daniel', 'Mbeki', $projects, $positions['PM'],
                $opsDirector, $locations['SITE-DT'], $roles['project-manager'], 'd.mbeki');

            $this->person($organization, 'MEI-0012', 'Kelechi', 'Nwosu', $siteTeam, $positions['SM'],
                $projectManager, $locations['SITE-DT'], $roles['general-employee'], 'k.nwosu');

            $hseManager = $this->person($organization, 'MEI-0013', 'Grace', 'Ibrahim', $hse, $positions['HSEM'],
                $opsDirector, $locations['HQ'], $roles['hse-manager'], 'g.ibrahim');
            $this->person($organization, 'MEI-0014', 'Emeka', 'Duru', $hse, $positions['HSEO'],
                $hseManager, $locations['SITE-DT'], $roles['hse-officer'], 'e.duru');

            $qaManager = $this->person($organization, 'MEI-0015', 'Yemi', 'Bakare', $quality, $positions['QAM'],
                $opsDirector, $locations['HQ'], $roles['qa-qc-manager'], 'y.bakare');
            $this->person($organization, 'MEI-0016', 'Ruth', 'Okafor', $quality, $positions['QAI'],
                $qaManager, $locations['SITE-DT'], $roles['qa-qc-officer'], 'r.okafor');

            $this->person($organization, 'MEI-0017', 'Amina', 'Tijani', $hr, $positions['HRM'],
                $md, $locations['HQ'], $roles['hr-manager'], 'a.tijani');
            $this->person($organization, 'MEI-0018', 'Victor', 'Anyanwu', $finance, $positions['FINM'],
                $md, $locations['HQ'], $roles['finance-manager'], 'v.anyanwu');

            $prcManager = $this->person($organization, 'MEI-0019', 'Bisi', 'Adeleke', $procurement, $positions['PRCM'],
                $md, $locations['HQ'], $roles['procurement-manager'], 'b.adeleke');
            $this->person($organization, 'MEI-0020', 'Uche', 'Nnamdi', $procurement, $positions['PRCO'],
                $prcManager, $locations['HQ'], $roles['procurement-officer'], 'u.nnamdi');

            // THE TWO HALVES OF A STORE, and the reason there are two people here rather than one.
            // `store-manager` holds `warehouse.*`, `stock.adjust` and `stock.reverse`; `store-officer`
            // deliberately holds none of the three. Opening a store, correcting a count against the
            // shelf and undoing a posting are the three ways stock moves with no document behind
            // them, and the person who moves the goods is not the person who may do them (§9).
            //
            // Her employee number is higher than his because she was hired after him. Employee
            // numbers are issue order and nothing else — the hierarchy is `supervisor_id`, which is
            // why a later hire supervising an earlier one costs nothing to represent (§86).
            $storesManager = $this->person($organization, 'MEI-0026', 'Halima', 'Yakubu', $stores,
                $positions['STRM'], $opsDirector, $locations['WH-01'], $roles['store-manager'], 'h.yakubu');

            $this->person($organization, 'MEI-0021', 'Sadiq', 'Bello', $stores, $positions['STRO'],
                $storesManager, $locations['WH-01'], $roles['store-officer'], 's.bello');

            $fleetManager = $this->person($organization, 'MEI-0022', 'Tunde', 'Ogunleye', $fleet, $positions['FLTM'],
                $opsDirector, $locations['YARD-IK'], $roles['fleet-manager'], 't.ogunleye');
            $this->person($organization, 'MEI-0023', 'Musa', 'Garba', $fleet, $positions['DRV'],
                $fleetManager, $locations['YARD-IK'], $roles['driver'], 'm.garba');

            $this->person($organization, 'MEI-0024', 'Chioma', 'Nwachukwu', $admin, $positions['OFA'],
                $md, $locations['HQ'], $roles['office-administrator'], 'c.nwachukwu');

            // A bounded administrator (section 10). The role is pinned to ONE org unit, so this
            // person administers Engineering and has no reach whatsoever into Finance or HR.
            $boundedAdmin = $this->person($organization, 'MEI-0025', 'Lara', 'Fashola', $engineering,
                $positions['OFA'], $engManager, $locations['HQ'], $roles['general-employee'], 'l.fashola');
            $boundedAdmin->user?->assignRole($roles['department-administrator'], 'org_unit', $engineering->id);

            // Heads of units, so "the Engineering Manager of this project" is resolvable by the
            // workflow engine without naming a person (section 22).
            $this->setHeads([
                $operations->id => $opsDirector->id,
                $engineering->id => $engManager->id,
                $projects->id => $projectManager->id,
                $hse->id => $hseManager->id,
                $quality->id => $qaManager->id,
                $procurement->id => $prcManager->id,
                $stores->id => $storesManager->id,
                $fleet->id => $fleetManager->id,
                $civil->id => $leadEngineer->id,
                $structural->id => $leadEngineer->id,
            ]);

            return $organization;
        });
    }

    // ================================================================= Kestrel (flat tree)

    private function seedKestrel(): Organization
    {
        $organization = $this->provisioner->provision([
            'name' => 'Kestrel Facilities Management',
            'slug' => 'kestrel-fm',
            'legal_name' => 'Kestrel Facilities Management Ltd',
            'industry' => 'Facilities Management',
            'email' => 'hello@kestrel-fm.example',
            'phone' => '+44 161 496 0110',
            'address' => 'Unit 7, Trafford Point, Manchester',
            'country' => 'GB',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
        ]);

        return $this->context->within($organization, function () use ($organization) {
            $locations = $this->locations($organization, [
                ['Manchester Office', 'MCR', 'office', 'Manchester', 53.4668, -2.2339, 100],
                ['Leeds Depot', 'LDS', 'yard', 'Leeds', 53.7965, -1.5478, 250],
            ]);

            // No divisions. No sections. Departments sit at the root and teams sit directly
            // beneath them — a shape the deep-tree company never uses, running the same code.
            $ops = $this->unit($organization, 'department', 'Operations', 'OPS');
            $maintenance = $this->unit($organization, 'department', 'Maintenance', 'MNT');
            $compliance = $this->unit($organization, 'department', 'Compliance & Safety', 'CMP');
            $business = $this->unit($organization, 'department', 'Business Services', 'BUS');

            $mechTeam = $this->unit($organization, 'team', 'Mechanical Team', 'MNT-MEC', $maintenance);
            $elecTeam = $this->unit($organization, 'team', 'Electrical Team', 'MNT-ELE', $maintenance);
            $helpdesk = $this->unit($organization, 'team', 'Helpdesk', 'OPS-HD', $ops);

            $roles = Role::withoutGlobalScopes()->where('organization_id', $organization->id)
                ->get()->keyBy('slug');

            $positions = $this->positions($organization, [
                ['Managing Director', 'MD', null, 1, true],
                ['Operations Manager', 'OM', $ops, 2, true],
                ['Maintenance Manager', 'MM', $maintenance, 2, true],
                ['Compliance Manager', 'CM', $compliance, 2, true],
                ['Mechanical Technician', 'MT', $mechTeam, 5, false],
                ['Electrical Technician', 'ET', $elecTeam, 5, false],
                ['Helpdesk Coordinator', 'HC', $helpdesk, 5, false],
                ['Finance & HR Officer', 'FHO', $business, 4, false],
            ]);

            $md = $this->person($organization, 'KFM-001', 'Rowan', 'Calder', $ops, $positions['MD'],
                null, $locations['MCR'], $roles['managing-director'], 'r.calder');

            // Somebody has to be able to configure the system, and in a company of eight it is the
            // MD. Meridian has a dedicated IT administrator; Kestrel does not, and the platform has
            // to work for both without either being the special case (§73). Note that this is an
            // ADDITIONAL role rather than a wider one: managing-director still grants no
            // `setting.manage`, and it is the administrator hat that carries `workflow.*` — which
            // is what lets him rewrite the starter approval route further down.
            $md->user?->assignRole($roles['system-administrator']);

            $opsManager = $this->person($organization, 'KFM-002', 'Priti', 'Shah', $ops, $positions['OM'],
                $md, $locations['MCR'], $roles['operations-manager'], 'p.shah');

            $maintManager = $this->person($organization, 'KFM-003', 'Duncan', 'Reilly', $maintenance, $positions['MM'],
                $md, $locations['LDS'], $roles['department-head'], 'd.reilly');

            // The spares cage at Leeds is his, because in a company of eight there is nobody else
            // for it to belong to. Meridian split the store across two people precisely so neither
            // could both move the goods and correct the count; Kestrel cannot, and the platform's
            // answer is to let them say so out loud rather than to pretend. The waiver is recorded
            // on every movement it touches — see `inventory.separate_approver_and_issuer` in the
            // stores chapter — which is the difference between a control relaxed and a control
            // absent (§73).
            $maintManager->user?->assignRole($roles['store-manager']);

            $complianceManager = $this->person($organization, 'KFM-004', 'Nadia', 'Osei', $compliance, $positions['CM'],
                $md, $locations['MCR'], $roles['hse-manager'], 'n.osei');

            $this->person($organization, 'KFM-005', 'Callum', 'Whitfield', $mechTeam, $positions['MT'],
                $maintManager, $locations['LDS'], $roles['general-employee'], 'c.whitfield');
            $this->person($organization, 'KFM-006', 'Erin', 'Doyle', $elecTeam, $positions['ET'],
                $maintManager, $locations['LDS'], $roles['general-employee'], 'e.doyle');
            $this->person($organization, 'KFM-007', 'Josh', 'Aiken', $helpdesk, $positions['HC'],
                $opsManager, $locations['MCR'], $roles['general-employee'], 'j.aiken');

            // One person wearing three hats — normal in a small company, and the reason roles are
            // additive rather than a single "user type" column.
            $generalist = $this->person($organization, 'KFM-008', 'Marianne', 'Ellis', $business, $positions['FHO'],
                $md, $locations['MCR'], $roles['hr-officer'], 'm.ellis');
            $generalist->user?->assignRole($roles['finance-officer']);
            $generalist->user?->assignRole($roles['office-administrator']);

            // A fourth hat, and the one that makes the document register usable here. `office-
            // administrator` deliberately grants no `transmittal.*` — issuing a controlled document
            // to a client is a document-control act, not an office-admin one — so without this the
            // company could file documents and never send any. In a firm this size the person who
            // runs the office is also the document controller, which is the whole argument for
            // roles being additive rather than a single "user type" column (§9).
            $generalist->user?->assignRole($roles['document-controller']);

            $this->setHeads([
                $ops->id => $opsManager->id,
                $maintenance->id => $maintManager->id,
                $compliance->id => $complianceManager->id,
                $business->id => $generalist->id,
            ]);

            return $organization;
        });
    }

    // ================================================================= the work itself

    /**
     * Meridian's delivery: three projects, their teams, and the tasks people are working on
     * (§13, §16).
     *
     * Written through the services rather than inserted, for the same reason the history is: every
     * project here goes through the numbering engine, the configured state machine, manager seating
     * and the activity timeline, so a defect in the delivery path shows up on `db:seed` rather than
     * on somebody's first real project.
     *
     * Every assignment below is one the policies would also have allowed, which matters more than
     * it looks. Seed data that could not have been produced through the UI teaches people states
     * the system will not let them reach. So: the project manager only tasks people he has on his
     * own team (TaskPolicy::canCommit), the engineering manager only tasks people inside
     * Engineering, and the two transitions that name a permission — `task.complete` and
     * `task.verify` — are made by somebody who actually holds it. Which is why the assessment
     * report is closed by the Operations Director and not by the lead engineer who wrote it: the
     * doer says it is finished, somebody else says it is acceptable.
     *
     * Three things are here to be demonstrated rather than merely populated:
     *
     *   - The two visibility axes side by side. A restricted commercial task sits in the PMO with
     *     the site team on the same project and unable to see it (§8, Gate 5).
     *   - A dependency that is actually holding something up: the client transmittal cannot close
     *     while the stress analysis it waits on is still in review.
     *   - A project whose team assignments have ENDED. The closed assessment sets `ends_on`, so the
     *     demo contains a project axis that has correctly lapsed with nobody revoking anything
     *     (§12).
     */
    private function seedMeridianDelivery(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();

            $ops = $this->accountOf($staff, 'MEI-0003');                 // Chinedu Balogun
            $engineeringManager = $this->accountOf($staff, 'MEI-0004');  // Farida Yusuf
            $leadEngineer = $this->accountOf($staff, 'MEI-0005');        // Marcus Adeyemi
            $structuralEngineer = $this->accountOf($staff, 'MEI-0006');  // Ngozi Eze
            $pipingEngineer = $this->accountOf($staff, 'MEI-0007');      // Segun Afolabi
            $geotechEngineer = $this->accountOf($staff, 'MEI-0009');     // Ibrahim Sule
            $documentController = $this->accountOf($staff, 'MEI-0010');  // Helen Obi
            $projectManager = $this->accountOf($staff, 'MEI-0011');      // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');         // Kelechi Nwosu
            $hseOfficer = $this->accountOf($staff, 'MEI-0014');          // Emeka Duru
            $qaManager = $this->accountOf($staff, 'MEI-0015');           // Yemi Bakare
            $qaInspector = $this->accountOf($staff, 'MEI-0016');         // Ruth Okafor
            $hrManager = $this->accountOf($staff, 'MEI-0017');           // Amina Tijani
            $storeOfficer = $this->accountOf($staff, 'MEI-0021');        // Sadiq Bello
            $procurementOfficer = $this->accountOf($staff, 'MEI-0020');  // Uche Nnamdi

            // ------------------------------------------------- the live EPC job
            $delta = $this->project($ops, [
                'name' => 'Delta Terminal Tank Farm Expansion',
                'code' => 'DTE',
                'description' => 'Two 15,000 m³ crude storage tanks, the associated tie-in piping '
                    .'and a firewater system upgrade at the Delta Terminal, Warri.',
                'project_type_id' => $this->projectTypes['epc']->id ?? null,
                'client_name' => 'Riverine Petroleum Development Company',
                'contract_reference' => 'RPDC/EPC/2025/017',
                'org_unit_id' => $unit['PMO']->id,
                'location_id' => $place['SITE-DT']->id,
                'manager_id' => $projectManager->id,
                'sponsor_id' => $ops->id,
                'planned_start' => now()->subMonths(5)->startOfMonth()->toDateString(),
                'planned_end' => now()->addMonths(7)->endOfMonth()->toDateString(),
                'actual_start' => now()->subMonths(5)->addDays(9)->toDateString(),
                'contract_value' => 4_850_000_000,
                'currency' => 'NGN',
                'progress_percent' => 42,
                'health' => 'at_risk',
                'priority' => 'high',
            ], [
                ['planning', $projectManager],
                ['active', $projectManager],
            ]);

            // Step 1 of the §13 cascade. Naming a department here grants its people nothing; it
            // tells their head there is work of theirs on this job.
            $this->departments($delta, $projectManager, [
                [$unit['ENG'], $staff['MEI-0004'], 'Design of the tanks, foundations and tie-ins.'],
                [$unit['CON'], $staff['MEI-0012'], 'Construction and installation on site.'],
                [$unit['HSE'], $staff['MEI-0013'], 'Site safety, permits and environmental compliance.'],
                [$unit['QA'], $staff['MEI-0015'], 'Weld, coating and civil inspection.'],
            ]);

            // Step 3, and the only step that grants anything.
            $this->team($delta, $projectManager, [
                [$leadEngineer, 'technical-lead', $unit['ENG-CS'],
                    'Structural design authority for the tanks and pipe racks.', 40],
                [$structuralEngineer, 'engineer', $unit['ENG-CS-SD'],
                    'Tank foundation and pipe rack design.', 80],
                [$geotechEngineer, 'engineer', $unit['ENG-CS-GT'],
                    'Geotechnical interpretation and settlement monitoring.', 50],
                [$pipingEngineer, 'engineer', $unit['ENG-MP-PD'],
                    'Tie-in piping and loading arm design.', 70],
                [$siteManager, 'site-supervisor', $unit['CON-DT'],
                    'Day-to-day supervision of the site works.', 100],
                [$hseOfficer, 'hse-officer', $unit['HSE'],
                    'Site HSE supervision and the permit-to-work system.', 100],
                [$qaInspector, 'qa-qc-officer', $unit['QA'],
                    'Weld and coating inspection, and NCR close-out.', 60],
                [$documentController, 'document-controller', $unit['ENG-DC'],
                    'Transmittals, the document register and client submissions.', 30],
            ]);

            $geotechnical = $this->task($projectManager, $geotechEngineer, [
                'title' => 'Complete the geotechnical interpretive report for the tank pad',
                'description' => 'Boreholes are logged; the settlement predictions drive the '
                    .'foundation design and are on the critical path.',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-CS-GT']->id,
                'priority' => 'high',
                'start_on' => now()->subMonths(4)->toDateString(),
                'due_on' => now()->subMonths(3)->toDateString(),
                'estimated_hours' => 60,
            ], [
                ['Log the boreholes to 25 m', true],
                ['Run the consolidation tests', true],
                ['Issue the settlement predictions to structural', true],
            ], [
                ['in_progress', $geotechEngineer],
                ['done', $geotechEngineer],
            ]);

            $foundationDrawings = $this->task($projectManager, $structuralEngineer, [
                'title' => 'Issue the IFC foundation drawings for Tanks 4 and 5',
                'description' => 'Ring beam and piled raft details, to follow the settlement '
                    .'predictions in the geotechnical report.',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-CS-SD']->id,
                'priority' => 'high',
                'start_on' => now()->subDays(24)->toDateString(),
                'due_on' => now()->addDays(14)->toDateString(),
                'estimated_hours' => 120,
                'progress_percent' => 55,
            ], [
                ['Reinforcement detailing for both ring beams', true],
                ['Internal check by the lead engineer', false],
                ['Squad check with piping and electrical', false],
            ], [
                ['in_progress', $structuralEngineer],
            ]);

            $stressAnalysis = $this->task($projectManager, $pipingEngineer, [
                'title' => 'Complete the stress analysis for the loading arm tie-in',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-MP-PD']->id,
                'priority' => 'normal',
                'start_on' => now()->subDays(18)->toDateString(),
                'due_on' => now()->addDays(21)->toDateString(),
                'estimated_hours' => 80,
                'progress_percent' => 90,
            ], [
                ['Model the tie-in and the manifold', true],
                ['Check the nozzle loads against API 650', true],
                ['Write up the analysis report', true],
            ], [
                ['in_progress', $pipingEngineer],
                ['review', $pipingEngineer],
            ]);

            $this->task($projectManager, $qaInspector, [
                'title' => 'Close out non-conformance NCR-014 — scaffold access to Tank 4',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['QA']->id,
                'priority' => 'high',
                'due_on' => now()->addDays(7)->toDateString(),
            ], [
                ['Re-inspect the scaffold once re-erected', false],
                ['Obtain the subcontractor’s corrective action record', false],
            ], [
                ['in_progress', $qaInspector],
                ['blocked', $qaInspector, 'Waiting on the subcontractor’s revised scaffold design.'],
            ]);

            $this->task($projectManager, $hseOfficer, [
                'title' => 'Update the site HSE plan for the November shutdown window',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['HSE']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(10)->toDateString(),
                'estimated_hours' => 16,
            ], [
                ['Revise the hot work and confined space procedures', false],
                ['Brief the subcontractor supervisors', false],
            ]);

            $transmittal = $this->task($projectManager, $documentController, [
                'title' => 'Transmit the firewater pump vendor data pack to the client',
                'description' => 'Includes the loading arm stress report, which is still in review.',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-DC']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(5)->toDateString(),
            ], [], [
                ['in_progress', $documentController],
            ]);

            $this->task($projectManager, $siteManager, [
                'title' => 'Weekly site progress report — week 34',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'priority' => 'low',
                'due_on' => now()->subDays(3)->toDateString(),
            ], [], [
                ['in_progress', $siteManager],
                ['done', $siteManager],
            ]);

            // Commercial, and restricted (Gate 5). It belongs to the PMO, so the project team can
            // see the job and not this: confidentiality is not lifted by being on the project.
            $this->task($ops, $projectManager, [
                'title' => 'Prepare the variation narrative for VO-004 — revised tank internals',
                'description' => 'Commercial position for the claim, ahead of the client meeting.',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['PMO']->id,
                'confidentiality' => 'restricted',
                'priority' => 'high',
                'due_on' => now()->addDays(6)->toDateString(),
            ], [], [
                ['in_progress', $projectManager],
            ]);

            // The drawings waited for the geotechnical report, which is finished. The transmittal
            // waits for a report that is not — so it cannot be closed, and the demo has a live
            // example of the dependency rule doing something (§16).
            $this->dependencies->add($foundationDrawings, $geotechnical, $projectManager);
            $this->dependencies->add($transmittal, $stressAnalysis, $projectManager);

            // ------------------------------------------------- an internal job, still in planning
            $yard = $this->project($ops, [
                'name' => 'Ikeja Yard Crane and Drainage Upgrade',
                'code' => 'IYU',
                'description' => 'Replacement of the yard gantry crane and remedial works to the '
                    .'hardstanding drainage at the Ikeja fabrication yard.',
                'project_type_id' => $this->projectTypes['internal']->id ?? null,
                'org_unit_id' => $unit['CON']->id,
                'location_id' => $place['YARD-IK']->id,
                'manager_id' => $ops->id,
                'planned_start' => now()->addMonth()->startOfMonth()->toDateString(),
                'planned_end' => now()->addMonths(5)->endOfMonth()->toDateString(),
                'currency' => 'NGN',
                'priority' => 'normal',
                'health' => 'on_track',
            ], [
                ['planning', $ops],
            ]);

            $this->team($yard, $ops, [
                [$pipingEngineer, 'engineer', $unit['ENG-MP'], 'Mechanical specification of the crane.', 20],
                [$siteManager, 'site-supervisor', $unit['CON'], 'Site works and subcontractor supervision.', 25],
                [$storeOfficer, 'team-member', $unit['STR'], 'Materials, storage and laydown.', 15],
            ]);

            $this->task($ops, $pipingEngineer, [
                'title' => 'Specify the replacement 20-tonne gantry crane',
                'project_id' => $yard->id,
                'org_unit_id' => $unit['ENG-MP']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(21)->toDateString(),
                'estimated_hours' => 40,
            ], [
                ['Confirm the duty cycle and span with the yard', false],
                ['Draft the technical specification', false],
                ['Issue for procurement enquiry', false],
            ]);

            $this->task($ops, $siteManager, [
                'title' => 'Survey the existing yard drainage and hardstanding',
                'project_id' => $yard->id,
                'org_unit_id' => $unit['CON']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(14)->toDateString(),
            ], [], [
                ['in_progress', $siteManager],
            ]);

            $this->task($ops, null, [
                'title' => 'Prepare the upgrade cost estimate for board approval',
                'description' => 'Unassigned until the crane specification is settled.',
                'project_id' => $yard->id,
                'org_unit_id' => $unit['CON']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(30)->toDateString(),
            ]);

            // ------------------------------------------------- a job that is finished
            $assessment = $this->project($ops, [
                'name' => 'Ogunu Jetty Deck Condition Assessment',
                'code' => 'OJA',
                'description' => 'Visual and hammer-tap survey of the jetty deck, with a repair '
                    .'strategy and budget estimate.',
                'project_type_id' => $this->projectTypes['consultancy']->id ?? null,
                'client_name' => 'Ogunu Port Services Limited',
                'contract_reference' => 'OPS/CON/2025/004',
                'org_unit_id' => $unit['ENG']->id,
                'location_id' => $place['HQ']->id,
                'manager_id' => $engineeringManager->id,
                'sponsor_id' => $ops->id,
                'planned_start' => now()->subMonths(5)->startOfMonth()->toDateString(),
                'planned_end' => now()->subMonth()->endOfMonth()->toDateString(),
                'actual_start' => now()->subMonths(5)->addDays(4)->toDateString(),
                'contract_value' => 68_500_000,
                'currency' => 'NGN',
                'progress_percent' => 100,
                'health' => 'on_track',
                'priority' => 'normal',
            ], [
                ['planning', $ops],
                ['active', $ops],
                ['completed', $ops],
            ]);

            // Time-bounded membership (§12): the assignments ended with the job. Nobody revoked
            // anything, and from the day after `ends_on` these three lose the project axis over it.
            $this->team($assessment, $engineeringManager, [
                [$leadEngineer, 'technical-lead', $unit['ENG-CS'], 'Technical authority for the assessment.', 30],
                [$structuralEngineer, 'engineer', $unit['ENG-CS-SD'], 'Survey and defect mapping.', 60],
                [$documentController, 'document-controller', $unit['ENG-DC'], 'Report production and issue.', 10],
            ], endsOn: now()->subMonth()->endOfMonth()->toDateString());

            $this->task($engineeringManager, $structuralEngineer, [
                'title' => 'Carry out the visual and hammer-tap deck survey',
                'project_id' => $assessment->id,
                'org_unit_id' => $unit['ENG-CS-SD']->id,
                'priority' => 'normal',
                'start_on' => now()->subMonths(4)->toDateString(),
                'due_on' => now()->subMonths(3)->toDateString(),
                'estimated_hours' => 90,
            ], [
                ['Survey the deck soffit from the barge', true],
                ['Map the delamination and spalling', true],
            ], [
                ['in_progress', $structuralEngineer],
                ['done', $structuralEngineer],
            ]);

            // Written by the lead engineer, accepted by somebody else. `task.verify` is the whole
            // reason those are two permissions rather than one.
            $this->task($engineeringManager, $leadEngineer, [
                'title' => 'Issue the deck assessment report and repair strategy',
                'project_id' => $assessment->id,
                'org_unit_id' => $unit['ENG-CS']->id,
                'priority' => 'normal',
                'start_on' => now()->subMonths(3)->toDateString(),
                'due_on' => now()->subMonths(2)->toDateString(),
                'estimated_hours' => 50,
            ], [
                ['Draft the repair strategy and budget estimate', true],
                ['Client review comments incorporated', true],
            ], [
                ['in_progress', $leadEngineer],
                ['review', $leadEngineer],
                ['done', $ops],
            ]);

            // ------------------------------------------------- work that belongs to no project
            // `project_id` is nullable for exactly this: plenty of real work belongs to a
            // department and no job, and it is reachable on the org ladder alone.
            $this->task($ops, $qaManager, [
                'title' => 'Renew the ISO 9001:2015 certification ahead of the surveillance audit',
                'org_unit_id' => $unit['QA']->id,
                'priority' => 'high',
                'due_on' => now()->addMonths(2)->toDateString(),
            ], [
                ['Close the two observations from last year', true],
                ['Update the quality manual to the new org structure', true],
                ['Book the surveillance audit', false],
            ], [
                ['in_progress', $qaManager],
            ]);

            $this->task($ops, $hrManager, [
                'title' => 'Draft the 2026 competency and training matrix',
                'org_unit_id' => $unit['HR']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(42)->toDateString(),
            ]);

            $this->task($ops, $procurementOfficer, [
                'title' => 'Prequalify three additional coating subcontractors',
                'org_unit_id' => $unit['PRC']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(21)->toDateString(),
            ], [], [
                ['in_progress', $procurementOfficer],
            ]);

            $this->spreadDeliveryHistory($organization, 45);
        });
    }

    /**
     * Kestrel's delivery, and the contrast that makes it worth seeding.
     *
     * A facilities-management company runs mostly on reactive and planned maintenance work that
     * belongs to a department and to no project at all — so most of what follows has a null
     * `project_id`, which is the case a project-shaped system usually gets wrong. There is one
     * small project as well, to show that a flat company with no divisions and no sections runs the
     * same delivery code as the four-level EPC contractor next door.
     */
    private function seedKestrelDelivery(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();

            $opsManager = $this->accountOf($staff, 'KFM-002');       // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003');  // Duncan Reilly
            $complianceHead = $this->accountOf($staff, 'KFM-004');   // Nadia Osei
            $mechanical = $this->accountOf($staff, 'KFM-005');       // Callum Whitfield
            $electrical = $this->accountOf($staff, 'KFM-006');       // Erin Doyle
            $helpdesk = $this->accountOf($staff, 'KFM-007');         // Josh Aiken
            $generalist = $this->accountOf($staff, 'KFM-008');       // Marianne Ellis

            $replacement = $this->project($opsManager, [
                'name' => 'Trafford Point AHU Replacement',
                'code' => 'TPA',
                'description' => 'Replacement of air handling unit AHU-3 serving floors 4 to 6, '
                    .'including electrical re-termination and commissioning.',
                'project_type_id' => $this->projectTypes['maintenance']->id ?? null,
                'client_name' => 'Trafford Point Estates LLP',
                'contract_reference' => 'TPE/PPM/2026/03',
                'org_unit_id' => $unit['MNT']->id,
                'location_id' => $place['MCR']->id,
                'manager_id' => $opsManager->id,
                'planned_start' => now()->subWeeks(6)->toDateString(),
                'planned_end' => now()->addWeeks(8)->toDateString(),
                'actual_start' => now()->subWeeks(5)->toDateString(),
                'contract_value' => 148_000,
                'currency' => 'GBP',
                'progress_percent' => 35,
                'health' => 'on_track',
                'priority' => 'normal',
            ], [
                ['planning', $opsManager],
                ['active', $opsManager],
            ]);

            $this->departments($replacement, $opsManager, [
                [$unit['MNT'], $staff['KFM-003'], 'Removal, installation and commissioning.'],
                [$unit['CMP'], $staff['KFM-004'], 'Permits, risk assessments and F-gas compliance.'],
            ]);

            $this->team($replacement, $opsManager, [
                [$maintenanceHead, 'discipline-lead', $unit['MNT'], 'Technical lead and subcontractor control.', 30],
                [$mechanical, 'team-member', $unit['MNT-MEC'], 'Mechanical removal and installation.', 70],
                [$electrical, 'team-member', $unit['MNT-ELE'], 'Isolation, re-termination and testing.', 40],
                [$complianceHead, 'hse-officer', $unit['CMP'], 'Permits to work and risk assessment.', 10],
            ]);

            $stripOut = $this->task($opsManager, $mechanical, [
                'title' => 'Strip out and remove AHU-3 from the fourth floor plant room',
                'project_id' => $replacement->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'priority' => 'high',
                'due_on' => now()->addDays(7)->toDateString(),
                'estimated_hours' => 24,
                'progress_percent' => 60,
            ], [
                ['Drain down and recover the refrigerant', true],
                ['Disconnect the ductwork and pipework', true],
                ['Crane the unit out through the roof hatch', false],
            ], [
                ['in_progress', $mechanical],
            ]);

            $reTermination = $this->task($opsManager, $electrical, [
                'title' => 'Isolate and re-terminate the AHU-3 power supply',
                'project_id' => $replacement->id,
                'org_unit_id' => $unit['MNT-ELE']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(16)->toDateString(),
                'estimated_hours' => 12,
            ]);

            $this->task($opsManager, $maintenanceHead, [
                'title' => 'Assemble the commissioning and handover pack for AHU-3',
                'project_id' => $replacement->id,
                'org_unit_id' => $unit['MNT']->id,
                'priority' => 'normal',
                'due_on' => now()->addWeeks(7)->toDateString(),
            ]);

            $this->dependencies->add($reTermination, $stripOut, $opsManager);

            // ------------------------------------------------- the day job, with no project on it
            $this->task($maintenanceHead, $electrical, [
                'title' => 'Emergency lighting test — Leeds Depot',
                'org_unit_id' => $unit['MNT-ELE']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(4)->toDateString(),
            ], [
                ['Three-hour duration test on all luminaires', false],
                ['Update the emergency lighting log book', false],
            ], [
                ['in_progress', $electrical],
            ]);

            $this->task($opsManager, $maintenanceHead, [
                'title' => 'Quarterly PPM schedule review',
                'description' => 'Reconcile the planned maintenance schedule against the asset '
                    .'register before the next quarter is issued.',
                'org_unit_id' => $unit['MNT']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(14)->toDateString(),
            ], [
                ['Pull the completion rates for the last quarter', true],
                ['Re-balance the mechanical and electrical rounds', false],
            ], [
                ['in_progress', $maintenanceHead],
            ]);

            $this->task($opsManager, $complianceHead, [
                'title' => 'Renew the Leeds Depot fire risk assessment',
                'org_unit_id' => $unit['CMP']->id,
                'priority' => 'high',
                'due_on' => now()->addDays(21)->toDateString(),
            ]);

            $this->task($opsManager, $helpdesk, [
                'title' => 'Clear the reactive jobs backlog from the August heatwave',
                'org_unit_id' => $unit['OPS-HD']->id,
                'priority' => 'high',
                'due_on' => now()->subDays(2)->toDateString(),
            ], [
                ['Triage the outstanding cooling failures', true],
                ['Confirm the remaining jobs with the client', true],
            ], [
                ['in_progress', $helpdesk],
                ['done', $helpdesk],
            ]);

            $this->task($opsManager, $generalist, [
                'title' => 'Issue the monthly client service reports',
                'org_unit_id' => $unit['BUS']->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(3)->toDateString(),
            ], [], [
                ['in_progress', $generalist],
                ['review', $generalist],
                ['done', $opsManager],
            ]);

            $this->spreadDeliveryHistory($organization, 30);
        });
    }

    // ================================================================= what the companies say

    /**
     * Meridian's memos, notices and meetings (§17, §18, §19).
     *
     * Written through the services for the same reason the delivery pass is: every number here comes
     * out of the tenant's own scheme, every state move goes through the configured whitelist, and
     * every recipient row is written by the code that also grants the access it implies. Seed data
     * produced any other way would show states the application will not let anybody reach.
     *
     * Four things are here to be demonstrated rather than merely populated:
     *
     *   - THE DRAFT CURTAIN. One memo and one notice are addressed and never issued. Their named
     *     recipients cannot see them, which is the publication predicate doing its job and the single
     *     easiest thing for a communications module to get wrong.
     *   - THE ACL PATH. One memo is confidential and one is restricted. Neither is reachable by
     *     ordinary scope — the recipients open them because issuing wrote them an explicit allow row,
     *     which is exactly what §67 and Gate 5 describe.
     *   - RECORDING IS NOT PUBLISHING. The progress meeting's minutes are written by the secretary
     *     and released by the chair, and the design review's minutes are written and deliberately
     *     NOT released, so the demo contains a meeting whose minutes only the author and the
     *     circulator can read.
     *   - A MEETING THAT PRODUCED WORK. The action arising is an ordinary Task pointed back at the
     *     meeting, so it turns up on its assignee's list, in their notifications and on the calendar
     *     — none of which would ever look inside a private actions table (§90).
     */
    private function seedMeridianCommunications(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'MEI-0001');                  // Adaeze Okonkwo
            $ops = $this->accountOf($staff, 'MEI-0003');                 // Chinedu Balogun
            $engineeringManager = $this->accountOf($staff, 'MEI-0004');  // Farida Yusuf
            $leadEngineer = $this->accountOf($staff, 'MEI-0005');        // Marcus Adeyemi
            $structuralEngineer = $this->accountOf($staff, 'MEI-0006');  // Ngozi Eze
            $pipingEngineer = $this->accountOf($staff, 'MEI-0007');      // Segun Afolabi
            $electricalEngineer = $this->accountOf($staff, 'MEI-0008');  // Priya Raghunathan
            $documentController = $this->accountOf($staff, 'MEI-0010');  // Helen Obi
            $projectManager = $this->accountOf($staff, 'MEI-0011');      // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');         // Kelechi Nwosu
            $hseManager = $this->accountOf($staff, 'MEI-0013');          // Grace Ibrahim
            $hseOfficer = $this->accountOf($staff, 'MEI-0014');          // Emeka Duru
            $qaManager = $this->accountOf($staff, 'MEI-0015');           // Yemi Bakare
            $qaInspector = $this->accountOf($staff, 'MEI-0016');         // Ruth Okafor
            $hrManager = $this->accountOf($staff, 'MEI-0017');           // Amina Tijani
            $financeManager = $this->accountOf($staff, 'MEI-0018');      // Victor Anyanwu
            $procurementManager = $this->accountOf($staff, 'MEI-0019');  // Bisi Adeleke
            $fleetManager = $this->accountOf($staff, 'MEI-0022');        // Tunde Ogunleye
            $officeAdmin = $this->accountOf($staff, 'MEI-0024');         // Chioma Nwachukwu
            $boundedAdmin = $this->accountOf($staff, 'MEI-0025');        // Lara Fashola

            // ------------------------------------------------- memos
            // Issued by the office administrator, who holds `memo.issue` across the company. The
            // site team is on the `to` line because they have to act on it; QA and the operations
            // director are copied because they need to know it happened.
            $access = $this->memo($officeAdmin, [
                'subject' => 'Revised site access and induction procedure — Delta Terminal',
                'body' => "The client has tightened terminal access with effect from Monday.\n\n"
                    .'1. Every person entering the terminal must now hold a valid RPDC induction card '
                    .'in addition to the Meridian site induction. Cards are issued at the main gate '
                    ."office between 07:00 and 09:00 only.\n"
                    .'2. Vehicle passes are no longer transferable between subcontractors. Requests '
                    ."for additional passes must reach the site office two working days in advance.\n"
                    ."3. Visitors without a confirmed escort will be turned away at the gate.\n\n"
                    .'Please brief your teams at tomorrow morning\'s toolbox talk.',
                'project_id' => $job['DTE']->id,
                'org_unit_id' => $unit['ADM']->id,
            ], [$projectManager, $siteManager, $hseOfficer, $hseManager], [$qaInspector, $ops]);

            $this->issue($access, $officeAdmin);

            // Two of six have opened it. The gap is the point: "who has seen this" is the question a
            // memo answers and an email does not, and a demo where everybody has read everything
            // shows an empty answer.
            $this->readBy($access, [$siteManager, $hseOfficer]);
            $this->happened($access, $this->workingDay(26, 8), ['issued_at' => 55], 120);

            // CONFIDENTIAL, and therefore unreachable by ordinary scope however senior the reader.
            // Daniel Mbeki holds no `memo.view_confidential`; he can open this one because issuing it
            // wrote him an explicit ACL allow row, and for no other reason. Take him off the
            // distribution and the memo disappears for him — which is the whole design.
            //
            // No org unit: a memo from the managing director's office belongs to the company rather
            // than to a department, and the column is nullable for exactly that.
            $variation = $this->memo($md, [
                'subject' => 'Commercial position on RPDC variation VO-04',
                'body' => 'This memo is confidential and is not to be forwarded outside the '
                    ."distribution below.\n\n"
                    .'VO-04 (additional firewater ring main tie-ins) has been priced at '
                    ."₦412m against the client's indicative ₦280m. Our position for Thursday's "
                    ."commercial meeting is:\n\n"
                    ."• We will not accept the indicative figure. The scope grew after the client's "
                    ."own hazard review, and the correspondence trail supports us.\n"
                    .'• We have room to settle at ₦370m provided the shutdown window is confirmed in '
                    ."writing for 14–18 October.\n"
                    ."• Any settlement below ₦340m must come back to me before it is offered.\n\n"
                    .'Finance is to hold the provision at the full claim value until this closes.',
                'project_id' => $job['DTE']->id,
                'confidentiality' => 'confidential',
            ], [$ops, $financeManager, $projectManager]);

            $this->issue($variation, $md);
            $this->readBy($variation, [$ops]);
            $this->happened($variation, $this->workingDay(13, 17), ['issued_at' => 35], 90);

            // Addressed to four people and never issued. None of them can see it — the publication
            // predicate refuses it before any scope question is asked — and the author can.
            $closure = $this->memo($officeAdmin, [
                'subject' => 'Year-end office closure and skeleton cover arrangements',
                'body' => "DRAFT for approval — not yet circulated.\n\n"
                    .'Proposed closure of the head office from 24 December to 2 January inclusive, '
                    ."with skeleton cover as follows:\n\n"
                    ."• Site operations at the Delta Terminal continue uninterrupted on the client's "
                    ."own shutdown calendar.\n"
                    ."• One HSE officer and one security officer on call throughout.\n"
                    ."• Payroll cut-off brought forward to 18 December.\n\n"
                    .'Please confirm the payroll date with Finance before this goes out.',
                'org_unit_id' => $unit['ADM']->id,
            ], [$md, $ops, $hrManager, $financeManager]);

            $this->happened($closure, $this->workingDay(4, 11), [], 40);

            // Issued, acted on, and then withdrawn. Not deleted: people approved overtime against
            // this for a fortnight, and the record of what they were told has to survive the
            // instruction being cancelled (§59).
            $overtime = $this->memo($md, [
                'subject' => 'Interim overtime authorisation limits',
                'body' => 'With immediate effect and until the year-end review, overtime '
                    ."authorisation limits are:\n\n"
                    ."• Section leads — up to 8 hours per person per week.\n"
                    ."• Department heads — up to 20 hours per person per week.\n"
                    ."• Anything beyond that requires my written approval.\n\n"
                    .'Timesheets submitted without the approving name will be returned unpaid.',
                'org_unit_id' => $unit['HR']->id,
            ], [$hrManager, $financeManager, $ops, $procurementManager]);

            $this->issue($overtime, $md);
            $this->readBy($overtime, [$hrManager, $financeManager]);
            $this->withdraw($overtime, $md,
                'Superseded by the revised delegation of authority agreed at the September board meeting.');

            // The spread is deliberately wide: the withdrawal happened about a fortnight after the
            // issue, and a timeline that puts both inside the same hour would read as a mistake.
            $this->happened($overtime, $this->workingDay(34, 9), ['issued_at' => 45], 18_000);

            // ------------------------------------------------- announcements
            // No confidentiality column anywhere below, by design: a noticeboard whose contents are
            // secret from some of the people standing in front of it is a memo wearing the wrong
            // name. Narrowing one to a department is ROUTING — whose board it appears on — and never
            // an access boundary.
            $shutdown = $this->notice($md, [
                'title' => 'Delta Terminal shutdown window confirmed for 14–18 October',
                'body' => "The client has confirmed the October shutdown window in writing.\n\n"
                    .'All tie-in work, the firewater ring main crossover and the tank 4 hydrotest are '
                    .'inside this window. Departments with scope in the shutdown should have their '
                    ."resource plans with the project manager by the end of next week.\n\n"
                    .'There will be no second window this year.',
                'urgency' => 'urgent',
                'is_pinned' => true,
                'expires_on' => now()->addDays(30)->toDateString(),
            ]);

            $this->publish($shutdown, $md);
            $this->happened($shutdown, $this->workingDay(9, 8), ['published_at' => 20], 60);

            // Aimed at Engineering. Everybody under Engineering sees it on their board, including the
            // teams three levels down — announcements route DOWN the tree, which is why a section
            // engineer gets a departmental notice without anybody listing them.
            $standard = $this->notice($officeAdmin, [
                'title' => 'Drawing numbering standard MEI-STD-04 replaces the 2023 revision',
                'body' => 'MEI-STD-04 is now the controlling standard for all drawing and document '
                    ."numbering.\n\n"
                    .'The changes that affect day-to-day work are the discipline code moving to three '
                    .'characters and the revision suffix separating from the sheet number. Drawings '
                    .'already in issue keep their existing numbers; anything issued from Monday uses '
                    ."the new scheme.\n\n"
                    .'Document Control will reject transmittals numbered under the old standard.',
                'org_unit_id' => $unit['ENG']->id,
                'urgency' => 'normal',
            ]);

            $this->publish($standard, $officeAdmin);
            $this->happened($standard, $this->workingDay(17, 10), ['published_at' => 15], 45);

            $medical = $this->notice($officeAdmin, [
                'title' => 'Staff medical scheme renewal — provider briefing sessions',
                'body' => "DRAFT — dates to be confirmed with the provider before this is published.\n\n"
                    .'The scheme renews on 1 January and the provider has offered on-site briefing '
                    ."sessions at the head office and at the Delta Terminal site office.\n\n"
                    .'Please do not circulate until HR confirms the dates.',
                'org_unit_id' => $unit['HR']->id,
                'urgency' => 'normal',
            ]);

            $this->happened($medical, $this->workingDay(6, 15), [], 30);

            // Published, and off the board on its own. Nothing archived it and nobody remembered to:
            // `expires_on` is what stops a noticeboard filling with notices about last July.
            $power = $this->notice($officeAdmin, [
                'title' => 'Head office power maintenance — Saturday 12 July, 07:00 to 16:00',
                'body' => "The building's main switchboard is being serviced this Saturday.\n\n"
                    .'There will be no power to floors 3 to 6 for the duration. The server room stays '
                    ."up on the UPS and generator, so remote access is unaffected.\n\n"
                    .'Please shut down desktop equipment before leaving on Friday.',
                'urgency' => 'high',
                'expires_on' => now()->subDays(44)->toDateString(),
            ]);

            $this->publish($power, $officeAdmin);
            $this->happened($power, $this->workingDay(50, 9), ['published_at' => 10], 30);

            $safety = $this->notice($md, [
                'title' => 'Q2 safety performance and the revised journey management plan',
                'body' => 'We closed the second quarter with no lost-time injuries across either site '
                    ."and a 40% fall in near-miss reports going unclosed beyond seven days.\n\n"
                    .'The revised journey management plan is now in force for all travel to the Delta '
                    ."Terminal. Night driving between Warri and Lagos remains prohibited.\n\n"
                    .'My thanks to the HSE team and to every supervisor who kept the reporting honest.',
                'org_unit_id' => $unit['HSE']->id,
                'urgency' => 'normal',
            ]);

            $this->publish($safety, $md);
            $this->archive($safety, $md);
            $this->happened($safety, $this->workingDay(70, 11), ['published_at' => 25], 57_000);

            // ------------------------------------------------- meetings
            // The one that did everything: held, register taken, minuted by the secretary, released
            // by the chair, two decisions recorded and one action raised as real work.
            $progress = $this->meeting($ops, [
                'title' => 'Delta Terminal — weekly project progress meeting',
                'agenda' => "1. Safety moment and open HSE actions\n"
                    ."2. Progress against the September lookahead\n"
                    ."3. Tank 4 ring beam — settlement predictions and pour authorisation\n"
                    ."4. Firewater ring main tie-in — shutdown window\n"
                    ."5. Client transmittals outstanding\n"
                    .'6. Any other business',
                'project_id' => $job['DTE']->id,
                'org_unit_id' => $unit['PMO']->id,
                'location' => 'Site office, Delta Terminal (and Teams)',
                'starts_at' => $this->workingDay(11, 9),
                'ends_at' => $this->workingDay(11, 10)->addMinutes(30),
            ], [
                'chair' => [$ops],
                'secretary' => [$officeAdmin],
                'participant' => [
                    $projectManager, $siteManager, $leadEngineer,
                    $hseOfficer, $qaInspector, $documentController,
                ],
            ]);

            // Sent before the meeting, by the person themselves. The register below leaves it alone.
            $this->apologise($progress, $leadEngineer,
                'At the client\'s design office in Port Harcourt for the loading arm review.');

            $progress = $this->meetings->transition($progress, $this->statusFor('meeting', 'held'), $ops);

            $this->register($progress, $ops, absent: [$documentController]);

            // Recorded by the secretary. Note the actor: writing minutes is `meeting.record_minutes`
            // and it is not the same act as releasing them.
            $progress = $this->meetings->recordMinutes($progress,
                'Present: C. Balogun (chair), C. Nwachukwu (secretary), D. Mbeki, K. Nwosu, '
                ."E. Duru, R. Okafor. Apologies: M. Adeyemi. Absent: H. Obi.\n\n"
                .'1. SAFETY MOMENT. E. Duru reported two near misses in the week, both scaffold '
                ."related and both closed out. The revised journey management plan is being observed.\n\n"
                .'2. PROGRESS. The job stands at 42% against a planned 47%. The slippage is entirely '
                .'in the tank 4 foundation and traces back to the geotechnical interpretation, which '
                ."is now closed out.\n\n"
                ."3. TANK 4 RING BEAM. N. Eze's revised settlement predictions have been checked by "
                .'the lead engineer. The pour can proceed on the revised figures; the pour card needs '
                ."reissuing to match. See decision 1 and the action arising.\n\n"
                .'4. FIREWATER RING MAIN. The client has confirmed the 14–18 October shutdown window '
                ."in writing. The tie-in moves into that window. See decision 2.\n\n"
                .'5. TRANSMITTALS. Two transmittals remain outstanding with the client, both awaiting '
                ."the stress analysis currently in review. H. Obi to chase on her return.\n\n"
                .'6. AOB. None.',
                $officeAdmin);

            $this->meetings->addDecision($progress, [
                'decision' => 'The tank 4 ring beam concrete pour is authorised to proceed on the '
                    .'revised settlement predictions.',
                'rationale' => 'The revised predictions have been independently checked by the lead '
                    .'structural engineer and are within the tolerance assumed by the foundation '
                    .'design. Waiting for the full monitoring period would put the pour into the '
                    .'rainy season.',
                'owner_id' => $leadEngineer->id,
            ], $officeAdmin);

            $this->meetings->addDecision($progress, [
                'decision' => 'The firewater ring main tie-in moves into the 14–18 October client '
                    .'shutdown window.',
                'rationale' => 'The client has confirmed the window in writing. Attempting the tie-in '
                    .'outside a shutdown would require a live-line procedure the client has already '
                    .'declined once.',
                'owner_id' => $projectManager->id,
            ], $officeAdmin);

            // Work arising is WORK: an ordinary task on the assignee's list, on the calendar and in
            // her notifications, carrying a link back to the meeting that raised it.
            $action = $this->meetings->raiseAction($progress, [
                'title' => 'Reissue the tank 4 ring beam pour card against the revised settlement '
                    .'predictions',
                'description' => 'Action arising from the weekly progress meeting. The pour card '
                    .'still quotes the superseded settlement figures.',
                'assignee_id' => $structuralEngineer->id,
                'org_unit_id' => $unit['ENG-CS-SD']->id,
                'priority' => 'high',
                'due_on' => now()->addDays(4)->toDateString(),
                'estimated_hours' => 6,
            ], $ops);

            // Released by the chair, not by the secretary who wrote them. This is the move that
            // stamps `minutes_circulated_at` and makes the minutes readable by everybody who was in
            // the room.
            $progress = $this->meetings->transition(
                $progress, $this->statusFor('meeting', 'minuted'), $ops,
            );

            $this->happened($progress, $this->workingDay(18, 15), [
                'minutes_recorded_at' => 9_800,     // the afternoon of the meeting itself
                'minutes_circulated_at' => 11_400,  // the following morning
            ], 12_000);

            $this->happened($action, $this->workingDay(10, 9), [], 30);

            // Minutes written and deliberately NOT released. Everybody who was in the room can see
            // that the meeting happened; only Lara Fashola, who wrote them, and the holders of
            // `meeting.circulate` can read a draft record of what was said.
            $review = $this->meeting($engineeringManager, [
                'title' => 'Engineering design review — tank farm piping squad check',
                'agenda' => "1. Squad check of the tie-in piping general arrangement\n"
                    ."2. Pipe rack loading against the revised structural model\n"
                    ."3. Electrical and instrumentation clashes\n"
                    .'4. Hold points for the next issue',
                'project_id' => $job['DTE']->id,
                'org_unit_id' => $unit['ENG']->id,
                'location' => 'Engineering meeting room, head office',
                'starts_at' => $this->workingDay(5, 14),
                'ends_at' => $this->workingDay(5, 15)->addMinutes(30),
            ], [
                'chair' => [$engineeringManager],
                'secretary' => [$boundedAdmin],
                'participant' => [
                    $leadEngineer, $structuralEngineer, $pipingEngineer,
                    $electricalEngineer, $documentController,
                ],
            ]);

            $review = $this->meetings->transition($review, $this->statusFor('meeting', 'held'), $engineeringManager);

            $this->register($review, $engineeringManager, absent: [$electricalEngineer]);

            $this->meetings->recordMinutes($review,
                "DRAFT — not yet circulated. Chair to review before release.\n\n"
                .'1. SQUAD CHECK. The tie-in general arrangement was walked line by line. Eleven '
                .'comments were raised, of which nine are drafting corrections and two are design '
                ."changes affecting the pipe rack.\n\n"
                .'2. PIPE RACK LOADING. The revised structural model shows the rack at 94% '
                .'utilisation at the north end. M. Adeyemi to confirm whether the two design changes '
                ."above push it over.\n\n"
                .'3. CLASHES. Deferred — P. Raghunathan was not available and the electrical model is '
                ."not current. To be taken at the next review.\n\n"
                .'4. HOLD POINTS. No issue for construction until item 2 is closed.',
                $boundedAdmin);

            $this->happened($review, $this->workingDay(12, 11), [
                'minutes_recorded_at' => 10_200,
            ], 10_400);

            // Ahead of us, and the one meeting on the demo calendar that has not happened yet.
            // Scheduled by the office administrator on the HSE manager's behalf: `organizer_id` and
            // `created_by` are different columns for exactly this reason, and both reach the record.
            $committee = $this->meeting($officeAdmin, [
                'title' => 'Monthly HSE committee',
                'agenda' => "1. Incident and near-miss statistics for the month\n"
                    ."2. Open corrective actions past their due date\n"
                    ."3. Journey management plan — first month in force\n"
                    ."4. Shutdown readiness: permits, isolations and standby cover\n"
                    .'5. Any other business',
                'org_unit_id' => $unit['HSE']->id,
                'organizer_id' => $hseManager->id,
                'location' => 'Boardroom, head office (and Teams)',
                'starts_at' => now()->addDays(6)->setTime(10, 0),
                'ends_at' => now()->addDays(6)->setTime(11, 30),
            ], [
                'chair' => [$hseManager],
                'secretary' => [$officeAdmin],
                'participant' => [$hseOfficer, $siteManager, $qaManager, $projectManager, $ops],
            ]);

            $this->apologise($committee, $projectManager,
                'On site at the Delta Terminal for the shutdown readiness walkdown.');

            $this->happened($committee, $this->workingDay(3, 9), [], 45);

            // Called off, with a reason. "Cancelled" on its own is the single most-asked question
            // about a cancelled meeting, so the reason is kept on the record rather than in an email.
            $workshop = $this->meeting($ops, [
                'title' => 'Ikeja yard — crane foundation constructability workshop',
                'agenda' => "1. Crane supplier's foundation loads\n"
                    ."2. Existing yard drainage and its interaction with the new pad\n"
                    .'3. Sequencing against continued yard operations',
                'project_id' => $job['IYU']->id,
                'org_unit_id' => $unit['ENG-CS']->id,
                'location' => 'Ikeja fabrication yard, site cabin',
                'starts_at' => now()->addDays(2)->setTime(9, 0),
                'ends_at' => now()->addDays(2)->setTime(12, 0),
            ], [
                'chair' => [$ops],
                'participant' => [$leadEngineer, $structuralEngineer, $siteManager, $fleetManager],
            ]);

            $workshop = $this->meetings->transition(
                $workshop, $this->statusFor('meeting', 'cancelled'), $ops,
                'The crane supplier\'s foundation load package has slipped by three weeks. '
                .'To be rescheduled once the loads are confirmed.',
            );

            $this->happened($workshop, $this->workingDay(8, 14), [], 2_000);
        });
    }

    /**
     * Kestrel's communications: the same three modules, a quarter of the size, run by one person.
     *
     * Marianne Ellis wears three role templates — HR officer, finance officer and office
     * administrator — and it is the third that lets her issue memos and take minutes. That is the
     * small-company case, and it is the reason roles are additive rather than a single "user type"
     * column: nothing about this company needed a line of code, only a different set of grants.
     */
    private function seedKestrelCommunications(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder
            $opsManager = $this->accountOf($staff, 'KFM-002');      // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003'); // Duncan Reilly
            $complianceHead = $this->accountOf($staff, 'KFM-004');  // Nadia Osei
            $mechanical = $this->accountOf($staff, 'KFM-005');      // Callum Whitfield
            $electrical = $this->accountOf($staff, 'KFM-006');      // Erin Doyle
            $helpdesk = $this->accountOf($staff, 'KFM-007');        // Josh Aiken
            $generalist = $this->accountOf($staff, 'KFM-008');      // Marianne Ellis

            // ------------------------------------------------- memos
            $rota = $this->memo($generalist, [
                'subject' => 'Out-of-hours callout rota — September',
                'body' => "The September callout rota is confirmed as follows.\n\n"
                    ."• Weeks 1 and 3 — mechanical: C. Whitfield. Electrical: E. Doyle.\n"
                    .'• Weeks 2 and 4 — mechanical: subcontract cover (Halden M&E). '
                    ."Electrical: E. Doyle.\n"
                    ."• Helpdesk triage remains with J. Aiken throughout, 18:00 to 08:00.\n\n"
                    .'Callouts must be logged on the same day. An unlogged callout cannot be '
                    .'recharged to the client and will not be paid.',
                'org_unit_id' => $unit['MNT']->id,
            ], [$maintenanceHead, $mechanical, $electrical, $helpdesk], [$opsManager]);

            $this->issue($rota, $generalist);
            $this->readBy($rota, [$electrical, $helpdesk]);
            $this->happened($rota, $this->workingDay(11, 9), ['issued_at' => 30], 60);

            // RESTRICTED rather than confidential — the other tier, and it behaves differently:
            // restricted is reachable from inside the owning unit's subtree as well as by an explicit
            // allow row, where confidential is reachable by the allow row alone.
            $renewal = $this->memo($md, [
                'subject' => 'Trafford Point contract renewal — negotiating position',
                'body' => "Restricted. Not for onward circulation.\n\n"
                    .'The Trafford Point framework is up for renewal in November and the client has '
                    ."signalled they will test the market.\n\n"
                    .'• Our current margin on the framework is 11.4%. We can go to 8% to retain it, '
                    ."and no further.\n"
                    .'• The AHU replacement has gone well and is our strongest reference on the '
                    ."account. Lead with it.\n"
                    .'• If they push past 8% we let it go. We have capacity for the Northern Estates '
                    .'framework and better margin in it.',
                'project_id' => $job['TPA']->id,
                'org_unit_id' => $unit['BUS']->id,
                'confidentiality' => 'restricted',
            ], [$opsManager, $generalist]);

            $this->issue($renewal, $md);
            $this->readBy($renewal, [$opsManager]);
            $this->happened($renewal, $this->workingDay(6, 16), ['issued_at' => 25], 50);

            // ------------------------------------------------- announcements
            $fgas = $this->notice($generalist, [
                'title' => 'F-gas record keeping moves into the platform on the 1st',
                'body' => 'From the 1st, all F-gas leak checks and refrigerant movements are recorded '
                    ."here rather than on the paper log sheets.\n\n"
                    .'The paper log stays in the plant room until the end of the month so nothing is '
                    .'lost mid-cycle, then it is retired. Anyone handling refrigerant needs to have '
                    ."completed the ten-minute walkthrough before the changeover.\n\n"
                    .'Speak to Nadia if your certification is due for renewal this year.',
                'urgency' => 'high',
                'is_pinned' => true,
                'expires_on' => now()->addDays(21)->toDateString(),
            ]);

            $this->publish($fgas, $generalist);
            $this->happened($fgas, $this->workingDay(8, 8), ['published_at' => 15], 40);

            $tags = $this->notice($generalist, [
                'title' => 'Ladder inspection tags — quarterly changeover to yellow',
                'body' => "The quarterly tag colour changes to yellow this week.\n\n"
                    .'Every ladder, step ladder and podium in the depots and on client sites must '
                    .'carry a current yellow tag by Friday. Anything still carrying a green tag on '
                    ."Monday is to be quarantined, not used.\n\n"
                    .'Spare tags and inspection sheets are with the depot supervisors.',
                'org_unit_id' => $unit['MNT']->id,
                'urgency' => 'normal',
            ]);

            $this->publish($tags, $generalist);
            $this->happened($tags, $this->workingDay(20, 10), ['published_at' => 20], 40);

            $tender = $this->notice($md, [
                'title' => 'Kestrel is tendering for the Northern Estates framework',
                'body' => "DRAFT — hold until the bid/no-bid decision is minuted.\n\n"
                    .'Northern Estates have invited us to tender for their hard services framework '
                    .'across eleven buildings in Leeds and Bradford. It would be our largest single '
                    ."contract.\n\n"
                    .'Nothing is to be said to clients or subcontractors until the decision is taken.',
                'urgency' => 'normal',
            ]);

            $this->happened($tender, $this->workingDay(2, 12), [], 25);

            // ------------------------------------------------- meetings
            $site = $this->meeting($opsManager, [
                'title' => 'Trafford Point AHU replacement — site progress meeting',
                'agenda' => "1. Strip-out progress and the roof hatch lift\n"
                    ."2. Electrical isolation and re-termination\n"
                    ."3. F-gas paperwork and permits\n"
                    .'4. Commissioning and handover pack',
                'project_id' => $job['TPA']->id,
                'org_unit_id' => $unit['MNT']->id,
                'location' => 'Trafford Point, fourth floor plant room',
                'starts_at' => $this->workingDay(7, 8)->addMinutes(30),
                'ends_at' => $this->workingDay(7, 9)->addMinutes(30),
            ], [
                'chair' => [$opsManager],
                'secretary' => [$generalist],
                'participant' => [$maintenanceHead, $mechanical, $electrical, $complianceHead],
            ]);

            $this->apologise($site, $electrical, 'Covering an emergency lighting failure at the Leeds depot.');

            $site = $this->meetings->transition($site, $this->statusFor('meeting', 'held'), $opsManager);

            $this->register($site, $opsManager);

            $site = $this->meetings->recordMinutes($site,
                'Present: P. Shah (chair), M. Ellis (secretary), D. Reilly, C. Whitfield, N. Osei. '
                ."Apologies: E. Doyle.\n\n"
                .'1. STRIP-OUT. Refrigerant recovered and ductwork disconnected. The roof hatch lift '
                .'is booked for next Tuesday subject to the crane permit, which is with the building '
                ."manager.\n\n"
                .'2. ELECTRICAL. Isolation is in place and locked off. Re-termination follows the '
                ."new unit's arrival and is not on the critical path.\n\n"
                .'3. F-GAS. All recovery paperwork is complete and filed. N. Osei confirmed the '
                ."recovery cylinders are booked for collection.\n\n"
                .'4. HANDOVER. D. Reilly has the pack template. The client has asked for the O&M '
                .'manuals digitally as well as in hard copy. See the action arising.',
                $generalist);

            $this->meetings->addDecision($site, [
                'decision' => 'The roof hatch lift proceeds next Tuesday, subject only to the crane '
                    .'permit being returned by the building manager.',
                'rationale' => 'The crane and the road closure are both booked and non-refundable '
                    .'inside seven days. Slipping the lift would push commissioning past the '
                    .'contractual handover date.',
                'owner_id' => $maintenanceHead->id,
            ], $generalist);

            $handover = $this->meetings->raiseAction($site, [
                'title' => 'Add digital O&M manuals to the AHU-3 handover pack',
                'description' => 'Action arising from the site progress meeting. The client has asked '
                    .'for searchable PDFs alongside the hard copy manuals.',
                'assignee_id' => $maintenanceHead->id,
                'priority' => 'normal',
                'due_on' => now()->addDays(12)->toDateString(),
                'estimated_hours' => 4,
            ], $opsManager);

            $site = $this->meetings->transition($site, $this->statusFor('meeting', 'minuted'), $opsManager);

            $this->happened($site, $this->workingDay(13, 15), [
                'minutes_recorded_at' => 8_400,
                'minutes_circulated_at' => 9_900,
            ], 10_200);

            $this->happened($handover, $this->workingDay(6, 10), [], 30);

            $ops = $this->meeting($opsManager, [
                'title' => 'Kestrel monthly operations review',
                'agenda' => "1. Reactive job volumes and first-time fix rate\n"
                    ."2. PPM completion against the quarterly schedule\n"
                    ."3. Compliance dashboard — fire, water and F-gas\n"
                    ."4. Northern Estates framework: bid or no bid\n"
                    .'5. Any other business',
                'org_unit_id' => $unit['OPS']->id,
                'location' => 'Manchester office, meeting room',
                'starts_at' => now()->addDays(9)->setTime(9, 0),
                'ends_at' => now()->addDays(9)->setTime(11, 0),
            ], [
                'chair' => [$md],
                'secretary' => [$generalist],
                'participant' => [$maintenanceHead, $complianceHead, $helpdesk],
            ]);

            $this->happened($ops, $this->workingDay(4, 16), [], 35);
        });
    }

    // ------------------------------------------------------------ communication helpers

    /**
     * Draft a memo and address it. Issuing is a separate act, made by whoever holds `memo.issue`.
     *
     * The distribution is written through the service rather than inserted, because every recipient
     * row is a VISIBILITY GRANT: on an issued memo the same call writes the ACL row that lets a
     * confidential memo be opened by somebody with no clearance for it. Inserting the rows directly
     * would produce a demo in which half the recipients cannot read what they were sent.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,User>  $to
     * @param  array<int,User>  $cc
     */
    private function memo(User $author, array $attributes, array $to, array $cc = []): Memo
    {
        $memo = $this->memos->create($attributes, $author);

        $this->memos->address($memo, $this->idsOf($to), $author, 'to');

        if ($cc !== []) {
            $this->memos->address($memo, $this->idsOf($cc), $author, 'cc');
        }

        return $memo->refresh();
    }

    private function issue(Memo $memo, User $issuer): Memo
    {
        return $this->memos->transition($memo, $this->statusFor('memo', 'issued'), $issuer);
    }

    private function withdraw(Memo $memo, User $actor, string $reason): Memo
    {
        return $this->memos->transition($memo, $this->statusFor('memo', 'withdrawn'), $actor, $reason);
    }

    /**
     * Stamp read receipts for the people who have opened it.
     *
     * @param  array<int,User>  $readers
     */
    private function readBy(Memo $memo, array $readers): void
    {
        foreach ($readers as $reader) {
            $recipient = $memo->recipients()->where('user_id', $reader->id)->first();

            if ($recipient !== null) {
                $this->memos->markRead($recipient);
            }
        }
    }

    /** @param  array<string,mixed>  $attributes */
    private function notice(User $author, array $attributes): Announcement
    {
        return $this->notices->create($attributes, $author);
    }

    private function publish(Announcement $announcement, User $actor): Announcement
    {
        return $this->notices->transition($announcement, $this->statusFor('announcement', 'published'), $actor);
    }

    private function archive(Announcement $announcement, User $actor): Announcement
    {
        return $this->notices->transition($announcement, $this->statusFor('announcement', 'archived'), $actor);
    }

    /**
     * Call a meeting and fill the room.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<string,array<int,User>>  $room  participant role => the people in it
     */
    private function meeting(User $convener, array $attributes, array $room): Meeting
    {
        $meeting = $this->meetings->create($attributes, $convener);

        foreach ($room as $role => $people) {
            $this->meetings->invite($meeting, $this->idsOf($people), $convener, $role);
        }

        return $meeting->refresh();
    }

    /** Send apologies, as the person themselves — which is the only way they are ever sent. */
    private function apologise(Meeting $meeting, User $who, string $reason): void
    {
        $participant = $meeting->participants()->where('user_id', $who->id)->first();

        if ($participant !== null) {
            $this->meetings->respond($participant, $reason, $who);
        }
    }

    /**
     * Take the register.
     *
     * Anybody who sent apologies is skipped rather than marked absent by the chair. They already
     * answered for themselves, and overwriting that would lose the distinction between "did not
     * come" and "said in advance they could not come" — which is most of what a register is for.
     *
     * @param  array<int,User>  $absent
     */
    private function register(Meeting $meeting, User $actor, array $absent = []): void
    {
        $absentIds = $this->idsOf($absent);
        $attendance = [];

        foreach ($meeting->participants()->get() as $participant) {
            if ($participant->apologies !== null) {
                continue;
            }

            $attendance[(int) $participant->getKey()] = ! in_array((int) $participant->user_id, $absentIds, true);
        }

        $this->meetings->recordAttendance($meeting, $attendance, $actor);
    }

    /**
     * Move a communication, and the trail it left, to the day it is meant to have happened.
     *
     * The delivery pass sweeps its whole timeline at once (see spreadDeliveryHistory) because the
     * order of forty task events is the only thing that matters about them. Communications are the
     * opposite: each one has a DATE that its own text refers to — a shutdown window, a rota month, a
     * withdrawal that came a fortnight after the instruction — so they are placed individually and
     * the activity rows are spread across the span that record actually occupied. A memo issued in
     * the same second it was drafted, or withdrawn in the same minute it was issued, reads as test
     * data however good the prose is.
     *
     * `activity_logs` only, as ever. The audit trail is left exactly as the engine wrote it (§59).
     *
     * @param  array<string,int>  $stamps  further timestamp columns, in minutes after $at
     */
    private function happened(Model $record, Carbon $at, array $stamps = [], int $overMinutes = 90): void
    {
        $values = ['created_at' => $at];

        foreach ($stamps as $column => $minutesAfter) {
            $values[$column] = $at->copy()->addMinutes($minutesAfter);
        }

        DB::table($record->getTable())->where('id', $record->getKey())->update($values);

        $rows = DB::table('activity_logs')
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->orderBy('id')
            ->pluck('id');

        $last = max(1, $rows->count() - 1);

        foreach ($rows->values() as $index => $id) {
            DB::table('activity_logs')->where('id', $id)->update([
                'created_at' => $at->copy()->addMinutes((int) round($overMinutes * $index / $last)),
            ]);
        }

        $record->refresh();
    }

    /**
     * A working-hours moment this many days ago, on the hour.
     *
     * Deliberately NOT random, unlike momentOn(): these timestamps are quoted back in the seeded
     * prose — a meeting that ran from 09:00 to 10:30 says so in its own minutes — and a fixture whose
     * times drift on every re-seed makes the demo contradict itself.
     */
    private function workingDay(int $daysAgo, int $hour): Carbon
    {
        return now()->subDays(max(0, $daysAgo))->setTime($hour, 0, 0);
    }

    /**
     * @param  array<int,User>  $users
     * @return array<int,int>
     */
    private function idsOf(array $users): array
    {
        return array_values(array_map(fn (User $user) => (int) $user->id, $users));
    }

    // ================================================================= documents & approvals

    /**
     * Meridian's register: what has been approved, what is out for approval right now, and what has
     * been issued to the client (§27–§38, docs/09).
     *
     * Every state the module actually has is represented once, because the missing ones are exactly
     * where a demo stops being able to answer a question:
     *
     *   - a native document that went the whole way through the seeded two-stage route and is now
     *     read-only at Revision A;
     *   - a second one SITTING at technical review and already past its SLA, so the approvals inbox
     *     is not empty and the escalation sweep has something real to find;
     *   - an uploaded PDF drawing, so the private-storage half of §67 has an actual file behind an
     *     actual authorization check rather than a paragraph promising one;
     *   - an incoming letter in an uncontrolled register, which submits and is approved outright
     *     because its register carries no route — the branch that distinguishes "no route" from
     *     "route that could not find anybody", which stalls;
     *   - a draft checked out and still in somebody's hands, so the lock is visible on a screen.
     *
     * Nothing here is written straight to the database. Documents are raised, revised, submitted and
     * decided through the same services the controllers call, so a defect in the approval path shows
     * up on `db:seed` rather than on somebody's first real procedure. Each actor is also somebody
     * whose role would genuinely have permitted the act: Ngozi holds `document.submit` over her own
     * work, Marcus and Farida hold `approval.act`, and every transmittal is issued by the document
     * controller, because `transmittal.issue` lives in exactly one role template.
     */
    private function seedMeridianDocuments(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDocuments();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');
            $register = $this->registersByCode();

            $leadEngineer = $this->accountOf($staff, 'MEI-0005');    // Marcus Adeyemi, heads Civil
            $structuralEng = $this->accountOf($staff, 'MEI-0006');   // Ngozi Eze
            $electricalEng = $this->accountOf($staff, 'MEI-0008');   // Priya Raghunathan
            $geotechEng = $this->accountOf($staff, 'MEI-0009');      // Ibrahim Sule
            $controller = $this->accountOf($staff, 'MEI-0010');      // Helen Obi
            $engManager = $this->accountOf($staff, 'MEI-0004');      // Farida Yusuf

            $delta = $job['DTE'];

            // ------------------------------------------------- the house template
            // Header and footer carry placeholders rather than a company name, because the same
            // template has to be correct on a document nobody has written yet. TemplateRenderer
            // resolves these against a fixed whitelist — this is data substitution, never Blade
            // compilation of something an administrator typed into a web form (§66).
            $procedureTemplate = $this->templates->create([
                'name' => 'Meridian controlled procedure',
                'kind' => 'document',
                'document_category_id' => $register['PROC']->id,
                'description' => 'The house layout for management-system procedures: numbered '
                    .'sections, a revision block in the header and the confidentiality marking in '
                    .'the footer. Start a new procedure from this rather than copying an old one.',
                'header_html' => '<p><strong>{{organization.name}}</strong> &middot; '
                    .'{{document.number}} &middot; Revision {{document.revision}}</p>',
                'footer_html' => '<p>{{document.confidentiality}} &middot; '
                    .'Uncontrolled when printed &middot; {{today}}</p>',
                'content_html' => '<h1>{{document.title}}</h1>'
                    .'<p><em>Prepared by {{user.name}}, {{user.job_title}}.</em></p>'
                    .'<h2>1. Purpose</h2><p>State what this procedure exists to achieve.</p>'
                    .'<h2>2. Scope</h2><p>State where it applies and where it does not.</p>'
                    .'<h2>3. Responsibilities</h2><p>Name the roles, not the people.</p>'
                    .'<h2>4. Procedure</h2><p>The steps, in the order they are carried out.</p>'
                    .'<h2>5. Records</h2><p>What is kept, by whom, and for how long.</p>',
                'page_size' => 'A4',
                'orientation' => 'portrait',
                'is_active' => true,
                'is_default' => true,
            ], $controller);

            // ------------------------------------------------- approved, the whole way through
            $criteria = $this->raise($structuralEng, [
                'title' => 'Structural steel design criteria — Delta Terminal',
                'description' => 'Design loads, load combinations, materials and deflection limits '
                    .'governing the tank farm steelwork and the pipe rack.',
                'document_category_id' => $register['SPEC']->id,
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-CS-SD']->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'tags' => ['structural', 'design basis'],
            ]);

            $this->draftOn($criteria, $structuralEng, [
                'change_summary' => 'First issue for review.',
                'content_html' => '<h1>Structural steel design criteria</h1>'
                    .'<h2>1. Basis of design</h2><p>Steelwork is designed to BS EN 1993 with the '
                    .'national annex for the project country, and to the client specification '
                    .'RPDC/EPC/2025/017 Appendix C where that is more onerous.</p>'
                    .'<h2>2. Loads</h2><p>Dead, imposed, wind and thermal loads are as tabulated '
                    .'below. Seismic action is not considered; the site is in a zone of very low '
                    .'seismicity and the client has confirmed this in writing.</p>'
                    .'<h2>3. Deflection limits</h2><p>Pipe rack beams are limited to span/300 under '
                    .'imposed load, tightened to span/500 where a line carries a flanged joint '
                    .'within one metre of midspan.</p>'
                    .'<h2>4. Corrosion protection</h2><p>Coastal C5-M environment throughout. '
                    .'Galvanising plus a two-coat system on all external steel.</p>',
            ]);

            $this->documents->submit($criteria, $structuralEng);
            $this->decide($criteria, $leadEngineer, Decision::Approve,
                'Loads and deflection limits agree with the client specification. '
                .'The C5-M call is right for this location.');
            $this->decide($criteria, $engManager, Decision::Approve,
                'Approved for issue to RPDC. Please transmit with the jetty GA.');

            $this->happened($criteria, $this->workingDay(26, 9), [], 300);

            // ------------------------------------------------- sitting in somebody's inbox, late
            $piling = $this->raise($geotechEng, [
                'title' => 'Piling and pile cap method statement — Delta Terminal',
                'description' => 'Sequence, plant, tolerances and hold points for the driven '
                    .'tubular piles and the in-situ pile caps to Tanks 1 and 2.',
                'document_category_id' => $register['MS']->id,
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-CS-GT']->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'tags' => ['geotechnical', 'site'],
            ]);

            $pilingDraft = $this->draftOn($piling, $geotechEng, [
                'change_summary' => 'First issue for technical review.',
                'content_html' => '<h1>Piling and pile cap method statement</h1>'
                    .'<h2>1. Sequence</h2><p>Piles are driven from the seaward row inward so that '
                    .'the rig never tracks over a completed cap.</p>'
                    .'<h2>2. Set criteria</h2><p>Driving continues to the specified set or to '
                    .'refusal, whichever comes first, and a redrive is taken 24 hours later on one '
                    .'pile in ten.</p>'
                    .'<h2>3. Hold points</h2><p>Pile cut-off level and reinforcement fixing are '
                    .'both hold points requiring QA sign-off before concrete is ordered.</p>',
            ]);

            $this->documents->submit($piling, $geotechEng);

            // Backdated so the demo has a genuinely OVERDUE approval. The SLA is 24 hours and this
            // entered review three days ago, which is what the overdue filter, the escalation sweep
            // and the reminder digest all key off — none of which can be demonstrated against a
            // stage instance that was created a second ago.
            $this->overdueSince($piling, $this->workingDay(3, 14));
            $this->happened($piling, $this->workingDay(4, 10), [], 180);

            // ------------------------------------------------- an uploaded drawing (§67)
            $jettyGa = $this->raise($controller, [
                'title' => 'Jetty topsides general arrangement',
                'description' => 'Plan and sections of the jetty topsides showing the pipe corridor, '
                    .'the access walkway and the firewater monitor positions.',
                'document_category_id' => $register['DWG']->id,
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-CS']->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'tags' => ['drawing', 'jetty'],
            ]);

            $this->uploadRevision($jettyGa, $controller, [
                'change_summary' => 'First issue for review.',
            ]);

            $this->documents->submit($jettyGa, $controller);
            $this->decide($jettyGa, $leadEngineer, Decision::Approve,
                'Walkway width and monitor coverage check out against the fire study.');
            $this->decide($jettyGa, $engManager, Decision::Approve, 'Approved for issue.');

            $this->happened($jettyGa, $this->workingDay(21, 11), [], 260);

            // ------------------------------------------------- a register with no route at all
            // Correspondence received is uncontrolled and approves nothing, so submitting it takes
            // the null-instance branch: the revision becomes current immediately and the timeline
            // records WHY it was never reviewed. Nobody approves an incoming letter.
            $rpdcLetter = $this->raise($controller, [
                'title' => 'RPDC letter — approval of jetty layout, ref RPDC/DC/2026/0311',
                'description' => 'Client approval of the jetty topsides layout, subject to the two '
                    .'comments on monitor positions recorded in the attached mark-up.',
                'document_category_id' => $register['CORR']->id,
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-DC']->id,
                'tags' => ['client', 'correspondence'],
            ]);

            $this->uploadRevision($rpdcLetter, $controller, [
                'change_summary' => 'Received by email and filed on the day of receipt.',
            ]);

            $this->documents->submit($rpdcLetter, $controller);
            $this->happened($rpdcLetter, $this->workingDay(18, 15), [], 20);

            // ------------------------------------------------- started from the template, locked
            $testProcedure = $this->raise($electricalEng, [
                'title' => 'Electrical installation test and inspection procedure',
                'description' => 'Initial verification and periodic testing of LV installations, '
                    .'including the records each test produces.',
                'document_category_id' => $register['PROC']->id,
                'org_unit_id' => $unit['ENG-EI']->id,
                'discipline_id' => $unit['ENG-EI']->id,
                'tags' => ['electrical', 'management system'],
            ]);

            $this->draftOn($testProcedure, $electricalEng, [
                'change_summary' => 'Started from the controlled procedure template.',
                'content_html' => $this->templates->seedContent(
                    $procedureTemplate,
                    $this->templates->contextFor($testProcedure, $electricalEng),
                ),
            ]);

            // Left CHECKED OUT deliberately. A demo in which no document is locked cannot show what
            // the lock looks like to the person who does not hold it, and check-out is the whole of
            // this platform's answer to concurrent editing (§1 rules out real-time co-authoring).
            $this->documents->checkOut($testProcedure, $electricalEng);
            $this->happened($testProcedure, $this->workingDay(2, 9), [], 45);

            // ------------------------------------------------- issued, and acknowledged
            $issued = $this->transmittals->create([
                'subject' => 'Delta Terminal — design criteria and jetty GA issued for approval',
                'to_name' => 'Riverine Petroleum Development Company',
                'to_attention' => 'Ifeoma Bassey, Document Control',
                'to_email' => 'documentcontrol@rpdc.example',
                'to_address' => "RPDC House, 4 Refinery Road\nEffurun, Delta State",
                'purpose' => 'for_approval',
                'transport' => 'email',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-DC']->id,
                'cover_note' => 'Two documents are enclosed for your approval under contract '
                    .'RPDC/EPC/2025/017. Please acknowledge receipt and return comments within ten '
                    .'working days.',
                'requires_acknowledgement' => true,
                // Ten working days from the issue date, which has already passed — and it came back
                // in two, which is what the acknowledgement below records.
                'due_response_on' => $this->workingDay(2, 17)->toDateString(),
            ], $controller);

            $this->transmittals->addItem($issued, $this->currentRevision($criteria), [
                'media' => 'electronic',
                'remarks' => 'Issued for approval.',
            ], $controller);

            $this->transmittals->addItem($issued, $this->currentRevision($jettyGa), [
                'media' => 'both',
                'copies' => 2,
                'remarks' => 'Two paper prints follow by courier.',
            ], $controller);

            $this->transmittals->issue($issued, $controller);
            $this->transmittals->acknowledge($issued, $controller, 'Ifeoma Bassey', 'RPDC-DC-2026-0442');

            $this->happened($issued, $this->workingDay(16, 10), [
                'issued_at' => 90,
                'acknowledged_at' => 2 * 24 * 60,
            ], 200);

            // ------------------------------------------------- still being assembled
            // A DRAFT transmittal, and the reason one is here: an unissued transmittal is scoped by
            // its publication column, so it is invisible to everybody except its author, an explicit
            // share, and holders of `transmittal.issue`. That rule has no visible consequence in a
            // demo where every transmittal has already gone out.
            $pending = $this->transmittals->create([
                'subject' => 'Delta Terminal — piling method statement (package being assembled)',
                'to_name' => 'Riverine Petroleum Development Company',
                'to_attention' => 'Ifeoma Bassey, Document Control',
                'to_email' => 'documentcontrol@rpdc.example',
                'purpose' => 'for_review',
                'transport' => 'portal',
                'project_id' => $delta->id,
                'org_unit_id' => $unit['ENG-DC']->id,
                'cover_note' => 'Holding until the method statement clears technical review. Do not '
                    .'issue before then.',
                'requires_acknowledgement' => true,
            ], $controller);

            $this->transmittals->addItem($pending, $pilingDraft, [
                'media' => 'electronic',
                'remarks' => 'Draft — awaiting technical review. Not for issue.',
            ], $controller);

            $this->happened($pending, $this->workingDay(2, 16), [], 30);
        });
    }

    /**
     * Kestrel's register — and, first, Kestrel deciding it does not like the route it was given.
     *
     * This is the pass that makes §73 a demonstration rather than a claim. Both companies were
     * provisioned with the identical starter route, whose second stage names an ENGINEERING MANAGER.
     * Meridian has one. Kestrel is a facilities firm with eight people and never will, and a stage
     * that resolves to nobody STALLS — correctly, loudly, and uselessly for the people involved.
     *
     * So Kestrel's administrator edits the route. Not a fork of the codebase, not a config file, not
     * a flag: the same screens any other tenant uses, changing an assignee type and a name on a
     * stage. Afterwards the two companies route documents to genuinely different people through one
     * engine, which is the entire architectural claim of this platform stated in about fifteen lines.
     */
    private function seedKestrelDocuments(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDocuments();

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');
            $register = $this->registersByCode();

            $md = $this->accountOf($staff, 'KFM-001');                 // Rowan Calder
            $maintenanceHead = $this->accountOf($staff, 'KFM-003');    // Duncan Reilly
            $complianceHead = $this->accountOf($staff, 'KFM-004');     // Nadia Osei
            $mechanical = $this->accountOf($staff, 'KFM-005');         // Callum Whitfield
            $electrical = $this->accountOf($staff, 'KFM-006');         // Erin Doyle
            $generalist = $this->accountOf($staff, 'KFM-008');         // Marianne Ellis

            $trafford = $job['TPA'];

            // ------------------------------------------------- re-pointing the starter route
            $route = Workflow::query()
                ->where('entity_type', 'document')
                ->where('name', 'Document approval')
                ->first();

            if ($route !== null) {
                $complianceRole = Role::query()->where('slug', 'hse-manager')->value('id');

                $approvalStage = WorkflowStage::query()
                    ->where('workflow_id', $route->getKey())
                    ->where('sequence', 2)
                    ->first();

                if ($approvalStage !== null && $complianceRole !== null) {
                    $this->routes->updateStage($approvalStage, [
                        'name' => 'Compliance sign-off',
                        'instructions' => 'Nothing leaves this company as a controlled document '
                            .'without compliance seeing it. Approving issues the revision; after '
                            .'that a change means a new revision.',
                        'assignee_type' => 'role',
                        'assignee_ref' => (string) $complianceRole,
                        'sla_hours' => 72,
                    ], $md);
                }

                $reviewStage = WorkflowStage::query()
                    ->where('workflow_id', $route->getKey())
                    ->where('sequence', 1)
                    ->first();

                if ($reviewStage !== null) {
                    $this->routes->updateStage($reviewStage, [
                        'name' => 'Supervisor review',
                        'sla_hours' => 48,
                    ], $md);
                }
            }

            // ------------------------------------------------- a template that is not a document
            // Kind `report`, and that is the point of including it: a template renders straight to
            // PDF as well as seeding a document, so the module is not quietly document-only.
            $this->templates->create([
                'name' => 'Kestrel service visit report',
                'kind' => 'report',
                'description' => 'The sheet an engineer completes at the end of a planned or '
                    .'reactive visit. Prints on one side of A4 on purpose.',
                'header_html' => '<p><strong>{{organization.name}}</strong> &middot; service visit '
                    .'report &middot; {{today}}</p>',
                'footer_html' => '<p>{{organization.address}} &middot; {{organization.phone}}</p>',
                'content_html' => '<h1>Service visit report</h1>'
                    .'<h2>Site and asset</h2><p>Site, building, plant reference and location.</p>'
                    .'<h2>Work carried out</h2><p>What was done, in the order it was done.</p>'
                    .'<h2>Parts used</h2><p>Part number, quantity, and whether it came off the van.</p>'
                    .'<h2>Left safe?</h2><p>State the condition the plant was left in.</p>'
                    .'<h2>Follow-up required</h2><p>Anything the helpdesk has to raise a job for.</p>',
                'page_size' => 'A4',
                'orientation' => 'portrait',
                'is_active' => true,
            ], $generalist);

            // ------------------------------------------------- approved through the edited route
            $ppm = $this->raise($mechanical, [
                'title' => 'Planned preventive maintenance procedure — mechanical plant',
                'description' => 'Frequencies, tasks and record requirements for AHUs, pumps and '
                    .'pressure systems across the managed portfolio.',
                'document_category_id' => $register['PROC']->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'tags' => ['maintenance', 'management system'],
            ]);

            $this->draftOn($ppm, $mechanical, [
                'change_summary' => 'First issue.',
                'content_html' => '<h1>Planned preventive maintenance — mechanical plant</h1>'
                    .'<h2>1. Frequencies</h2><p>AHU filter checks monthly, belt and bearing checks '
                    .'quarterly, full service annually. Pressure systems follow the written scheme '
                    .'of examination and nothing in this procedure overrides it.</p>'
                    .'<h2>2. Records</h2><p>Every visit produces a service visit report, which is '
                    .'filed against the asset the same day. A visit with no report did not happen.</p>'
                    .'<h2>3. Escalation</h2><p>Anything found that cannot be left safe is reported '
                    .'to the helpdesk before the engineer leaves site, not at the end of the day.</p>',
            ]);

            $this->documents->submit($ppm, $mechanical);
            $this->decide($ppm, $maintenanceHead, Decision::Approve,
                'Frequencies match the contract schedule. Happy with the escalation wording.');
            $this->decide($ppm, $complianceHead, Decision::Approve,
                'Approved. The pressure systems carve-out is the right way round.');

            $this->happened($ppm, $this->workingDay(23, 9), [], 280);

            // ------------------------------------------------- waiting on compliance
            $isolation = $this->raise($electrical, [
                'title' => 'Electrical isolation and re-termination method statement — AHU-3',
                'description' => 'Safe isolation, proving dead, and re-termination of the AHU-3 '
                    .'supply during the replacement works at Trafford Point.',
                'document_category_id' => $register['MS']->id,
                'project_id' => $trafford->id,
                'org_unit_id' => $unit['MNT-ELE']->id,
                'tags' => ['electrical', 'site'],
            ]);

            $this->draftOn($isolation, $electrical, [
                'change_summary' => 'First issue for review.',
                'content_html' => '<h1>Electrical isolation and re-termination — AHU-3</h1>'
                    .'<h2>1. Isolation</h2><p>Isolate at DB-4/way 12, lock off, and prove dead with '
                    .'a proving unit before and after testing.</p>'
                    .'<h2>2. Points of work</h2><p>Only the two engineers named on the permit hold '
                    .'keys. The permit is returned to the helpdesk on completion, not kept in the '
                    .'van.</p>'
                    .'<h2>3. Re-energisation</h2><p>Insulation resistance and polarity are recorded '
                    .'before the supply is restored, and the certificate is filed the same day.</p>',
            ]);

            $this->documents->submit($isolation, $electrical);
            $this->decide($isolation, $maintenanceHead, Decision::Approve,
                'Isolation point and proving sequence are right. Over to compliance.');

            // Left pending at compliance sign-off — the route Kestrel edited into existence,
            // resolving to a person Meridian's identical starter route would never have found.
            $this->happened($isolation, $this->workingDay(2, 11), [], 120);

            // ------------------------------------------------- filed, never approved
            $fireAssessment = $this->raise($generalist, [
                'title' => 'Trafford Point Estates — landlord fire risk assessment 2026',
                'description' => 'Issued to us by the landlord. Held for reference; the actions '
                    .'falling to Kestrel are tracked separately.',
                'document_category_id' => $register['CORR']->id,
                'project_id' => $trafford->id,
                'org_unit_id' => $unit['CMP']->id,
                'tags' => ['client', 'fire safety'],
            ]);

            $this->uploadRevision($fireAssessment, $generalist, [
                'change_summary' => 'Received from the landlord and filed on receipt.',
            ]);

            $this->documents->submit($fireAssessment, $generalist);
            $this->happened($fireAssessment, $this->workingDay(12, 14), [], 20);

            // ------------------------------------------------- issued, still unacknowledged
            // The other half of the acknowledgement story. Meridian's transmittal came back;
            // this one has not, and the response date is still in the future — which is what the
            // register has to be able to show, because chasing it is the document controller's job.
            $toClient = $this->transmittals->create([
                'subject' => 'Trafford Point — PPM procedure issued for information',
                'to_name' => 'Trafford Point Estates LLP',
                'to_attention' => 'Helen Marsh, Estates Manager',
                'to_email' => 'estates@traffordpoint.example',
                'purpose' => 'for_information',
                'transport' => 'email',
                'project_id' => $trafford->id,
                'org_unit_id' => $unit['BUS']->id,
                'cover_note' => 'Issued for your information under contract TPE/PPM/2026/03. '
                    .'Please confirm receipt.',
                'requires_acknowledgement' => true,
                // Still in the future: this one is outstanding, and chasing it is somebody's job.
                'due_response_on' => now()->addDays(5)->toDateString(),
            ], $generalist);

            $this->transmittals->addItem($toClient, $this->currentRevision($ppm), [
                'media' => 'electronic',
                'remarks' => 'Issued for information.',
            ], $generalist);

            $this->transmittals->issue($toClient, $generalist);

            $this->happened($toClient, $this->workingDay(4, 12), ['issued_at' => 60], 90);
        });
    }

    // ================================================================= engineering · HSE · quality

    /**
     * What Meridian is DESIGNING (§39–§45).
     *
     * The engineering registers hang off the live EPC job the delivery pass created, because that
     * is the only place they mean anything: a deliverable with no project is a filename, and an RFI
     * with no client is a question asked into the air.
     *
     * SERVICES ARE RESOLVED LOCALLY here rather than added to the class properties above. The
     * delivery and document chapters keep theirs because their helpers are shared across both
     * tenants; these three chapters are Meridian's alone, and fifteen more properties would make the
     * top of this file harder to read than the code that uses them.
     */
    private function seedMeridianEngineering(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $deliverables = app(DeliverableService::class);
            $drawings = app(DrawingService::class);
            $rfis = app(RfiService::class);
            $submittals = app(SubmittalService::class);
            $changes = app(ChangeRequestService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $engineeringManager = $this->accountOf($staff, 'MEI-0004');  // Farida Yusuf
            $leadEngineer = $this->accountOf($staff, 'MEI-0005');        // Marcus Adeyemi
            $structuralEngineer = $this->accountOf($staff, 'MEI-0006');  // Ngozi Eze
            $pipingEngineer = $this->accountOf($staff, 'MEI-0007');      // Segun Afolabi
            $electricalEngineer = $this->accountOf($staff, 'MEI-0008');  // Priya Raghunathan
            $documentController = $this->accountOf($staff, 'MEI-0010');  // Helen Obi
            $projectManager = $this->accountOf($staff, 'MEI-0011');      // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');         // Kelechi Nwosu

            $dte = $job['DTE'];

            // ------------------------------------------------- deliverables
            // Weighted, because a deliverables register whose rows all count the same reports a job
            // as half done when the two big design packages have not started. `weight` is what the
            // project's progress roll-up multiplies by.
            $foundations = $deliverables->create([
                'title' => 'Tank T-401 foundation design report',
                'description' => 'Ring beam and piled raft design for the new 15,000 m³ product tank, '
                    .'including settlement analysis against the 2019 site investigation.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'org_unit_id' => $unit['ENG']->id,
                'type' => 'Design report',
                'responsible_id' => $structuralEngineer->id,
                'planned_start_on' => now()->subDays(120)->toDateString(),
                'planned_issue_on' => now()->subDays(64)->toDateString(),
                'weight' => 8,
                'revision' => 'Rev 1',
            ], $leadEngineer);

            $deliverables->reportProgress($foundations, 100, $structuralEngineer, null,
                'Settlement check reissued after the client supplied the revised product density.');
            $deliverables->issue($foundations, $leadEngineer, now()->subDays(61)->toDateString());

            $isometrics = $deliverables->create([
                'title' => 'Piping isometrics — 10" product transfer line P-1204',
                'description' => 'Forty-one isometrics from the manifold to T-401, for fabrication '
                    .'and for the stress report that follows them.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'type' => 'Drawing package',
                'responsible_id' => $pipingEngineer->id,
                'planned_start_on' => now()->subDays(70)->toDateString(),
                'planned_issue_on' => now()->subDays(6)->toDateString(),
                'weight' => 12,
                'revision' => 'Rev 0',
            ], $leadEngineer);

            // Reported late and short, and the forecast says so. A register in which every forecast
            // equals every plan is the one thing a project manager will not believe.
            $deliverables->reportProgress($isometrics, 65, $pipingEngineer,
                now()->addDays(11)->toDateString(),
                'Held on the tie-in points at the manifold until RFI-0002 comes back.');

            $singleLine = $deliverables->create([
                'title' => 'Electrical single line diagram — substation B',
                'description' => 'Incomer, bus section and outgoing feeders for the new pump house, '
                    .'to be read with the load schedule.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-EI']->id,
                'org_unit_id' => $unit['ENG']->id,
                'type' => 'Drawing',
                'responsible_id' => $electricalEngineer->id,
                'planned_start_on' => now()->subDays(52)->toDateString(),
                'planned_issue_on' => now()->addDays(9)->toDateString(),
                'weight' => 5,
                'revision' => 'Rev A',
            ], $leadEngineer);

            $deliverables->reportProgress($singleLine, 80, $electricalEngineer, null,
                'Out for internal check with the lead engineer.');

            // ------------------------------------------------- drawings
            // The drawing register is NOT the deliverables register. One deliverable is forty-one
            // sheets; one sheet has its own revision, purpose of issue and checker. Both point at
            // the same job and neither is derived from the other.
            $foundationPlan = $drawings->create([
                'number' => 'DTE-CS-DWG-1001',
                'title' => 'T-401 foundation — general arrangement',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'org_unit_id' => $unit['ENG']->id,
                'deliverable_id' => $foundations->id,
                'sheet_number' => 1,
                'sheet_count' => 3,
                'sheet_size' => 'A1',
                'scale' => '1:50',
                'revision' => 'A',
                'purpose' => 'ifr',
                'revision_date' => now()->subDays(88)->toDateString(),
                'drawn_by' => $structuralEngineer->id,
                'checked_by' => $leadEngineer->id,
            ], $structuralEngineer);

            // Two revisions, in the order a real sheet gets them: reviewed, then approved, then
            // released to build from. The register keeps the history; the site sees only the latest.
            $drawings->revise($foundationPlan, 'B', $structuralEngineer, 'ifa',
                now()->subDays(74)->toDateString());
            $drawings->revise($foundationPlan, '0', $leadEngineer, 'ifc',
                now()->subDays(60)->toDateString());

            $drawings->create([
                'number' => 'DTE-CS-DWG-1002',
                'title' => 'T-401 foundation — reinforcement details',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'org_unit_id' => $unit['ENG']->id,
                'deliverable_id' => $foundations->id,
                'sheet_number' => 2,
                'sheet_count' => 3,
                'sheet_size' => 'A1',
                'scale' => '1:20',
                'revision' => '0',
                'purpose' => 'ifc',
                'revision_date' => now()->subDays(60)->toDateString(),
                'drawn_by' => $structuralEngineer->id,
                'checked_by' => $leadEngineer->id,
            ], $structuralEngineer);

            $isoSheet = $drawings->create([
                'number' => 'DTE-MP-ISO-1204-07',
                'title' => 'Isometric — line 10"-P-1204, spool 7 of 41',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'deliverable_id' => $isometrics->id,
                'sheet_number' => 7,
                'sheet_count' => 41,
                'sheet_size' => 'A3',
                'scale' => 'NTS',
                'revision' => 'A',
                'purpose' => 'ifr',
                'revision_date' => now()->subDays(15)->toDateString(),
                'drawn_by' => $pipingEngineer->id,
            ], $pipingEngineer);

            // Voided rather than deleted. A drawing number that was issued once exists forever, and
            // the site is entitled to find out what happened to a sheet somebody printed last month.
            $superseded = $drawings->create([
                'number' => 'DTE-EI-DWG-2101',
                'title' => 'Substation B — single line diagram (superseded numbering)',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-EI']->id,
                'org_unit_id' => $unit['ENG']->id,
                'sheet_size' => 'A2',
                'revision' => 'A',
                'purpose' => 'ifr',
                'revision_date' => now()->subDays(41)->toDateString(),
                'drawn_by' => $electricalEngineer->id,
            ], $electricalEngineer);

            $this->statuses->transition($superseded, $this->statusFor('drawing', 'void'), $engineeringManager,
                'Renumbered to DTE-EI-DWG-2110 after the client adopted their own substation numbering.');

            // ------------------------------------------------- RFIs and technical queries
            // ONE REGISTER, three kinds, both directions. The question we asked the client and the
            // question the fabricator asked us are the same shape of record and are counted
            // together when somebody asks how many queries this job is carrying.
            $tieIn = $rfis->create([
                'kind' => 'rfi',
                'direction' => 'outgoing',
                'subject' => 'Tie-in elevation at the existing product manifold',
                'question' => 'Drawing RPDC-PR-1102 Rev 3 shows the manifold header at +4.250 m; the '
                    ."site survey of 14 March measured +4.615 m at the same tag.\n\n"
                    .'Please confirm which is correct. The isometrics for line 10"-P-1204 cannot be '
                    .'released for fabrication until the tie-in elevation is fixed.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'party_name' => 'Riverine Petroleum Development Company',
                'raised_by' => $pipingEngineer->id,
                'raised_on' => now()->subDays(19)->toDateString(),
                'required_by' => now()->subDays(5)->toDateString(),
            ], $pipingEngineer);

            $rfis->send($tieIn, $leadEngineer);

            // Open, past its required-by date, and the deliverable above says it is what it is
            // waiting on. The two records agree without either of them being derived from the other.

            $weldProcedure = $rfis->create([
                'kind' => 'technical_query',
                'direction' => 'incoming',
                'subject' => 'Acceptability of GTAW root with SMAW fill on the 10" line',
                'question' => 'Our welders are qualified to WPS-014 (GTAW root, SMAW fill and cap). '
                    .'The specification calls up WPS-011 throughout. May we use WPS-014 on the '
                    .'buried section?',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'party_name' => 'Harcourt Fabrication Services Ltd',
                'raised_by' => $documentController->id,
                'raised_on' => now()->subDays(12)->toDateString(),
                'required_by' => now()->addDays(2)->toDateString(),
            ], $documentController);

            $rfis->send($weldProcedure, $documentController);
            $rfis->answer($weldProcedure,
                'WPS-014 is acceptable for the buried section provided the root pass is purged to '
                    .'less than 0.5% oxygen and each joint is subject to 100% radiography rather '
                    .'than the 10% called up in the specification. The additional NDT is at our '
                    .'cost and has been raised as CR-0001.',
                $leadEngineer, true, false,
                'Additional radiography on 41 joints. Priced in CR-0001.');

            $rfis->close($weldProcedure, $leadEngineer, 'Fabricator has confirmed and started welding.');

            $siteQuery = $rfis->create([
                'kind' => 'site_query',
                'direction' => 'incoming',
                'subject' => 'Obstruction found at gridline C4 during excavation',
                'question' => 'A 150 mm abandoned line, not on any drawing we hold, crosses the '
                    .'foundation footprint at roughly 1.2 m depth. Excavation stopped. Please advise '
                    .'whether it can be cut and capped.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'party_name' => 'Delta Terminal site team',
                'raised_by' => $siteManager->id,
                'raised_on' => now()->subDays(3)->toDateString(),
                'required_by' => now()->addDays(1)->toDateString(),
            ], $siteManager);

            $rfis->send($siteQuery, $siteManager);

            // ------------------------------------------------- submittals
            // A submittal is a package, and the review code lands on the PACKAGE and on each ITEM.
            // "Approved as noted" on the cover with one item marked revise-and-resubmit is the
            // normal outcome of a real review, and a register that only records the cover code
            // cannot tell the fabricator which of eleven items to redo.
            $valves = $submittals->create([
                'title' => 'Manual valves — vendor data and material certificates',
                'description' => 'Gate and globe valves for the T-401 transfer line, package 3 of 5.',
                'type' => 'Vendor data',
                'revision' => '0',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'party_name' => 'Riverine Petroleum Development Company',
                'required_by' => now()->subDays(9)->toDateString(),
            ], $documentController);

            $submittals->addItem($valves, null, [
                'description' => '10" gate valve, class 300, gear operated',
                'reference' => 'TAG V-1204-01 / spec MPS-014',
                'quantity' => 6,
            ], $documentController);

            $certificates = $submittals->addItem($valves, null, [
                'description' => 'EN 10204 3.1 material certificates for the valve bodies',
                'reference' => 'MPS-014 cl. 8.3',
                'quantity' => 6,
            ], $documentController);

            $submittals->addItem($valves, null, [
                'description' => 'Hydrostatic test reports',
                'reference' => 'API 598',
                'quantity' => 6,
            ], $documentController);

            $submittals->submit($valves, $documentController);
            $submittals->codeItem($certificates, 'C Revise and Resubmit', $engineeringManager,
                'Certificates supplied are 2.2. The specification requires 3.1 with the heat numbers '
                    .'traceable to the bodies.');
            $submittals->review($valves, 'B Approved as Noted', 'revise_resubmit', $engineeringManager,
                'Valves accepted. Resubmit the material certificates to 3.1 before the valves ship.');

            $valvesRev1 = $submittals->resubmit($valves, $documentController);
            $submittals->submit($valvesRev1, $documentController);

            $coating = $submittals->create([
                'title' => 'External coating system — data sheets and applicator qualification',
                'description' => 'Three-coat epoxy system for the tank shell and the buried line.',
                'type' => 'Method statement',
                'revision' => '0',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['ENG']->id,
                'party_name' => 'Riverine Petroleum Development Company',
                'required_by' => now()->addDays(6)->toDateString(),
            ], $documentController);

            $submittals->addItem($coating, null, [
                'description' => 'Product data sheets, primer / intermediate / topcoat',
                'reference' => 'CTS-002',
                'quantity' => 3,
            ], $documentController);

            $submittals->submit($coating, $documentController);

            // ------------------------------------------------- change requests
            // THE DISPOSITION IS NOT THE STATUS. This one is `closed` (where it got to) and
            // `approved` (what was decided), and "how much did we approve this quarter" is a
            // question the status column cannot answer on its own.
            $radiography = $changes->create([
                'title' => 'Additional radiography on buried section of line 10"-P-1204',
                'description' => 'Increase NDT coverage on the buried section from 10% to 100% '
                    .'radiography, following acceptance of WPS-014 for the root pass.',
                'reason' => 'Raised out of TQ-0002. The alternative weld procedure is acceptable to '
                    .'engineering only with full volumetric examination of the buried joints, which '
                    .'cannot be re-excavated once backfilled.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'org_unit_id' => $unit['PMO']->id,
                'origin' => 'internal',
                'cost_impact' => 2850000,
                'schedule_impact_days' => 3,
                'impact_notes' => '41 joints at ₦68,500 per joint including the standby time. Three '
                    .'days on the critical path, absorbed into the float on the tie-in window.',
                'raised_by' => $leadEngineer->id,
                'raised_on' => now()->subDays(11)->toDateString(),
                'required_by' => now()->subDays(4)->toDateString(),
            ], $leadEngineer);

            $changes->submit($radiography, $leadEngineer);
            $changes->decide($radiography, ChangeDisposition::Approved, $projectManager,
                'Approved. The client has accepted the cost under the variation account; the three '
                    .'days come out of the tie-in float and the programme date does not move.',
                2850000, 3);
            $changes->close($radiography, $projectManager);

            $craneAccess = $changes->create([
                'title' => 'Second crane hardstanding on the east side of T-401',
                'description' => 'Construct an additional 20 m × 15 m hardstanding to allow tandem '
                    .'lifting of the shell courses.',
                'reason' => 'Site-raised. The single hardstanding forces a long radius lift and adds '
                    .'roughly nine days to the shell erection sequence.',
                'project_id' => $dte->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'origin' => 'site',
                'cost_impact' => 6400000,
                'schedule_impact_days' => -9,
                'impact_notes' => 'Saves nine days on erection at a cost the client has not agreed to '
                    .'carry. Deferred pending the next progress meeting.',
                'raised_by' => $siteManager->id,
                'raised_on' => now()->subDays(6)->toDateString(),
                'required_by' => now()->addDays(8)->toDateString(),
            ], $siteManager);

            $changes->submit($craneAccess, $siteManager);
        });
    }

    /**
     * What Meridian is DOING ABOUT SAFETY (§46–§53) — and the phase's exit criterion, demonstrated
     * rather than described.
     *
     * THE ORDER OF THIS CHAPTER IS THE ARGUMENT. Configuration first: permit types, training
     * courses, and an approval route for incidents whose review stage is configured to open an
     * investigation and raise a corrective action. Then an incident is reported by a site officer
     * and sent for review — and the investigation and the corrective action appear, assigned and
     * dated, because of the route somebody set up on a screen. Nothing in this method creates
     * either of them. If that ever stops being true this seeder will still run and the two records
     * will simply not be there, which is why IncidentSpawnsInvestigationTest asserts it as well.
     */
    private function seedMeridianHse(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();
            $this->bootDocuments();

            $incidents = app(IncidentService::class);
            $investigations = app(InvestigationService::class);
            $actions = app(CorrectiveActionService::class);
            $observations = app(HseObservationService::class);
            $risks = app(RiskAssessmentService::class);
            $inspections = app(HseInspectionService::class);
            $talks = app(ToolboxTalkService::class);
            $permits = app(PermitService::class);
            $training = app(TrainingRecordService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'MEI-0001');             // Adaeze Okonkwo
            $pipingEngineer = $this->accountOf($staff, 'MEI-0007'); // Segun Afolabi
            $siteManager = $this->accountOf($staff, 'MEI-0012');    // Kelechi Nwosu
            $hseManager = $this->accountOf($staff, 'MEI-0013');     // Grace Ibrahim
            $hseOfficer = $this->accountOf($staff, 'MEI-0014');     // Emeka Duru
            $qaInspector = $this->accountOf($staff, 'MEI-0016');    // Ruth Okafor

            $dte = $job['DTE'];

            // ------------------------------------------------- configuration: permit types
            // Not provisioned with the tenant, and deliberately: a facilities-management firm needs
            // hot work and nothing else, while this company works in confined spaces every week.
            // Each type carries its own precaution list, copied onto the permit at creation so the
            // permit stays readable after somebody edits the type (§73).
            $hotWork = $this->permitType($md, [
                'name' => 'Hot work',
                'code' => 'HW',
                'description' => 'Welding, cutting, grinding or any work producing a source of '
                    .'ignition outside a designated workshop.',
                'color' => 'danger',
                'default_duration_hours' => 8,
                'requires_gas_test' => true,
                'sort_order' => 1,
                'precaution_template' => [
                    ['description' => 'Combustible material removed or protected within 15 m', 'is_required' => true],
                    ['description' => 'Gas test carried out and recorded within the last hour', 'is_required' => true],
                    ['description' => 'Fire extinguisher and charged hose reel at the work face', 'is_required' => true],
                    ['description' => 'Fire watch posted for the duration and 30 minutes after', 'is_required' => true],
                    ['description' => 'Drains and openings within 15 m covered', 'is_required' => true],
                    ['description' => 'Adjacent operations notified', 'is_required' => false],
                ],
            ]);

            $confinedSpace = $this->permitType($md, [
                'name' => 'Confined space entry',
                'code' => 'CSE',
                'description' => 'Entry into any tank, vessel, pit or excavation over 1.2 m deep '
                    .'with restricted egress.',
                'color' => 'danger',
                'default_duration_hours' => 6,
                'requires_gas_test' => true,
                'requires_isolation' => true,
                'sort_order' => 2,
                'precaution_template' => [
                    ['description' => 'Space isolated, drained, purged and certified gas free', 'is_required' => true],
                    ['description' => 'Continuous atmospheric monitoring in place', 'is_required' => true],
                    ['description' => 'Standby person at the entry point at all times', 'is_required' => true],
                    ['description' => 'Rescue equipment and tripod rigged before entry', 'is_required' => true],
                    ['description' => 'Entry log maintained at the access point', 'is_required' => true],
                    ['description' => 'Communication with the control room tested', 'is_required' => false],
                ],
            ]);

            $this->permitType($md, [
                'name' => 'Working at height',
                'code' => 'WAH',
                'description' => 'Any work above 2 m where a fall could occur, other than from a '
                    .'fully boarded and tagged scaffold.',
                'color' => 'warning',
                'default_duration_hours' => 10,
                'sort_order' => 3,
                'precaution_template' => [
                    ['description' => 'Fall arrest harness inspected and anchor point identified', 'is_required' => true],
                    ['description' => 'Exclusion zone established below the work', 'is_required' => true],
                    ['description' => 'Tools tethered', 'is_required' => true],
                    ['description' => 'Weather checked; work suspended above 25 knots', 'is_required' => false],
                ],
            ]);

            $this->permitType($md, [
                'name' => 'Excavation',
                'code' => 'EXC',
                'description' => 'Breaking ground anywhere inside the terminal fence.',
                'color' => 'warning',
                'default_duration_hours' => 10,
                'requires_isolation' => true,
                'sort_order' => 4,
                'precaution_template' => [
                    ['description' => 'Underground services scanned and marked within the last 24 hours', 'is_required' => true],
                    ['description' => 'Permit to break ground held by the terminal operator', 'is_required' => true],
                    ['description' => 'Sides battered or shored beyond 1.2 m', 'is_required' => true],
                    ['description' => 'Edge protection and access ladder in place', 'is_required' => true],
                ],
            ]);

            // ------------------------------------------------- configuration: training courses
            // `validity_months` is what turns a filing cabinet into a register that tells you
            // something: it is the only reason the platform can say who is out of ticket next month.
            $induction = $this->trainingCourse($md, [
                'name' => 'Meridian site induction',
                'code' => 'IND',
                'description' => 'Company rules, site hazards, emergency arrangements and the '
                    .'stop-work authority every person on a Meridian site holds.',
                'category' => 'Induction',
                'validity_months' => 24,
                'is_mandatory' => true,
                'provider' => 'Meridian HSE department',
                'duration_hours' => 3,
                'sort_order' => 1,
            ]);

            $workingAtHeight = $this->trainingCourse($md, [
                'name' => 'Working at height and harness use',
                'code' => 'WAH',
                'description' => 'Fall protection hierarchy, harness inspection, anchor selection '
                    .'and suspension trauma.',
                'category' => 'Technical',
                'validity_months' => 36,
                'is_mandatory' => false,
                'provider' => 'Delta Safety Training Ltd',
                'duration_hours' => 8,
                'sort_order' => 2,
            ]);

            $confinedSpaceCourse = $this->trainingCourse($md, [
                'name' => 'Confined space entry and rescue',
                'code' => 'CSE',
                'description' => 'Entry procedures, gas testing, standby duties and non-entry rescue.',
                'category' => 'Technical',
                'validity_months' => 24,
                'provider' => 'Delta Safety Training Ltd',
                'duration_hours' => 16,
                'sort_order' => 3,
            ]);

            $firstAid = $this->trainingCourse($md, [
                'name' => 'First aid at work',
                'code' => 'FAW',
                'description' => 'Three-day certificated first aid at work.',
                'category' => 'Statutory',
                'validity_months' => 36,
                'provider' => 'Rivers State Red Cross',
                'duration_hours' => 21,
                'sort_order' => 4,
            ]);

            $this->trainingCourse($md, [
                'name' => 'H2S awareness and escape breathing apparatus',
                'code' => 'H2S',
                'description' => 'Required for anyone entering the terminal process area.',
                'category' => 'Technical',
                'validity_months' => 12,
                'is_mandatory' => true,
                'provider' => 'Riverine Petroleum Development Company',
                'duration_hours' => 4,
                'sort_order' => 5,
            ]);

            // ------------------------------------------------- configuration: the incident route
            // THIS IS THE EXIT CRITERION OF PHASE 4, set up the way a company sets it up: a route,
            // one stage, two automatic actions chosen from a list. The stage's `auto_actions` name
            // record TYPES — `investigation`, `corrective_action` — which the runner resolves
            // through the morph map. No module is named in code anywhere in this block.
            $hseRole = Role::query()->where('slug', 'hse-manager')->value('id');

            $incidentRoute = $this->routes->create([
                'name' => 'Incident review',
                'description' => 'Every reported incident is reviewed by the HSE manager. Reaching '
                    .'that review opens the investigation and raises the first corrective action, '
                    .'so neither depends on somebody remembering.',
                'entity_type' => 'hse_incident',
                'is_default' => true,
            ], $md);

            $this->routes->addStage($incidentRoute, [
                'name' => 'HSE manager review',
                'instructions' => 'Confirm the classification and the potential severity, then '
                    .'approve. The investigation and the first corrective action have already been '
                    .'opened; approving closes the review, not the incident.',
                'sequence' => 1,
                'action_type' => 'approve',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $hseRole,
                'quorum' => 'all',
                'on_reject' => 'return_to_owner',
                'sla_hours' => 24,
                'allow_delegate' => true,
                'allow_self_approval' => false,
                'is_active' => true,
                'auto_actions' => [
                    [
                        'verb' => 'create_record',
                        'record' => 'investigation',
                        'title' => 'Investigation',
                        'due_in_days' => 14,
                        'assignee_type' => 'role',
                        'assignee_ref' => (string) $hseRole,
                    ],
                    [
                        'verb' => 'create_record',
                        'record' => 'corrective_action',
                        'title' => 'Immediate make-safe of the reported condition',
                        'due_in_days' => 7,
                        'assignee_type' => 'user',
                        'assignee_ref' => (string) $siteManager->id,
                    ],
                ],
            ], $md);

            $this->routes->publish($incidentRoute, $md);

            // ------------------------------------------------- risk assessment
            // Two scores per hazard, before and after. A register that records only the residual
            // score cannot show a regulator what the controls are actually for.
            $scaffoldRa = $risks->create([
                'title' => 'Erection and dismantling of access scaffold to T-401 shell',
                'description' => 'Tube and fitting scaffold to 14 m around the new tank shell, '
                    .'erected in lifts as the courses go up.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'assessment_type' => 'Task based',
                'activity' => 'Scaffold erection, alteration and dismantling',
                'location' => 'Delta Terminal — tank farm, T-401',
                'assessed_by' => $hseOfficer->id,
                'assessed_on' => now()->subDays(48)->toDateString(),
                'valid_from' => now()->subDays(45)->toDateString(),
                'valid_to' => now()->addDays(135)->toDateString(),
                'review_frequency_months' => 6,
            ], $hseOfficer);

            $risks->setHazards($scaffoldRa, [
                [
                    'hazard' => 'Fall from height during erection of the second and third lifts',
                    'who_is_at_risk' => 'Scaffolders, banksman',
                    'existing_controls' => 'Advanced guardrail system; harness clipped to the '
                        .'transom above; scaffolders CISRS carded.',
                    'likelihood' => 3, 'severity' => 5,
                    'additional_controls' => 'Second scaffolder to check every harness before the '
                        .'lift starts; no erection above 8 m in wind over 20 knots.',
                    'residual_likelihood' => 2, 'residual_severity' => 5,
                    'responsible_id' => $siteManager->id,
                    'target_date' => now()->subDays(40)->toDateString(),
                ],
                [
                    'hazard' => 'Dropped tubes and fittings onto operatives below',
                    'who_is_at_risk' => 'Anyone at ground level within the tank bund',
                    'existing_controls' => 'Exclusion zone barriered to 4 m; tools tethered; '
                        .'materials raised by gin wheel, never thrown.',
                    'likelihood' => 3, 'severity' => 4,
                    'additional_controls' => 'Debris netting to the outer face from the second lift.',
                    'residual_likelihood' => 2, 'residual_severity' => 3,
                    'responsible_id' => $siteManager->id,
                    'target_date' => now()->subDays(42)->toDateString(),
                ],
                [
                    'hazard' => 'Incomplete scaffold used before it has been inspected and tagged',
                    'who_is_at_risk' => 'Painters, welders, inspectors',
                    'existing_controls' => 'Scafftag system; incomplete scaffolds tagged red and '
                        .'access ladders removed at the end of each shift.',
                    'likelihood' => 3, 'severity' => 4,
                    'additional_controls' => 'Weekly documented scaffold inspection by a competent '
                        .'person, recorded on the HSE inspection register.',
                    'residual_likelihood' => 1, 'residual_severity' => 4,
                    'responsible_id' => $hseOfficer->id,
                    'target_date' => now()->subDays(38)->toDateString(),
                ],
                [
                    'hazard' => 'Contact with the existing product line running through the bund',
                    'who_is_at_risk' => 'Scaffolders, terminal operators',
                    'existing_controls' => 'Line identified and marked; standoff maintained; '
                        .'terminal operator notified before each lift.',
                    'likelihood' => 2, 'severity' => 5,
                    'additional_controls' => 'Physical standoff barrier fixed for the duration.',
                    'residual_likelihood' => 1, 'residual_severity' => 5,
                    'responsible_id' => $siteManager->id,
                    'target_date' => now()->subDays(44)->toDateString(),
                ],
            ], $hseOfficer);

            $risks->review($scaffoldRa, $siteManager);
            $risks->approve($scaffoldRa, $hseManager);

            // ------------------------------------------------- toolbox talk
            // Attendees are NOT all accounts. Most people at a toolbox talk on a construction site
            // work for somebody else, and a register that can only record employees records the
            // wrong half of the workforce.
            $scaffoldTalk = $talks->create([
                'title' => 'Scaffold tagging — never climb a red tag',
                'topic' => 'Scaffold inspection and the Scafftag system',
                'content' => 'What the three tags mean, who is allowed to change one, and what to '
                    ."do if you find a scaffold with no tag at all.\n\n"
                    .'Green: inspected within the last seven days, safe to use. Amber: incomplete, '
                    .'use only with a specific permit and harness. Red: do not climb. Only the '
                    .'scaffold supervisor changes a tag, and the inspection is recorded on the '
                    .'HSE inspection register the same day.',
                'key_points' => "Check the tag before you climb.\n"
                    ."A missing tag is a red tag.\n"
                    ."Report an altered scaffold to the site office immediately.\n"
                    .'Anyone may stop the job. Nobody is questioned for stopping it.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location' => 'Delta Terminal — muster point B',
                'duration_minutes' => 20,
                'presented_by' => $hseOfficer->id,
            ], $hseOfficer);

            $talks->record($scaffoldTalk, [
                ['user_id' => $siteManager->id, 'person_name' => $siteManager->name, 'company' => 'Meridian Engineering & Infrastructure', 'trade' => 'Site management'],
                ['user_id' => $qaInspector->id, 'person_name' => $qaInspector->name, 'company' => 'Meridian Engineering & Infrastructure', 'trade' => 'QA/QC'],
                ['person_name' => 'Sunday Okon', 'company' => 'Harcourt Fabrication Services Ltd', 'trade' => 'Scaffolder'],
                ['person_name' => 'Peter Uduak', 'company' => 'Harcourt Fabrication Services Ltd', 'trade' => 'Scaffolder'],
                ['person_name' => 'Musa Danjuma', 'company' => 'Harcourt Fabrication Services Ltd', 'trade' => 'Banksman'],
                ['person_name' => 'Ayo Fasanya', 'company' => 'Coastline Coatings Ltd', 'trade' => 'Blaster / painter'],
                ['person_name' => 'John Etim', 'company' => 'Coastline Coatings Ltd', 'trade' => 'Blaster / painter'],
                ['person_name' => 'Samuel Okoro', 'company' => 'Meridian Engineering & Infrastructure', 'trade' => 'Pipefitter'],
            ], $hseOfficer, $this->workingDay(9, 7)->toDateTimeString());

            // ------------------------------------------------- weekly site inspection
            // Scored, and only the rows that were actually scored count towards either total, so a
            // not-applicable line cannot quietly improve the percentage.
            $weekly = $inspections->create([
                'title' => 'Weekly site HSE inspection — week 34',
                'description' => 'Routine walk of the tank farm working areas with the site manager.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'inspection_type' => 'Weekly site inspection',
                'location' => 'Delta Terminal — tank farm and laydown',
                'scheduled_for' => now()->subDays(11)->toDateString(),
                'accompanied_by' => 'Kelechi Nwosu (site manager), Sunday Okon (scaffold supervisor)',
            ], $hseOfficer);

            $inspections->setItems($weekly, [
                ['reference' => '1.1', 'description' => 'Scaffolds tagged and inspected within seven days', 'category' => 'Work at height', 'score_possible' => 10],
                ['reference' => '1.2', 'description' => 'Edge protection complete on all open edges', 'category' => 'Work at height', 'score_possible' => 10],
                ['reference' => '2.1', 'description' => 'Excavations shored, barriered and with ladder access', 'category' => 'Excavation', 'score_possible' => 10],
                ['reference' => '3.1', 'description' => 'Hot work permits displayed at the work face', 'category' => 'Permits', 'score_possible' => 10],
                ['reference' => '3.2', 'description' => 'Fire extinguishers in date and unobstructed', 'category' => 'Fire', 'score_possible' => 10],
                ['reference' => '4.1', 'description' => 'Housekeeping — walkways clear of trip hazards', 'category' => 'Housekeeping', 'score_possible' => 10],
                ['reference' => '4.2', 'description' => 'Waste segregated and skips not overfilled', 'category' => 'Environment', 'score_possible' => 10],
                ['reference' => '5.1', 'description' => 'PPE worn correctly, including eye protection in the grinding area', 'category' => 'PPE', 'score_possible' => 10],
                ['reference' => '6.1', 'description' => 'Emergency muster points signed and unobstructed', 'category' => 'Emergency', 'score_possible' => 10],
                ['reference' => '7.1', 'description' => 'Lifting equipment colour coded and certificates on site', 'category' => 'Lifting', 'score_possible' => 10],
            ], $hseOfficer);

            $weekly->load('items');
            $weeklyResults = [];

            foreach ($weekly->items as $index => $item) {
                // Two failures and a borderline, positioned deliberately: the scaffold line is the
                // one the incident below turns on, and an inspection that found nothing would make
                // the incident look like bad luck rather than a missed warning.
                $weeklyResults[$item->getKey()] = match ($index) {
                    0 => ['result' => 'fail', 'severity' => 'Major', 'action_required' => true, 'score_achieved' => 0,
                        'comment' => 'North-east scaffold to T-401 carrying a green tag dated 19 days ago. '
                            .'Tag replaced with red and access ladder removed pending re-inspection.'],
                    5 => ['result' => 'fail', 'severity' => 'Minor', 'action_required' => true, 'score_achieved' => 4,
                        'comment' => 'Welding leads and hoses run across the main walkway between the '
                            .'bund and the laydown. Route them at high level or cover them.'],
                    7 => ['result' => 'observation', 'severity' => 'Minor', 'action_required' => false, 'score_achieved' => 7,
                        'comment' => 'Two operatives in the grinding area with visors lifted. Corrected '
                            .'on the spot and covered in the toolbox talk.'],
                    default => ['result' => 'pass', 'score_achieved' => 10],
                };
            }

            $inspections->perform($weekly, $weeklyResults, $hseOfficer,
                'Site generally in good order. Two failures, both raised as corrective actions. The '
                    .'scaffold tagging failure is the second in six weeks and is now on the HSE '
                    .'manager report.');

            $tagAction = $actions->openFrom($weekly, [
                'title' => 'Re-establish the seven-day scaffold inspection routine',
                'description' => 'Scaffold inspections are being missed. Assign a named competent '
                    .'person for each scaffold, put the inspection in the weekly plan, and check '
                    .'every tag on site against its date before the next shift starts.',
                'action_type' => 'Corrective',
                'priority' => 'High',
                'due_in_days' => 5,
            ], $siteManager->id, $hseOfficer);

            $actions->openFrom($weekly, [
                'title' => 'Route welding leads and hoses clear of the main walkway',
                'action_type' => 'Corrective',
                'priority' => 'Medium',
                'due_in_days' => 3,
            ], $siteManager->id, $hseOfficer);

            $actions->complete($tagAction, $siteManager,
                'Sunday Okon named as the competent person for all scaffolds on the job. Every tag '
                    .'on site checked and four re-inspected. Inspection now a standing item on the '
                    .'Monday plan.');

            // ------------------------------------------------- observations
            $observations->create([
                'title' => 'Operative crossing the bund wall instead of using the steps',
                'description' => 'Two operatives seen climbing the bund wall at the south corner to '
                    .'save the walk to the steps. Spoken to at the time; both accepted it.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'observation_type' => 'Unsafe act',
                'category' => 'Behaviour',
                'risk_level' => 'Medium',
                'location' => 'Delta Terminal — T-401 bund, south corner',
                'observed_at' => $this->workingDay(14, 10)->toDateTimeString(),
                'observer_name' => 'Emeka Duru',
                'immediate_action' => 'Stopped and explained. Bund steps signed and a second set of '
                    .'steps requested at the south corner.',
                'action_required' => false,
            ], $hseOfficer);

            $looseGrating = $observations->create([
                'title' => 'Unsecured grating panel on the pump house access platform',
                'description' => 'One grating panel lifting at the fixings on the east side of the '
                    .'platform. It moves underfoot. Barriered off pending repair.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'observation_type' => 'Unsafe condition',
                'category' => 'Access',
                'risk_level' => 'High',
                'location' => 'Delta Terminal — pump house access platform',
                'observed_at' => $this->workingDay(4, 15)->toDateTimeString(),
                'observer_name' => 'Ruth Okafor',
                'immediate_action' => 'Panel barriered and the platform closed to non-essential access.',
                'action_required' => true,
            ], $qaInspector);

            $actions->openFrom($looseGrating, [
                'title' => 'Refix the grating panel and check every fixing on the platform',
                'action_type' => 'Corrective',
                'priority' => 'High',
                'due_in_days' => 2,
            ], $siteManager->id, $hseManager);

            // An anonymous one. The observer's name is deliberately absent rather than blanked on
            // the screen: a reporting channel people believe is anonymous is the only kind that
            // gets used, and a nullable column with `is_anonymous` beside it is the honest schema.
            $observations->create([
                'title' => 'Pressure to work through the gas test on the night shift',
                'description' => 'Reported through the anonymous channel: a supervisor is said to '
                    .'have told a crew to start cutting while the gas tester was still walking over. '
                    .'No names given.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'observation_type' => 'Unsafe act',
                'category' => 'Supervision',
                'risk_level' => 'High',
                'location' => 'Delta Terminal — night shift',
                'observed_at' => $this->workingDay(7, 21)->toDateTimeString(),
                'is_anonymous' => true,
                'action_required' => true,
                'confidentiality' => 'restricted',
            ], $hseManager);

            // ------------------------------------------------- a near miss, closed out
            // No route is started on this one. Submitting for review is a decision somebody makes,
            // not something reporting does to you, and a register where every near miss drags a
            // manager review behind it is a register that stops receiving near misses.
            $dropped = $incidents->create([
                'title' => 'Scaffold board fell two lifts into an empty exclusion zone',
                'description' => 'A board being passed up to the third lift was not taken by the '
                    .'receiving scaffolder and fell approximately 6 m. It landed inside the barriered '
                    .'exclusion zone. Nobody was below.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'category' => 'near_miss',
                'severity' => 'minor',
                'potential_severity' => 'fatality',
                'location' => 'Delta Terminal — T-401, north face',
                'occurred_at' => $this->workingDay(21, 11)->toDateTimeString(),
                'immediate_actions' => 'Work stopped. Exclusion zone widened to 6 m and a second '
                    .'banksman posted for the remaining lifts. Gin wheel used for all boards after.',
            ], $hseOfficer);

            // POTENTIAL severity is `fatality` while actual severity is `minor`, and that gap is the
            // entire value of a near miss. A register that ranked this by what happened would file
            // the loudest warning the job ever got at the bottom of the list.
            $incidents->close($dropped, $hseManager);

            // ------------------------------------------------- the incident that matters
            $fall = $incidents->create([
                'title' => 'Fall from scaffold during dismantling — fractured wrist',
                'description' => 'While dismantling the second lift on the north-east face of T-401, '
                    .'a scaffolder stepped onto a section from which the boards had already been '
                    .'removed and fell approximately 2.4 m to the bund floor, landing on his '
                    .'outstretched right hand. He was wearing a harness; it was not clipped at the '
                    .'time of the fall.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['HSE']->id,
                'category' => 'lost_time_injury',
                'severity' => 'major',
                'potential_severity' => 'fatality',
                'location' => 'Delta Terminal — T-401, north-east face, bund floor',
                'occurred_at' => $this->workingDay(6, 14)->toDateTimeString(),
                'immediate_actions' => 'First aid given on site by the site nurse. Taken to Port '
                    .'Harcourt General by company vehicle. All scaffold dismantling on the terminal '
                    .'stopped by the HSE officer under stop-work authority and remains stopped.',
                'is_lost_time' => true,
                'lost_time_days' => 12,
            ], $hseOfficer);

            // The medical detail goes on the person, not on the incident, and only through the one
            // method that accepts those columns (§68). Everybody who can see the incident can see
            // that somebody was hurt; a much smaller group can see what happened to him.
            $incidents->addPerson($fall, [
                'person_name' => 'Peter Uduak',
                'company' => 'Harcourt Fabrication Services Ltd',
                'occupation' => 'Scaffolder',
                'involvement' => 'injured',
                'injury_type' => 'Fracture',
                'body_part' => 'Right wrist',
                'treatment' => 'hospital',
                'days_lost' => 12,
                'returned_to_work_on' => now()->addDays(6)->toDateString(),
                'notes' => 'Distal radius fracture, cast applied, no surgery. Fit for light duties '
                    .'from the return date, no work at height for eight weeks.',
            ], $hseOfficer);

            $incidents->addPerson($fall, [
                'person_name' => 'Sunday Okon',
                'company' => 'Harcourt Fabrication Services Ltd',
                'occupation' => 'Scaffold supervisor',
                'involvement' => 'witness',
                'notes' => 'Was at ground level and saw the fall. Gave a statement the same afternoon.',
            ], $hseOfficer);

            $incidents->markReportable($fall, $hseManager,
                now()->subDays(5)->toDateString(), 'FMoL/RS/2026/0871');

            // ---------------------------------------------------------------------------------
            // THE EXIT CRITERION, IN DEMO DATA.
            //
            // One line. It starts the route configured above, the review stage opens, and the
            // stage's auto-actions run: an investigation is opened against this incident with the
            // HSE manager as lead and fourteen days to run, and a corrective action is raised on
            // the site manager with seven. Neither is created anywhere in this file — read on and
            // you will find them fetched back out of the database, never constructed.
            // ---------------------------------------------------------------------------------
            $this->approvals->start($fall, $hseOfficer);

            $this->decide($fall, $hseManager, Decision::Approve,
                'Classification agreed. Reportable, and reported. Dismantling stays stopped across '
                    .'the terminal until the investigation has an immediate cause and the method '
                    .'statement has been rewritten.');

            // ------------------------------------------------- what the route opened
            $investigation = $fall->investigation()->first();

            if ($investigation === null) {
                // Loud, and deliberately so. If this ever fires, the platform's headline claim —
                // that a company can configure a consequence without a developer — has quietly
                // stopped being true, and a demo that silently seeded a thinner story would hide it.
                throw new \RuntimeException(
                    'The incident route did not open an investigation. The exit criterion of '
                    .'phase 4 is not being met and the demo data would misrepresent the platform.'
                );
            }

            $investigations->update($investigation, [
                'method' => '5 Whys',
                'started_on' => now()->subDays(5)->toDateString(),
                'sequence_of_events' => "07:10 — Toolbox talk held; scaffold tagging covered.\n"
                    ."13:20 — Dismantling of the second lift begins, three scaffolders, one banksman.\n"
                    ."14:05 — Boards removed from the north-east bay and passed down.\n"
                    .'14:12 — The injured party steps from the ledger onto the bay the boards had '
                    ."been removed from, believing it still boarded, and falls to the bund floor.\n"
                    ."14:14 — Fall witnessed by the supervisor at ground level; first aid called.\n"
                    .'14:40 — Site nurse hands over; casualty taken to hospital.',
                'immediate_cause' => 'He stepped onto an unboarded bay while unclipped. The section '
                    .'had been stripped by a second pair working from the far end, and there was '
                    .'nothing at the boundary to show where the boarded deck stopped.',
                'root_cause' => 'The dismantling sequence was never agreed between the two pairs '
                    .'working on the same lift. The method statement covers erection in detail and '
                    .'treats dismantling as its reverse, so it specifies no sequence, no boundary '
                    .'marking, and no clipping regime for a deck that is being progressively removed. '
                    .'The crew were left to arrange it between themselves each shift.',
                'contributing_factors' => 'Scaffold inspection had lapsed to 19 days, so the last '
                    ."formal look at the structure predated the dismantling entirely.\n"
                    .'Harness use during dismantling was custom rather than instruction: clipping to '
                    ."a structure you are removing has no obvious anchor, and nobody had been given one.\n"
                    .'Programme pressure to release the bund for the coating crew.',
                'recommendations' => 'Rewrite the scaffold method statement to cover dismantling as '
                    .'its own operation, with a named sequence, a marked boundary at the edge of the '
                    ."remaining deck and a nominated anchor for each stage.\n"
                    ."Reinstate the seven-day inspection with a named competent person.\n"
                    .'Brief every scaffolder on the terminal before dismantling restarts.',
            ], $hseManager);

            $investigations->complete($investigation, $hseManager,
                'The fall was caused by an unmarked and unboarded bay on a deck being dismantled by '
                    .'two pairs working without an agreed sequence. The harness was worn and not '
                    .'clipped; no anchor had been specified for dismantling. The method statement is '
                    .'silent on all of it.');

            // The corrective action the route raised. Fetched, not made.
            $makeSafe = $fall->correctiveActions()->orderBy('id')->first();

            if ($makeSafe === null) {
                throw new \RuntimeException(
                    'The incident route did not raise a corrective action, so the phase 4 exit '
                    .'criterion is only half met.'
                );
            }

            $actions->complete($makeSafe, $siteManager,
                'All scaffold dismantling on the terminal stopped the same afternoon. The T-401 '
                    .'scaffold has been re-boarded to a safe configuration, tagged red, and the '
                    .'access ladders removed. Nothing will be dismantled until the rewritten method '
                    .'statement is briefed.');

            // Verified by somebody who neither carried it out nor was assigned it — the service
            // refuses anything else, and so does the policy.
            $actions->verify($makeSafe, $hseManager, 'effective',
                'Walked the scaffold with the supervisor. Configuration is safe, tag is red, ladders '
                    .'are off. Confirmed with the subcontractor that no dismantling is planned.');

            // And the one the investigation asked for, which is still running.
            $actions->openFrom($investigation, [
                'title' => 'Rewrite the scaffold method statement to cover dismantling',
                'description' => 'Dismantling to be written as its own operation: agreed sequence, '
                    .'a marked boundary at the edge of the remaining deck, a nominated anchor point '
                    .'for each stage, and a hold point before any lift is stripped. Brief every '
                    .'scaffolder on the terminal before work restarts.',
                'action_type' => 'Corrective',
                'priority' => 'Critical',
                'due_in_days' => 9,
            ], $hseOfficer->id, $hseManager);

            $actions->openFrom($investigation, [
                'title' => 'Extend the seven-day scaffold inspection to every subcontracted scaffold',
                'action_type' => 'Preventive',
                'priority' => 'High',
                'due_in_days' => 21,
            ], $siteManager->id, $hseManager);

            // The incident stays OPEN. Its investigation is complete and it could be closed today,
            // and the reason it is not is that two actions are still running — which is exactly the
            // state an HSE manager's dashboard exists to show.

            // ------------------------------------------------- permits to work
            // THREE PEOPLE, THREE ROLES, AND THE SERVICE ENFORCES ALL OF IT: the supervisor who
            // wants the work requests it, the issuing authority issues it, and the supervisor
            // accepts it back. A permit issued by the person who asked for it is not a permit, it
            // is a note to self, and PermitService refuses to write one.
            $hotWorkPermit = $permits->create([
                'permit_type_id' => $hotWork->id,
                'title' => 'Welding of nozzle N4 reinforcing pad, T-401',
                'description' => 'Manual metal arc welding of the reinforcing pad to the shell at '
                    .'N4, including grinding preparation.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location' => 'Delta Terminal — T-401, east shell, elevation +3.2 m',
                'equipment' => 'Welding set, angle grinder, welding screens',
                'worker_count' => 4,
                'valid_from' => $this->workingDay(2, 7)->toDateTimeString(),
            ], $siteManager);

            foreach ($hotWorkPermit->precautions()->orderBy('sort_order')->get() as $precaution) {
                if (! $precaution->is_required) {
                    continue;
                }

                $permits->confirmPrecaution($precaution, $siteManager);
            }

            $permits->recordGasTest($hotWorkPermit, $hseOfficer, 'LEL 0%, O2 20.9%, H2S 0 ppm at the work face and 3 m radius');
            $permits->issue($hotWorkPermit, $hseOfficer);
            $permits->accept($hotWorkPermit, $siteManager);
            $permits->close($hotWorkPermit, $siteManager,
                'Welding complete, area cold, fire watch held for 45 minutes after the last arc. '
                    .'Screens and leads removed.');

            // Live now, and suspended. A suspension is not a cancellation: the work is expected to
            // resume under the same permit once the reason has gone away, and the register has to
            // be able to say what the reason was.
            $entryPermit = $permits->create([
                'permit_type_id' => $confinedSpace->id,
                'title' => 'Internal inspection and weld cleaning, T-402 shell',
                'description' => 'Entry to the existing tank for internal weld cleaning and the '
                    .'pre-hydrotest inspection.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location' => 'Delta Terminal — T-402, manway M1',
                'equipment' => 'Tripod and winch, forced ventilation, 24 V lighting, 4-gas monitor',
                'worker_count' => 3,
                'valid_from' => now()->subHours(4)->toDateTimeString(),
                'isolation_reference' => 'ISO-DTE-2026-0114',
            ], $siteManager);

            foreach ($entryPermit->precautions()->orderBy('sort_order')->get() as $precaution) {
                if (! $precaution->is_required) {
                    continue;
                }

                $permits->confirmPrecaution($precaution, $siteManager);
            }

            $permits->recordGasTest($entryPermit, $hseOfficer, 'LEL 0%, O2 20.8%, H2S 0 ppm, CO 0 ppm at three depths');
            $permits->issue($entryPermit, $hseOfficer);
            $permits->accept($entryPermit, $siteManager);
            $permits->suspend($entryPermit, $hseOfficer,
                'Ventilation fan tripped and the atmosphere was not being monitored continuously '
                    .'for approximately four minutes. Space evacuated, entry log closed out. Not to '
                    .'resume until the fan is on a dedicated supply and a fresh gas test is run.');

            // Requested and waiting. Somebody has to issue it, and until they do the work does not
            // start — which is the whole point of the register having a status at all.
            $permits->create([
                'permit_type_id' => $confinedSpace->id,
                'title' => 'Excavation entry for the tie-in pit at chainage 0+180',
                'description' => 'Entry to the shored tie-in pit for the hot tap connection.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location' => 'Delta Terminal — pipe rack corridor, chainage 0+180',
                'equipment' => 'Ladder access, 4-gas monitor, tripod',
                'worker_count' => 2,
                'valid_from' => now()->addDay()->setTime(7, 0)->toDateTimeString(),
            ], $siteManager);

            // ------------------------------------------------- training records
            // The register earns its keep on the expiry column: everything below is the same shape,
            // and the only thing that distinguishes a compliant workforce from an exposed one is
            // which side of today `expires_on` falls on.
            $training->create([
                'training_course_id' => $induction->id,
                'user_id' => $siteManager->id,
                'project_id' => $dte->id,
                'completed_on' => now()->subMonths(9)->toDateString(),
                'certificate_number' => 'IND-2025-0412',
                'result' => 'pass',
                'provider' => 'Meridian HSE department',
                'trainer_name' => 'Emeka Duru',
            ], $hseOfficer);

            $training->create([
                'training_course_id' => $induction->id,
                'user_id' => $qaInspector->id,
                'project_id' => $dte->id,
                'completed_on' => now()->subMonths(7)->toDateString(),
                'certificate_number' => 'IND-2025-0468',
                'result' => 'pass',
                'provider' => 'Meridian HSE department',
                'trainer_name' => 'Emeka Duru',
            ], $hseOfficer);

            $training->create([
                'training_course_id' => $firstAid->id,
                'user_id' => $hseOfficer->id,
                'org_unit_id' => $unit['HSE']->id,
                'completed_on' => now()->subMonths(14)->toDateString(),
                'certificate_number' => 'FAW/PH/24/1180',
                'result' => 'pass',
                'score' => 88,
                'provider' => 'Rivers State Red Cross',
                'cost' => 145000,
            ], $hseManager);

            // Expires in five weeks. This is the row the register exists to surface, while there is
            // still time to book the refresher.
            $training->create([
                'training_course_id' => $workingAtHeight->id,
                'person_name' => 'Sunday Okon',
                'company' => 'Harcourt Fabrication Services Ltd',
                'project_id' => $dte->id,
                'completed_on' => now()->subMonths(36)->addDays(35)->toDateString(),
                'certificate_number' => 'DST/WAH/23/0904',
                'result' => 'pass',
                'provider' => 'Delta Safety Training Ltd',
                'notes' => 'Subcontractor. Certificate copy held in the site office file.',
            ], $hseOfficer);

            // Already expired, and the man is on site. A register that could not show this would be
            // worse than no register, because it would be quietly reassuring.
            $training->create([
                'training_course_id' => $confinedSpaceCourse->id,
                'person_name' => 'Musa Danjuma',
                'company' => 'Harcourt Fabrication Services Ltd',
                'project_id' => $dte->id,
                'completed_on' => now()->subMonths(26)->toDateString(),
                'certificate_number' => 'DST/CSE/24/0221',
                'result' => 'pass',
                'provider' => 'Delta Safety Training Ltd',
                'notes' => 'EXPIRED. Flagged to the subcontractor; not to stand by on any entry '
                    .'until the refresher is done.',
            ], $hseOfficer);

            // Renewed rather than edited: the old certificate stays exactly as it was, because it
            // was true for the period it covered.
            $expiringInduction = $training->create([
                'training_course_id' => $induction->id,
                'user_id' => $pipingEngineer->id,
                'org_unit_id' => $unit['ENG-MP-PD']->id,
                'completed_on' => now()->subMonths(25)->toDateString(),
                'certificate_number' => 'IND-2024-0207',
                'result' => 'pass',
                'provider' => 'Meridian HSE department',
            ], $hseOfficer);

            $training->renew($expiringInduction, [
                'completed_on' => now()->subDays(20)->toDateString(),
                'certificate_number' => 'IND-2026-0913',
                'result' => 'pass',
                'trainer_name' => 'Emeka Duru',
            ], $hseOfficer);
        });
    }

    /**
     * What Meridian is DOING ABOUT QUALITY (§54–§58) — inspection requests, and what happens when
     * one of them fails.
     *
     * THE TWO REGISTERS ARE ONE STORY. An inspection request is raised against an ITP line, somebody
     * other than the requester attends it, and the verdict is recorded against each checkpoint. When
     * the verdict is a rejection, the non-conformance that follows carries the inspection with it,
     * so the NCR does not have to re-describe what was found — it points at the record that found
     * it. Every state of that chain appears below exactly once, including the two that flatter
     * nobody: an NCR waiting on a response that is already late, and a repair disposition that had
     * to justify itself in writing before the service would accept it.
     */
    private function seedMeridianQuality(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $inspections = app(QualityInspectionService::class);
            $ncrs = app(NcrService::class);
            $actions = app(CorrectiveActionService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $engineeringManager = $this->accountOf($staff, 'MEI-0004'); // Farida Yusuf
            $projectManager = $this->accountOf($staff, 'MEI-0011');     // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');        // Kelechi Nwosu
            $qaManager = $this->accountOf($staff, 'MEI-0015');          // Yemi Bakare
            $qaInspector = $this->accountOf($staff, 'MEI-0016');        // Ruth Okafor

            $dte = $job['DTE'];

            // ------------------------------------------------- a hold point, passed
            // A HOLD POINT IS THE ONE THE CLIENT MUST ATTEND, and the work does not proceed past it
            // whether they turn up or not. The distinction between hold, witness and surveillance is
            // the whole of an ITP, and it is a plain string here because a company writes its own.
            $foundationPour = $inspections->create([
                'title' => 'Pre-pour inspection — T-401 ring beam, segment 3',
                'description' => 'Reinforcement, formwork, cover and embedment check before the '
                    .'third segment pour.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'discipline_id' => $unit['ENG-CS']->id,
                'inspection_type' => 'Hold point',
                'itp_reference' => 'ITP-DTE-CIV-001 line 14',
                'activity' => 'Reinforced concrete ring beam, segment 3 of 6',
                'location' => 'Delta Terminal — tank farm, T-401 ring beam',
                'scheduled_for' => $this->workingDay(24, 8)->toDateTimeString(),
                'required_by' => now()->subDays(24)->toDateString(),
            ], $siteManager);

            $inspections->setItems($foundationPour, [
                ['reference' => '1', 'description' => 'Bar size, spacing and lap lengths to drawing DTE-CS-DWG-1001 rev 0', 'acceptance_criteria' => 'As drawing; laps not less than 45 diameters'],
                ['reference' => '2', 'description' => 'Concrete cover to outer face', 'acceptance_criteria' => '50 mm minus 0 plus 10 mm'],
                ['reference' => '3', 'description' => 'Formwork line, level and tie condition', 'acceptance_criteria' => 'Plus or minus 10 mm on line, plus or minus 5 mm on level'],
                ['reference' => '4', 'description' => 'Anchor bolt template position and projection', 'acceptance_criteria' => 'Plus or minus 3 mm on centres'],
                ['reference' => '5', 'description' => 'Formwork clean, free of debris and standing water', 'acceptance_criteria' => 'Visually clean'],
            ], $siteManager);

            $foundationPour->load('items');
            $pourResults = [];

            foreach ($foundationPour->items as $index => $item) {
                $pourResults[$item->getKey()] = match ($index) {
                    1 => ['result' => 'pass', 'measured_value' => '52 mm to 58 mm over 12 readings'],
                    3 => ['result' => 'pass', 'measured_value' => 'Maximum 2 mm deviation on centres'],
                    default => ['result' => 'pass'],
                };
            }

            $inspections->inspect($foundationPour, 'passed', $qaInspector, $pourResults);

            // ------------------------------------------------- passed, with something to say
            $weldVisual = $inspections->create([
                'title' => 'Visual and NDT review — spool 1204-07 shop welds',
                'description' => 'Final visual inspection and review of the radiographic film for '
                    .'the six shop welds on spool 1204-07.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'discipline_id' => $unit['ENG-MP']->id,
                'inspection_type' => 'Witness point',
                'itp_reference' => 'ITP-DTE-PIP-003 line 9',
                'activity' => 'Shop fabrication of spool 1204-07',
                'location' => 'Harcourt Fabrication Services — shop 2',
                'scheduled_for' => $this->workingDay(17, 10)->toDateTimeString(),
                'required_by' => now()->subDays(16)->toDateString(),
                'witnessed_by' => 'B. Achike (Harcourt Fabrication QC), O. Lawal (client inspector)',
            ], $qaManager);

            $inspections->setItems($weldVisual, [
                ['reference' => 'W1', 'description' => 'Weld 1204-07-01 visual', 'acceptance_criteria' => 'ASME B31.3 normal fluid service'],
                ['reference' => 'W2', 'description' => 'Weld 1204-07-02 visual', 'acceptance_criteria' => 'ASME B31.3 normal fluid service'],
                ['reference' => 'W3', 'description' => 'Weld 1204-07-03 visual and RT', 'acceptance_criteria' => 'ASME B31.3 normal fluid service'],
                ['reference' => 'W4', 'description' => 'Weld 1204-07-04 visual and RT', 'acceptance_criteria' => 'ASME B31.3 normal fluid service'],
                ['reference' => 'D1', 'description' => 'Dimensional check against isometric', 'acceptance_criteria' => 'Plus or minus 3 mm on face-to-face'],
                ['reference' => 'M1', 'description' => 'Material traceability — heat numbers against certificates', 'acceptance_criteria' => 'All heats traceable to EN 10204 3.1'],
            ], $qaManager);

            $weldVisual->load('items');
            $weldResults = [];

            foreach ($weldVisual->items as $index => $item) {
                $weldResults[$item->getKey()] = match ($index) {
                    2 => ['result' => 'pass', 'comment' => 'Film RT-1204-07-03 reviewed. Two isolated pores, both within acceptance.'],
                    4 => ['result' => 'pass', 'measured_value' => 'Face to face 2 mm short of nominal'],
                    5 => ['result' => 'observation', 'comment' => 'Heat number for the 6 in elbow is legible on the certificate but the hard stamp on the fitting is painted over. Re-marked in the presence of the inspector.'],
                    default => ['result' => 'pass'],
                };
            }

            $inspections->inspect($weldVisual, 'passed_with_comment', $qaInspector, $weldResults,
                'Welds accepted. The spool is released for coating. One traceability marking was '
                    .'obscured by primer and has been re-marked; the fabricator is to mask hard '
                    .'stamps before priming on the remaining spools.');

            // ------------------------------------------------- the one that failed
            $coating = $inspections->create([
                'title' => 'Coating inspection — T-401 shell, courses 1 and 2 external',
                'description' => 'Dry film thickness, adhesion and holiday detection on the '
                    .'completed external coating to the lower two courses.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'inspection_type' => 'Inspection point',
                'itp_reference' => 'ITP-DTE-COAT-002 line 6',
                'activity' => 'External coating system, three coat, to T-401 shell',
                'location' => 'Delta Terminal — T-401, courses 1 and 2',
                'scheduled_for' => $this->workingDay(13, 9)->toDateTimeString(),
                'required_by' => now()->subDays(12)->toDateString(),
                'witnessed_by' => 'A. Fasanya (Coastline Coatings)',
            ], $qaManager);

            $inspections->setItems($coating, [
                ['reference' => 'C1', 'description' => 'Surface preparation record and ambient conditions at application', 'acceptance_criteria' => 'Sa 2.5; RH below 85%; steel 3 C above dew point'],
                ['reference' => 'C2', 'description' => 'Dry film thickness, 80/20 rule over 40 readings', 'acceptance_criteria' => 'Nominal 320 microns; no reading below 256 microns'],
                ['reference' => 'C3', 'description' => 'Adhesion, cross hatch to ISO 2409', 'acceptance_criteria' => 'Classification 1 or better'],
                ['reference' => 'C4', 'description' => 'Holiday detection at 5 kV', 'acceptance_criteria' => 'No holidays'],
                ['reference' => 'C5', 'description' => 'Appearance, runs, sags and overspray', 'acceptance_criteria' => 'Uniform, free of defects'],
            ], $qaManager);

            $coating->load('items');
            $coatingResults = [];

            foreach ($coating->items as $index => $item) {
                $coatingResults[$item->getKey()] = match ($index) {
                    0 => ['result' => 'pass', 'measured_value' => 'RH 71%, steel 29.4 C, dew point 24.1 C'],
                    1 => ['result' => 'fail', 'measured_value' => '11 of 40 readings between 180 and 240 microns',
                        'comment' => 'Low readings clustered on the north face between the stiffener '
                            .'and the second course weld seam. Nominal achieved elsewhere.'],
                    3 => ['result' => 'fail', 'measured_value' => '3 holidays over 4 m of seam',
                        'comment' => 'All three on the same seam as the low DFT readings.'],
                    default => ['result' => 'pass'],
                };
            }

            $inspections->inspect($coating, 'rejected', $qaInspector, $coatingResults,
                'Rejected on dry film thickness and holiday detection over approximately 8 square '
                    .'metres of the north face. Everything else is acceptable. NCR raised.');

            // ------------------------------------------------- the NCR that came out of it
            // The inspection is CARRIED, not copied. An NCR that restated the findings would drift
            // from them the first time somebody corrected a reading.
            $coatingNcr = $ncrs->create([
                'title' => 'Coating thickness and holidays, T-401 north face, courses 1 and 2',
                'description' => 'Eleven of forty DFT readings below the specified minimum and three '
                    .'holidays detected at 5 kV, all in an area of approximately 8 square metres on '
                    .'the north face adjacent to the course 2 seam.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'quality_inspection_id' => $coating->id,
                'source' => 'Inspection',
                'severity' => 'Major',
                'category' => 'Coating',
                'location' => 'Delta Terminal — T-401, north face, courses 1 and 2',
                'requirement' => 'Specification DTE-SPC-COAT-001 clause 7.4: three coat system, '
                    .'nominal 320 microns dry, no individual reading below 80% of nominal, and '
                    .'clause 8.2: holiday detection at 5 kV with no holidays permitted.',
                'party_name' => 'Coastline Coatings Ltd',
                'required_by' => now()->subDays(2)->toDateString(),
            ], $qaInspector);

            $ncrs->issue($coatingNcr, $qaInspector);

            $ncrs->respond($coatingNcr, $qaManager,
                'Coastline Coatings response, received by email 4 days after issue: the affected '
                    .'area was coated in the last hour of a shift by an operative who has since been '
                    .'removed from the job. They propose to blast back the 8 square metre area to '
                    .'bare steel, feather the edges, and reapply the full three coat system with '
                    .'inspection at each coat. No charge to the project.');

            // A `repair` disposition is one of the three the service refuses without a written
            // justification, and it refuses it from the person who raised the NCR as well.
            $ncrs->disposition($coatingNcr, $engineeringManager, 'repair',
                'Accepted. Blast back to bare steel over the full affected area plus 100 mm, '
                    .'feathered edges, three coat system reapplied with hold points at primer and '
                    .'topcoat. Adhesion and holiday testing to be repeated over the repaired area '
                    .'and over a 2 m band either side. The repair is confined to the north face and '
                    .'does not affect the coating warranty on the remainder of the shell.');

            $actions->openFrom($coatingNcr, [
                'title' => 'Stop coating application in the last hour of shift without a second check',
                'description' => 'The subcontractor is to hold a check by their own QC on any coat '
                    .'applied after 16:00, and Meridian will witness the first four.',
                'action_type' => 'Preventive',
                'priority' => 'Medium',
                'due_in_days' => 12,
            ], $qaManager->id, $engineeringManager);

            $ncrs->verify($coatingNcr, $qaManager,
                'Repair inspected 6 days after disposition. DFT over 40 fresh readings on the '
                    .'repaired area: minimum 302 microns, mean 331. Holiday detection clear over the '
                    .'repair and the 2 m band either side. Adhesion classification 1.');

            $ncrs->close($coatingNcr, $qaManager,
                'Closed. No cost to the project; the subcontractor bore the repair.');

            // ------------------------------------------------- and one still running, and late
            // Nothing on this record is unusual, which is the point: the register has to be able to
            // show an open NCR whose response date has passed, or a project reads as healthier than
            // it is.
            $anchorBolts = $ncrs->create([
                'title' => 'Anchor bolt centres out of tolerance, pump P-201 plinth',
                'description' => 'Anchor bolt centres on the P-201 plinth measured 12 mm out over '
                    .'the 640 mm span against a plus or minus 3 mm tolerance. The pump baseplate '
                    .'will not sit without reaming the holes, which the pump vendor does not permit.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'source' => 'Self identified',
                'severity' => 'Major',
                'category' => 'Dimensional',
                'location' => 'Delta Terminal — pump house, P-201 plinth',
                'requirement' => 'Drawing DTE-CS-DWG-1002 rev 0 and vendor drawing HPX-4400-GA: '
                    .'anchor bolt centres plus or minus 3 mm.',
                'party_name' => 'Meridian Construction — Delta Terminal site team',
                'detected_by' => $qaInspector->id,
                'detected_on' => now()->subDays(9)->toDateString(),
                'required_by' => now()->subDays(2)->toDateString(),
                'cost_impact' => 1450000,
            ], $qaInspector);

            $ncrs->issue($anchorBolts, $qaManager);

            // ------------------------------------------------- and one still to happen
            $upcoming = $inspections->create([
                'title' => 'Hydrotest — T-401, witness point',
                'description' => 'Fill, hold and inspect. Client and third party to attend.',
                'project_id' => $dte->id,
                'org_unit_id' => $unit['QA']->id,
                'inspection_type' => 'Hold point',
                'itp_reference' => 'ITP-DTE-MEC-004 line 22',
                'activity' => 'Hydrostatic test of T-401 to 1.25 times design',
                'location' => 'Delta Terminal — T-401',
                'scheduled_for' => now()->addDays(9)->setTime(7, 0)->toDateTimeString(),
                'required_by' => now()->addDays(11)->toDateString(),
                'witnessed_by' => 'Client representative and third party inspector, to be confirmed',
            ], $projectManager);

            $inspections->setItems($upcoming, [
                ['reference' => 'H1', 'description' => 'Test water quality — chlorides', 'acceptance_criteria' => 'Below 50 ppm chlorides'],
                ['reference' => 'H2', 'description' => 'Fill rate and hold period', 'acceptance_criteria' => 'Fill in stages with a 24 hour hold at full'],
                ['reference' => 'H3', 'description' => 'Shell and bottom weld seams during hold', 'acceptance_criteria' => 'No leakage, no visible distortion'],
                ['reference' => 'H4', 'description' => 'Foundation settlement readings at eight stations', 'acceptance_criteria' => 'Differential settlement within API 653 limits'],
            ], $projectManager);
        });
    }

    /**
     * Kestrel's safety file — the same modules, at a tenth of the size, and CONFIGURED DIFFERENTLY.
     *
     * The contrast with Meridian is the entire reason this method exists. An eight-person facilities
     * company has one permit type, not four; two courses, not five; and — this is the important one
     * — NO APPROVAL ROUTE FOR INCIDENTS AT ALL. Nothing here configures one, so nothing spawns: the
     * near miss below is reported, looked at by the compliance manager, and closed by hand. That is
     * a legitimate way to run a small company, and a platform that forced Meridian's arrangement
     * onto it would be the kind of system §73 exists to rule out.
     *
     * The same seeder code produces both, because the difference lives in configuration rather than
     * in a branch.
     */
    private function seedKestrelHse(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $incidents = app(IncidentService::class);
            $actions = app(CorrectiveActionService::class);
            $observations = app(HseObservationService::class);
            $risks = app(RiskAssessmentService::class);
            $talks = app(ToolboxTalkService::class);
            $permits = app(PermitService::class);
            $training = app(TrainingRecordService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder
            $maintenanceHead = $this->accountOf($staff, 'KFM-003'); // Duncan Reilly
            $complianceHead = $this->accountOf($staff, 'KFM-004');  // Nadia Osei
            $mechanical = $this->accountOf($staff, 'KFM-005');      // Callum Whitfield
            $electrical = $this->accountOf($staff, 'KFM-006');      // Erin Doyle

            $ahu = $job['TPA'];

            // One type. Kestrel does not enter confined spaces and does not break ground, and a
            // configuration screen that made them carry those anyway would be answered the way
            // every unused field is answered: by ticking through it.
            $isolation = $this->permitType($md, [
                'name' => 'Electrical isolation',
                'code' => 'ISO',
                'description' => 'Isolation, locking off and proving dead before any work on or '
                    .'near live equipment in a client building.',
                'color' => 'warning',
                'default_duration_hours' => 8,
                'requires_isolation' => true,
                'sort_order' => 1,
                'precaution_template' => [
                    ['description' => 'Point of isolation identified and agreed with the building manager', 'is_required' => true],
                    ['description' => 'Locked off, personal lock applied, key retained by the isolator', 'is_required' => true],
                    ['description' => 'Proved dead at the point of work with a proving unit checked before and after', 'is_required' => true],
                    ['description' => 'Caution notices posted and affected occupants informed', 'is_required' => true],
                    ['description' => 'Alternative supply to life safety systems confirmed', 'is_required' => false],
                ],
            ]);

            $ipaf = $this->trainingCourse($md, [
                'name' => 'IPAF 3a and 3b — mobile elevating work platforms',
                'code' => 'IPAF',
                'description' => 'Static vertical and mobile boom platforms, as used for plant room '
                    .'and atrium access.',
                'category' => 'Technical',
                'validity_months' => 60,
                'provider' => 'North West Access Training',
                'duration_hours' => 8,
                'sort_order' => 1,
            ]);

            $this->trainingCourse($md, [
                'name' => 'Asbestos awareness',
                'code' => 'ASB',
                'description' => 'Category A awareness for anyone working in buildings constructed '
                    .'before 2000. Refreshed annually.',
                'category' => 'Statutory',
                'validity_months' => 12,
                'is_mandatory' => true,
                'provider' => 'North West Access Training',
                'duration_hours' => 3,
                'sort_order' => 2,
            ]);

            // ------------------------------------------------- one risk assessment, two hazards
            $ahuRisk = $risks->create([
                'title' => 'AHU-3 removal and replacement, Trafford Point plant room',
                'description' => 'Removal of the existing unit through the plant room doors and '
                    .'installation of the replacement, working at height off a scaffold tower.',
                'project_id' => $ahu->id,
                'org_unit_id' => $unit['CMP']->id,
                'assessment_type' => 'Task based',
                'activity' => 'Mechanical plant replacement in an occupied building',
                'location' => 'Trafford Point — roof plant room',
                'assessed_by' => $complianceHead->id,
                'assessed_on' => now()->subDays(38)->toDateString(),
                'valid_from' => now()->subDays(35)->toDateString(),
                'valid_to' => now()->addDays(55)->toDateString(),
                'review_frequency_months' => 3,
            ], $complianceHead);

            $risks->setHazards($ahuRisk, [
                [
                    'hazard' => 'Manual handling of AHU sections through a restricted plant room door',
                    'who_is_at_risk' => 'Mechanical technicians',
                    'existing_controls' => 'Unit split into four sections by the manufacturer; '
                        .'skates and a pallet truck; two-person lift minimum.',
                    'likelihood' => 3, 'severity' => 3,
                    'additional_controls' => 'Genie lift hired for the fan section, which exceeds '
                        .'the two-person limit.',
                    'residual_likelihood' => 2, 'residual_severity' => 2,
                    'responsible_id' => $maintenanceHead->id,
                    'target_date' => now()->subDays(30)->toDateString(),
                ],
                [
                    'hazard' => 'Live working during re-termination',
                    'who_is_at_risk' => 'Electrical technician, building occupants',
                    'existing_controls' => 'Permit to isolate; lock off and prove dead; no live '
                        .'working permitted under any circumstances.',
                    'likelihood' => 2, 'severity' => 5,
                    'additional_controls' => 'Isolation agreed with the building manager in advance '
                        .'so the supply is not restored by a third party mid-shift.',
                    'residual_likelihood' => 1, 'residual_severity' => 5,
                    'responsible_id' => $complianceHead->id,
                    'target_date' => now()->subDays(32)->toDateString(),
                ],
            ], $complianceHead);

            $risks->review($ahuRisk, $maintenanceHead);
            $risks->approve($ahuRisk, $md);

            // ------------------------------------------------- the isolation permit, live
            $isolationPermit = $permits->create([
                'permit_type_id' => $isolation->id,
                'title' => 'Isolation of AHU-3 supply for re-termination',
                'description' => 'Isolation at distribution board DB-R2 way 14 for the duration of '
                    .'the re-termination and commissioning.',
                'project_id' => $ahu->id,
                'org_unit_id' => $unit['MNT-ELE']->id,
                'location' => 'Trafford Point — roof plant room and DB-R2',
                'equipment' => 'DB-R2 way 14, AHU-3 local isolator',
                'worker_count' => 2,
                'valid_from' => now()->subHours(2)->toDateTimeString(),
                'isolation_reference' => 'KFM/ISO/2026/044',
            ], $maintenanceHead);

            foreach ($isolationPermit->precautions()->orderBy('sort_order')->get() as $precaution) {
                if (! $precaution->is_required) {
                    continue;
                }

                $permits->confirmPrecaution($precaution, $maintenanceHead);
            }

            $permits->issue($isolationPermit, $complianceHead);
            $permits->accept($isolationPermit, $electrical);

            // ------------------------------------------------- an observation and a near miss
            $observations->create([
                'title' => 'Plant room door propped open with a fire extinguisher',
                'description' => 'The roof plant room fire door was being held open with an '
                    .'extinguisher to run cables through. Closed, and a proper door retainer '
                    .'requested from the building manager.',
                'project_id' => $ahu->id,
                'org_unit_id' => $unit['CMP']->id,
                'observation_type' => 'Unsafe condition',
                'category' => 'Fire',
                'risk_level' => 'Medium',
                'location' => 'Trafford Point — roof plant room',
                'observed_at' => $this->workingDay(12, 11)->toDateTimeString(),
                'observer_name' => 'Nadia Osei',
                'immediate_action' => 'Door closed and the extinguisher returned to its bracket. '
                    .'Cable route rethought so the door can stay shut.',
                'action_required' => false,
            ], $complianceHead);

            // NO ROUTE IS STARTED, because Kestrel has not configured one. Compare with Meridian,
            // where the same call on the same service opens an investigation and a corrective
            // action. The difference is a configuration screen, not a code path.
            $nearMiss = $incidents->create([
                'title' => 'Scaffold tower moved while a technician was on the platform',
                'description' => 'A second contractor began moving the tower to reach a light '
                    .'fitting without checking the platform. The technician on it called out and '
                    .'the tower was stopped within about half a metre. Nobody was hurt.',
                'project_id' => $ahu->id,
                'org_unit_id' => $unit['CMP']->id,
                'category' => 'near_miss',
                'severity' => 'minor',
                'potential_severity' => 'major',
                'location' => 'Trafford Point — fourth floor lobby',
                'occurred_at' => $this->workingDay(16, 13)->toDateTimeString(),
                'immediate_actions' => 'Tower tagged, and a rule agreed with the building manager '
                    .'that only Kestrel moves Kestrel access equipment.',
            ], $maintenanceHead);

            $towerAction = $actions->openFrom($nearMiss, [
                'title' => 'Tag all access equipment with a named responsible person',
                'description' => 'Every tower and podium on a Kestrel job to carry a tag naming who '
                    .'may move it, and the building manager to be told at induction.',
                'action_type' => 'Corrective',
                'priority' => 'Medium',
                'due_in_days' => 10,
            ], $maintenanceHead->id, $complianceHead);

            $actions->complete($towerAction, $maintenanceHead,
                'Tags printed and fitted to both towers and the podium. Covered with the building '
                    .'manager at the Monday progress meeting and added to the site induction.');

            $actions->verify($towerAction, $complianceHead, 'effective',
                'Checked on site. Tags in place and legible. The other contractor confirmed they '
                    .'were told.');

            $incidents->close($nearMiss, $complianceHead);

            // ------------------------------------------------- one toolbox talk, four people
            $towerTalk = $talks->create([
                'title' => 'Nobody moves a tower with somebody on it',
                'topic' => 'Mobile access towers in occupied buildings',
                'content' => 'Prompted by the near miss at Trafford Point. Who may move a tower, '
                    .'what the tag means, and what to say to another contractor who reaches for it.',
                'key_points' => "Only the person named on the tag moves the tower.\n"
                    ."Check the platform before you move anything, every time.\n"
                    .'Tell the building manager at induction, not after.',
                'project_id' => $ahu->id,
                'org_unit_id' => $unit['MNT']->id,
                'location' => 'Manchester Office — meeting room',
                'duration_minutes' => 15,
            ], $complianceHead);

            $talks->record($towerTalk, [
                ['user_id' => $maintenanceHead->id, 'person_name' => $maintenanceHead->name, 'company' => 'Kestrel Facilities Management', 'trade' => 'Maintenance'],
                ['user_id' => $mechanical->id, 'person_name' => $mechanical->name, 'company' => 'Kestrel Facilities Management', 'trade' => 'Mechanical'],
                ['user_id' => $electrical->id, 'person_name' => $electrical->name, 'company' => 'Kestrel Facilities Management', 'trade' => 'Electrical'],
                ['person_name' => 'Dean Marsh', 'company' => 'Marsh Interiors Ltd', 'trade' => 'Fit-out'],
            ], $complianceHead, $this->workingDay(11, 8)->toDateTimeString());

            // ------------------------------------------------- two tickets, one running out
            $training->create([
                'training_course_id' => $ipaf->id,
                'user_id' => $mechanical->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'completed_on' => now()->subMonths(19)->toDateString(),
                'certificate_number' => 'IPAF/2024/88214',
                'result' => 'pass',
                'provider' => 'North West Access Training',
                'cost' => 340,
            ], $complianceHead);

            $training->create([
                'training_course_id' => $ipaf->id,
                'user_id' => $electrical->id,
                'org_unit_id' => $unit['MNT-ELE']->id,
                'completed_on' => now()->subMonths(60)->addDays(24)->toDateString(),
                'certificate_number' => 'IPAF/2021/61077',
                'result' => 'pass',
                'provider' => 'North West Access Training',
                'cost' => 310,
                'notes' => 'Renewal booked. Not to operate a MEWP after the expiry date.',
            ], $complianceHead);
        });
    }

    // ================================================================= who they trade with

    /*
     * COMMERCIAL IS WHERE THE DEMO STOPS BEING A COLLECTION OF REGISTERS (§90).
     *
     * Up to here the two companies had clients and contracts as TYPED TEXT — `client_name` on a
     * project, `contract_reference` beside it, a party name on every RFI and transmittal. That is
     * genuinely how the earlier phases had to work, and it is also exactly what an unconnected
     * system looks like: three spellings of the same company across three screens and no way to
     * ask what is outstanding with any of them.
     *
     * These two chapters promote those strings to records and then POINT THE OLD ROWS AT THEM, so
     * the demo shows the join rather than describing it. Opening Riverine Petroleum lists the job
     * and the contract with the retention held against it, and every RFI, submittal and transmittal
     * that named them now opens the client from the correspondence — a round trip that did not
     * exist in either direction an hour ago.
     *
     * THE TWO COMPANIES ARE DELIBERATELY UNALIKE HERE TOO (§73). Meridian runs a full
     * prequalification process — approvals, expiry dates, ratings, one blacklisting — and its
     * register carries a supplier in nearly every state the status graph has. Kestrel runs no
     * prequalification at all: two suppliers, both usable, neither ever assessed. Both companies
     * work on the same code because `Vendor::canBeUsed()` defaults permissive and lets the
     * assessment mean something only where somebody actually does it.
     *
     * PERMISSIONS ARE REAL HERE. StatusMachine::transition() checks the actor, so every move below
     * is made by somebody whose ROLE TEMPLATE grants it — the procurement officer registers a
     * supplier and cannot approve one, the procurement manager approves, the managing director
     * approves contracts for signature and finance records them as executed. If a default template
     * ever loses one of those grants this seeder fails loudly, rather than quietly producing a
     * register nobody could have produced by hand.
     */
    private function seedMeridianCommercial(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $vendors = app(VendorService::class);
            $clients = app(ClientService::class);
            $contracts = app(ContractService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'MEI-0001');             // Adaeze Okonkwo
            $ops = $this->accountOf($staff, 'MEI-0003');            // Chinedu Balogun
            $engManager = $this->accountOf($staff, 'MEI-0004');     // Farida Yusuf
            $projectManager = $this->accountOf($staff, 'MEI-0011'); // Daniel Mbeki
            $qaManager = $this->accountOf($staff, 'MEI-0015');      // Yemi Bakare
            $finance = $this->accountOf($staff, 'MEI-0018');        // Victor Anyanwu
            $prcManager = $this->accountOf($staff, 'MEI-0019');     // Bisi Adeleke
            $prcOfficer = $this->accountOf($staff, 'MEI-0020');     // Uche Nnamdi

            // ------------------------------------------------------------------- clients
            // Note who opens the account: operations, not procurement. The client register and the
            // vendor register are owned by different parts of the company, and the role templates
            // say so rather than leaving both to whoever happens to be an administrator.
            $riverine = $clients->create([
                'name' => 'Riverine Petroleum Development Company',
                'legal_name' => 'Riverine Petroleum Development Company Limited',
                'code' => 'RPDC',
                'industry' => 'Oil & gas — upstream',
                'client_type' => 'operator',
                'registration_no' => 'RC 418772',
                'tax_id' => 'TIN 10294857-0001',
                'email' => 'contracts@rpdc.example',
                'phone' => '+234 1 271 4400',
                'website' => 'https://rpdc.example',
                'address' => "Plot 4, Riverine House\nTrans-Amadi Industrial Layout",
                'city' => 'Port Harcourt',
                'state' => 'Rivers',
                'country' => 'Nigeria',
                'payment_terms_days' => 45,
                'credit_limit' => 2500000000,
                'org_unit_id' => $unit['OPS']->id,
                'notes' => 'Their contracts department insists on the contract number appearing on '
                    .'every transmittal cover sheet. Two packages live at present.',
            ], $ops);

            $this->moveCommercial($riverine, 'client', 'active', $ops,
                'Delta Terminal contract executed. Account live.');

            $clients->assignOwner($riverine, $ops, $ops);

            $clients->addContact($riverine, [
                'name' => 'Ijeoma Chukwu',
                'position' => 'Contracts Manager',
                'email' => 'i.chukwu@rpdc.example',
                'phone' => '+234 803 411 2288',
                'is_primary' => true,
                'notes' => 'All contractual correspondence. Copies the project engineer on technical '
                    .'queries but will not answer them herself.',
            ], $ops);

            $clients->addContact($riverine, [
                'name' => 'Femi Ogundipe',
                'position' => 'Company Site Representative, Delta Terminal',
                'email' => 'f.ogundipe@rpdc.example',
                'phone' => '+234 802 664 9137',
                'notes' => 'On site four days a week. Signs daily records and witness points.',
            ], $projectManager);

            // The consultancy client. The job finished; the account has not closed, because the
            // repair strategy that survey produced is expected to become a tender.
            $ogunu = $clients->create([
                'name' => 'Ogunu Port Services Limited',
                'legal_name' => 'Ogunu Port Services Limited',
                'code' => 'OPS-CL',
                'industry' => 'Ports & marine terminals',
                'client_type' => 'private',
                'registration_no' => 'RC 902114',
                'email' => 'engineering@ogunuport.example',
                'phone' => '+234 53 250 118',
                'address' => 'Ogunu Jetty Complex, Warri South',
                'city' => 'Warri',
                'state' => 'Delta',
                'country' => 'Nigeria',
                'payment_terms_days' => 30,
                'org_unit_id' => $unit['ENG']->id,
                'notes' => 'Condition assessment delivered. Repair works expected to tender next '
                    .'quarter on the strength of it.',
            ], $ops);

            $this->moveCommercial($ogunu, 'client', 'active', $ops, 'Survey commissioned.');
            $clients->assignOwner($ogunu, $engManager, $ops);

            $clients->addContact($ogunu, [
                'name' => 'Bright Eweka',
                'position' => 'Harbour Engineer',
                'email' => 'b.eweka@ogunuport.example',
                'phone' => '+234 806 512 7740',
                'is_primary' => true,
            ], $ops);

            // A PROSPECT, and left as one. A client register holding nothing but live accounts is a
            // register somebody keeps a spreadsheet beside — which is how the spreadsheet ends up
            // being the real system.
            $lekki = $clients->create([
                'name' => 'Lekki Industrial Park Development Company',
                'code' => 'LIPD',
                'industry' => 'Industrial estates',
                'client_type' => 'private',
                'email' => 'projects@lekki-park.example',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'org_unit_id' => $unit['OPS']->id,
                'notes' => 'Prequalification pack submitted. Tender for the phase 2 utilities '
                    .'corridor expected in the new year.',
            ], $ops);

            $clients->assignOwner($lekki, $ops, $ops);

            // ------------------------------------------------------------------- vendors
            // Registered by the procurement OFFICER and approved by the procurement MANAGER. The
            // officer's template grants `vendor.create` and not `vendor.manage`, so the split below
            // is enforced by the engine rather than illustrated by convention — segregation of
            // duties, in place before the procurement module that leans on it exists.
            $sahara = $vendors->create([
                'name' => 'Sahara Steel & Fabrication Limited',
                'legal_name' => 'Sahara Steel & Fabrication Limited',
                'code' => 'SSF',
                'category' => 'Structural steel & tank fabrication',
                'vendor_type' => 'subcontractor',
                'registration_no' => 'RC 226901',
                'tax_id' => 'TIN 20871144-0001',
                'email' => 'tenders@saharasteel.example',
                'phone' => '+234 1 448 7120',
                'address' => 'KM 18 Lagos–Badagry Expressway',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'payment_terms_days' => 30,
                'credit_limit' => 400000000,
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'ASME U-stamp holder. Two shops, one of which is committed elsewhere '
                    .'until the end of the quarter.',
            ], $prcOfficer);

            $this->moveCommercial($sahara, 'vendor', 'under_review', $prcOfficer,
                'Prequalification pack received in full.');
            $this->moveCommercial($sahara, 'vendor', 'approved', $prcManager,
                'Shop audited. Welder qualifications and PQRs verified against the tank scope.');

            $vendors->recordPrequalification(
                $sahara,
                now()->subMonths(8)->toDateString(),
                now()->addMonths(16)->toDateString(),
                $prcManager,
            );

            // Bank details are written by FINANCE and by nobody else. `vendor.bank` is a separate
            // permission from `vendor.edit` for the reason §67 gives: an account number is a
            // payment instrument, and the register masks it for everyone who does not hold it.
            $vendors->updateBankDetails($sahara, [
                'bank_name' => 'First Continental Bank Plc',
                'bank_account_name' => 'Sahara Steel & Fabrication Limited',
                'bank_account_number' => '0117420893',
                'bank_branch' => 'Apapa',
                'bank_swift' => 'FCBLNGLA',
            ], $finance, 'Verified against the certificate of incorporation and a bank reference.');

            $vendors->addContact($sahara, [
                'name' => 'Kunle Adebayo',
                'position' => 'Commercial Manager',
                'email' => 'k.adebayo@saharasteel.example',
                'phone' => '+234 805 219 6633',
                'is_primary' => true,
            ], $prcOfficer);

            $vendors->recordRating($sahara, 4.5, $qaManager,
                'Tank floor plates delivered to programme. Two minor NCRs, both closed at the shop.');
            $vendors->recordRating($sahara, 4.0, $projectManager,
                'Shell course delivery slipped a week against their own promise, recovered on site.');

            // AN EXPIRED PREQUALIFICATION — the condition a real register is most often failing on,
            // and the one nobody notices until an order is raised. This vendor is still approved,
            // still active, well rated, and `Vendor::canBeUsed()` refuses them anyway.
            $panafric = $vendors->create([
                'name' => 'PanAfric Valves & Fittings',
                'legal_name' => 'PanAfric Valves & Fittings Nigeria Limited',
                'code' => 'PAVF',
                'category' => 'Valves, flanges and pipe fittings',
                'vendor_type' => 'supplier',
                'registration_no' => 'RC 551408',
                'email' => 'sales@panafricvalves.example',
                'phone' => '+234 1 291 8850',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'payment_terms_days' => 21,
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'Renewal pack chased twice. Their ISO 9001 certificate lapsed in the '
                    .'meantime, which is why this has not simply been re-dated.',
            ], $prcOfficer);

            $this->moveCommercial($panafric, 'vendor', 'under_review', $prcOfficer, 'Renewal in progress.');
            $this->moveCommercial($panafric, 'vendor', 'approved', $prcManager, 'Approved on the original assessment.');

            $vendors->recordPrequalification(
                $panafric,
                now()->subMonths(26)->toDateString(),
                now()->subMonths(2)->toDateString(),
                $prcManager,
            );

            $vendors->recordRating($panafric, 3.0, $prcOfficer,
                'Correct goods, consistently late. Two deliveries out of five inside the promised week.');

            // EXPIRING, not expired — inside the 60-day warning window the provisioner sets as the
            // default, so the register's `expiring` view has something in it on the day the demo is
            // opened rather than on some day nobody is looking.
            $coastal = $vendors->create([
                'name' => 'Coastal Marine Services Ltd',
                'code' => 'CMS',
                'category' => 'Marine logistics & jetty access',
                'vendor_type' => 'service',
                'registration_no' => 'RC 731266',
                'email' => 'ops@coastalmarine.example',
                'phone' => '+234 84 230 615',
                'city' => 'Warri',
                'state' => 'Delta',
                'country' => 'Nigeria',
                'payment_terms_days' => 30,
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'Boat and barge hire for the Ogunu jetty survey. Renewal pack requested.',
            ], $prcOfficer);

            $this->moveCommercial($coastal, 'vendor', 'under_review', $prcOfficer, 'Assessed for the jetty survey.');
            $this->moveCommercial($coastal, 'vendor', 'approved', $prcManager, 'Vessel certificates and P&I cover verified.');

            $vendors->recordPrequalification(
                $coastal,
                now()->subMonths(22)->toDateString(),
                now()->addDays(38)->toDateString(),
                $prcManager,
            );

            $vendors->recordRating($coastal, 5.0, $engManager,
                'Survey team on the water every day of the window, including the two we did not expect to get.');

            // PROVISIONAL: used once, under supervision, on the strength of a reference rather than
            // a full assessment. The state most companies actually have and few systems model — and
            // without it they either approve people they have not assessed or refuse work they have
            // already promised.
            $ashaka = $vendors->create([
                'name' => 'Ashaka Industrial Coatings',
                'code' => 'AIC',
                'category' => 'Surface preparation & protective coatings',
                'vendor_type' => 'subcontractor',
                'email' => 'enquiries@ashakacoatings.example',
                'phone' => '+234 807 330 4412',
                'city' => 'Warri',
                'state' => 'Delta',
                'country' => 'Nigeria',
                'payment_terms_days' => 14,
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'Referred by the client. One tank exterior only, with our own inspector '
                    .'at every hold point, pending a full assessment.',
            ], $prcOfficer);

            $this->moveCommercial($ashaka, 'vendor', 'under_review', $prcOfficer, 'Client referral. Pack partially returned.');
            $this->moveCommercial($ashaka, 'vendor', 'provisional', $prcManager,
                'One package only, supervised. Full prequalification before any repeat award.');

            // BLACKLISTED, with the reason on the record. Blacklisting goes through the service
            // rather than the status graph precisely so the reason cannot be omitted — and the
            // status chip follows the column rather than the other way round.
            $horizon = $vendors->create([
                'name' => 'Bright Horizon Logistics',
                'code' => 'BHL',
                'category' => 'Haulage & heavy lift transport',
                'vendor_type' => 'service',
                'registration_no' => 'RC 880417',
                'email' => 'dispatch@brighthorizon.example',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'org_unit_id' => $unit['PRC']->id,
            ], $prcOfficer);

            $this->moveCommercial($horizon, 'vendor', 'under_review', $prcOfficer, 'Assessed for tank shell haulage.');
            $this->moveCommercial($horizon, 'vendor', 'approved', $prcManager, 'Approved on documents.');

            $vendors->blacklist(
                $horizon,
                'Two of the three low-loaders presented for the shell course move carried no valid '
                .'insurance, and the certificates produced at the gate had been altered. Referred '
                .'to the managing director. No further enquiries to be issued to this company.',
                $prcManager,
            );

            // NEVER ASSESSED, and deliberately left that way. This is the permissive default made
            // visible: procurement can raise an order against this supplier today, and the register
            // says plainly that nobody has looked at them. A company that wants that refused turns
            // it on in configuration — the platform does not assume every tenant prequalifies.
            $vendors->create([
                'name' => 'Kano Instrument Supplies',
                'code' => 'KIS',
                'category' => 'Field instrumentation & calibration',
                'vendor_type' => 'supplier',
                'email' => 'orders@kanoinstruments.example',
                'city' => 'Kano',
                'state' => 'Kano',
                'country' => 'Nigeria',
                'payment_terms_days' => 14,
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'Added by the officer against a single low-value requisition. No '
                    .'prequalification pack requested yet.',
            ], $prcOfficer);

            // ARCHIVED: off the register without being deleted, because the history of what was
            // bought from them has to outlive them.
            $ironclad = $vendors->create([
                'name' => 'Ironclad Scaffold Hire',
                'code' => 'ISH',
                'category' => 'Scaffold hire & erection',
                'vendor_type' => 'subcontractor',
                'email' => 'hire@ironcladscaffold.example',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'org_unit_id' => $unit['PRC']->id,
                'notes' => 'Ceased trading. Kept on the register because two closed jobs were '
                    .'scaffolded by them and the records name them.',
            ], $prcOfficer);

            $this->moveCommercial($ironclad, 'vendor', 'archived', $prcManager,
                'Company dissolved. Retained for the historical record only.');

            // ----------------------------------------------------------------- contracts
            // The head contract, and the number the client will quote at us for the next two years.
            // CONFIDENTIAL BY DEFAULT — ContractService stamps it — so the value is invisible to
            // everybody without `contract.view_confidential`, which on the default templates means
            // the managing director, finance and procurement, and nobody else.
            $headContract = $contracts->create([
                'title' => 'Delta Terminal Tank Farm Expansion — EPC Contract',
                'description' => 'Engineering, procurement and construction of two 15,000 m³ crude '
                    .'storage tanks with associated tie-in piping and a firewater system upgrade.',
                'direction' => 'client',
                'party_type' => 'client',
                'party_id' => $riverine->id,
                'contract_type' => 'lump_sum',
                'reference' => 'RPDC/EPC/2025/017',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PMO']->id,
                'value' => 4180000000,
                'retention_percent' => 5,
                'advance_percent' => 15,
                'signed_on' => now()->subMonths(5)->startOfMonth()->subDays(9)->toDateString(),
                'starts_on' => now()->subMonths(5)->startOfMonth()->toDateString(),
                'ends_on' => now()->addMonths(7)->endOfMonth()->toDateString(),
                'defects_liability_months' => 12,
                'owner_id' => $projectManager->id,
                'scope_summary' => 'Two tanks to API 650, tie-in spools to the existing 24" header, '
                    .'a firewater ring main and two new monitors. Commissioning support for 30 days '
                    .'after mechanical completion.',
                'payment_terms' => 'Monthly valuation, certified within 14 days, paid within 45 days '
                    .'of certification. 15% advance against an on-demand bank guarantee, recovered '
                    .'pro rata. 5% retention, half released at taking-over and half at the end of '
                    .'the defects liability period.',
            ], $finance);

            $this->moveCommercial($headContract, 'contract', 'in_review', $finance,
                'Drafted onto their form of contract.');
            $this->moveCommercial($headContract, 'contract', 'awaiting_signature', $md,
                'Commercial terms accepted. Liability cap and the LD rate negotiated down.');
            $this->moveCommercial($headContract, 'contract', 'active', $finance,
                'Executed by both parties. Original filed.');

            // Retention accrues per valuation and is released in stages — which is the whole reason
            // it is a movement with a reason attached rather than a figure somebody types over.
            $contracts->addRetention($headContract, 41800000, $finance, 'Valuation 1 — 5% of ₦836m certified.');
            $contracts->addRetention($headContract, 33900000, $finance, 'Valuation 2 — 5% of ₦678m certified.');
            $contracts->addRetention($headContract, 29500000, $finance, 'Valuation 3 — 5% of ₦590m certified.');

            // The consultancy contract: finished, and marked so. `completed` and `terminated` are
            // both terminal and deliberately different conversations.
            $surveyContract = $contracts->create([
                'title' => 'Ogunu Jetty Deck Condition Assessment',
                'description' => 'Visual and hammer-tap survey of the jetty deck with a repair '
                    .'strategy and a budget estimate.',
                'direction' => 'client',
                'party_type' => 'client',
                'party_id' => $ogunu->id,
                'contract_type' => 'lump_sum',
                'reference' => 'OPS/CON/2025/004',
                'project_id' => $job['OJA']?->id,
                'org_unit_id' => $unit['ENG']->id,
                'value' => 62400000,
                'signed_on' => now()->subMonths(5)->subDays(4)->toDateString(),
                'starts_on' => now()->subMonths(5)->toDateString(),
                'ends_on' => now()->subMonths(2)->toDateString(),
                'owner_id' => $engManager->id,
                'scope_summary' => 'Survey of 4,200 m² of deck soffit and 96 piles above low water, '
                    .'a condition report and a costed repair strategy.',
                'payment_terms' => '40% on mobilisation, 60% on acceptance of the report. 30 days.',
            ], $finance);

            $this->moveCommercial($surveyContract, 'contract', 'in_review', $finance, 'Their standard consultancy terms.');
            $this->moveCommercial($surveyContract, 'contract', 'awaiting_signature', $md, 'Approved.');
            $this->moveCommercial($surveyContract, 'contract', 'active', $finance, 'Executed.');
            $this->moveCommercial($surveyContract, 'contract', 'completed', $finance,
                'Report accepted and the final invoice settled.');

            // THE SAME REGISTER, HELD THE OTHER WAY ROUND. A subcontract is not a different kind of
            // record, and a system that gave it one would ask the same eleven questions twice and
            // then disagree with itself about the answers.
            $fabrication = $contracts->create([
                'title' => 'Delta Terminal — Tank Plate Fabrication Subcontract',
                'description' => 'Shop fabrication of floor plates, shell courses and roof structure '
                    .'for both tanks, delivered to site in erection sequence.',
                'direction' => 'vendor',
                'party_type' => 'vendor',
                'party_id' => $sahara->id,
                'contract_type' => 'remeasurable',
                'reference' => 'MEI/SUB/2025/031',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PRC']->id,
                'value' => 1240000000,
                'retention_percent' => 5,
                'signed_on' => now()->subMonths(4)->subDays(12)->toDateString(),
                'starts_on' => now()->subMonths(4)->toDateString(),
                'ends_on' => now()->addMonths(3)->toDateString(),
                'defects_liability_months' => 12,
                'owner_id' => $prcManager->id,
                'scope_summary' => 'Plate cutting, rolling, shop welding and NDT to the approved WPS. '
                    .'Delivery in erection sequence to the site laydown area.',
                'payment_terms' => 'Back to back with the head contract: certified monthly, paid '
                    .'within 14 days of receipt from the client. 5% retention on the same terms.',
                'notes' => 'Deliberately back to back with RPDC/EPC/2025/017 on payment and '
                    .'retention. The defects liability periods are aligned so the two expire together.',
            ], $prcManager);

            $this->moveCommercial($fabrication, 'contract', 'in_review', $prcManager, 'Drafted on our own form.');
            $this->moveCommercial($fabrication, 'contract', 'awaiting_signature', $md, 'Approved for award.');
            $this->moveCommercial($fabrication, 'contract', 'active', $prcManager, 'Executed and the shop released to start.');

            $contracts->addRetention($fabrication, 24800000, $finance, 'Application 1 — 5% of ₦496m.');
            $contracts->addRetention($fabrication, 19100000, $finance, 'Application 2 — 5% of ₦382m.');
            $contracts->releaseRetention($fabrication, 11000000, $finance,
                'Half of the first application released against the shop completion certificate.');

            // A framework with no project and no committed value: the call-off agreement every
            // buying department has, and the one a project-shaped register cannot hold at all.
            $framework = $contracts->create([
                'title' => 'Valves and Pipe Fittings — Framework Agreement',
                'description' => 'Two-year call-off agreement for valves, flanges and fittings '
                    .'against an agreed schedule of rates.',
                'direction' => 'vendor',
                'party_type' => 'vendor',
                'party_id' => $panafric->id,
                'contract_type' => 'framework',
                'reference' => 'MEI/FRM/2025/006',
                'org_unit_id' => $unit['PRC']->id,
                'retention_percent' => 0,
                'signed_on' => now()->subMonths(14)->toDateString(),
                'starts_on' => now()->subMonths(14)->toDateString(),
                'ends_on' => now()->addMonths(10)->toDateString(),
                'owner_id' => $prcManager->id,
                'scope_summary' => 'Schedule of rates fixed for the first year and indexed '
                    .'thereafter. No committed volume.',
                'payment_terms' => '21 days from receipt of a correct invoice against a call-off order.',
                'notes' => 'Suspended: their prequalification lapsed two months ago and no call-off '
                    .'is to be raised until it is renewed.',
            ], $prcManager);

            $this->moveCommercial($framework, 'contract', 'in_review', $prcManager, 'Rates agreed.');
            $this->moveCommercial($framework, 'contract', 'awaiting_signature', $md, 'Approved.');
            $this->moveCommercial($framework, 'contract', 'active', $prcManager, 'Executed.');
            $this->moveCommercial($framework, 'contract', 'suspended', $prcManager,
                'Prequalification lapsed. No call-off to be raised until it is renewed.');

            // ------------------------------------------------------------ the join itself
            $this->linkProjectCommercially($job['DTE'] ?? null, $riverine, $headContract, $ops);
            $this->linkProjectCommercially($job['OJA'] ?? null, $ogunu, $surveyContract, $ops);
            $this->linkCorrespondence();
        });
    }

    /*
     * Kestrel, who do none of this.
     *
     * ONE CLIENT, TWO SUPPLIERS, ONE CONTRACT, AND NO PREQUALIFICATION AT ALL. A facilities
     * management company of eight does not run a vendor assessment process, and a platform that
     * forced one on them would be telling them their business. Their suppliers sit in `draft`
     * forever, carry no prequalification dates and are perfectly orderable — the same
     * `Vendor::canBeUsed()` that refuses Meridian's lapsed supplier lets both of these through,
     * because there is nothing here to have lapsed.
     *
     * THE MANAGING DIRECTOR DRAFTS, APPROVES AND EXECUTES THE CONTRACT, which in a bigger company
     * would be a segregation failure and here is simply Tuesday. The platform does not pretend
     * otherwise: it enforces whatever the tenant's roles say, and Kestrel's say this.
     */
    private function seedKestrelCommercial(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $vendors = app(VendorService::class);
            $clients = app(ClientService::class);
            $contracts = app(ContractService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder
            $opsManager = $this->accountOf($staff, 'KFM-002');      // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003'); // Duncan Reilly

            $trafford = $clients->create([
                'name' => 'Trafford Point Estates LLP',
                'legal_name' => 'Trafford Point Estates LLP',
                'code' => 'TPE',
                'industry' => 'Commercial property',
                'client_type' => 'private',
                'registration_no' => 'OC 402118',
                'email' => 'buildingservices@traffordpoint.example',
                'phone' => '+44 161 244 0180',
                'address' => 'Trafford Point, 1 Ashburton Road West',
                'city' => 'Manchester',
                'country' => 'United Kingdom',
                'payment_terms_days' => 30,
                'org_unit_id' => $unit['MNT']->id,
                'notes' => 'Managing agent for the building. The tenant on floors 4 to 6 is who '
                    .'actually complains, and they are not our client.',
            ], $opsManager);

            $this->moveCommercial($trafford, 'client', 'active', $opsManager,
                'PPM contract renewed for a third year.');
            $clients->assignOwner($trafford, $opsManager, $opsManager);

            $clients->addContact($trafford, [
                'name' => 'Helen Marsh',
                'position' => 'Building Manager',
                'email' => 'h.marsh@traffordpoint.example',
                'phone' => '+44 7700 900412',
                'is_primary' => true,
                'notes' => 'Agrees every isolation before it happens. Nothing goes off without her.',
            ], $opsManager);

            // No prequalification, no assessment, no status moves. This is what the register looks
            // like in a company that buys from people it has used for years, and it is a complete,
            // working register rather than a half-filled one.
            $airHandling = $vendors->create([
                'name' => 'Northern Air Handling Ltd',
                'code' => 'NAH',
                'category' => 'AHU supply & commissioning',
                'vendor_type' => 'supplier',
                'email' => 'sales@northernair.example',
                'phone' => '+44 161 872 3311',
                'city' => 'Manchester',
                'country' => 'United Kingdom',
                'payment_terms_days' => 30,
                'org_unit_id' => $unit['MNT']->id,
                'notes' => 'Supplied and commissioned AHU-1 and AHU-2 in 2023. Same unit, same '
                    .'controls, which is most of why they got AHU-3.',
            ], $maintenanceHead);

            $vendors->addContact($airHandling, [
                'name' => 'Paul Brennan',
                'position' => 'Sales Engineer',
                'email' => 'p.brennan@northernair.example',
                'phone' => '+44 7700 900733',
                'is_primary' => true,
            ], $maintenanceHead);

            $vendors->recordRating($airHandling, 4.0, $maintenanceHead,
                'Unit arrived on the promised day. Commissioning engineer needed chasing twice.');

            $vendors->create([
                'name' => 'Peak Electrical Contractors',
                'code' => 'PEC',
                'category' => 'Electrical installation & testing',
                'vendor_type' => 'subcontractor',
                'email' => 'office@peakelectrical.example',
                'phone' => '+44 161 903 4420',
                'city' => 'Stockport',
                'country' => 'United Kingdom',
                'payment_terms_days' => 21,
                'org_unit_id' => $unit['MNT']->id,
                'notes' => 'Used for anything beyond our own two electricians. NICEIC registered; '
                    .'the certificate is on file with the compliance lead.',
            ], $maintenanceHead);

            // One contract, and NO RETENTION. Facilities management is a service against a schedule
            // of rates; a form that demanded a retention percentage would get a zero typed into it,
            // which is how a field stops meaning anything.
            $ppm = $contracts->create([
                'title' => 'Trafford Point — Planned Preventive Maintenance',
                'description' => 'Mechanical and electrical PPM across the building, with a reactive '
                    .'call-out allowance and an agreed schedule of rates for additional works.',
                'direction' => 'client',
                'party_type' => 'client',
                'party_id' => $trafford->id,
                'contract_type' => 'framework',
                'reference' => 'TPE/PPM/2026/03',
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT']->id,
                'value' => 284000,
                'currency' => 'GBP',
                'signed_on' => now()->subMonths(8)->toDateString(),
                'starts_on' => now()->subMonths(8)->startOfMonth()->toDateString(),
                'ends_on' => now()->addMonths(4)->endOfMonth()->toDateString(),
                'owner_id' => $opsManager->id,
                'scope_summary' => 'Statutory and manufacturer PPM to the M&E asset register, with a '
                    .'four-hour reactive response in hours and eight out of hours. Additional works '
                    .'ordered against the agreed rates.',
                'payment_terms' => 'Invoiced monthly in arrears, 30 days. Additional works invoiced '
                    .'on completion against a signed instruction.',
                'notes' => 'The AHU replacement is an additional work under this contract, not a '
                    .'separate one — which is why the project points here.',
            ], $md);

            $this->moveCommercial($ppm, 'contract', 'in_review', $md, 'Third-year renewal, rates uplifted 3.1%.');
            $this->moveCommercial($ppm, 'contract', 'awaiting_signature', $md, 'Agreed with the managing agent.');
            $this->moveCommercial($ppm, 'contract', 'active', $md, 'Signed by both parties.');

            $this->linkProjectCommercially($job['TPA'] ?? null, $trafford, $ppm, $opsManager);
            $this->linkCorrespondence();
        });
    }

    /*
     * Meridian's stores: four of them, ten catalogue lines, and a ledger that agrees with itself.
     *
     * THE CHAPTER EXISTS SO THE NEXT ONE CAN JOIN UP. Procurement runs immediately after this and
     * puts pipe on a shelf; it can only do that because the shelf is opened here, the catalogue
     * line is created here, and the requisition below names it. Everything else in this chapter is
     * the store's own life — an opening balance, a transfer between two of the stores, a count
     * that disagreed with the book, and a site asking for consumables — which is what makes the
     * stock a delivery lands in look like stock rather than a single row from a demo.
     *
     * THREE THINGS ARE HERE TO BE DEMONSTRATED rather than merely populated:
     *
     *   - A SUSPENDED STORE WITH GOODS IN IT. The Ikeja yard is mid-stocktake: fully stocked,
     *     staffed, and refusing every receipt and issue until the count is signed off. That is the
     *     state an `is_active` boolean cannot express, and the reason warehouses carry a status.
     *   - A DISCONTINUED ITEM WITH SIX OF ITSELF ON THE SHELF. The CAF gaskets are superseded and
     *     nobody may order more; the six that exist stay issuable until they are gone. Deleting the
     *     row would orphan the ledger and a boolean would have to mean both things at once.
     *   - A REQUEST THAT CLOSES SHORT. Two lines went out, the third was never in the store, and
     *     the job moved on. `part_issued` then `closed` is the honest record; `issued` would be a
     *     lie the arithmetic would have to be overridden to tell.
     *
     * WHO DOES WHAT IS THE POINT, as everywhere else. Sadiq moves goods and may not correct a
     * count; Halima corrects counts, opens stores and suspends them, and does not stand at the
     * goods-out counter. Neither arrangement is in the code — it is two role templates, and a
     * company that wants one person doing both says so in its own roles (§9, §73).
     */
    private function seedMeridianInventory(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $warehouses = app(WarehouseService::class);
            $catalogue = app(StockItemService::class);
            $stock = app(StockService::class);
            $requests = app(MaterialRequestService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');

            $storesManager = $this->accountOf($staff, 'MEI-0026');  // Halima Yakubu
            $storeOfficer = $this->accountOf($staff, 'MEI-0021');   // Sadiq Bello
            $siteManager = $this->accountOf($staff, 'MEI-0012');    // Kelechi Nwosu
            $projectManager = $this->accountOf($staff, 'MEI-0011'); // Daniel Mbeki

            // ==================================================================== the stores
            // Opened by the manager, kept by the officer. `warehouse.manage` is hers and the
            // create call below would throw for him, which is the separation working rather than
            // an accident of who was to hand.
            $central = $warehouses->create([
                'code' => 'WH-CENTRAL',
                'name' => 'Central Warehouse, Lagos',
                'type' => 'Store',
                'location_id' => $place['WH-01']->id,
                'org_unit_id' => $unit['STR']->id,
                'keeper_id' => $storeOfficer->getKey(),
                'notes' => 'Main holding store. Everything that is not consumed straight off a '
                    .'lorry comes through here first.',
            ], $storesManager);

            $siteStore = $warehouses->create([
                'code' => 'WH-DT',
                'name' => 'Delta Terminal Site Store',
                'type' => 'Site store',
                'location_id' => $place['SITE-DT']->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'project_id' => $job['DTE']?->id,
                'keeper_id' => $siteManager->getKey(),
                'notes' => 'Container store in the laydown area. Issues to the tank erection and '
                    .'piping fronts.',
            ], $storesManager);

            $yard = $warehouses->create([
                'code' => 'WH-IK',
                'name' => 'Ikeja Yard Store',
                'type' => 'Store',
                'location_id' => $place['YARD-IK']->id,
                'org_unit_id' => $unit['STR']->id,
                'keeper_id' => $storeOfficer->getKey(),
                'notes' => 'Fabrication yard consumables and the harness stock.',
            ], $storesManager);

            // A QUARANTINE CAGE IS A STORE, not a flag on a movement. Goods that failed inspection
            // are physically somewhere, they are somebody's until they are collected, and a system
            // that models them as an attribute of a receipt loses them the moment the receipt is
            // filed. The eighteen wrong-rating gaskets in the procurement chapter are the kind of
            // thing that ends up in here — although those never came out of `disputed`, so nothing
            // was ever posted for them, which is itself the correct answer.
            $quarantine = $warehouses->create([
                'code' => 'WH-QUAR',
                'name' => 'Quarantine Cage — Central',
                'type' => 'Quarantine',
                'location_id' => $place['WH-01']->id,
                'org_unit_id' => $unit['STR']->id,
                'keeper_id' => $storeOfficer->getKey(),
                'notes' => 'Rejected and held material awaiting supplier collection or a '
                    .'concession. Nothing leaves here on an ordinary issue.',
            ], $storesManager);

            foreach ([$central, $siteStore, $yard, $quarantine] as $store) {
                $warehouses->activate($store, $storesManager);
            }

            // ================================================================= the catalogue
            // TEN LINES, WHICH IS THE POINT. A catalogue is not a copy of everything the company
            // buys — procurement runs on free text precisely so it does not have to be — it is the
            // list of things worth counting. Scaffold hire and third-party radiography appear in
            // the procurement chapter and will never appear here, except for the one below that is
            // catalogued deliberately WITHOUT being stocked.
            $item = [];

            $item['PIPE'] = $catalogue->create([
                'code' => 'MAT-PIPE-12-S40',
                'name' => '12" carbon steel pipe, API 5L Gr.B, schedule 40',
                'category' => 'Raw material',
                'uom' => 'm',
                'specification' => 'Seamless. Mill certificates to EN 10204 3.1 held with the '
                    .'heat number against every length.',
                'reorder_level' => 60,
                'reorder_quantity' => 120,
                'storage_conditions' => 'Racked off the ground, ends capped.',
            ], $storesManager);

            $item['FLANGE'] = $catalogue->create([
                'code' => 'MAT-FLG-12-150',
                'name' => '12" weld-neck flange, ANSI 150, raised face',
                'category' => 'Raw material',
                'uom' => 'ea',
                'specification' => 'Forged ASTM A105, bore to schedule 40.',
                'reorder_level' => 8,
                'reorder_quantity' => 24,
            ], $storesManager);

            $item['ELBOW'] = $catalogue->create([
                'code' => 'MAT-ELB-12-LR',
                'name' => '12" long-radius elbow, 90°, butt weld',
                'category' => 'Raw material',
                'uom' => 'ea',
                'reorder_level' => 4,
                'reorder_quantity' => 16,
            ], $storesManager);

            $item['GASKET'] = $catalogue->create([
                'code' => 'MAT-GSK-12-SW',
                'name' => '12" spiral wound gasket, ANSI 150, 316L/graphite',
                'category' => 'Spare part',
                'uom' => 'ea',
                'reorder_level' => 20,
                'reorder_quantity' => 40,
            ], $storesManager);

            $item['CAF'] = $catalogue->create([
                'code' => 'MAT-GSK-12-CAF',
                'name' => '12" CAF gasket, ANSI 150',
                'category' => 'Spare part',
                'uom' => 'ea',
                'notes' => 'Superseded by the spiral wound set. Use the remaining six on '
                    .'non-hydrocarbon service only.',
            ], $storesManager);

            $item['ELECTRODE'] = $catalogue->create([
                'code' => 'CON-ELE-7018',
                'name' => 'Welding electrodes, E7018, 3.2 mm',
                'category' => 'Consumable',
                'uom' => 'kg',
                'reorder_level' => 25,
                'reorder_quantity' => 100,
                'storage_conditions' => 'Quiver at 120 °C once the packet is opened. Rebake or '
                    .'scrap anything left out overnight.',
                'shelf_life_days' => 730,
            ], $storesManager);

            $item['ARGON'] = $catalogue->create([
                'code' => 'CON-GAS-AR',
                'name' => 'Shielding gas, argon/CO₂ 80:20, 50 l cylinder',
                'category' => 'Consumable',
                'uom' => 'cyl',
                'is_hazardous' => true,
                'storage_conditions' => 'Upright, chained, outside, away from the diesel bowser.',
            ], $storesManager);

            $item['GLOVES'] = $catalogue->create([
                'code' => 'PPE-GLV-RIG',
                'name' => 'Rigger gloves, leather, size 10',
                'category' => 'PPE',
                'uom' => 'pair',
                'reorder_level' => 20,
                'reorder_quantity' => 60,
            ], $storesManager);

            $item['HARNESS'] = $catalogue->create([
                'code' => 'PPE-HAR-FB',
                'name' => 'Full body harness, twin lanyard, shock absorbing',
                'category' => 'PPE',
                'uom' => 'ea',
                'specification' => 'EN 361 with EN 355 energy absorber. Serial numbered.',
                'shelf_life_days' => 1825,
                'notes' => 'Inspected every six months and on every issue. A harness that comes '
                    .'back without its inspection tag goes to quarantine, not to the rack.',
            ], $storesManager);

            /*
             * CATALOGUED AND DELIBERATELY NOT STOCKED, which is the most useful row here.
             *
             * Radiography is bought constantly, is named on requisitions in the same words every
             * time, and cannot be counted. Cataloguing it makes those requisitions consistent and
             * reportable; `is_stock_item = false` makes StockService refuse to move it. A receipt
             * against it goes straight to cost with no ledger row, which is the correct behaviour
             * and not an omission (§90 does not mean everything must touch everything).
             */
            $item['NDT'] = $catalogue->create([
                'code' => 'SVC-NDT-RT',
                'name' => 'Radiographic testing — third party, per weld',
                'uom' => 'weld',
                'is_stock_item' => false,
                'notes' => 'Catalogued so requisitions name it the same way every time. It is not '
                    .'held, counted or issued — there is nothing to put on a shelf.',
            ], $storesManager);

            foreach ($item as $line) {
                $catalogue->activate($line, $storesManager);
            }

            // Withdrawn AFTER activation, because that is the life it actually had: bought for
            // years, superseded last quarter, and six of them still on the shelf. The status graph
            // is `draft → active → discontinued` and there is no shortcut, so the demo cannot show
            // a state the application would not let somebody reach.
            $catalogue->discontinue($item['CAF'], $storesManager,
                'Superseded by MAT-GSK-12-SW on all flanged joints in hydrocarbon service. Six '
                    .'remain and stay issuable for utility lines until they are gone.');

            // ========================================================== what was on the shelf
            /*
             * AN OPENING BALANCE IS A RECEIPT, not a number typed into a balance.
             *
             * There is no way to write a quantity straight into `stock_balances` through any door
             * this application offers, and this chapter does not get a private one. Every figure
             * below arrives as a ledger row with a date, a value, a reason and a name against it,
             * exactly as the day-two movements do — which is what makes StockService::rebuildBalances()
             * a meaningful claim rather than a method nothing could ever contradict.
             */
            $opening = static function (Warehouse $store, StockItem $line, float $quantity, float $unitCost) use ($stock, $storesManager): void {
                $stock->receive($store, $line, $quantity, $storesManager, [
                    'unit_cost' => $unitCost,
                    'reason' => 'Opening balance at go-live',
                    'occurred_at' => now()->subDays(90),
                    'notes' => 'Counted and valued at the changeover from the stores spreadsheet.',
                ]);
            };

            $opening($central, $item['PIPE'], 120, 44800);
            $opening($central, $item['FLANGE'], 12, 76500);
            $opening($central, $item['ELBOW'], 8, 62000);
            $opening($central, $item['GASKET'], 60, 13200);
            $opening($central, $item['CAF'], 6, 4900);
            $opening($central, $item['ELECTRODE'], 240, 3850);
            $opening($central, $item['ARGON'], 14, 28500);
            $opening($central, $item['GLOVES'], 90, 4200);

            $opening($siteStore, $item['ELECTRODE'], 60, 3850);
            $opening($siteStore, $item['GLOVES'], 24, 4200);

            $opening($yard, $item['HARNESS'], 12, 96000);
            $opening($yard, $item['GLOVES'], 36, 4200);

            /*
             * A TRANSFER IS ONE ACT AND TWO ROWS, joined by `transfer_ref`.
             *
             * Sadiq could post the two halves separately — he holds `stock.create` — and the yard
             * would then be short for however long it took him to type the second one. That is why
             * `stock.transfer` exists as a permission of its own: not because moving stock between
             * two stores is more dangerous than moving it out of one, but because the pair must
             * either both happen or neither, and only one method can promise that.
             */
            $stock->transfer($central, $siteStore, $item['PIPE'], 40, $storeOfficer, [
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'reason' => 'First tie-in spool set — cut and prep at site.',
                'occurred_at' => now()->subDays(21),
            ]);

            $stock->transfer($central, $siteStore, $item['ELBOW'], 4, $storeOfficer, [
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'reason' => 'With the pipe.',
                'occurred_at' => now()->subDays(21),
            ]);

            /*
             * A COUNT THAT DISAGREED WITH THE BOOK, and the two people it takes.
             *
             * Six pairs of gloves are missing. There is no document behind that — no issue note, no
             * receipt, nothing to point at — which is exactly why `stock.adjust` is not in the
             * store officer's role: an adjustment is the one movement that says "the book was
             * wrong" and it needs somebody who did not write the book. Sadiq counts, Halima posts
             * the variance, and `inventory.separate_counter_and_adjuster` is the setting that says
             * whether a company insists on it.
             */
            $stock->adjust($central, $item['GLOVES'], 84, $storesManager,
                'Quarterly count. Book 90, counted 84. Six pairs went to the yard with the '
                    .'scaffold gang in December and no issue was raised at the time.', [
                        'occurred_at' => now()->subDays(14),
                    ]);

            // A STORE THAT IS FULL AND WILL NOT MOVE. The yard is being counted this week; it has
            // twelve harnesses and thirty-six pairs of gloves in it, is staffed, and refuses every
            // receipt and issue until Halima reopens it. Nothing in the procurement chapter names
            // this store, which is the only reason that chapter still runs.
            $warehouses->suspend($yard, $storesManager,
                'Annual stocktake. Nothing in or out until the count is signed off on Friday.');

            // ========================================================== the store's own chain
            /*
             * REQUEST → APPROVAL → ISSUE → LEDGER → PROJECT, which is §90 read from the other end.
             *
             * The procurement chapter shows stock arriving against an order. This is the same
             * ledger being drawn down against a job, and the two meet in `stock_transactions`: the
             * movements below carry the Delta Terminal project id, so what the tie-in front has
             * consumed is answerable from the ledger rather than from somebody's memory of which
             * requisitions were for which front.
             *
             * FOUR PEOPLE AND THREE OF THEM HAD TO BE DIFFERENT. Daniel asks, Halima decides, Sadiq
             * hands it over. `inventory.separate_approver_and_issuer` is what makes the third
             * separation compulsory here, and Kestrel switch it off ten pages down because they
             * have eight staff — same engine, different answer, no branch in the code (§73).
             */
            $consumables = $requests->create([
                'title' => 'Consumables — tie-in spool fabrication front',
                'purpose' => 'Welding consumables and gloves for the tie-in spool crew for the '
                    .'next three weeks. Harnesses are for the two men going up on the header.',
                'warehouse_id' => $siteStore->getKey(),
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'normal',
                'required_by' => now()->addDays(4)->toDateString(),
                'items' => [
                    ['stock_item_id' => $item['ELECTRODE']->getKey(), 'quantity_requested' => 40],
                    ['stock_item_id' => $item['GLOVES']->getKey(), 'quantity_requested' => 12],
                    [
                        'stock_item_id' => $item['HARNESS']->getKey(),
                        'quantity_requested' => 2,
                        'notes' => 'Site store has never held these. Yard has twelve.',
                    ],
                ],
            ], $projectManager);

            $requests->submit($consumables, $projectManager);
            $requests->approve($consumables, $storesManager, [], 'Approved as asked. The harnesses '
                .'are at Ikeja and that store is shut for the count until Friday, so those two '
                .'will not go out on this issue.');

            // TWO LINES OUT AND ONE NOT, and the status is arithmetic rather than a decision. The
            // third line is passed as zero explicitly: a storekeeper who hands over nothing on a
            // line has not refused it, and an absent key means "all of it" by design.
            $lines = $consumables->items()->orderBy('line_no')->get()->keyBy('line_no');
            $consumables = $requests->issue($consumables, $storeOfficer, [
                $lines[1]->getKey() => 40.0,
                $lines[2]->getKey() => 12.0,
                $lines[3]->getKey() => 0.0,
            ], 'Electrodes into the quiver on the front. Gloves signed for by the foreman.');

            // AND THEN IT CLOSES SHORT, which is the human act the arithmetic cannot take. The two
            // harnesses were never issued and never will be — the men came off the header — and
            // somebody has to say the store is finished with this rather than leave it outstanding
            // forever. `part_issued → closed` needs `material_request.manage` and a reason.
            $requests->close($consumables, $storesManager,
                'Closing short. The header work was re-sequenced and the two harnesses are no '
                    .'longer needed; the yard keeps them.');

            // THE COMMONEST ROW IN ANY STORE REGISTER and the one a demo full of finished work
            // never shows: submitted, sitting there, waiting for somebody to decide. It also asks
            // for something the store it names does not hold, which is the normal reason a request
            // waits — the answer is a transfer from Central, and nobody has done it yet.
            $gasketRequest = $requests->create([
                'title' => 'Gaskets and electrodes — hydrotest make-up',
                'purpose' => 'Making up the flanged joints for the hydrotest. The site store has '
                    .'no gaskets at all; Central holds sixty.',
                'warehouse_id' => $siteStore->getKey(),
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'high',
                'required_by' => now()->addDays(6)->toDateString(),
                'items' => [
                    ['stock_item_id' => $item['GASKET']->getKey(), 'quantity_requested' => 12],
                    ['stock_item_id' => $item['ELECTRODE']->getKey(), 'quantity_requested' => 25],
                ],
            ], $projectManager);

            $requests->submit($gasketRequest, $projectManager);
        });
    }

    /*
     * Kestrel's store: a spares cage, a van, and the switch that says a van is not a warehouse.
     *
     * TWO STORES FOR EIGHT PEOPLE, and the second one is the interesting half. An engineer's van is
     * a store by every definition that matters — parts are in it, they leave it, somebody has to
     * know what is there — and it is the one place where `allow_negative_stock` earns its keep. A
     * fitter changes a belt at eight in the morning and posts the paperwork at six in the evening,
     * by which time the balance has been negative all day. Refusing that movement does not make the
     * belt reappear on the shelf; it makes the fitter stop posting movements at all.
     *
     * THE DEPOT CAGE IS THE OPPOSITE and is left as it comes: no negative stock, every issue
     * against a request. Same table, same service, two answers, and the difference is one boolean
     * a company sets for itself (§73).
     *
     * SEPARATION OF DUTIES, RELAXED AND RECORDED. Meridian's store needs three people to get a box
     * of electrodes onto a site. Kestrel have one maintenance manager, and
     * `inventory.separate_approver_and_issuer` comes off — which is not the same as it never having
     * been applied: the second request below is raised, approved and issued by the same man, and
     * the activity trail says `self_approved` and `self_issued` in as many words. An auditor asking
     * the question in March gets an answer instead of a silence.
     */
    private function seedKestrelInventory(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $warehouses = app(WarehouseService::class);
            $catalogue = app(StockItemService::class);
            $stock = app(StockService::class);
            $requests = app(MaterialRequestService::class);

            // Written before anything moves, because these are the rules the chapter runs under.
            $settings->set('inventory.separate_approver_and_issuer', false, $organization->id, 'inventory');

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');

            $opsManager = $this->accountOf($staff, 'KFM-002');       // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003');  // Duncan Reilly
            $fitter = $this->accountOf($staff, 'KFM-005');           // Callum Whitfield

            $depot = $warehouses->create([
                'code' => 'STORE-LDS',
                'name' => 'Leeds Depot Spares Store',
                'type' => 'Store',
                'location_id' => $place['LDS']->id,
                'org_unit_id' => $unit['MNT']->id,
                'keeper_id' => $maintenanceHead->getKey(),
                'notes' => 'Filters, belts, bearings and lamps for the whole contract portfolio.',
            ], $maintenanceHead);

            $van = $warehouses->create([
                'code' => 'VAN-CW',
                'name' => 'Van stock — Callum Whitfield',
                'type' => 'Transit',
                'location_id' => $place['LDS']->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'keeper_id' => $fitter->getKey(),
                // THE ONE PLACE THIS IS SWITCHED ON, and a deliberate decision rather than a
                // convenience. A van that may go negative will happily issue a belt it does not
                // have and the balance then reads as a debt to itself — which is exactly right
                // here, because the belt is on a fan and the paperwork is in his pocket.
                'allow_negative_stock' => true,
                'notes' => 'Restocked from the depot on a Monday. Balances are trued up at the '
                    .'monthly van count, and the count is the record that matters.',
            ], $maintenanceHead);

            foreach ([$depot, $van] as $store) {
                $warehouses->activate($store, $maintenanceHead);
            }

            // Six lines, which is a facilities catalogue: the things that get changed on a planned
            // visit, plus the one thing that gets changed when something breaks.
            $item = [];

            $item['FILTER'] = $catalogue->create([
                'code' => 'FLT-G4-595',
                'name' => 'Panel filter, G4, 595 × 595 × 48 mm',
                'category' => 'Consumable',
                'uom' => 'ea',
                'reorder_level' => 24,
                'reorder_quantity' => 96,
                'notes' => 'Changed quarterly on every AHU in the portfolio. Ordered by the '
                    .'pallet because the planned schedule says exactly how many are needed.',
            ], $maintenanceHead);

            $item['BELT'] = $catalogue->create([
                'code' => 'BLT-SPZ-1400',
                'name' => 'V-belt, SPZ 1400',
                'category' => 'Spare part',
                'uom' => 'ea',
                'reorder_level' => 6,
                'reorder_quantity' => 12,
            ], $maintenanceHead);

            $item['BEARING'] = $catalogue->create([
                'code' => 'BRG-6205',
                'name' => 'Bearing, 6205-2RS',
                'category' => 'Spare part',
                'uom' => 'ea',
                'reorder_level' => 4,
                'reorder_quantity' => 10,
            ], $maintenanceHead);

            $item['LAMP'] = $catalogue->create([
                'code' => 'LMP-LED-T8',
                'name' => 'LED tube, T8 1500 mm, 4000 K',
                'category' => 'Electrical',
                'uom' => 'ea',
                'reorder_level' => 40,
                'reorder_quantity' => 120,
            ], $maintenanceHead);

            $item['SENSOR'] = $catalogue->create([
                'code' => 'SNS-CO2-DUCT',
                'name' => 'Duct CO₂ sensor, 0–2000 ppm, 0–10 V',
                'category' => 'Instrumentation',
                'uom' => 'ea',
                'specification' => 'Must match the existing BMS scaling. Two spares held.',
                'reorder_level' => 1,
                'reorder_quantity' => 2,
            ], $maintenanceHead);

            $item['CLEANER'] = $catalogue->create([
                'code' => 'CHM-COIL-CLN',
                'name' => 'Coil cleaner, alkaline, 5 l',
                'category' => 'Consumable',
                'uom' => 'can',
                'is_hazardous' => true,
                'storage_conditions' => 'Bunded cabinet at the depot. COSHH sheet on the inside of '
                    .'the door and a copy in every van that carries it.',
            ], $maintenanceHead);

            foreach ($item as $line) {
                $catalogue->activate($line, $maintenanceHead);
            }

            $opening = static function (Warehouse $store, StockItem $line, float $quantity, float $unitCost) use ($stock, $maintenanceHead): void {
                $stock->receive($store, $line, $quantity, $maintenanceHead, [
                    'unit_cost' => $unitCost,
                    'reason' => 'Opening balance at go-live',
                    'occurred_at' => now()->subDays(75),
                ]);
            };

            $opening($depot, $item['FILTER'], 96, 4.85);
            $opening($depot, $item['BELT'], 14, 9.40);
            $opening($depot, $item['BEARING'], 8, 6.20);
            $opening($depot, $item['LAMP'], 120, 3.15);
            $opening($depot, $item['SENSOR'], 2, 168.00);
            $opening($depot, $item['CLEANER'], 6, 22.50);

            // Monday morning: the van is loaded out of the depot. One act, two rows, one reference.
            foreach ([['FILTER', 12.0], ['BELT', 2.0], ['LAMP', 20.0], ['CLEANER', 1.0]] as [$key, $quantity]) {
                $stock->transfer($depot, $van, $item[$key], $quantity, $maintenanceHead, [
                    'org_unit_id' => $unit['MNT-MEC']->id,
                    'reason' => 'Weekly van load-out.',
                    'occurred_at' => now()->subDays(6),
                ]);
            }

            /*
             * AND HERE IS WHAT THE SWITCH IS FOR. Three belts went on this week and the van was
             * loaded with two: the third came off the depot rack on Wednesday afternoon with
             * nobody at the desk, and Callum posted all three on Friday. The van balance is now
             * minus one belt.
             *
             * THAT IS THE HONEST NUMBER. The depot is short a belt and the van owes it one, and
             * both of those are true until the month-end count says otherwise. The alternative —
             * refusing the third posting — does not put the belt back on the rack; it teaches a
             * fitter that the system is something to be worked around, and after that the ledger
             * is decoration. Meridian's stores would have thrown here, and should have.
             */
            $stock->issue($van, $item['BELT'], 3, $maintenanceHead, [
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'reason' => 'Three belt changes on the Trafford Point AHUs this week.',
                'notes' => 'Third belt came off the depot rack on Wednesday without a transfer. '
                    .'The van reads minus one until the month-end count.',
                'occurred_at' => now()->subDays(1),
            ]);

            // ============================================================ the planned change
            // The ordinary shape: the manager asks, operations decides, the manager hands it over.
            // Two different people, and nothing had to be relaxed for it.
            $filterChange = $requests->create([
                'title' => 'Quarterly filter change — Trafford Point AHUs',
                'purpose' => 'Q3 planned filter change across AHU-1 to AHU-4. Belts inspected at '
                    .'the same visit and changed where they are glazed.',
                'warehouse_id' => $depot->getKey(),
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT']->id,
                'location_id' => $place['MCR']->id,
                'priority' => 'normal',
                'required_by' => now()->addDays(9)->toDateString(),
                'items' => [
                    ['stock_item_id' => $item['FILTER']->getKey(), 'quantity_requested' => 48],
                    ['stock_item_id' => $item['BELT']->getKey(), 'quantity_requested' => 4],
                ],
            ], $maintenanceHead);

            $requests->submit($filterChange, $maintenanceHead);
            $requests->approve($filterChange, $opsManager, [], 'Planned work against the PPM '
                .'schedule and the filters are already in the depot. Go ahead.');
            $requests->issue($filterChange, $maintenanceHead, [],
                'Loaded for the Tuesday visit. Old filters back to the depot for disposal.');

            /*
             * AND THE ONE THAT COULD NOT WAIT FOR ANYBODY.
             *
             * A CO₂ sensor failed at Trafford Point at half past four and the building goes to
             * full fresh air without it. Duncan raised it, approved it and issued it inside ten
             * minutes, which is the only thing that could have happened in a company of eight.
             *
             * MERIDIAN'S COPY OF THESE THREE LINES WOULD THROW, twice. Kestrel's writes
             * `self_approved` and `self_issued` onto the trail instead — the control relaxed by a
             * setting and recorded where an auditor will find it, rather than a control that was
             * never there. That distinction is the entire argument for configuration over a branch
             * in the code per customer (§73, §59).
             */
            $sensor = $requests->create([
                'title' => 'CO₂ sensor — Trafford Point AHU-2 supply duct',
                'purpose' => 'Sensor failed open circuit at 16:30. The AHU has gone to full fresh '
                    .'air and the building will be cold by morning.',
                'warehouse_id' => $depot->getKey(),
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT']->id,
                'location_id' => $place['MCR']->id,
                'priority' => 'urgent',
                'required_by' => now()->toDateString(),
                'items' => [
                    ['stock_item_id' => $item['SENSOR']->getKey(), 'quantity_requested' => 1],
                ],
            ], $maintenanceHead);

            $requests->submit($sensor, $maintenanceHead);
            $requests->approve($sensor, $maintenanceHead, [], 'Self approved out of hours. One of '
                .'the two spares — the other stays on the shelf until this one is replaced.');
            $requests->issue($sensor, $maintenanceHead, [], 'Taken to site the same evening.');
        });
    }

    /*
     * Meridian buy things, and the chain that starts when they decide to.
     *
     * THIS IS THE CHAPTER THE EXIT CRITERION IS ABOUT. Somewhere below, one requisition becomes an
     * approved requisition, becomes an enquiry to three suppliers, becomes two quotations, becomes
     * an award, becomes an order, becomes two deliveries — and every join is made by the service
     * that makes it in production, never by typing a foreign key into a `create()`. A demo that
     * assembled the chain by hand would show the screens working while the thing they are screens
     * onto was broken.
     *
     * FIVE THREADS, DELIBERATELY AT FIVE DIFFERENT STAGES, because a procurement register in which
     * everything is finished is a register nobody recognises. Real ones are mostly middles: a
     * requisition somebody has not looked at, an enquiry whose deadline has not passed, an order
     * half delivered, a delivery in dispute. Only the first thread below runs all the way through.
     *
     * WHO DOES WHAT IS THE POINT AND NOT THE DECORATION. Daniel Mbeki asks for materials; Bisi
     * Adeleke decides they may be bought; Uche Nnamdi goes to the market and raises the order;
     * Sadiq Bello, in stores, is the one who says the lorry arrived. Four people, because
     * `SeparatesDuties` refuses to let fewer do it — and the refusal is the control working, not an
     * obstacle the demo routes around. See seedKestrelProcurement() for the company too small to
     * afford four, and what the platform does for them instead.
     */
    private function seedMeridianProcurement(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $this->bootDelivery();

            $requests = app(PurchaseRequestService::class);
            $rfqs = app(RfqService::class);
            $quotations = app(QuotationService::class);
            $orders = app(PurchaseOrderService::class);
            $receipts = app(GoodsReceiptService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');
            // The status eagerly, because Vendor::canBeUsed() reads it and the lazy-loading guard is on:
            // whether a supplier may be invited is a question about their status, and asking it once
            // per invitation off an unloaded relation is exactly what the guard exists to catch.
            $vendor = Vendor::with('status')->get()->keyBy('code');
            $contract = Contract::query()->get()->keyBy('reference');
            // The two registers the stores chapter opened, and the only reason anything below
            // reaches a shelf. A requisition line that names a catalogue item carries that item
            // through the enquiry, the order and the delivery; a receipt that names a store is what
            // turns acceptance into a ledger row. Neither is compulsory and both are here (§90).
            $catalogue = StockItem::query()->get()->keyBy('code');
            $store = Warehouse::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'MEI-0001');             // Adaeze Okonkwo
            $projectManager = $this->accountOf($staff, 'MEI-0011'); // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');    // Kelechi Nwosu
            $prcManager = $this->accountOf($staff, 'MEI-0019');     // Bisi Adeleke
            $prcOfficer = $this->accountOf($staff, 'MEI-0020');     // Uche Nnamdi
            $storeOfficer = $this->accountOf($staff, 'MEI-0021');   // Sadiq Bello

            // ============================================================ thread 1: the whole chain
            // Requisition → approval → enquiry → quotations → evaluation → award → order → issue →
            // two deliveries → received in full → closed. §90, in one supply of pipe.
            $spools = $requests->create([
                'title' => 'Tie-in spool materials — Delta Terminal',
                'justification' => 'The tie-in spools between the new tanks and the existing 12" '
                    .'header cannot be fabricated until the pipe and fittings are on site. The '
                    .'erection sequence has the first spool going in four weeks from now.',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PMO']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'high',
                'required_by' => now()->addWeeks(3)->toDateString(),
                'currency' => 'NGN',
                // ALL THREE LINES NAME A CATALOGUE ITEM, which is what makes this thread the one
                // that reaches a shelf. The reference travels: requisition line → enquiry line →
                // quotation line → order line → delivery line, and acceptance reads it back
                // through the chain to know which balance to move. Nobody retypes it anywhere.
                'items' => [
                    [
                        'stock_item_id' => $catalogue['MAT-PIPE-12-S40']?->id,
                        'description' => '12" carbon steel pipe, API 5L Gr.B, schedule 40',
                        'specification' => 'Seamless. Mill certificates to EN 10204 3.1 required '
                            .'with the delivery, not after it.',
                        'quantity' => 96,
                        'uom' => 'm',
                        'estimated_unit_price' => 46500,
                    ],
                    [
                        'stock_item_id' => $catalogue['MAT-FLG-12-150']?->id,
                        'description' => '12" weld-neck flanges, ANSI 150, raised face',
                        'specification' => 'Forged, ASTM A105. Bore to match schedule 40.',
                        'quantity' => 24,
                        'uom' => 'ea',
                        'estimated_unit_price' => 78000,
                    ],
                    [
                        'stock_item_id' => $catalogue['MAT-ELB-12-LR']?->id,
                        'description' => '12" long-radius elbows, 90°, butt weld',
                        'quantity' => 16,
                        'uom' => 'ea',
                        'estimated_unit_price' => 64000,
                    ],
                ],
                'notes' => 'Quantities include the 5% cutting allowance the piping team asked for.',
            ], $projectManager);

            $requests->submit($spools, $projectManager);
            $requests->approve($spools, $prcManager,
                'Within the tie-in package allowance. Go to the market — do not single source this.');

            // The enquiry is raised FROM the requisition, which is what carries the three lines
            // across. Nobody retypes them, so nobody can retype them wrongly.
            $enquiry = $rfqs->fromPurchaseRequest($spools, [
                'title' => 'Tie-in spool materials — 12" pipe, flanges and fittings',
                'scope' => 'Supply only, delivered to the Delta Terminal site laydown area in one '
                    .'or two consignments. Offloading is ours. Mill certificates to accompany the '
                    .'delivery note.',
                'org_unit_id' => $unit['PRC']->id,
                'closes_at' => now()->subDays(9)->setTime(16, 0),
                'terms' => "Prices to be held for 30 days.\nDelivery to site, Warri.\n"
                    ."Payment 30 days from receipt of a correct invoice.\n"
                    .'Part offers accepted; alternatives to be priced separately and marked as such.',
            ], $prcOfficer);

            // PANAFRIC ARE NOT ON THIS LIST, AND NOT BY CHOICE. They are the obvious third
            // invitation — the framework supplier for exactly these fittings — and RfqService will
            // not send it, because their prequalification lapsed on 2 July and Vendor::canBeUsed()
            // says so. The vendor register and the enquiry are the same system; this is what that
            // means in practice, and it is why the award reason further down reads as it does.
            $rfqs->invite($enquiry, $vendor['SSF'], $prcOfficer,
                'Carry line pipe stock and are already on site for the plate work.');
            $rfqs->invite($enquiry, $vendor['KIS'], $prcOfficer,
                'Asked to quote on the strength of their pipe stock list.');
            $rfqs->invite($enquiry, $vendor['CMS'], $prcOfficer,
                'Third invitation to meet our own minimum. They are logistics rather than supply, '
                    .'so this is a long shot.');

            $rfqs->issue($enquiry, $prcOfficer);

            // A DECLINE IS A RESPONSE. An enquiry that shows three invitations and two answers
            // looks like a supplier who ignored us; recording the decline is the difference between
            // a register and a list of things that did not happen.
            $declined = $enquiry->invitations()->where('vendor_id', $vendor['CMS']->id)->first();

            if ($declined !== null) {
                $rfqs->recordDecline($declined, $prcOfficer,
                    'We move materials to the jetty, we do not sell them. Happy to price the '
                        .'haulage separately if whoever wins is ex works.');
            }

            $saharaQuote = $this->quoteFor($quotations, $enquiry, $vendor['SSF'], $prcOfficer, [
                48900, 71000, 63500,
            ], [
                'reference' => 'SSF-TQ-1188',
                'quoted_on' => now()->subDays(12)->toDateString(),
                'valid_until' => now()->addDays(18)->toDateString(),
                'lead_time_days' => 12,
                'currency' => 'NGN',
                'payment_terms' => '30 days from a correct invoice, as tendered.',
                'delivery_terms' => 'Delivered to site, Warri. One consignment, possibly two.',
                'notes' => 'Mill certificates confirmed to accompany the delivery, not to follow it.',
            ]);

            $kanoQuote = $this->quoteFor($quotations, $enquiry, $vendor['KIS'], $prcOfficer, [
                46200, 74500, 61000,
            ], [
                'reference' => 'KIS/Q/26-0418',
                'quoted_on' => now()->subDays(11)->toDateString(),
                'valid_until' => now()->addDays(10)->toDateString(),
                'lead_time_days' => 21,
                'currency' => 'NGN',
                'payment_terms' => '14 days, or 30 against a proforma.',
                'delivery_terms' => 'Ex works Kano.',
                'notes' => 'Cheaper on two lines. Ex works Kano is 1,100 km from Warri and the '
                    .'haulage is ours to arrange and pay for.',
            ]);

            // TWO PEOPLE SCORE, SEPARATELY, and each writes only their own marks. The evaluation
            // screen adds them up; nobody's opinion overwrites anybody else's.
            $quotations->recordScores($saharaQuote, $prcOfficer, [
                'price' => ['score' => 7, 'comment' => 'Dearer on pipe and elbows, cheaper on flanges.'],
                'delivery' => ['score' => 10, 'comment' => 'Twelve days, delivered. Nobody else is close.'],
                'technical_compliance' => ['score' => 9, 'comment' => 'Fully compliant. Certificates with the delivery.'],
                'commercial_terms' => ['score' => 9, 'comment' => 'Took our terms as issued, delivered to site.'],
            ]);

            $quotations->recordScores($saharaQuote, $prcManager, [
                'price' => ['score' => 7],
                'delivery' => ['score' => 10, 'comment' => 'They are already on site for the plate work.'],
                'technical_compliance' => ['score' => 9],
                'commercial_terms' => ['score' => 10, 'comment' => 'Delivered to site is worth more than the price gap.'],
            ]);

            $quotations->recordScores($kanoQuote, $prcOfficer, [
                'price' => ['score' => 9, 'comment' => 'Lowest on pipe and elbows before haulage.'],
                'delivery' => ['score' => 4, 'comment' => 'Twenty-one days, and then a lorry from Kano.'],
                'technical_compliance' => ['score' => 6, 'comment' => 'Certificates offered "on request", which is not the same as with the delivery.'],
                'commercial_terms' => ['score' => 5, 'comment' => 'Ex works Kano. The haulage lands back on us and is not in the price.'],
            ]);

            $quotations->recordScores($kanoQuote, $prcManager, [
                'price' => ['score' => 8, 'comment' => 'Not lowest once the haulage from Kano is added.'],
                'delivery' => ['score' => 4],
                'technical_compliance' => ['score' => 5, 'comment' => 'No prequalification pack from them yet either.'],
                'commercial_terms' => ['score' => 5],
            ]);

            // CLOSED BY THE MANAGER, NOT THE OFFICER. Closing an enquiry for responses is
            // `rfq.manage`, which the procurement-officer template does not carry — the person who
            // ran the exercise is not the person who decides the market has finished speaking.
            $this->moveCommercial($enquiry, 'rfq', 'closed', $prcManager,
                'Deadline passed. Two responses and one decline.');
            $this->moveCommercial($enquiry, 'rfq', 'under_evaluation', $prcManager,
                'Scoring with the piping lead on technical compliance.');

            // A WRITTEN REASON, BECAUSE TWO IS NOT THREE. `procurement.minimum_quotations` is set
            // to three, only two arrived, and RfqService refuses a silent award below the company's
            // own threshold. The exception is not an obstacle here — it is the reason the sentence
            // below exists at all, and in six months it is the only record of why.
            $rfqs->award($enquiry, $saharaQuote, $prcManager,
                'Two responses against three invitations. PanAfric would have been the natural '
                    .'third and the register would not let us invite them — their prequalification '
                    .'lapsed on 2 July and it is still not back. Coastal Marine declined in '
                    .'writing and the decline is on the enquiry. Kano are lower on two lines before '
                    .'haulage from Kano to Warri, which is not in their price, and offer '
                    .'certificates on request rather than with the delivery. Sahara are nine days '
                    .'faster, delivered to site, certificates in hand, and already mobilised here.');

            // The order comes FROM the winning quotation: the prices, the currency and the lines are
            // carried across by the service, so what we commit to is what they quoted.
            $spoolOrder = $orders->fromQuotation($saharaQuote, [
                'title' => 'Tie-in spool materials — 12" pipe, flanges and fittings',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PRC']->id,
                'location_id' => $place['SITE-DT']->id,
                // NO CONTRACT. Sahara have a subcontract with us for the tank plate, and this is
                // not it: a supply order won on a tender stands on its own. Pointing it at the
                // fabrication subcontract because the same company happens to be on both would
                // quietly add ₦11m of pipe to a subcontract value nobody agreed to change.
                'ordered_on' => now()->subDays(7)->toDateString(),
                'required_by' => now()->addDays(14)->toDateString(),
                'delivery_terms' => 'Delivered to the Delta Terminal site laydown area, Warri. '
                    .'Offloading by us. Mill certificates to accompany the delivery note.',
                'payment_terms' => '30 days from receipt of a correct invoice.',
                'notes' => 'Supply only, against the tender. Separate from their plate '
                    .'fabrication subcontract and to be invoiced separately.',
            ], $prcOfficer);

            $orders->submit($spoolOrder, $prcOfficer);
            $orders->approve($spoolOrder, $prcManager, 'Competitively tendered. Award reason on the enquiry.');
            $orders->issue($spoolOrder, $prcManager);

            // ---------------------------------------------------------------- the deliveries
            // TWO OF THEM, because that is how pipe arrives. The first is short and the order goes
            // to `part_received` by arithmetic rather than by anybody deciding it did; the second
            // completes it and the order reads `received` for the same reason.
            //
            // Sadiq Bello receives, and he must: `procurement.separate_buyer_and_receiver` is on,
            // and Uche raised, submitted and would otherwise be certifying his own order.
            $firstDrop = $receipts->create([
                'purchase_order_id' => $spoolOrder->getKey(),
                'location_id' => $place['SITE-DT']->id,
                'delivery_note_ref' => 'SSF/DN/4471',
                'carrier' => 'Sahara own fleet',
                'vehicle_ref' => 'LAG-441-XA',
                // THE FIELD THAT MAKES THE CHAIN ONE CHAIN. Naming a store here is what turns
                // acceptance into stock: every accepted line carrying a catalogue item posts a
                // receipt to this warehouse's ledger, at the ORDER's price rather than the
                // requisition's estimate, against the order's project. Leave it out and the
                // delivery is still a complete record of what arrived — it simply never reaches a
                // shelf, which is the right answer for the many deliveries consumed on site the
                // same week. See GoodsReceiptService::postToStock().
                'warehouse_id' => $store['WH-DT']->id,
                'notes' => 'Pipe and elbows. Flanges to follow — they were short on the raised face.',
                'items' => $this->receiptLines($spoolOrder, [1 => 96.0, 3 => 16.0], 'Good'),
            ], $storeOfficer);

            $receipts->confirm($firstDrop, $storeOfficer);
            $receipts->inspect($firstDrop, $siteManager,
                'Pipe measured and marked off against the delivery note. Mill certificates present '
                    .'for both heat numbers. Two elbows scuffed in transit; coating only, no dents.');
            $receipts->accept($firstDrop, $projectManager, 'Accepted. Elbows to be touched up on site.');

            $secondDrop = $receipts->create([
                'purchase_order_id' => $spoolOrder->getKey(),
                'location_id' => $place['SITE-DT']->id,
                'delivery_note_ref' => 'SSF/DN/4488',
                'carrier' => 'Sahara own fleet',
                'vehicle_ref' => 'LAG-208-KJ',
                'warehouse_id' => $store['WH-DT']->id,
                'notes' => 'Balance of the flanges, six days after the pipe.',
                'items' => $this->receiptLines($spoolOrder, [2 => 24.0], 'Good'),
            ], $storeOfficer);

            $receipts->confirm($secondDrop, $storeOfficer);
            $receipts->accept($secondDrop, $projectManager, 'Count and certificates agree.');

            // The order is `received` because the arithmetic says so. Closing it is a decision, and
            // it is somebody's — the register is finished with it, and finance can see that.
            $this->moveCommercial($spoolOrder->refresh(), 'purchase_order', 'closed', $prcManager,
                'Delivered in full over two consignments. Invoice passed to finance.');

            // =========================================================== thread 2: nobody has looked
            // The commonest row in any procurement register, and the one a demo full of finished
            // work never shows: submitted, sitting there, waiting for a decision.
            $scaffold = $requests->create([
                'title' => 'Scaffold hire — tank 2 shell course 3 and above',
                'justification' => 'The hired scaffold comes off tank 1 next week and tank 2 needs '
                    .'it from the third course. Eight weeks, three lifts.',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'normal',
                'required_by' => now()->addDays(11)->toDateString(),
                'currency' => 'NGN',
                'items' => [
                    [
                        'description' => 'Scaffold hire, erected and struck, tank 2 shell',
                        'specification' => 'Three lifts to 14 m. Handover certificate and weekly '
                            .'inspection tags included. Ironclad have ceased trading, so this needs '
                            .'a new supplier.',
                        'quantity' => 8,
                        'uom' => 'week',
                        'estimated_unit_price' => 1450000,
                    ],
                ],
            ], $siteManager);

            $requests->submit($scaffold, $siteManager);

            // ============================================================ thread 3: the answer was no
            $cabin = $requests->create([
                'title' => 'Site office air conditioning replacement',
                'justification' => 'The unit in the site office has been failing since March and '
                    .'the meeting room is unusable in the afternoons.',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'low',
                'currency' => 'NGN',
                'items' => [
                    [
                        'description' => '2.5 hp split air conditioning unit, supplied and installed',
                        'quantity' => 2,
                        'uom' => 'ea',
                        'estimated_unit_price' => 690000,
                    ],
                ],
            ], $siteManager);

            $requests->submit($cabin, $siteManager);
            $requests->reject($cabin, $prcManager,
                'The cabins are on hire and the hirer is contractually responsible for the air '
                    .'conditioning. Raise it with them under the hire agreement rather than buying '
                    .'units we then leave behind. Happy to fund a temporary unit for the meeting '
                    .'room if they will not move.');

            // ========================================================= thread 4: an enquiry still open
            // Issued, deadline next week, one supplier in and one still to answer. No award, no
            // order, and nothing to close — the state an enquiry spends most of its life in.
            $coating = $requests->create([
                'title' => 'External coating system — tank 2',
                'justification' => 'Tank 1 was coated by the shell contractor under the old scope. '
                    .'Tank 2 is ours to place and the erection finishes in six weeks.',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PMO']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'normal',
                'required_by' => now()->addWeeks(6)->toDateString(),
                'currency' => 'NGN',
                'items' => [
                    [
                        'description' => 'Blast and coat tank 2 external shell and roof',
                        'specification' => 'Sa 2½ blast, three-coat epoxy system to the client '
                            .'specification. Approximately 2,400 m².',
                        'quantity' => 2400,
                        'uom' => 'm²',
                        'estimated_unit_price' => 18500,
                    ],
                ],
            ], $projectManager);

            $requests->submit($coating, $projectManager);
            $requests->approve($coating, $prcManager, 'In the budget. Three quotations please.');

            $coatingEnquiry = $rfqs->fromPurchaseRequest($coating, [
                'title' => 'External coating system — Delta Terminal tank 2',
                'scope' => 'Surface preparation and application of the three-coat epoxy system to '
                    .'the tank 2 external shell and roof, including access, containment and '
                    .'inspection hold points.',
                'org_unit_id' => $unit['PRC']->id,
                'closes_at' => now()->addDays(6)->setTime(16, 0),
                'terms' => "Prices to be held for 45 days.\nAccess and containment are the "
                    ."contractor's.\nOur QA inspector holds the blast and each coat.\n"
                    .'Payment 30 days from a correct invoice against measured work.',
            ], $prcOfficer);

            $rfqs->invite($coatingEnquiry, $vendor['AIC'], $prcOfficer,
                'Provisionally approved only. If they win, the prequalification pack has to be '
                    .'completed before an order goes out.');
            $rfqs->invite($coatingEnquiry, $vendor['SSF'], $prcOfficer, 'They have a paint shop.');

            $rfqs->issue($coatingEnquiry, $prcOfficer);

            $this->quoteFor($quotations, $coatingEnquiry, $vendor['AIC'], $prcOfficer, [17900], [
                'reference' => 'AIC/2026/Q-311',
                'quoted_on' => now()->subDays(1)->toDateString(),
                'valid_until' => now()->addDays(44)->toDateString(),
                'lead_time_days' => 30,
                'currency' => 'NGN',
                'payment_terms' => 'Monthly against measured work, 30 days.',
                'delivery_terms' => 'Site works. Access and containment included.',
                'notes' => 'First in. Sahara have asked for two more days and the deadline has not '
                    .'passed, so nothing is being compared yet.',
            ]);

            // ======================================================= thread 5: bought without a tender
            // Small, urgent, single-sourced, and the platform lets it happen while making sure it
            // cannot happen quietly: below the enquiry threshold the order still needs a decision,
            // and the approver's comment is the record of why nobody went to the market.
            $gaskets = $requests->create([
                'title' => 'Replacement gaskets — 12" ANSI 150 flanged joints',
                'justification' => 'The gaskets that came with the valve package are the wrong '
                    .'rating. Hydrotest is Thursday.',
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['CON-DT']->id,
                'location_id' => $place['SITE-DT']->id,
                'priority' => 'urgent',
                'required_by' => now()->subDays(4)->toDateString(),
                'currency' => 'NGN',
                'items' => [
                    [
                        'stock_item_id' => $catalogue['MAT-GSK-12-SW']?->id,
                        'description' => '12" spiral wound gaskets, ANSI 150, 316L/graphite',
                        'quantity' => 40,
                        'uom' => 'ea',
                        'estimated_unit_price' => 14500,
                    ],
                ],
            ], $siteManager);

            $requests->submit($gaskets, $siteManager);
            $requests->approve($gaskets, $prcManager,
                'Urgent and small. Kano hold them and can courier tomorrow — place it with them.');

            $gasketOrder = $orders->create([
                'title' => 'Replacement gaskets — hydrotest',
                'vendor_id' => $vendor['KIS']->id,
                'project_id' => $job['DTE']?->id,
                'org_unit_id' => $unit['PRC']->id,
                'location_id' => $place['SITE-DT']->id,
                'ordered_on' => now()->subDays(5)->toDateString(),
                'required_by' => now()->subDays(3)->toDateString(),
                'currency' => 'NGN',
                'payment_terms' => '14 days, as quoted.',
                'delivery_terms' => 'Overnight courier to site.',
                'items' => [
                    [
                        'description' => '12" spiral wound gaskets, ANSI 150, 316L/graphite',
                        'quantity' => 40,
                        'uom' => 'ea',
                        'unit_price' => 13800,
                    ],
                ],
                'notes' => 'No enquiry — see the approval comment. Kano have no prequalification '
                    .'pack with us yet, which the officer has been asked to chase.',
            ], $prcOfficer);

            $orders->submit($gasketOrder, $prcOfficer);
            $orders->approve($gasketOrder, $prcManager,
                'Single sourced deliberately. Forty gaskets against a hydrotest on Thursday; a '
                    .'three-quotation exercise costs more in delay than the whole order is worth. '
                    .'Kano were the only supplier holding stock and could courier the same night.');
            $orders->issue($gasketOrder, $prcManager);

            // A DISPUTE, AND THE ORDER DOES NOT READ AS SATISFIED. Half the delivery is the wrong
            // rating; the rejected quantity stays outstanding on the order line, which is the whole
            // reason `quantity_rejected` is a separate column from a note in the margin.
            $gasketDrop = $receipts->create([
                'purchase_order_id' => $gasketOrder->getKey(),
                'location_id' => $place['SITE-DT']->id,
                'delivery_note_ref' => 'KIS/DN/0212',
                'carrier' => 'Courier',
                // A STORE IS NAMED AND NOTHING REACHES IT, which is the case worth seeding. The
                // twenty-two good gaskets would post to WH-DT the moment somebody accepts this
                // delivery, and nobody has: it is `disputed` and it stays there until Kano collect
                // the eighteen wrong ones. Stock follows ACCEPTANCE, not arrival — a lorry on the
                // weighbridge is not stock, and a register that said it was would be lying to
                // whoever asks what is on site.
                'warehouse_id' => $store['WH-DT']->id,
                'notes' => 'Opened on the bench with the piping supervisor before signing.',
                'items' => [
                    [
                        'purchase_order_item_id' => $gasketOrder->items()->orderBy('line_no')->value('id'),
                        'quantity_accepted' => 22,
                        'quantity_rejected' => 18,
                        'rejection_reason' => 'Eighteen are ANSI 300 ring type, not 150 spiral '
                            .'wound. Marked up on the box and on the gasket itself.',
                        'condition' => 'Wrong item',
                    ],
                ],
            ], $storeOfficer);

            $receipts->confirm($gasketDrop, $storeOfficer);
            $receipts->dispute($gasketDrop, $prcOfficer,
                'Eighteen of forty are the wrong rating. Raised with Kano the same afternoon; '
                    .'they are sending the balance and collecting the ANSI 300 set. The order stays '
                    .'part received until they do.');
        });
    }

    /*
     * Kestrel buy things too, with eight people and no procurement department at all.
     *
     * THE SAME ENGINE, TOLD TO STOP DOING ONE THING. Meridian's four-handed chain is not a feature
     * of the software; it is a setting Meridian have switched on. Kestrel switch
     * `procurement.separate_buyer_and_receiver` OFF, because in a company of eight the maintenance
     * manager specifies the unit, orders the unit and is standing in the plant room when the lorry
     * arrives, and a platform that refused to let him sign for it would be telling him to lie about
     * who was there.
     *
     * IT IS SWITCHED OFF, NOT ABSENT. GoodsReceiptService still notices that the buyer received
     * their own order and writes `duties_waived` onto the trail, so the auditor who asks the
     * question in March gets an honest answer instead of a silence. That difference — the control
     * relaxed and recorded, rather than never applied — is the whole argument for configuration
     * over branching the code per customer.
     *
     * NO ENQUIRY EITHER. `procurement.minimum_quotations` comes down to one. They have bought the
     * same air handling unit from the same supplier twice before, the controls have to match the
     * two already on the roof, and going to the market for a third would be a ritual rather than a
     * decision. The order still needs approving, and the managing director still approves it.
     */
    private function seedKestrelProcurement(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $requests = app(PurchaseRequestService::class);
            $orders = app(PurchaseOrderService::class);
            $receipts = app(GoodsReceiptService::class);

            // Written before anything is bought, because these are the rules the chapter below
            // runs under and a setting applied afterwards would be a setting nothing obeyed.
            $settings->set('procurement.separate_buyer_and_receiver', false, $organization->id, 'procurement');
            $settings->set('procurement.minimum_quotations', 1, $organization->id, 'procurement');

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');
            // The status eagerly, because Vendor::canBeUsed() reads it and the lazy-loading guard is on:
            // whether a supplier may be invited is a question about their status, and asking it once
            // per invitation off an unloaded relation is exactly what the guard exists to catch.
            $vendor = Vendor::with('status')->get()->keyBy('code');
            $contract = Contract::query()->get()->keyBy('reference');

            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder
            $opsManager = $this->accountOf($staff, 'KFM-002');      // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003'); // Duncan Reilly
            $electrician = $this->accountOf($staff, 'KFM-006');     // Erin Doyle

            // ------------------------------------------------------------------ the AHU itself
            $ahu = $requests->create([
                'title' => 'AHU-3 replacement unit — Trafford Point',
                'justification' => 'AHU-3 has failed twice since November and the second repair '
                    .'was a bearing set we had to make up. The client has agreed to fund a '
                    .'replacement as an additional work under the PPM contract.',
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT']->id,
                'location_id' => $place['MCR']->id,
                'priority' => 'high',
                'required_by' => now()->addWeeks(2)->toDateString(),
                'currency' => 'GBP',
                'items' => [
                    [
                        'description' => 'Air handling unit, 4.2 m³/s, thermal wheel, to match AHU-1 and AHU-2',
                        'specification' => 'Same frame, same controls interface as the 2023 units. '
                            .'Delivered in sections through the goods lift — the plant room door is '
                            .'1,100 mm and this has caught people out before.',
                        'quantity' => 1,
                        'uom' => 'ea',
                        'estimated_unit_price' => 18400,
                    ],
                    [
                        'description' => 'Commissioning and controls integration',
                        'quantity' => 1,
                        'uom' => 'visit',
                        'estimated_unit_price' => 1850,
                    ],
                ],
            ], $maintenanceHead);

            $requests->submit($ahu, $maintenanceHead);
            // Two people, even here: `separate_requester_and_approver` is left ON, because
            // "somebody else says yes to the money" is the one control a company of eight can
            // still afford. It is the receiving control they cannot.
            $requests->approve($ahu, $opsManager,
                'Client has confirmed in writing that they will fund it under the PPM contract.');

            $ahuOrder = $orders->create([
                'title' => 'AHU-3 replacement — supply and commissioning',
                'vendor_id' => $vendor['NAH']->id,
                'purchase_request_id' => $ahu->getKey(),
                // NO CONTRACT ON THE ORDER, and the refusal that taught us so is worth keeping.
                // TPE/PPM/2026/03 is the contract under which the CLIENT is paying for this unit;
                // it is not a contract with Northern Air, and PurchaseOrderService checks that the
                // contract named on an order is actually with the supplier being ordered from.
                // The link that belongs here is the project — which does point at the PPM contract,
                // one hop away, where it is true.
                'project_id' => $job['TPA']?->id,
                'org_unit_id' => $unit['MNT']->id,
                'location_id' => $place['MCR']->id,
                'ordered_on' => now()->subDays(10)->toDateString(),
                'required_by' => now()->addDays(4)->toDateString(),
                'currency' => 'GBP',
                'payment_terms' => '30 days from invoice, as usual with them.',
                'delivery_terms' => 'Delivered in sections to the fourth floor plant room. '
                    .'Booking through the building manager, weekday mornings only.',
                'items' => [
                    [
                        'description' => 'Air handling unit, 4.2 m³/s, thermal wheel, to match AHU-1 and AHU-2',
                        'specification' => 'Sectional delivery. 1,100 mm maximum section width.',
                        'quantity' => 1,
                        'uom' => 'ea',
                        'unit_price' => 18150,
                    ],
                    [
                        'description' => 'Commissioning and controls integration',
                        'quantity' => 1,
                        'uom' => 'visit',
                        'unit_price' => 1850,
                    ],
                ],
                'notes' => 'Third unit from them and the same specification as the other two. No '
                    .'enquiry — the controls have to match what is already on the roof.',
            ], $maintenanceHead);

            $orders->submit($ahuOrder, $maintenanceHead);
            $orders->approve($ahuOrder, $md,
                'Agreed. Same unit as the other two, price is £250 under the requisition estimate, '
                    .'and the client is paying. No enquiry needed for a matched replacement.');
            $orders->issue($ahuOrder, $maintenanceHead);

            // AND HERE IS THE WHOLE POINT. Duncan raised this order, submitted it and issued it,
            // and Duncan is the one signing for the lorry. Meridian's copy of this line would throw.
            // Kestrel's writes a `duties_waived` entry onto the delivery instead, which is the
            // honest record of a small company doing the only thing it can.
            $ahuDrop = $receipts->create([
                'purchase_order_id' => $ahuOrder->getKey(),
                'location_id' => $place['MCR']->id,
                'delivery_note_ref' => 'NAH-DN-40188',
                'carrier' => "Supplier's own vehicle",
                // NO STORE, AND NO CATALOGUE ITEM ON THE REQUISITION EITHER — both deliberate, and
                // between them the reason this delivery reaches no shelf. An air handling unit is
                // craned onto a roof and installed; it is never counted, never issued and never
                // held, and cataloguing it so that a balance could read "1" would be bookkeeping
                // for its own sake. Kestrel's spares cage is three pages up and takes filters,
                // belts and bearings. §90 asks that the parts of the system connect, not that
                // every record touch every register.
                'notes' => 'Unit only. Commissioning is booked for the week after the install.',
                'items' => $this->receiptLines($ahuOrder, [1 => 1.0], 'Good'),
            ], $maintenanceHead);

            $receipts->confirm($ahuDrop, $maintenanceHead);
            $receipts->inspect($ahuDrop, $electrician,
                'Six sections, all through the goods lift without stripping the frame. Serial and '
                    .'controls card checked against the order. Isolator and cable route already in '
                    .'from the survey, so nothing to change on the electrical side.');
            $receipts->accept($ahuDrop, $opsManager,
                'Accepted. Commissioning visit still outstanding, so the order stays open.');
        });
    }

    // ================================================================ procurement helpers

    /**
     * A quotation priced line for line against the enquiry it answers.
     *
     * THE LINES COME FROM THE ENQUIRY, not from the caller, which is what makes a comparison
     * possible at all: two suppliers answering the same three lines can be laid side by side, and
     * two suppliers who each typed their own description cannot. The caller supplies prices in
     * line order and nothing else, so a demo quotation cannot silently answer a different question
     * from the one that was asked.
     *
     * @param  array<int,float>  $unitPrices  in line order, one per line on the enquiry
     * @param  array<string,mixed>  $attributes
     */
    private function quoteFor(
        QuotationService $quotations,
        Rfq $rfq,
        Vendor $vendor,
        User $actor,
        array $unitPrices,
        array $attributes = [],
    ): Quotation {
        $items = $rfq->items()->orderBy('line_no')->get()->values()->map(fn ($item, $index) => [
            'rfq_item_id' => $item->getKey(),
            'description' => $item->description,
            'quantity' => $item->quantity,
            'uom' => $item->uom,
            'unit_price' => $unitPrices[$index] ?? end($unitPrices),
        ])->all();

        return $quotations->create([
            'rfq_id' => $rfq->getKey(),
            'vendor_id' => $vendor->getKey(),
            'items' => $items,
            ...$attributes,
        ], $actor);
    }

    /**
     * Delivery lines for named lines of an order, by the order's own line numbers.
     *
     * KEYED BY LINE NUMBER RATHER THAN BY POSITION, because a short delivery is the normal case and
     * "line 1 and line 3 came, line 2 follows" is how the storekeeper reads the delivery note. A
     * line the caller does not name simply is not on this receipt, which is exactly what an
     * outstanding quantity means.
     *
     * @param  array<int,float>  $quantities  order line_no => quantity accepted
     * @return array<int,array<string,mixed>>
     */
    private function receiptLines(PurchaseOrder $order, array $quantities, ?string $condition = null): array
    {
        return $order->items()
            ->whereIn('line_no', array_keys($quantities))
            ->orderBy('line_no')
            ->get()
            ->map(fn ($item) => [
                'purchase_order_item_id' => $item->getKey(),
                'quantity_accepted' => $quantities[(int) $item->line_no],
                'condition' => $condition,
            ])
            ->all();
    }

    // ================================================================= commercial helpers

    /**
     * Move a commercial record along its own graph, as somebody entitled to.
     *
     * Thin, but not pointless: `StatusMachine::transition()` takes a Status rather than a key, and
     * the two chapters above make roughly forty of these moves. Without it every line would carry
     * a `statusFor()` call and the narrative would disappear into plumbing.
     */
    private function moveCommercial(Model $record, string $entityType, string $key, User $actor, ?string $why = null): void
    {
        $this->statuses->transition($record, $this->statusFor($entityType, $key), $actor, $why);
    }

    /**
     * Point a project at the client and the contract it was always about.
     *
     * THE TYPED NAMES ARE LEFT EXACTLY AS THEY WERE. `client_name` and `contract_reference` are
     * what somebody wrote when the job was opened, and overwriting them with the register's
     * spelling would destroy the only evidence of what was actually agreed at the time. The link
     * goes beside them — the same rule the party morph follows everywhere else in the application.
     *
     * Written with saveQuietly() rather than through ProjectService on purpose. These two columns
     * are not on any form; they are set by picking a client on the project screen. Routing a
     * back-fill through the update path would file a "project updated" entry on every job in the
     * demo for a change nobody made today, which is precisely the kind of noise that teaches people
     * to stop reading timelines.
     */
    private function linkProjectCommercially(?Project $project, Client $client, Contract $contract, User $actor): void
    {
        if ($project === null) {
            return;
        }

        $project->client_id = $client->id;
        $project->contract_id = $contract->id;
        $project->saveQuietly();

        app(ActivityRecorder::class)->record(
            $project,
            'linked',
            $actor->name.' linked '.$project->name.' to '.$client->name.' and contract '
                .($contract->reference ?? $contract->number),
            ['client_id' => $client->id, 'contract_id' => $contract->id],
            $actor,
        );
    }

    /**
     * Point the correspondence at the register it has been naming in free text all along.
     *
     * THE MATCH IS ON THE NAME SOMEBODY TYPED, which is the honest way to do this: it is precisely
     * the join a real company has to make by hand when it adopts a client register after two years
     * of correspondence, and matching on anything cleverer would demonstrate a migration the
     * application does not actually offer.
     *
     * ANYTHING THAT DOES NOT MATCH IS LEFT ALONE and stays free text — the regulators, the one-off
     * consultants, the joint-venture partners who are on no register and never will be. A back-fill
     * that invented a client record for each of them would be the "Miscellaneous client"
     * anti-pattern, arrived at by script.
     */
    private function linkCorrespondence(): void
    {
        $parties = Client::query()->get()
            ->map(fn (Client $c) => ['client', $c->id, $c->name])
            ->concat(Vendor::query()->get()->map(fn (Vendor $v) => ['vendor', $v->id, $v->name]));

        // COLUMN PREFIXES, NOT RELATION NAMES. Transmittals address `to_party_type`/`to_party_id`
        // through a relation called `toParty`, and reaching for the relation name here builds a
        // `whereNull("toParty_id")` that SQLite quietly reads as a string literal rather than an
        // unknown column — no error, no matches, and a back-fill that appears to have run.
        $registers = [
            [Rfi::class, 'party', 'party_name'],
            [Submittal::class, 'party', 'party_name'],
            [Transmittal::class, 'to_party', 'to_name'],
        ];

        foreach ($registers as [$class, $prefix, $column]) {
            foreach ($class::query()->whereNull($prefix.'_id')->get() as $record) {
                $match = $parties->first(
                    fn (array $party) => $party[2] === (string) $record->getAttribute($column),
                );

                if ($match === null) {
                    continue;
                }

                $record->setAttribute($prefix.'_type', $match[0]);
                $record->setAttribute($prefix.'_id', $match[1]);
                $record->saveQuietly();
            }
        }
    }

    // ================================================================= HSE configuration helpers

    /**
     * Add a permit type, the way the configuration screen adds one.
     *
     * PERMIT TYPES AND TRAINING COURSES HAVE NO SERVICE CLASS, and that is not an oversight: they
     * are configuration, not records. Nothing numbers them, nothing routes them for approval and
     * nothing moves them through a status graph, so a RegisterService subclass would be four
     * abstract methods returning nothing. Their controllers construct the model, stamp the tenant
     * and record the activity, which is exactly what these two helpers do — if that ever stops being
     * true the demo will drift from the application, so keep them next to each other.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function permitType(User $actor, array $attributes): PermitType
    {
        $type = new PermitType($attributes);
        $type->organization_id = $actor->organization_id;
        $type->save();

        app(ActivityRecorder::class)->record(
            $type,
            'created',
            $actor->name.' added the '.$type->displayName().' permit type.',
            ['precautions' => count($type->defaultPrecautions())],
            $actor,
        );

        return $type;
    }

    /**
     * Add a training course. See permitType() above for why this is a helper and not a service.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function trainingCourse(User $actor, array $attributes): TrainingCourse
    {
        $course = new TrainingCourse($attributes);
        $course->organization_id = $actor->organization_id;
        $course->save();

        app(ActivityRecorder::class)->record(
            $course,
            'created',
            $actor->name.' added the '.$course->displayName().' course.',
            ['validity_months' => $course->validity_months],
            $actor,
        );

        return $course;
    }

    // ----------------------------------------------------------------- document helpers

    /**
     * Resolve the document services for the tenant currently in context.
     *
     * Same reasoning as bootDelivery(): every one of these memoizes something tenant-shaped — the
     * numbering pattern, the revision scheme, the configured status vocabulary — and a single
     * instance shared between the two companies would number Kestrel's documents out of Meridian's
     * sequence.
     */
    private function bootDocuments(): void
    {
        $this->documents = app(DocumentService::class);
        $this->revisions = app(DocumentVersionService::class);
        $this->transmittals = app(TransmittalService::class);
        $this->templates = app(TemplateService::class);
        $this->routes = app(WorkflowDefinitionService::class);
        $this->approvals = app(WorkflowEngine::class);
        $this->pdfs = app(PdfRenderer::class);
    }

    /** @return array<string,DocumentCategory> the tenant's registers, by code */
    private function registersByCode(): array
    {
        return DocumentCategory::query()->orderBy('sort')->get()->keyBy('code')->all();
    }

    /** @param array<string,mixed> $attributes */
    private function raise(User $actor, array $attributes): Document
    {
        return $this->documents->create($attributes, $actor);
    }

    /**
     * Write a native revision.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function draftOn(Document $document, User $actor, array $attributes): DocumentVersion
    {
        return $this->revisions->createNative($document, $attributes, $actor);
    }

    /**
     * Attach a real file to a document, through the real upload path.
     *
     * The bytes are a genuine PDF rendered by the same renderer the export button uses, wrapped in
     * an UploadedFile and pushed through FileVault exactly as a browser upload would be — extension
     * whitelist, MIME sniffing, size limit and all (§67). Inserting a row with a made-up
     * `file_path` would have been three lines shorter and would have seeded a document whose
     * download button 404s.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function uploadRevision(Document $document, User $actor, array $attributes): DocumentVersion
    {
        // The vault reads the category to decide which extensions are acceptable here, and lazy
        // loading is barred outside production — so it is loaded rather than reached for.
        $document->loadMissing('category');

        $bytes = $this->pdfs->renderHtml(
            $document->title,
            '<h1>'.e($document->title).'</h1>'
            .'<p>'.e((string) $document->description).'</p>'
            .'<p><em>Demonstration file. A real register would hold the drawing or the scanned '
            .'letter itself; what matters here is that the bytes are on the private disk and the '
            .'download route has to authorize before it will hand them over.</em></p>',
        );

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'demo-'.Str::random(16).'.pdf';
        file_put_contents($path, $bytes);

        try {
            $upload = new UploadedFile(
                $path,
                Str::slug($document->title).'.pdf',
                'application/pdf',
                null,
                true,   // test mode: the file is already where it is, not a real POST
            );

            return $this->revisions->createFromUpload($document, $upload, $attributes, $actor);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Act on whatever stage the record is currently sitting at.
     *
     * Reads the pending stage instance back out of the database rather than remembering one, because
     * approving stage one ENTERS stage two: the id changes underneath, and a seeder holding the old
     * model would act on a stage that has already closed.
     *
     * TYPED TO `Model`, NOT `Document`. It was a document method until phase 4 gave incidents a
     * route of their own, and the only thing it ever used was `HasWorkflow` — which is the whole
     * argument for Engine 2 in one signature: the seeder approves an incident with the same call
     * it approves a drawing register submission with, because the engine never knew which it had.
     */
    private function decide(Model $record, User $actor, Decision $decision, string $comment): void
    {
        $instance = $record->currentWorkflowInstance();

        if ($instance === null) {
            throw new \RuntimeException(
                'The demo expected '.$record->number.' to be out for approval, and it is not.'
            );
        }

        $stage = WorkflowStageInstance::query()->whereKey($instance->current_stage_instance_id)->first();

        if ($stage === null) {
            throw new \RuntimeException(
                'The route on '.$record->number.' has stalled: '
                .($instance->outcome_reason ?? 'no stage is current.')
            );
        }

        $this->approvals->act($stage, $actor, $decision, $comment);
    }

    /**
     * Push a pending stage instance into the past so it is genuinely late.
     *
     * `due_at` alone would be enough for the overdue filter, but `entered_at` is what the inbox
     * shows as "waiting since" and what the escalation sweep measures from, so both move together.
     * A record that claims to be four days late and arrived a second ago is the kind of detail that
     * makes an evaluator stop trusting everything else on the screen.
     */
    private function overdueSince(Document $document, Carbon $entered): void
    {
        $instance = $document->currentWorkflowInstance();

        if ($instance?->current_stage_instance_id === null) {
            return;
        }

        $stage = WorkflowStageInstance::query()->whereKey($instance->current_stage_instance_id)->first();

        if ($stage === null) {
            return;
        }

        // The SLA the stage was given, preserved: moving the entry backwards must not silently
        // rewrite how long the company said this step is allowed to take.
        $sla = ($stage->entered_at !== null && $stage->due_at !== null)
            ? (int) round($stage->entered_at->diffInMinutes($stage->due_at))
            : 24 * 60;

        DB::table('workflow_stage_instances')->where('id', $stage->getKey())->update([
            'entered_at' => $entered,
            'due_at' => $entered->copy()->addMinutes($sla),
            'created_at' => $entered,
        ]);

        DB::table('workflow_instances')->where('id', $instance->getKey())->update([
            'created_at' => $entered,
            'started_at' => $entered,
        ]);
    }

    /** The approved, current revision — loudly, if the approval never landed. */
    private function currentRevision(Document $document): DocumentVersion
    {
        $version = DocumentVersion::query()->whereKey($document->refresh()->current_version_id)->first();

        if ($version === null) {
            throw new \RuntimeException(
                'The demo expected '.$document->number.' to have a current revision by now.'
            );
        }

        return $version;
    }

    // ----------------------------------------------------------------- delivery helpers

    /**
     * Resolve the delivery services for the tenant currently in context.
     *
     * See the property declarations for why these are resolved per tenant rather than injected.
     */
    private function bootDelivery(): void
    {
        $this->projects = app(ProjectService::class);
        $this->teams = app(ProjectTeamService::class);
        $this->tasks = app(TaskService::class);
        $this->checklists = app(TaskChecklistService::class);
        $this->dependencies = app(TaskDependencyService::class);
        $this->memos = app(MemoService::class);
        $this->notices = app(AnnouncementService::class);
        $this->meetings = app(MeetingService::class);
        $this->statuses = app(StatusMachine::class);

        $this->projectTypes = ProjectType::query()->get()->keyBy('slug')->all();
        $this->projectRoles = ProjectRole::query()->get()->keyBy('slug')->all();
    }

    /**
     * The people of the tenant in context, by employee number.
     *
     * Employee numbers rather than names, because a demo that reads `$staff['Ngozi Eze']` breaks
     * the day somebody fixes a spelling, and because the numbers are what the structure seeder
     * above already treats as the identifier.
     *
     * @return array<string,Employee>
     */
    private function staff(): array
    {
        // `user.organization` and not merely `user`: every policy check below reads the tenant's
        // permissions_version off the account to key its cache, and these people were persisted by
        // an earlier pass, so they are not exempt from the lazy-loading guard the way freshly
        // created models are. Left unloaded it fails here rather than in production — which is the
        // guard doing its job, not an obstacle to route around.
        return Employee::with('user.organization')->orderBy('id')->get()->keyBy('employee_no')->all();
    }

    /** @return array<string,OrgUnit> */
    private function unitsByCode(): array
    {
        return OrgUnit::query()->orderBy('id')->get()->keyBy('code')->all();
    }

    /** @return array<string,Location> */
    private function locationsByCode(): array
    {
        return Location::query()->orderBy('id')->get()->keyBy('code')->all();
    }

    /**
     * The account of one employee — loudly, if they are not there.
     *
     * A missing person here would otherwise arrive as a null assignee three screens later and look
     * like an unassigned task, which is a legitimate state and therefore an excellent place for a
     * typo to hide.
     *
     * @param  array<string,Employee>  $staff
     */
    private function accountOf(array $staff, string $employeeNo): User
    {
        $user = ($staff[$employeeNo] ?? null)?->user;

        if ($user === null) {
            throw new \RuntimeException('The demo expects an account for employee '.$employeeNo.'.');
        }

        return $user;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<int,array{0:string,1:User,2?:string}>  $moves  status key, who moved it, why
     */
    private function project(User $actor, array $attributes, array $moves = []): Project
    {
        $project = $this->projects->create($attributes, $actor);

        foreach ($moves as $move) {
            $project = $this->projects->transition(
                $project,
                $this->statusFor('project', $move[0]),
                $move[1],
                $move[2] ?? null,
            );
        }

        return $project;
    }

    /**
     * @param  array<int,array{0:User,1:string,2:?OrgUnit,3:string,4:int}>  $rows
     */
    private function team(Project $project, User $actor, array $rows, ?string $endsOn = null): void
    {
        foreach ($rows as [$member, $roleSlug, $orgUnit, $responsibilities, $allocation]) {
            $this->teams->addMember($project, [
                'user_id' => $member->id,
                'project_role_id' => ($this->projectRoles[$roleSlug] ?? null)?->id,
                'org_unit_id' => $orgUnit?->id,
                'responsibilities' => $responsibilities,
                'allocation_percent' => $allocation,
                'starts_on' => $project->planned_start,
                'ends_on' => $endsOn,
            ], $actor);
        }
    }

    /**
     * @param  array<int,array{0:OrgUnit,1:?Employee,2:string}>  $rows
     */
    private function departments(Project $project, User $actor, array $rows): void
    {
        foreach ($rows as [$orgUnit, $lead, $responsibilities]) {
            $this->teams->attachDepartment($project, [
                'org_unit_id' => $orgUnit->id,
                'lead_employee_id' => $lead?->id,
                'responsibilities' => $responsibilities,
            ], $actor);
        }
    }

    /**
     * Raise a task, give it a checklist, and walk it to where it has actually got to.
     *
     * The assignee is a parameter rather than an attribute because assignment is a grant: writing
     * `assignee_id` hands that account sight of the record, and burying it in an array of fields
     * would read here exactly as it must never read in a controller.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,array{0:string,1:bool}>  $checklist  title, and whether it is ticked
     * @param  array<int,array{0:string,1:User,2?:string}>  $moves  status key, who moved it, why
     */
    private function task(
        User $raiser,
        ?User $assignee,
        array $attributes,
        array $checklist = [],
        array $moves = [],
    ): Task {
        $attributes['assignee_id'] = $assignee?->id;

        $task = $this->tasks->create($attributes, $raiser);

        foreach ($checklist as [$title, $ticked]) {
            $item = $this->checklists->add($task, ['title' => $title], $raiser);

            if ($ticked) {
                // Ticked by whoever is doing the work, not by whoever wrote the list. On an
                // inspection checklist that stamp is the record.
                $this->checklists->toggle($item, true, $assignee ?? $raiser);
            }
        }

        foreach ($moves as $move) {
            $task = $this->tasks->transition(
                $task,
                $this->statusFor('task', $move[0]),
                $move[1],
                $move[2] ?? null,
            );
        }

        return $task;
    }

    private function statusFor(string $entityType, string $key): Status
    {
        $status = $this->statuses->statusesFor($entityType)->firstWhere('key', $key);

        if ($status === null) {
            throw new \RuntimeException(sprintf('No “%s” status is defined for %s.', $key, $entityType));
        }

        return $status;
    }

    /**
     * Spread the delivery timeline over the weeks leading up to now.
     *
     * Everything above was written in one pass, so every activity row carries the same second, and
     * a timeline where forty things happened at once is worse than no timeline. The rows are
     * restamped in id order, which is the order they were recorded and therefore the order the work
     * actually happened in, and each project and task is aged to its own first entry so the record
     * and its history agree.
     *
     * Only `activity_logs` is touched — the human narrative, which this seeder already writes
     * directly. `audit_logs` is left exactly as the engine wrote it (§59).
     */
    private function spreadDeliveryHistory(Organization $organization, int $overDays): void
    {
        $rows = DB::table('activity_logs')
            ->where('organization_id', $organization->id)
            ->whereIn('subject_type', ['project', 'project_department', 'project_member', 'task'])
            ->orderBy('id')
            ->get(['id', 'subject_type', 'subject_id']);

        if ($rows->isEmpty()) {
            return;
        }

        // Anchored at both ends rather than at the start alone. Counting a fixed number of minutes
        // forward from 08:30 on the first morning lands the last entry at 05:30 TODAY whatever the
        // clock says — which is the future, and therefore a lie, for anybody seeding before
        // breakfast. The end is read off the clock instead, a few hours short of now.
        $start = now()->subDays($overDays)->setTime(8, 30);
        $end = now()->subHours(3);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addHour();
        }

        $span = max(60, (int) round($start->diffInMinutes($end)));
        $last = max(1, $rows->count() - 1);

        /** @var array<string,array<int,\DateTimeInterface>> $opened */
        $opened = [];

        foreach ($rows->values() as $index => $row) {
            $at = $start->copy()->addMinutes((int) round($span * $index / $last));

            DB::table('activity_logs')->where('id', $row->id)->update(['created_at' => $at]);

            $opened[$row->subject_type][$row->subject_id] ??= $at;
        }

        foreach (['project' => 'projects', 'task' => 'tasks'] as $subjectType => $table) {
            foreach ($opened[$subjectType] ?? [] as $id => $at) {
                DB::table($table)->where('id', $id)->update(['created_at' => $at]);
            }
        }
    }

    // ================================================================= assets, plant and fleet

    /**
     * Meridian's plant, its lorries, and the paperwork that keeps them on the road.
     *
     * WHY THIS CHAPTER LOOKS LIKE A YARD RATHER THAN A TABLE. Four things are registered and every
     * one of them is somewhere, in somebody's hands, for a reason that is written down: a generator
     * that left the Ikorodu yard for Delta Terminal, a tipper and a pickup that belong to Fleet &
     * Logistics, and a thickness gauge that belongs to the inspector who signs the readings taken
     * with it. Custody is written through `AssetService::transferCustody()` every single time, so
     * the four custody columns on `assets` are the cache of a trail rather than four fields
     * somebody typed and can retype.
     *
     * THE GENERATOR IS ON THE FLEET REGISTER, and it is the whole reason `odometer_unit` exists. A
     * 250 kVA set is metered in RUNNING HOURS and serviced every 250 of them; put it on a register
     * that assumes kilometres and the planner is told it needs a service every 250 km. Its "plate"
     * is the number stencilled on its side, which is what a forty-character registration column is
     * for on a machine that has no plate.
     *
     * MERIDIAN REQUIRES A SECOND SIGNATURE ON EVERY HANDOVER, and requires the odometer on every
     * fill. Kestrel, one chapter down, requires neither. Same code, two companies (§73).
     *
     * ONE CERTIFICATE HAS ALREADY LAPSED. The tipper's roadworthiness expired eleven days ago and
     * the vehicle's own page says so in red, because a fleet register whose every row is green
     * teaches nothing about the one screen a transport manager actually opens on a Monday.
     */
    private function seedMeridianAssets(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $assets = app(AssetService::class);
            $fleet = app(FleetService::class);
            $jobs = app(AssetMaintenanceService::class);
            $papers = app(VehicleDocumentService::class);

            // Written before anything is registered, because these are the rules the chapter runs
            // under. A control applied afterwards is a control nothing obeyed.
            $settings->set('asset.require_authoriser_on_transfer', true, $organization->id, 'assets');
            $settings->set('asset.maintenance_warning_days', 30, $organization->id, 'assets');
            $settings->set('fuel_log.odometer_required', true, $organization->id, 'assets');
            $settings->set('vehicle_document.expiry_warning_days', 60, $organization->id, 'assets');

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');

            $opsDirector = $this->accountOf($staff, 'MEI-0003');    // Chinedu Balogun
            $projectManager = $this->accountOf($staff, 'MEI-0011'); // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');    // Kelechi Nwosu
            $qaManager = $this->accountOf($staff, 'MEI-0015');      // Yemi Bakare
            $qaInspector = $this->accountOf($staff, 'MEI-0016');    // Ruth Okafor
            $fleetManager = $this->accountOf($staff, 'MEI-0022');   // Tunde Ogunleye
            $driver = $this->accountOf($staff, 'MEI-0023');         // Musa Garba

            // ------------------------------------------------------------------ the generator
            $generator = $this->asset($assets, [
                'code' => 'GEN-02',
                'name' => '250 kVA diesel generator',
                'description' => 'Site power for the tank farm compound. Runs the welding bay, the '
                    .'site offices and the compound lighting when the grid supply drops, which at '
                    .'Delta Terminal is most afternoons.',
                'category' => 'Generator',
                'manufacturer' => 'Perkins',
                'model' => '1506A-E88TAG5',
                'serial_number' => 'PK1506A-44821',
                'tag_number' => 'MEI-PLT-0002',
                'acquired_on' => now()->subYears(3)->subMonths(2)->toDateString(),
                'purchase_cost' => 18500000,
                'notes' => 'Serviced every 250 running hours. The hour meter, not the calendar, is '
                    .'what the service interval is counted in.',
            ], $fleetManager);

            // Two moves rather than one, because a trail of a single row proves nothing: the first
            // says the set came onto the yard's books, the second says it went out to a job. Both
            // name an authoriser, because this company requires one — see the setting above.
            $assets->transferCustody($generator, [
                'custodian_id' => $fleetManager->getKey(),
                'org_unit_id' => $unit['FLT']->id,
                'project_id' => null,
                'location_id' => $place['YARD-IK']->id,
            ], $fleetManager, 'Taken onto the yard register after the last hire returned it', 'good',
                null, $opsDirector->getKey());

            $assets->transferCustody($generator, [
                'custodian_id' => $siteManager->getKey(),
                'org_unit_id' => $unit['CON-DT']->id,
                'project_id' => $job['DTE']?->id,
                'location_id' => $place['SITE-DT']->id,
            ], $fleetManager,
                'Mobilised to Delta Terminal for the tank farm compound',
                'good',
                'Low-loader on the Thursday. Fuel bowser follows on the Friday; site to draw from '
                    .'it and log every fill against the set rather than against the truck.',
                $opsDirector->getKey());

            // A generator on the fleet register. See the class docblock — the unit is hours.
            $generatorPlant = $fleet->attachVehicle($generator, [
                'registration_number' => 'GEN-02',
                'vehicle_type' => 'Generator',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2022,
                'tank_capacity' => 400,
                'odometer_unit' => 'hours',
                'current_odometer' => 1900,
            ], $fleetManager);

            $fleet->logFuel($generatorPlant, [
                'filled_at' => now()->subDays(25)->setTime(7, 20),
                'litres' => 900,
                'odometer' => 1940,
                'total_cost' => 1233000,
                'station' => 'Site bowser, Delta Terminal',
                'receipt_reference' => 'BWR-0441',
                'project_id' => $job['DTE']?->id,
            ], $siteManager);

            $fleet->logFuel($generatorPlant, [
                'filled_at' => now()->subDays(5)->setTime(6, 55),
                'litres' => 880,
                'odometer' => 1962,
                'total_cost' => 1214400,
                'station' => 'Site bowser, Delta Terminal',
                'receipt_reference' => 'BWR-0468',
                'project_id' => $job['DTE']?->id,
                'notes' => 'Twenty-two hours on the meter since the last fill. Forty litres an hour '
                    .'is about right for the load it is carrying.',
            ], $siteManager);

            // ------------------------------------------------------------------ the tipper
            $tipper = $this->asset($assets, [
                'code' => 'TRK-01',
                'name' => 'Tipper truck, 15 m³',
                'category' => 'Vehicle',
                'manufacturer' => 'Howo',
                'model' => 'ZZ3257N3847A',
                'serial_number' => 'LZZ5ELND9KA284471',
                'tag_number' => 'MEI-VEH-0001',
                'acquired_on' => now()->subYears(4)->toDateString(),
                'purchase_cost' => 34200000,
            ], $fleetManager);

            $assets->transferCustody($tipper, [
                'custodian_id' => $fleetManager->getKey(),
                'org_unit_id' => $unit['FLT']->id,
                'project_id' => null,
                'location_id' => $place['YARD-IK']->id,
            ], $fleetManager, 'Yard fleet', 'fair', null, $opsDirector->getKey());

            $tipperVehicle = $fleet->attachVehicle($tipper, [
                'registration_number' => 'LSR 482 XA',
                'vin' => 'LZZ5ELND9KA284471',
                'vehicle_type' => 'Tipper truck',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2019,
                'colour' => 'White',
                'tank_capacity' => 300,
                'current_odometer' => 213100,
            ], $fleetManager);

            // The driver, not the custodian. Musa drives it; Tunde answers for it. Conflating the
            // two would mean a lorry parked for a fortnight had nobody responsible for it.
            $fleet->assignDriver($tipperVehicle, $driver->getKey(), $fleetManager);

            foreach ([
                [40, 213400, 185.0, 259000.0, 'NNPC Ikorodu', 'FR-88214'],
                [22, 213950, 178.0, 251980.0, 'NNPC Ikorodu', 'FR-88907'],
                [6, 214500, 180.0, 257400.0, 'Ardova, Sagamu Road', 'AR-12066'],
            ] as [$daysAgo, $odometer, $litres, $total, $station, $receipt]) {
                $fleet->logFuel($tipperVehicle, [
                    'filled_at' => now()->subDays($daysAgo)->setTime(6, 40),
                    'litres' => $litres,
                    'odometer' => $odometer,
                    'total_cost' => $total,
                    'station' => $station,
                    'receipt_reference' => $receipt,
                ], $driver);
            }

            // Three certificates and three different states, which is the only way the register's
            // tabs mean anything: one lapsed, one inside the warning window, one that never expires.
            $papers->create($tipperVehicle, [
                'document_type' => 'Roadworthiness certificate',
                'document_number' => 'RWC/LA/2025/338204',
                'issuer' => 'Lagos State Vehicle Inspection Service',
                'issued_on' => now()->subDays(376)->toDateString(),
                'expires_on' => now()->subDays(11)->toDateString(),
                'cost' => 27500,
                'notes' => 'Lapsed. Booked in for re-test; the lorry is off the road until it has '
                    .'a fresh certificate in the cab.',
            ], $fleetManager);

            $papers->create($tipperVehicle, [
                'document_type' => 'Insurance',
                'document_number' => 'LWA/CV/0092841',
                'issuer' => 'Leadway Assurance',
                'issued_on' => now()->subDays(341)->toDateString(),
                'expires_on' => now()->addDays(24)->toDateString(),
                'cost' => 412000,
                'notes' => 'Comprehensive. Renewal quote requested from the broker.',
            ], $fleetManager);

            $papers->create($tipperVehicle, [
                'document_type' => 'Proof of ownership',
                'document_number' => 'POC/LA/2019/118763',
                'issuer' => 'Lagos State Motor Vehicle Administration Agency',
                'issued_on' => now()->subYears(4)->toDateString(),
                // NO EXPIRY, and that is a fact rather than a gap. The register prints "Never
                // expires" in words for exactly this row.
                'expires_on' => null,
            ], $fleetManager);

            // A service that has been done, and one that has not. The completed card carries what
            // it cost and how long the lorry was off the road; the open one is what the planner is
            // looking at.
            $service = $jobs->create([
                'asset_id' => $tipper->getKey(),
                'title' => '20,000 km service — tipper LSR 482 XA',
                'description' => 'Engine and gearbox oil, all filters, brake adjustment, tipping '
                    .'ram seals checked.',
                'is_planned' => true,
                'maintenance_type' => 'Routine service',
                'due_on' => now()->subDays(2)->toDateString(),
                'due_odometer' => 214000,
                'performed_by' => $fleetManager->getKey(),
            ], $fleetManager);

            $jobs->start($service, $fleetManager, 214780);
            $jobs->complete($service, [
                'labour_cost' => 45000,
                'parts_cost' => 128500,
                'downtime_hours' => 6.5,
                'odometer' => 214780,
                'findings' => 'Nearside rear brake chamber weeping and replaced. Tipping ram seals '
                    .'still serviceable but marked for the next service. Two tyres on the drive '
                    .'axle down to 4 mm — quoted separately.',
            ], $fleetManager);

            $jobs->create([
                'asset_id' => $tipper->getKey(),
                'title' => 'Drive axle tyre replacement — LSR 482 XA',
                'description' => 'Two tyres, drive axle, fitted at the yard.',
                'is_planned' => true,
                'maintenance_type' => 'Tyre change',
                'due_on' => now()->addDays(12)->toDateString(),
                'due_odometer' => 217000,
            ], $fleetManager);

            // ------------------------------------------------------------------ the pickup
            $pickup = $this->asset($assets, [
                'code' => 'PKP-04',
                'name' => 'Site pickup, double cab',
                'category' => 'Vehicle',
                'manufacturer' => 'Toyota',
                'model' => 'Hilux 2.4D',
                'tag_number' => 'MEI-VEH-0004',
                'acquired_on' => now()->subYears(2)->subMonths(1)->toDateString(),
                'purchase_cost' => 29800000,
            ], $fleetManager);

            $assets->transferCustody($pickup, [
                'custodian_id' => $projectManager->getKey(),
                'org_unit_id' => $unit['PMO']->id,
                'project_id' => $job['DTE']?->id,
                'location_id' => $place['SITE-DT']->id,
            ], $fleetManager,
                'Allocated to the Delta Terminal project team',
                'good',
                'Runs between the yard and site four days a week.',
                $opsDirector->getKey());

            $pickupVehicle = $fleet->attachVehicle($pickup, [
                'registration_number' => 'KJA 119 BM',
                'vehicle_type' => 'Pickup',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2021,
                'colour' => 'Silver',
                'tank_capacity' => 80,
                'current_odometer' => 60900,
            ], $fleetManager);

            $fleet->assignDriver($pickupVehicle, $projectManager->getKey(), $fleetManager);

            foreach ([
                [30, 61100, 62.0, 88660.0],
                [9, 61820, 58.0, 84100.0],
            ] as [$daysAgo, $odometer, $litres, $total]) {
                $fleet->logFuel($pickupVehicle, [
                    'filled_at' => now()->subDays($daysAgo)->setTime(17, 15),
                    'litres' => $litres,
                    'odometer' => $odometer,
                    'total_cost' => $total,
                    'station' => 'Ardova, Sagamu Road',
                    'project_id' => $job['DTE']?->id,
                ], $projectManager);
            }

            $papers->create($pickupVehicle, [
                'document_type' => 'Insurance',
                'document_number' => 'LWA/CV/0093117',
                'issuer' => 'Leadway Assurance',
                'issued_on' => now()->subMonths(5)->toDateString(),
                'expires_on' => now()->addMonths(7)->toDateString(),
                'cost' => 268000,
            ], $fleetManager);

            $papers->create($pickupVehicle, [
                'document_type' => 'Vehicle licence',
                'document_number' => 'VL/LA/2025/771904',
                'issuer' => 'Lagos State Motor Vehicle Administration Agency',
                'issued_on' => now()->subDays(320)->toDateString(),
                'expires_on' => now()->addDays(45)->toDateString(),
                'cost' => 18500,
            ], $fleetManager);

            // ------------------------------------------------------------------ the gauge
            // Not a vehicle and not plant, and the reason the asset register is not the fleet
            // register: a calibrated instrument has a custodian, a certificate interval and a serial
            // number, and none of that has anything to do with a plate.
            $gauge = $this->asset($assets, [
                'code' => 'TST-03',
                'name' => 'Ultrasonic thickness gauge',
                'description' => 'Wall thickness readings on the tank shell courses and on the '
                    .'tie-in spools. Readings taken with this instrument go onto inspection '
                    .'records, so its calibration certificate is part of the quality file.',
                'category' => 'Test equipment',
                'manufacturer' => 'Olympus',
                'model' => '38DL PLUS',
                'serial_number' => '38DL-2211-0774',
                'tag_number' => 'MEI-QA-0003',
                'acquired_on' => now()->subYears(2)->toDateString(),
                'purchase_cost' => 4150000,
            ], $qaManager);

            $assets->transferCustody($gauge, [
                'custodian_id' => $qaInspector->getKey(),
                'org_unit_id' => $unit['QA']->id,
                'project_id' => null,
                'location_id' => $place['HQ']->id,
            ], $qaManager,
                'Issued to the inspector who takes the readings',
                'good',
                'Signed out of the QA cabinet. Goes to site and comes back the same week.',
                $qaManager->getKey());

            $jobs->create([
                'asset_id' => $gauge->getKey(),
                'title' => 'Annual calibration — thickness gauge 38DL-2211-0774',
                'description' => 'The certificate expires with the calibration. Readings taken '
                    .'after that date cannot be used on an inspection record.',
                'is_planned' => true,
                'maintenance_type' => 'Calibration',
                'due_on' => now()->addDays(21)->toDateString(),
            ], $qaManager);
        });
    }

    /**
     * Kestrel's two vans, its one big machine, and the same code with the controls turned down.
     *
     * THE AIR HANDLING UNIT ARRIVES FROM THE PROCUREMENT CHAPTER. It was requisitioned, ordered,
     * delivered, inspected and accepted three chapters up, and here it becomes an asset whose
     * `goods_receipt_item_id` points back at the delivery line — which points at the order, which
     * points at the requisition. "Why do we own this and what did it cost" is answerable by clicking
     * rather than by asking somebody who was there (§90). The cost and the supplier are not typed
     * here at all; `registerFromReceipt()` reads them off the order.
     *
     * THE CONTROLS ARE TURNED DOWN, NOT ABSENT. `asset.require_authoriser_on_transfer` is OFF,
     * because in a company of eight the person handing over the van and the person permitting it
     * are the same person, and pretending otherwise produces a second signature that means nothing.
     * `fuel_log.odometer_required` is OFF too, and the consequence is carried honestly: the
     * mechanical van's fills record what was spent and nothing else, so the system declines to
     * report a consumption figure for it rather than inventing one. The electrical van's driver
     * does note the reading, and that van gets a figure. Same table, same code, one setting (§73).
     *
     * ONE VAN IS OVERDUE ON ITS SERVICE, nine days past, and it stays overdue: nothing sweeps a
     * status onto it and nothing needs to. Overdue is a date question asked of an open job.
     */
    private function seedKestrelAssets(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $assets = app(AssetService::class);
            $fleet = app(FleetService::class);
            $jobs = app(AssetMaintenanceService::class);
            $papers = app(VehicleDocumentService::class);

            $settings->set('asset.require_authoriser_on_transfer', false, $organization->id, 'assets');
            $settings->set('asset.maintenance_warning_days', 14, $organization->id, 'assets');
            $settings->set('fuel_log.odometer_required', false, $organization->id, 'assets');
            $settings->set('vehicle_document.expiry_warning_days', 30, $organization->id, 'assets');

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $job = Project::query()->get()->keyBy('code');
            $roles = Role::query()->get()->keyBy('slug');

            $opsManager = $this->accountOf($staff, 'KFM-002');       // Priti Shah
            $maintenanceHead = $this->accountOf($staff, 'KFM-003');  // Duncan Reilly
            $fitter = $this->accountOf($staff, 'KFM-005');           // Callum Whitfield
            $electrician = $this->accountOf($staff, 'KFM-006');      // Erin Doyle

            // A fourth hat for Duncan, and the same argument as the store-manager one he already
            // wears: roles are additive because in a company of eight one person runs the spares
            // cage, the two vans and the planned maintenance, and a single "user type" column could
            // not say that without inventing a role nobody has a name for (§9).
            if (($fleetRole = $roles['fleet-manager'] ?? null) !== null) {
                $maintenanceHead->assignRole($fleetRole);
            }

            // ------------------------------------------------------------ the AHU, from the delivery
            $ahuLine = GoodsReceiptItem::query()
                ->with(['purchaseOrderItem.purchaseOrder', 'goodsReceipt'])
                ->whereHas(
                    'purchaseOrderItem',
                    fn (Builder $query) => $query->where('description', 'like', 'Air handling unit%'),
                )
                ->first();

            if ($ahuLine !== null) {
                $ahu = $assets->registerFromReceipt($ahuLine, [
                    'code' => 'AHU-3',
                    'name' => 'Air handling unit AHU-3 — Trafford Point',
                    'description' => 'Roof-mounted, 4.2 m³/s, thermal wheel. Replaced the original '
                        .'unit after the second bearing failure. Client-funded under the PPM '
                        .'contract, maintained by us.',
                    'category' => 'Plant and machinery',
                    'manufacturer' => 'Northern Air Handling',
                    'serial_number' => 'NAH-42TW-118206',
                    'tag_number' => 'TPA-AHU-3',
                ], $maintenanceHead);

                $assets->commission($ahu, $maintenanceHead);

                // NO AUTHORISER, and the service accepts it because this company said so. The
                // identical call in Meridian's chapter above would refuse.
                $assets->transferCustody($ahu, [
                    'custodian_id' => $maintenanceHead->getKey(),
                    'org_unit_id' => $unit['MNT']->id,
                    'project_id' => $job['TPA']?->id,
                    'location_id' => $place['MCR']->id,
                ], $maintenanceHead, 'Installed and handed over on the Trafford Point roof', 'good');

                $jobs->create([
                    'asset_id' => $ahu->getKey(),
                    'title' => 'First planned service — AHU-3',
                    'description' => 'Belt tension, filter change and thermal wheel seal check at '
                        .'three months from commissioning, as the warranty requires.',
                    'is_planned' => true,
                    'maintenance_type' => 'Routine service',
                    'due_on' => now()->addDays(20)->toDateString(),
                ], $maintenanceHead);
            }

            // ------------------------------------------------------------------ the mechanical van
            $mechVan = $this->asset($assets, [
                'code' => 'VAN-01',
                'name' => 'Mechanical van',
                'category' => 'Vehicle',
                'manufacturer' => 'Ford',
                'model' => 'Transit Custom 300 L2',
                'acquired_on' => now()->subYears(3)->subMonths(4)->toDateString(),
                'purchase_cost' => 24800,
                'notes' => 'Carries the van stock — see the spares chapter. The stock is a store in '
                    .'its own right and the van is an asset; the two are joined by the person '
                    .'holding both.',
            ], $maintenanceHead);

            $assets->transferCustody($mechVan, [
                'custodian_id' => $fitter->getKey(),
                'org_unit_id' => $unit['MNT-MEC']->id,
                'project_id' => null,
                'location_id' => $place['LDS']->id,
            ], $maintenanceHead, 'Allocated to the mechanical fitter', 'fair');

            $mechVehicle = $fleet->attachVehicle($mechVan, [
                'registration_number' => 'YK21 HRT',
                'vehicle_type' => 'Pickup',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2021,
                'colour' => 'White',
                'tank_capacity' => 70,
            ], $maintenanceHead);

            $fleet->assignDriver($mechVehicle, $fitter->getKey(), $maintenanceHead);

            // NO ODOMETER ON ANY OF THESE. The company turned the requirement off, and this is what
            // that decision looks like on the ground: three receipts, real money, and a consumption
            // figure the system refuses to compute because a fill at an unknown reading says
            // nothing about how far the van went.
            foreach ([
                [28, 58.4, 76.51, 'Shell, Leeds Road'],
                [16, 61.9, 80.47, 'Shell, Leeds Road'],
                [4, 55.2, 72.29, 'BP, Kirkstall'],
            ] as [$daysAgo, $litres, $total, $station]) {
                $fleet->logFuel($mechVehicle, [
                    'filled_at' => now()->subDays($daysAgo)->setTime(7, 30),
                    'litres' => $litres,
                    'total_cost' => $total,
                    'station' => $station,
                ], $fitter);
            }

            $papers->create($mechVehicle, [
                'document_type' => 'Insurance',
                'document_number' => 'AXA/CFL/771208',
                'issuer' => 'AXA Commercial',
                'issued_on' => now()->subDays(265)->toDateString(),
                'expires_on' => now()->addDays(100)->toDateString(),
                'cost' => 1180,
            ], $maintenanceHead);

            $papers->create($mechVehicle, [
                'document_type' => 'Roadworthiness certificate',
                'document_number' => 'MOT-4471-0982-2255',
                'issuer' => 'Kirkstall Motor Services',
                'issued_on' => now()->subDays(346)->toDateString(),
                'expires_on' => now()->addDays(19)->toDateString(),
                'cost' => 54.85,
                'notes' => 'Booked in alongside the service below.',
            ], $maintenanceHead);

            // NINE DAYS OVERDUE AND STILL OPEN. Nothing has written a flag onto this row and nothing
            // will: the register asks whether an open job's due date has passed, so a missed cron
            // costs a warning and not the truth.
            $jobs->create([
                'asset_id' => $mechVan->getKey(),
                'title' => 'Annual service and MOT — YK21 HRT',
                'description' => 'Service, MOT and the two front tyres.',
                'is_planned' => true,
                'maintenance_type' => 'Routine service',
                'due_on' => now()->subDays(9)->toDateString(),
            ], $maintenanceHead);

            // ------------------------------------------------------------------ the electrical van
            $elecVan = $this->asset($assets, [
                'code' => 'VAN-02',
                'name' => 'Electrical van',
                'category' => 'Vehicle',
                'manufacturer' => 'Ford',
                'model' => 'Transit Connect 240 L2',
                'acquired_on' => now()->subYears(2)->subMonths(7)->toDateString(),
                'purchase_cost' => 19400,
            ], $maintenanceHead);

            $assets->transferCustody($elecVan, [
                'custodian_id' => $electrician->getKey(),
                'org_unit_id' => $unit['MNT-ELE']->id,
                'project_id' => null,
                'location_id' => $place['LDS']->id,
            ], $maintenanceHead, 'Allocated to the electrical engineer', 'good');

            $elecVehicle = $fleet->attachVehicle($elecVan, [
                'registration_number' => 'YK19 LDF',
                'vehicle_type' => 'Pickup',
                'fuel_type' => 'Diesel',
                'year_of_manufacture' => 2019,
                'colour' => 'White',
                'tank_capacity' => 60,
                'current_odometer' => 60800,
            ], $maintenanceHead);

            $fleet->assignDriver($elecVehicle, $electrician->getKey(), $maintenanceHead);

            // Erin writes the reading on the receipt, so these two fills DO produce a figure. The
            // setting relaxed a rule; it did not forbid the data, and one van with a consumption
            // figure beside one without is the clearest statement of what the setting costs.
            foreach ([
                [21, 61220, 48.6, 63.66],
                [7, 61940, 51.2, 67.07],
            ] as [$daysAgo, $odometer, $litres, $total]) {
                $fleet->logFuel($elecVehicle, [
                    'filled_at' => now()->subDays($daysAgo)->setTime(16, 45),
                    'litres' => $litres,
                    'odometer' => $odometer,
                    'total_cost' => $total,
                    'station' => 'Shell, Leeds Road',
                ], $electrician);
            }

            $papers->create($elecVehicle, [
                'document_type' => 'Insurance',
                'document_number' => 'AXA/CFL/771209',
                'issuer' => 'AXA Commercial',
                'issued_on' => now()->subDays(265)->toDateString(),
                'expires_on' => now()->addDays(100)->toDateString(),
                'cost' => 1040,
            ], $maintenanceHead);

            // Unplanned, done and closed. `is_planned` false with no due date is dated today by the
            // service rather than left blank — a breakdown recorded after the fact is not something
            // the planner has to arrange.
            $repair = $jobs->create([
                'asset_id' => $elecVan->getKey(),
                'title' => 'Front brake pads and discs — YK19 LDF',
                'is_planned' => false,
                'maintenance_type' => 'Repair',
                'performed_by' => $electrician->getKey(),
            ], $maintenanceHead);

            $jobs->start($repair, $maintenanceHead, 61950);
            $jobs->complete($repair, [
                'labour_cost' => 120,
                'parts_cost' => 186.4,
                'downtime_hours' => 3.5,
                'odometer' => 61950,
                'findings' => 'Pads down to the wear indicators and both discs lipped. Replaced as '
                    .'a pair. Rear pads have another few thousand miles in them.',
            ], $opsManager);
        });
    }

    /**
     * Register a thing and bring it into use, which is two acts and always both.
     *
     * A newly created asset sits in `draft` — "being registered" — and an asset register full of
     * draft rows is a register nobody trusts. Commissioning is a separate act because it is a
     * decision somebody makes, and keeping it separate is what lets a company receive a machine in
     * March and put it to work in April without the register lying in between.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function asset(AssetService $assets, array $attributes, User $actor): Asset
    {
        /** @var Asset $asset */
        $asset = $assets->create($attributes, $actor);

        return $assets->commission($asset, $actor);
    }

    // ================================================================= finance

    private function seedMeridianFinance(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $taxes = app(TaxCodeService::class);
            $expenses = app(ExpenseService::class);
            $incomes = app(IncomeService::class);
            $payments = app(PaymentService::class);
            $cash = app(PettyCashService::class);

            // Nobody signs off their own claim or releases money they approved — the defaults, set
            // out loud so the settings screen shows a decision rather than a blank.
            $settings->set('finance.separate_claimant_and_approver', true, $organization->id, 'finance');
            $settings->set('finance.separate_payment_approver_and_payer', true, $organization->id, 'finance');
            $settings->set('petty_cash.single_disbursement_limit', '150000', $organization->id, 'finance');

            $staff = $this->staff();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'MEI-0001');             // Adaeze Okonkwo
            $projectManager = $this->accountOf($staff, 'MEI-0011'); // Daniel Mbeki
            $siteManager = $this->accountOf($staff, 'MEI-0012');    // Kelechi Nwosu
            $finance = $this->accountOf($staff, 'MEI-0018');        // Victor Anyanwu

            // The rate is data, not code (§47): a company in another jurisdiction types its own.
            $vat = $taxes->create([
                'code' => 'VAT', 'name' => 'Value added tax 7.5%', 'rate' => '7.5',
                'applies_to' => 'both', 'is_recoverable' => true, 'is_active' => true,
            ], $finance);
            $taxes->create([
                'code' => 'EXEMPT', 'name' => 'Exempt supply', 'rate' => '0',
                'applies_to' => 'both', 'is_recoverable' => false, 'is_active' => true,
            ], $finance);

            $dte = $job['DTE'] ?? null;

            // ------------------------------------------------------------ claims
            // Approved and paid: the whole route, claimant → finance approval → MD sign-off on the
            // payment → finance releases it.
            $hotel = $expenses->create([
                'title' => 'Accommodation for tie-in survey — Warri',
                'category' => 'accommodation',
                'expense_date' => now()->subDays(24)->toDateString(),
                'amount' => '186000',
                'tax_code_id' => $vat->id,
                'payee_name' => 'Delta Crest Hotel',
                'receipt_reference' => 'DCH-44120',
                'project_id' => $dte?->id,
                'confidentiality' => 'internal',
            ], $siteManager);
            $expenses->submit($hotel, $siteManager);
            $expenses->approve($hotel, $finance, 'Receipts match the site diary.');

            $refund = $payments->create([
                'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $hotel->id,
                'amount' => (string) $hotel->fresh()->total_amount, 'method' => 'bank_transfer',
                'confidentiality' => 'internal',
            ], $finance);
            $payments->submit($refund, $finance);
            $payments->approve($refund, $md);
            $payments->pay($refund, $finance, now()->subDays(18)->toDateString(), 'NIP-2026-0917-1142');

            // Approved, not yet paid — what a finance manager's "to pay" list looks like on a Monday.
            $fuel = $expenses->create([
                'title' => 'Diesel for site generator — week 36',
                'category' => 'fuel',
                'expense_date' => now()->subDays(9)->toDateString(),
                'amount' => '94500',
                'payee_name' => 'Ekpan Filling Station',
                'receipt_reference' => 'EFS-7781',
                'project_id' => $dte?->id,
                'confidentiality' => 'internal',
            ], $siteManager);
            $expenses->submit($fuel, $siteManager);
            $expenses->approve($fuel, $finance);

            // Waiting on a decision.
            $courier = $expenses->create([
                'title' => 'Courier of weld procedure records to client',
                'category' => 'courier',
                'expense_date' => now()->subDays(3)->toDateString(),
                'amount' => '18500',
                'payee_name' => 'Swiftline Dispatch',
                'project_id' => $dte?->id,
                'confidentiality' => 'internal',
            ], $projectManager);
            $expenses->submit($courier, $projectManager);

            // Sent back for a receipt — a draft again, in the claimant's hands, with the reason on it.
            $meals = $expenses->create([
                'title' => 'Crew meals during night pour',
                'category' => 'meals',
                'expense_date' => now()->subDays(6)->toDateString(),
                'amount' => '42000',
                'project_id' => $dte?->id,
                'confidentiality' => 'internal',
            ], $siteManager);
            $expenses->submit($meals, $siteManager);
            $expenses->returnForAmendment($meals, $finance, 'Attach the canteen receipt, and split the two nights.');

            // ------------------------------------------------------------ a supplier paid
            // §90: the order the procurement chapter issued is the thing the money goes against.
            $order = PurchaseOrder::query()
                ->with('status')->orderBy('id')
                ->get()
                ->first(fn (PurchaseOrder $po) => $payments->isPayable($po));

            if ($order !== null) {
                $outstanding = (float) $payments->outstandingOn($order);

                if ($outstanding > 0) {
                    $advance = $payments->create([
                        'direction' => 'outgoing', 'payable_type' => 'purchase_order', 'payable_id' => $order->id,
                        'amount' => number_format($outstanding * 0.3, 2, '.', ''), 'method' => 'bank_transfer',
                        'reference' => 'Advance 30% per PO terms', 'confidentiality' => 'internal',
                    ], $finance);
                    $payments->submit($advance, $finance);
                    $payments->approve($advance, $md);
                    $payments->pay($advance, $finance, now()->subDays(12)->toDateString(), 'NIP-2026-0905-0833');

                    // The balance is raised and waiting for the MD.
                    $balance = $payments->create([
                        'direction' => 'outgoing', 'payable_type' => 'purchase_order', 'payable_id' => $order->id,
                        'amount' => number_format($outstanding * 0.7, 2, '.', ''), 'method' => 'bank_transfer',
                        'reference' => 'Balance on delivery', 'confidentiality' => 'internal',
                    ], $finance);
                    $payments->submit($balance, $finance);
                }
            }

            // ------------------------------------------------------------ billing the client
            $contract = Contract::query()
                ->where('direction', 'client')
                ->when($dte !== null, fn (Builder $query) => $query->where('project_id', $dte->id))
                ->orderBy('id')
                ->first()
                ?? Contract::query()->where('direction', 'client')->orderBy('id')->first();

            $valuation = $incomes->create([
                'title' => 'Interim valuation 2 — civil and mechanical works',
                'category' => 'contract_billing',
                'income_date' => now()->subDays(40)->toDateString(),
                'due_on' => now()->subDays(10)->toDateString(),
                'invoice_reference' => 'MEI-INV-2026-014',
                'amount' => '48500000',
                'tax_code_id' => $vat->id,
                'contract_id' => $contract?->id,
                'confidentiality' => 'confidential',
            ], $finance);
            $incomes->issue($valuation, $finance);

            // Part received: the client paid on the certified amount, not the invoiced one.
            $payments->create([
                'direction' => 'incoming', 'payable_type' => 'income', 'payable_id' => $valuation->id,
                'amount' => '30000000', 'method' => 'bank_transfer', 'reference' => 'RTGS-RPDC-88213',
                'paid_on' => now()->subDays(8)->toDateString(), 'confidentiality' => 'confidential',
            ], $finance);

            $incomes->create([
                'title' => 'Interim valuation 3 — draft for QS review',
                'category' => 'contract_billing',
                'income_date' => now()->toDateString(),
                'amount' => '21750000',
                'tax_code_id' => $vat->id,
                'contract_id' => $contract?->id,
                'confidentiality' => 'confidential',
            ], $finance);

            // ------------------------------------------------------------ the site tin
            $tin = $cash->createAccount([
                'code' => 'PC-DTE',
                'name' => 'Delta terminal site office tin',
                'custodian_id' => $siteManager->id,
                'float_amount' => '500000',
                'project_id' => $dte?->id,
                'is_active' => true,
                'confidentiality' => 'internal',
                'notes' => 'Counted every Friday by the site manager; topped up by finance on request.',
            ], $finance);

            $cash->replenish($tin, [
                'amount' => '400000', 'transacted_on' => now()->subDays(21)->toDateString(),
                'description' => 'Opening float — cash withdrawn from operating account', 'receipt_reference' => 'CHQ-004417',
            ], $finance);

            foreach ([
                [19, '35000', 'Bottled water for the crew', 'meals', 'Warri Mega Stores'],
                [15, '62000', 'Hire of tricycle for material runs', 'travel', 'Keke rider — Musa'],
                [11, '28500', 'Padlocks and chain for container store', 'consumables', 'Effurun hardware'],
                [7, '140000', 'Emergency repair to site water pump', 'repairs', 'Okpe Plumbing Works'],
            ] as [$daysAgo, $amount, $what, $category, $payee]) {
                $cash->disburse($tin, [
                    'amount' => $amount, 'transacted_on' => now()->subDays($daysAgo)->toDateString(),
                    'description' => $what, 'category' => $category, 'payee_name' => $payee,
                ], $siteManager);
            }

            // Booked to the tin by mistake, and put right by its own voucher rather than an edit.
            $wrong = $cash->disburse($tin, [
                'amount' => '15000', 'transacted_on' => now()->subDays(5)->toDateString(),
                'description' => 'Printer toner — head office', 'category' => 'consumables',
            ], $siteManager);
            $cash->reverse($wrong, 'Head office purchase, paid from the wrong tin.', $finance);

        });
    }

    private function seedKestrelFinance(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $this->bootDelivery();

            $settings = app(SettingsRepository::class);
            $taxes = app(TaxCodeService::class);
            $expenses = app(ExpenseService::class);
            $incomes = app(IncomeService::class);
            $payments = app(PaymentService::class);
            $cash = app(PettyCashService::class);

            $staff = $this->staff();
            $job = Project::query()->get()->keyBy('code');

            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder
            $maintenanceHead = $this->accountOf($staff, 'KFM-003'); // Duncan Reilly
            $fitter = $this->accountOf($staff, 'KFM-005');          // Callum Whitfield
            $finance = $this->accountOf($staff, 'KFM-008');         // Marianne Ellis

            // A small company with no treasurer: the payer rule stays on, so an approved payment
            // waits for someone who holds `payment.pay` rather than being released by its approver.
            $settings->set('petty_cash.single_disbursement_limit', '75', $organization->id, 'finance');

            $vat = $taxes->create([
                'code' => 'S20', 'name' => 'Standard rate 20%', 'rate' => '20',
                'applies_to' => 'both', 'is_recoverable' => true, 'is_active' => true,
            ], $md);
            $taxes->create([
                'code' => 'Z0', 'name' => 'Zero rated', 'rate' => '0',
                'applies_to' => 'both', 'is_recoverable' => true, 'is_active' => true,
            ], $md);

            $tpa = $job['TPA'] ?? null;
            $contract = Contract::query()
                ->where('direction', 'client')
                ->when($tpa !== null, fn (Builder $query) => $query->where('project_id', $tpa->id))
                ->orderBy('id')
                ->first()
                ?? Contract::query()->where('direction', 'client')->orderBy('id')->first();

            // ------------------------------------------------------------ the monthly PPM invoice
            $invoice = $incomes->create([
                'title' => 'Planned maintenance — August',
                'category' => 'contract_billing',
                'income_date' => now()->subDays(30)->toDateString(),
                'due_on' => now()->toDateString(),
                'invoice_reference' => 'KFM-INV-0231',
                'amount' => '14250',
                'tax_code_id' => $vat->id,
                'contract_id' => $contract?->id,
                'confidentiality' => 'confidential',
            ], $finance);
            $incomes->issue($invoice, $finance);
            $payments->create([
                'direction' => 'incoming', 'payable_type' => 'income', 'payable_id' => $invoice->id,
                'amount' => (string) $invoice->fresh()->total_amount, 'method' => 'bank_transfer',
                'reference' => 'BACS TPE LLP 0231', 'paid_on' => now()->subDays(2)->toDateString(),
                'confidentiality' => 'confidential',
            ], $finance);

            // ------------------------------------------------------------ a claim, approved by the MD
            $mileage = $expenses->create([
                'title' => 'Mileage — emergency call-out to Trafford Point',
                'category' => 'travel',
                'expense_date' => now()->subDays(10)->toDateString(),
                'amount' => '38.70',
                'description' => '86 miles at 45p, own vehicle, AHU-3 alarm out of hours.',
                'project_id' => $tpa?->id,
                'confidentiality' => 'internal',
            ], $fitter);
            $expenses->submit($mileage, $fitter);
            $expenses->approve($mileage, $md);

            // Raised and approved; nobody here holds the release, so it waits (see above).
            $reimburse = $payments->create([
                'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $mileage->id,
                'amount' => (string) $mileage->fresh()->total_amount, 'method' => 'bank_transfer',
                'confidentiality' => 'internal',
            ], $finance);
            $payments->submit($reimburse, $finance);
            $payments->approve($reimburse, $md);

            // ------------------------------------------------------------ the van tin
            $tin = $cash->createAccount([
                'code' => 'PC-TPA',
                'name' => 'Trafford Point maintenance van tin',
                'custodian_id' => $maintenanceHead->id,
                'float_amount' => '200',
                'project_id' => $tpa?->id,
                'is_active' => true,
                'confidentiality' => 'internal',
            ], $finance);
            $cash->replenish($tin, [
                'amount' => '200', 'transacted_on' => now()->subDays(14)->toDateString(),
                'description' => 'Float issued from the office safe',
            ], $finance);
            $cash->disburse($tin, [
                'amount' => '12.40', 'transacted_on' => now()->subDays(9)->toDateString(),
                'description' => 'Parking — Trafford Point visitor bays', 'category' => 'travel',
                'payee_name' => 'Trafford Point car park', 'receipt_reference' => 'TP-PARK-5561',
            ], $maintenanceHead);
            $cash->disburse($tin, [
                'amount' => '46.95', 'transacted_on' => now()->subDays(4)->toDateString(),
                'description' => 'Cable ties, fuses and PTFE tape', 'category' => 'consumables',
                'payee_name' => 'Parkside Trade Counter', 'receipt_reference' => 'PTC-889104',
            ], $maintenanceHead);
        });
    }

    // ================================================================= people (Phase 6)

    /**
     * Holidays and leave, with every approval decided through the workflow engine.
     *
     * Each company has leave in every state an evaluator should be able to open: approved (the line
     * manager decided it through the route), waiting in a manager's inbox, rejected with a reason,
     * and a draft nobody has sent. The holidays differ because they are company data (§47).
     *
     * @param  array<int,array{0:string,1:string,2:bool}>  $holidays  name, date (Y-m-d), recurring
     * @param  array<int,array{0:string,1:string,2:int,3:int,4:string,5:?string,6:?string}>  $leave
     *                                                                                               employee no, type code, week of year, working days, state, decider employee no, comment
     */
    private function seedPeople(Organization $organization, User $hr, array $holidays, array $leave): void
    {
        $this->context->within($organization, function () use ($organization, $hr, $holidays, $leave) {
            $service = app(LeaveService::class);
            $staff = $this->staff();
            $types = LeaveType::query()->get()->keyBy('code');

            foreach ($holidays as [$name, $date, $recurring]) {
                PublicHoliday::create([
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'holiday_date' => $date,
                    'is_recurring' => $recurring,
                    'created_by' => $hr->id,
                ]);
            }

            foreach ($leave as [$employeeNo, $code, $week, $days, $state, $deciderNo, $comment]) {
                $employee = $staff[$employeeNo];
                $requester = $this->accountOf($staff, $employeeNo);

                // Always inside this calendar year, whenever the demo is seeded: leave never spans a
                // year boundary, because balances are kept per year.
                $start = now()->startOfYear()->addWeeks($week)->startOfWeek();
                $end = $start->copy()->addDays($days - 1);

                $request = $service->create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $types[$code]->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'reason' => $code === 'SICK' ? 'Unwell.' : 'Family time.',
                    'contact_while_away' => 'Mobile, urgent matters only',
                ], $requester);

                if ($state === 'draft') {
                    continue;
                }

                $service->submit($request, $requester);

                if ($deciderNo !== null) {
                    $decision = $state === 'rejected' ? Decision::Reject : Decision::Approve;
                    $this->decide($request->refresh(), $this->accountOf($staff, $deciderNo), $decision, (string) $comment);
                }
            }
        });
    }

    /**
     * Attendance, timesheets and overtime — the second half of people.
     *
     * The shape to notice is that a timesheet and an overtime request are decided EXACTLY as the
     * leave above is, and as a drawing three chapters up is: the line manager acts on a stage. The
     * seeder therefore calls the same `decide()` helper for all four.
     *
     * Attendance is the exception, and deliberately so — a clock-in is a fact, not a request, so
     * nothing here waits in an inbox. Past shifts are written as rows rather than through
     * `clockIn()`, because that method stamps the server clock and a demo needs a fortnight of
     * history; the one shift entered by hand and the one open shift go through the service, so the
     * corrected-by-HR and currently-on-site states are real rather than painted on.
     *
     * @param  array<int,array{0:string,1:int,2:string,3:?string,4:?string}>  $sheets
     *                                                                                 employee no, weeks ago, state, decider employee no, comment
     * @param  array<int,array{0:string,1:int,2:string,3:string,4:string,5:?string,6:?string}>  $overtime
     *                                                                                                     employee no, days ago, hours asked, state, reason, decider employee no, hours worked
     */
    private function seedTime(Organization $organization, User $hr, array $sheets, array $overtime): void
    {
        $this->context->within($organization, function () use ($hr, $sheets, $overtime) {
            $timesheets = app(TimesheetService::class);
            $overtimeService = app(OvertimeService::class);
            $attendance = app(AttendanceService::class);
            $staff = $this->staff();
            $clocked = [];   // employee id + date, so two sheets for one person never double-book a day

            foreach ($sheets as [$employeeNo, $weeksAgo, $state, $deciderNo, $comment]) {
                $employee = $staff[$employeeNo];
                $owner = $this->accountOf($staff, $employeeNo);
                $week = $timesheets->weekStart(now()->subWeeks($weeksAgo));
                $projects = $timesheets->allowedProjectIds($employee, $owner);

                if ($projects === []) {
                    continue;   // somebody on no project has nothing to book hours against
                }

                // Monday to Friday on site, with the odd late finish. The hours booked below match
                // what was clocked, which is the point of showing both rows on the sheet.
                $pattern = [['07:30', '16:30', '8'], ['07:30', '16:30', '8'], ['07:30', '17:15', '8.75'],
                    ['07:30', '16:30', '8'], ['07:30', '15:00', '6.5']];
                $hours = [];

                foreach ($pattern as $index => [$in, $out, $booked]) {
                    $day = $week->addDays($index);

                    if ($day->isFuture()) {
                        break;
                    }

                    $hours[$day->toDateString()] = $booked;
                    $key = $employee->getKey().' '.$day->toDateString();

                    if (isset($clocked[$key])) {
                        continue;
                    }

                    $clocked[$key] = true;
                    $from = $day->setTimeFromTimeString($in);
                    $to = $day->setTimeFromTimeString($out);

                    AttendanceRecord::create([
                        'organization_id' => $employee->organization_id,
                        'employee_id' => $employee->getKey(),
                        'user_id' => $employee->user_id,
                        'org_unit_id' => $employee->org_unit_id,
                        'project_id' => $projects[0],
                        'work_date' => $day->toDateString(),
                        'clock_in_at' => $from,
                        'clock_out_at' => $to,
                        'minutes' => (int) $from->diffInMinutes($to),
                        'source' => AttendanceRecord::SOURCE_SELF,
                        'created_by' => $employee->user_id,
                    ]);
                }

                if ($hours === []) {
                    continue;
                }

                $sheet = $timesheets->create([
                    'employee_id' => $employee->getKey(),
                    'period_start' => $week->toDateString(),
                ], $owner);

                $timesheets->saveEntries($sheet, [[
                    'project_id' => $projects[0],
                    'description' => 'Site supervision and progress checks',
                    'hours' => $hours,
                ]], $owner, $state === 'draft' ? 'Still filling this in.' : null);

                if ($state === 'draft') {
                    continue;
                }

                $timesheets->submit($sheet->refresh(), $owner);

                if ($deciderNo !== null) {
                    $decision = $state === 'rejected' ? Decision::Reject : Decision::Approve;
                    $this->decide($sheet->refresh(), $this->accountOf($staff, $deciderNo), $decision, (string) $comment);
                }
            }

            foreach ($overtime as [$employeeNo, $daysAgo, $asked, $state, $reason, $deciderNo, $worked]) {
                $employee = $staff[$employeeNo];
                $owner = $this->accountOf($staff, $employeeNo);
                $projects = $timesheets->allowedProjectIds($employee, $owner);

                $request = $overtimeService->create([
                    'employee_id' => $employee->getKey(),
                    'project_id' => $projects[0] ?? null,
                    'work_date' => now()->subDays($daysAgo)->toDateString(),
                    'hours' => $asked,
                    'reason' => $reason,
                ], $owner);

                if ($state === 'draft') {
                    continue;
                }

                $overtimeService->submit($request, $owner);

                if ($deciderNo === null) {
                    continue;
                }

                $this->decide(
                    $request->refresh(),
                    $this->accountOf($staff, $deciderNo),
                    $state === 'rejected' ? Decision::Reject : Decision::Approve,
                    $state === 'rejected' ? 'Bring the work forward into normal hours instead.' : 'Approved — log the hours once they are worked.',
                );

                if ($worked !== null) {
                    $overtimeService->recordWorked($request->refresh(), $worked, $owner);
                }
            }

            // One person still on site, and one shift somebody forgot to clock, put right by HR.
            // The personnel record is handed over rather than looked up again: `staff()` loads
            // accounts without it, and clocking in reads `$actor->employee`.
            $onSite = $this->accountOf($staff, $sheets[0][0])->setRelation('employee', $staff[$sheets[0][0]]);

            if ($attendance->openShift($staff[$sheets[0][0]]) === null && ! now()->isWeekend()) {
                $attendance->clockIn($onSite, ['notes' => 'On site for the morning inspection.']);
            }

            // The sixth day of last week: a Saturday call-out, which is exactly the shift nobody
            // remembers to clock, and the one day the weekday pattern above leaves free.
            // Not the person clocked in above: an open shift and a hand-entered one on the same
            // personnel record is a state worth showing, but not the first one an evaluator opens.
            $forgot = $staff[end($sheets)[0]];
            $missed = $timesheets->weekStart(now()->subWeek())->addDays(5);

            $attendance->recordManual(
                $forgot,
                $missed->setTimeFromTimeString('07:45'),
                $missed->setTimeFromTimeString('16:15'),
                'Phone battery was flat at the gate; times taken from the site register.',
                $hr,
            );
        });
    }

    /**
     * Asking for a head, filling it, and somebody leaving — Meridian.
     *
     * THE REQUISITION IS DECIDED BY THE SAME ENGINE AS EVERYTHING ELSE. All three vacancies below
     * are submitted and then land in Chinedu Balogun's My Approvals, next to a drawing and a
     * timesheet, and the seeder decides them with the identical `decide()` helper it uses for a
     * document. There is no `approve()` on VacancyService to call instead, which is the point.
     *
     * TWO OF THE THREE ARE DECIDED AND ONE IS NOT, because an empty approvals inbox teaches an
     * evaluator that the inbox does not work. The open one is the night-shift HSE officer.
     *
     * THE EXITS ARE THE HALF WORTH READING. Priya's is cleared because she holds nothing; Ruth's
     * sits at `notified` and shows the blockers screen with a real answer on it — her calibrated
     * instrument, read out of the asset register rather than off a ticked box.
     */
    private function seedMeridianHiring(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $vacancies = app(VacancyService::class);
            $applications = app(ApplicantService::class);
            $lists = app(ChecklistService::class);
            $exits = app(EmployeeExitService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $post = Position::query()->get()->keyBy('code');

            $hr = $this->accountOf($staff, 'MEI-0017');           // Amina Tijani
            $opsDirector = $this->accountOf($staff, 'MEI-0003');  // Chinedu Balogun — decides all three
            $engManager = $this->accountOf($staff, 'MEI-0004');   // Farida Yusuf
            $leadEngineer = $this->accountOf($staff, 'MEI-0005'); // Marcus Adeyemi
            $qaManager = $this->accountOf($staff, 'MEI-0015');    // Yemi Bakare
            $hseManager = $this->accountOf($staff, 'MEI-0013');   // Grace Ibrahim

            // A construction company's joining list is not a consultancy's. Two items added to the
            // template the platform provided, by the company, on the company's own screen (§47) —
            // and note what happens to them below: they are copied into the new starter's list at
            // the moment it is started, so editing the template later leaves that list alone.
            $joining = $lists->defaultTemplate($organization->id, 'onboarding');

            if ($joining !== null) {
                $sequence = (int) $joining->items()->max('sequence');

                foreach ([
                    ['Issue PPE and the Delta Terminal site pass', true],
                    ['Book the pre-employment medical', false],
                ] as [$label, $requiresNote]) {
                    ChecklistTemplateItem::create([
                        'organization_id' => $organization->id,
                        'checklist_template_id' => $joining->getKey(),
                        'label' => $label,
                        'sequence' => ++$sequence,
                        'requires_note' => $requiresNote,
                    ]);
                }
            }

            // ------------------------------------------------------------ the head that got filled
            $structuralPost = $vacancies->create([
                'title' => 'Structural Engineer',
                'position_id' => $post['SE']->id,
                'org_unit_id' => $unit['ENG-CS-SD']->id,
                'location_id' => $place['HQ']->id,
                'hiring_manager_id' => $leadEngineer->getKey(),
                'employment_type' => 'permanent',
                'openings' => 1,
                'summary' => 'Design and check steel and concrete structures for the terminal and '
                    .'yard work, reporting to the Lead Structural Engineer.',
                'requirements' => 'B.Eng Civil or Structural, COREN registrable, three years of '
                    .'design office experience, competent in staad and the Eurocodes.',
                'target_start_date' => now()->subWeeks(3)->toDateString(),
                'closes_on' => now()->addWeeks(2)->toDateString(),
            ], $engManager);

            $vacancies->submit($structuralPost, $engManager);
            $this->decide(
                $structuralPost->refresh(),
                $opsDirector,
                Decision::Approve,
                'Agreed — the Delta Terminal workload justifies the head. Advertise it.',
            );

            $hired = $applications->createFor($structuralPost->refresh(), [
                'first_name' => 'Yetunde',
                'last_name' => 'Balogun',
                'email' => 'yetunde.balogun@example.com',
                'phone' => '+234 802 555 0147',
                'source' => 'website',
                'notes' => 'Four years at a design consultancy in Lagos; brought a portfolio of '
                    .'jetty and pipe-rack work to the panel.',
            ], $hr);

            $this->attachDemoCv(
                $hired,
                'Four years of structural design; steel jetties, pipe racks and foundations.',
                $hr,
            );

            $screen = $applications->addStage($hired, [
                'name' => 'Telephone screen',
                'scheduled_at' => now()->subWeeks(7),
                'interviewer_id' => $hr->getKey(),
            ], $hr);
            $applications->recordStage($screen, 'passed', 4, 'Clear on the fundamentals and available at a month\'s notice.', $hr);

            $panel = $applications->addStage($hired, [
                'name' => 'Technical panel',
                'scheduled_at' => now()->subWeeks(6),
                'interviewer_id' => $leadEngineer->getKey(),
            ], $hr);
            $applications->recordStage($panel, 'passed', 5, 'Best of the three we saw. Talked through a pile cap calculation without notes.', $leadEngineer);

            $turnedDown = $applications->createFor($structuralPost->refresh(), [
                'first_name' => 'Femi',
                'last_name' => 'Adebayo',
                'email' => 'femi.adebayo@example.com',
                'phone' => '+234 809 555 0192',
                'source' => 'agency',
                'notes' => 'Strong on site supervision, thin on design office work.',
            ], $hr);

            $femiScreen = $applications->addStage($turnedDown, [
                'name' => 'Telephone screen',
                'scheduled_at' => now()->subWeeks(7),
                'interviewer_id' => $hr->getKey(),
            ], $hr);
            $applications->recordStage($femiScreen, 'failed', 2, 'Has not worked in a design office; this post is 80% design.', $hr);

            // The REASON is on the record, not in the timeline — an applicant's classification is
            // restricted, and "not taken forward" is as much as the activity entry says (§68).
            $applications->reject($turnedDown->refresh(), 'Not enough design office experience for this post. Worth keeping for a site engineer vacancy.', $hr);

            $applications->makeOffer($hired->refresh(), $hr);

            // Hiring writes the personnel record and counts the head — the vacancy closes ITSELF at
            // the number of openings that were approved, which is why there is no "mark as filled"
            // and why the rejection above had to happen first: nothing can be filed against a
            // vacancy that is already full.
            $starter = $applications->hire($hired->refresh(), [
                'employee_no' => 'MEI-0027',
                'hired_on' => now()->subWeeks(3)->toDateString(),
                'supervisor_id' => $staff['MEI-0005']->getKey(),
                'employment_type' => 'permanent',
                'probation_ends_on' => now()->addMonths(3)->toDateString(),
            ], $hr);

            // The new starter's joining list, part done. The item still open is the sign-in — and
            // the personnel record genuinely has no account against it, because hiring writes the
            // person and never the login (§6).
            $starterList = $lists->start($starter, 'onboarding', $joining, [
                'started_on' => now()->subWeeks(3)->toDateString(),
                'due_on' => now()->addWeek()->toDateString(),
            ], $hr);

            foreach ($starterList->items()->orderBy('sequence')->get() as $item) {
                $done = match (true) {
                    str_starts_with($item->label, 'Sign and return') => 'Signed on the first morning and filed.',
                    str_starts_with($item->label, 'Collect identity') => 'Degree certificate and NYSC discharge seen and copied.',
                    str_starts_with($item->label, 'Introduce the line manager') => null,
                    str_starts_with($item->label, 'Issue PPE') => 'Helmet, boots and pass 2451 issued at the gate.',
                    default => false,
                };

                if ($done !== false) {
                    $lists->tick($item, $done, $hr);
                }
            }

            // ------------------------------------------------------------ the head still open
            $inspectorPost = $vacancies->create([
                'title' => 'QA/QC Inspector',
                'position_id' => $post['QAI']->id,
                'org_unit_id' => $unit['QA']->id,
                'location_id' => $place['SITE-DT']->id,
                'hiring_manager_id' => $qaManager->getKey(),
                'employment_type' => 'contract',
                'openings' => 2,
                'summary' => 'Witness and sign off welding, coating and civil works at the Delta '
                    .'Terminal site. Two heads, both on the site rotation.',
                'requirements' => 'CSWIP 3.1 or equivalent, NDT appreciation, five years on '
                    .'oil and gas construction.',
                'target_start_date' => now()->addMonth()->toDateString(),
                'closes_on' => now()->addWeeks(3)->toDateString(),
            ], $qaManager);

            $vacancies->submit($inspectorPost, $qaManager);
            $this->decide(
                $inspectorPost->refresh(),
                $opsDirector,
                Decision::Approve,
                'Two heads approved on the site rate, for the duration of the terminal contract only.',
            );

            $offered = $applications->createFor($inspectorPost->refresh(), [
                'first_name' => 'Idris',
                'last_name' => 'Mohammed',
                'email' => 'idris.mohammed@example.com',
                'phone' => '+234 803 555 0233',
                'source' => 'referral',
                'notes' => 'Referred by Kelechi Nwosu. CSWIP current until next March.',
            ], $hr);

            $idrisPanel = $applications->addStage($offered, [
                'name' => 'Site interview',
                'scheduled_at' => now()->subDays(9),
                'interviewer_id' => $qaManager->getKey(),
            ], $qaManager);
            $applications->recordStage($idrisPanel, 'passed', 4, 'Knows the coating spec and has witnessed to it before.', $qaManager);
            $applications->makeOffer($offered->refresh(), $hr);

            $inScreening = $applications->createFor($inspectorPost->refresh(), [
                'first_name' => 'Chiamaka',
                'last_name' => 'Nwosu',
                'email' => 'chiamaka.nwosu@example.com',
                'phone' => '+234 805 555 0311',
                'source' => 'website',
                'notes' => 'Certificates to be verified with the issuing body before any offer.',
            ], $hr);

            // Booked, not yet held — the outcome is blank, which is what a pending stage looks like.
            $applications->addStage($inScreening, [
                'name' => 'Site interview',
                'scheduled_at' => now()->addDays(4),
                'interviewer_id' => $qaManager->getKey(),
            ], $qaManager);

            $applications->createFor($inspectorPost->refresh(), [
                'first_name' => 'Blessing',
                'last_name' => 'Eke',
                'email' => 'blessing.eke@example.com',
                'phone' => '+234 806 555 0422',
                'source' => 'agency',
                'notes' => 'Applied through the agency framework; CV to be read.',
            ], $hr);

            $withdrew = $applications->createFor($inspectorPost->refresh(), [
                'first_name' => 'Samuel',
                'last_name' => 'Okoro',
                'email' => 'samuel.okoro@example.com',
                'phone' => '+234 807 555 0518',
                'source' => 'referral',
            ], $hr);
            $applications->withdraw($withdrew, 'Took a permanent post elsewhere before we interviewed.', $hr);

            // ------------------------------------------------------------ the head still waiting
            $nightHse = $vacancies->create([
                'title' => 'HSE Officer — night shift',
                'position_id' => $post['HSEO']->id,
                'org_unit_id' => $unit['HSE']->id,
                'location_id' => $place['SITE-DT']->id,
                'hiring_manager_id' => $hseManager->getKey(),
                'employment_type' => 'contract',
                'openings' => 1,
                'summary' => 'Cover the night shift at the terminal while the pipe-rack erection '
                    .'runs around the clock. Six months, reviewed at the end of the erection.',
                'requirements' => 'NEBOSH General Certificate, first aid at work, previous night '
                    .'shift experience on a live site.',
                'target_start_date' => now()->addWeeks(6)->toDateString(),
                'closes_on' => now()->addWeeks(4)->toDateString(),
            ], $hseManager);

            $vacancies->submit($nightHse, $hseManager);

            // Deliberately left undecided: it is sitting in Chinedu Balogun's My Approvals right now.

            // ------------------------------------------------------------ leaving
            // Cleared, because the registers say she holds nothing. Still `clearing` rather than
            // completed: her last day has not arrived, and the service refuses to finish an exit
            // for somebody who is still employed.
            $resigning = $exits->open($staff['MEI-0008'], [   // Priya Raghunathan
                'reason' => 'resignation',
                'notified_on' => now()->subDays(6)->toDateString(),
                'last_working_day' => now()->addWeeks(3)->toDateString(),
                'notes' => 'Moving to a client-side role in Port Harcourt. Two months\' notice, '
                    .'working it in full.',
                'eligible_for_rehire' => true,
            ], $hr);

            $leaverList = $resigning->checklist;

            if ($leaverList !== null) {
                foreach ($leaverList->items()->orderBy('sequence')->limit(3)->get() as $item) {
                    $lists->tick($item, $item->requires_note ? 'Done and witnessed by HR.' : null, $hr);
                }
            }

            $exits->clear($resigning->refresh(), $hr);

            // NOT cleared, and the screen will say why: the asset register still has her calibrated
            // instrument. Nobody can tick their way past this one (§84).
            $exits->open($staff['MEI-0016'], [               // Ruth Okafor
                'reason' => 'end_of_contract',
                'notified_on' => now()->subDays(2)->toDateString(),
                'last_working_day' => now()->addWeeks(6)->toDateString(),
                'notes' => 'Fixed-term inspection contract ends with the coating campaign.',
                'eligible_for_rehire' => true,
            ], $hr);
        });
    }

    /**
     * The same three registers in a company of eight — Kestrel.
     *
     * ONE EXIT IS FINISHED HERE, and it is the row worth opening: completing Josh Aiken's exit ends
     * the employment AND switches his sign-in off in one transaction, so the demo contains a person
     * who is genuinely no longer able to log in while every approval he ever made still points at
     * his account. Nothing is deleted.
     *
     * The cover for his desk is the requisition immediately above it, which is how the two halves of
     * this chapter meet: somebody left, and the company is already advertising the seat.
     */
    private function seedKestrelHiring(Organization $organization): void
    {
        $this->context->within($organization, function () use ($organization) {
            $vacancies = app(VacancyService::class);
            $applications = app(ApplicantService::class);
            $lists = app(ChecklistService::class);
            $exits = app(EmployeeExitService::class);

            $staff = $this->staff();
            $unit = $this->unitsByCode();
            $place = $this->locationsByCode();
            $post = Position::query()->get()->keyBy('code');

            $generalist = $this->accountOf($staff, 'KFM-008');      // Marianne Ellis — finance AND HR
            $md = $this->accountOf($staff, 'KFM-001');              // Rowan Calder — decides
            $opsManager = $this->accountOf($staff, 'KFM-002');      // Priti Shah
            $maintManager = $this->accountOf($staff, 'KFM-003');    // Duncan Reilly

            // Eight people and two vans: this company's joining list is the platform's minus the
            // items it has no use for, plus the one that matters to it. The other company added
            // two items to the same starting list and kept all seven — same code, same screen.
            $joining = $lists->defaultTemplate($organization->id, 'onboarding');

            if ($joining !== null) {
                $joining->items()->where('label', 'Agree the first objectives')->delete();

                ChecklistTemplateItem::create([
                    'organization_id' => $organization->id,
                    'checklist_template_id' => $joining->getKey(),
                    'label' => 'Add to the out-of-hours call-out rota',
                    'sequence' => (int) $joining->items()->max('sequence') + 1,
                    'requires_note' => false,
                ]);
            }

            // ------------------------------------------------------------ the technician they hired
            $technicianPost = $vacancies->create([
                'title' => 'Mobile Electrical Technician',
                'position_id' => $post['ET']->id,
                'org_unit_id' => $unit['MNT-ELE']->id,
                'location_id' => $place['LDS']->id,
                'hiring_manager_id' => $maintManager->getKey(),
                'employment_type' => 'permanent',
                'openings' => 1,
                'summary' => 'Second electrical technician covering the Leeds and Sheffield PPM '
                    .'rounds, on the call-out rota one week in three.',
                'requirements' => '18th edition, City & Guilds 2391 testing and inspection, full '
                    .'driving licence. Emergency lighting experience preferred.',
                'target_start_date' => now()->subWeeks(2)->toDateString(),
                'closes_on' => now()->addWeek()->toDateString(),
            ], $maintManager);

            $vacancies->submit($technicianPost, $maintManager);
            $this->decide(
                $technicianPost->refresh(),
                $md,
                Decision::Approve,
                'Approved. The Leeds round has outgrown one pair of hands and we are paying for it in call-outs.',
            );

            $newTech = $applications->createFor($technicianPost->refresh(), [
                'first_name' => 'Harriet',
                'last_name' => 'Nolan',
                'email' => 'harriet.nolan@example.com',
                'phone' => '+44 7700 900412',
                'source' => 'website',
                'notes' => 'Six years with a facilities contractor in Bradford; knows the tenant '
                    .'fit-out side as well as the plant.',
            ], $generalist);

            $this->attachDemoCv(
                $newTech,
                'Six years of commercial electrical maintenance; testing, inspection and emergency lighting.',
                $generalist,
            );

            $interview = $applications->addStage($newTech, [
                'name' => 'Interview with the Maintenance Manager',
                'scheduled_at' => now()->subWeeks(5),
                'interviewer_id' => $maintManager->getKey(),
            ], $maintManager);
            $applications->recordStage($interview, 'passed', 5, 'Straightforward, tidy work and happy on the rota. Offer it.', $maintManager);

            $otherCandidate = $applications->createFor($technicianPost->refresh(), [
                'first_name' => 'Dean',
                'last_name' => 'Marsh',
                'email' => 'dean.marsh@example.com',
                'phone' => '+44 7700 900518',
                'source' => 'agency',
            ], $generalist);
            $applications->withdraw($otherCandidate, 'Accepted an offer closer to home before we could interview.', $generalist);

            $applications->makeOffer($newTech->refresh(), $generalist);

            // The head is for one person, so this hire fills the vacancy and shuts it: the other
            // application had to be closed before it, not after.
            $joiner = $applications->hire($newTech->refresh(), [
                'employee_no' => 'KFM-009',
                'hired_on' => now()->subWeeks(2)->toDateString(),
                'supervisor_id' => $staff['KFM-003']->getKey(),
                'employment_type' => 'permanent',
                'probation_ends_on' => now()->addMonths(4)->toDateString(),
            ], $generalist);

            $joinerList = $lists->start($joiner, 'onboarding', $joining, [
                'started_on' => now()->subWeeks(2)->toDateString(),
                'due_on' => now()->addDays(3)->toDateString(),
            ], $generalist);

            foreach ($joinerList->items()->orderBy('sequence')->limit(3)->get() as $item) {
                $lists->tick($item, $item->requires_note ? 'Seen, copied and filed by Marianne.' : null, $generalist);
            }

            // ------------------------------------------------------------ the desk being covered
            $coverPost = $vacancies->create([
                'title' => 'Helpdesk Coordinator — maternity cover',
                'position_id' => $post['HC']->id,
                'org_unit_id' => $unit['OPS-HD']->id,
                'location_id' => $place['MCR']->id,
                'hiring_manager_id' => $opsManager->getKey(),
                'employment_type' => 'fixed_term',
                'openings' => 1,
                'summary' => 'Twelve months covering the helpdesk: logging tenant calls, dispatching '
                    .'the engineers and chasing the PPM schedule.',
                'requirements' => 'Helpdesk or scheduling experience, calm on the phone, comfortable '
                    .'with a CAFM system.',
                'target_start_date' => now()->addWeeks(3)->toDateString(),
                'closes_on' => now()->addWeeks(2)->toDateString(),
            ], $opsManager);

            $vacancies->submit($coverPost, $opsManager);
            $this->decide(
                $coverPost->refresh(),
                $md,
                Decision::Approve,
                'Approved for twelve months. The helpdesk cannot go dark now that Josh has gone.',
            );

            $applications->createFor($coverPost->refresh(), [
                'first_name' => 'Leah',
                'last_name' => 'Sutcliffe',
                'email' => 'leah.sutcliffe@example.com',
                'phone' => '+44 7700 900633',
                'source' => 'agency',
                'notes' => 'Two years on a facilities helpdesk in Salford.',
            ], $generalist);

            $secondCandidate = $applications->createFor($coverPost->refresh(), [
                'first_name' => 'Owen',
                'last_name' => 'Pritchard',
                'email' => 'owen.pritchard@example.com',
                'phone' => '+44 7700 900771',
                'source' => 'referral',
                'notes' => 'Erin knows him from a previous contract.',
            ], $generalist);

            $applications->addStage($secondCandidate, [
                'name' => 'Interview with the Operations Manager',
                'scheduled_at' => now()->addDays(3),
                'interviewer_id' => $opsManager->getKey(),
            ], $opsManager);

            // ------------------------------------------------------------ the head still waiting
            // In a company of eight the MD decides every head personally, and the route says so in
            // data rather than in code. This one is sitting in Rowan Calder's My Approvals.
            $apprenticePost = $vacancies->create([
                'title' => 'Apprentice Mechanical Technician',
                'position_id' => $post['MT']->id,
                'org_unit_id' => $unit['MNT-MEC']->id,
                'location_id' => $place['MCR']->id,
                'hiring_manager_id' => $maintManager->getKey(),
                'employment_type' => 'apprenticeship',
                'openings' => 1,
                'summary' => 'Three-year apprenticeship shadowing the mechanical rounds, day '
                    .'release at the college.',
                'requirements' => 'Level 2 maths and English, and an interest in building services.',
                'target_start_date' => now()->addMonths(2)->toDateString(),
                'closes_on' => now()->addWeeks(5)->toDateString(),
            ], $maintManager);

            $vacancies->submit($apprenticePost, $maintManager);

            // ------------------------------------------------------------ the one who left
            // Notice served, worked out, nothing outstanding, last day already past. This is the
            // only exit in the demo that goes all the way: it ends the employment, switches the
            // sign-in off and revokes the access in one transaction — and leaves every timesheet,
            // approval and job he ever touched exactly where it was.
            $leaving = $exits->open($staff['KFM-007'], [        // Josh Aiken, helpdesk
                'reason' => 'resignation',
                'notified_on' => now()->subWeeks(7)->toDateString(),
                'last_working_day' => now()->subDays(10)->toDateString(),
                'notes' => 'Left to study full time. Four weeks\' notice, worked in full.',
                'interview_notes' => 'Enjoyed the team and the variety; found the call-out rota hard '
                    .'to plan around while studying. Asked us to look again at how the rota is '
                    .'published — it goes out a week ahead and that is not long enough.',
                'eligible_for_rehire' => true,
            ], $generalist);

            $exitList = $leaving->checklist;

            if ($exitList !== null) {
                foreach ($exitList->items()->orderBy('sequence')->get() as $item) {
                    $lists->tick($item, $item->requires_note ? 'Returned and checked off on his last afternoon.' : null, $generalist);
                }
            }

            $exits->clear($leaving->refresh(), $generalist);
            $exits->complete($leaving->refresh(), $generalist);
        });
    }

    /**
     * A CV on the private disk, through the real upload path.
     *
     * The same argument as `uploadRevision()` above: a row with a made-up `cv_path` seeds an
     * applicant whose download button 404s, and a CV is precisely the file §67 is about — somebody's
     * address, phone number and employment history, streamed only to an authorized reader.
     */
    private function attachDemoCv(Applicant $applicant, string $summary, User $actor): void
    {
        $bytes = $this->pdfs->renderHtml(
            $applicant->fullName().' — curriculum vitae',
            '<h1>'.e($applicant->fullName()).'</h1>'
            .'<p>'.e($summary).'</p>'
            .'<p><em>Demonstration file. What matters here is that the bytes are on the private '
            .'disk and the download route has to authorize before it will hand them over.</em></p>',
        );

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'demo-cv-'.Str::random(16).'.pdf';
        file_put_contents($path, $bytes);

        try {
            app(ApplicantService::class)->attachCv(
                $applicant,
                new UploadedFile($path, Str::slug($applicant->fullName()).'-cv.pdf', 'application/pdf', null, true),
                $actor,
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * A plausible past for a company that was set up months ago (section 60).
     *
     * Written through ActivityRecorder rather than inserted, so the demo exercises the same engine
     * every controller calls — a bug in the recorder shows up here rather than on the first real
     * record somebody creates.
     *
     * Two things are deliberate. Rows are ATTRIBUTED: the org chart was built by whoever runs the
     * system, people were added by HR, accounts by IT. A timeline where one name did everything is
     * worse than no timeline, because it is confidently wrong. And rows are BACK-DATED in setup
     * order — structure first, then places and posts, then roles, then people — so the timeline
     * reads as a company being stood up rather than as one frantic afternoon.
     *
     * Nothing here needs to survive a re-seed: DatabaseSeeder refuses to run against production
     * and the whole demo is disposable.
     */
    private function seedHistory(Organization $organization): void
    {
        $this->context->within($organization, function () {
            $recorder = app(ActivityRecorder::class);

            $people = Employee::with(['user', 'orgUnit', 'position'])->orderBy('id')->get();

            // The first employee seeded is the managing director, and their account is the one the
            // console prints as the way in. Making them the causer of the setup work is what puts
            // something on the dashboard of the person most likely to sign in first.
            $founder = $people->first()?->user;

            if ($founder === null) {
                return;
            }

            $hr = $this->whoever($people, ['hr', 'human resources']) ?? $founder;
            $it = $this->whoever($people, ['it ', 'administrator', 'systems']) ?? $founder;

            // --- structure, oldest ------------------------------------------------------
            $day = 96;

            foreach (OrgUnit::orderBy('id')->get() as $unit) {
                $recorder->record($unit, 'created',
                    $founder->name.' created the '.$unit->type->value.' “'.$unit->name.'”',
                    actor: $founder, at: $this->momentOn($day));

                $day = max(88, $day - 1);
            }

            // --- places and posts -------------------------------------------------------
            foreach (Location::orderBy('id')->get() as $location) {
                $recorder->record($location, 'created', $founder->name.' added the '.$location->name,
                    actor: $founder, at: $this->momentOn(86));
            }

            foreach (Position::orderBy('id')->get() as $position) {
                $recorder->record($position, 'created', $hr->name.' defined the post of '.$position->title,
                    actor: $hr, at: $this->momentOn(84));
            }

            // --- who may do what --------------------------------------------------------
            foreach (Role::orderBy('id')->get() as $role) {
                $recorder->record($role, 'created', $it->name.' created the role “'.$role->name.'”',
                    actor: $it, at: $this->momentOn(80));
            }

            // --- people, spread across the months since ---------------------------------
            // Newest hire last, so "recent activity" on the dashboard is genuinely the recent end
            // of a real sequence rather than a random sample of one.
            $day = 74;
            $step = max(1, intdiv(70, max(1, $people->count())));

            foreach ($people as $employee) {
                $recorder->record($employee, 'created',
                    $hr->name.' added '.$employee->fullName().' to '.($employee->orgUnit?->name ?? 'the company'),
                    actor: $hr, at: $this->momentOn($day));

                if ($employee->user !== null) {
                    $recorder->record($employee->user, 'created',
                        $it->name.' issued a system account to '.$employee->fullName(),
                        actor: $it, at: $this->momentOn(max(4, $day - 1)));

                    $recorder->record($employee->user, 'assigned',
                        $it->name.' set the roles held by '.$employee->fullName(),
                        actor: $it, at: $this->momentOn(max(3, $day - 1)));
                }

                $day = max(5, $day - $step);
            }

            // --- the last fortnight -----------------------------------------------------
            // A handful of ordinary edits by the managing director, so the dashboard timeline of
            // the demonstration account is populated the moment it signs in.
            foreach ($people->take(6) as $offset => $employee) {
                $recorder->record($employee, 'updated',
                    $founder->name.' revised the record for '.$employee->fullName(),
                    actor: $founder, at: $this->momentOn(12 - $offset * 2));
            }
        });
    }

    /**
     * The account of the first person whose job title mentions any of these words.
     *
     * Matched on the title rather than on a role slug because role slugs are configurable per
     * company (section 73) — the whole point of the two demo tenants is that they do not share a
     * vocabulary, and a helper that assumed they did would work for one and silently fall back for
     * the other.
     *
     * @param  Collection<int,Employee>  $people
     * @param  array<int,string>  $words
     */
    private function whoever($people, array $words): ?User
    {
        foreach ($people as $employee) {
            $title = Str::lower((string) $employee->position?->title).' ';

            foreach ($words as $word) {
                if (str_contains($title, $word)) {
                    return $employee->user;
                }
            }
        }

        return null;
    }

    /** A believable working-hours timestamp this many days ago. */
    private function momentOn(int $daysAgo): \DateTimeInterface
    {
        return now()->subDays(max(0, $daysAgo))
            ->setTime(random_int(8, 17), random_int(0, 59), random_int(0, 59));
    }

    // ================================================================= helpers

    private function unit(Organization $org, string $type, string $name, string $code, ?OrgUnit $parent = null): OrgUnit
    {
        return $this->units->create([
            'organization_id' => $org->id,
            'parent_id' => $parent?->id,
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int,array{0:string,1:string,2:string,3:string,4:float,5:float,6:int}>  $rows
     * @return array<string,Location>
     */
    private function locations(Organization $org, array $rows): array
    {
        $out = [];

        foreach ($rows as [$name, $code, $type, $city, $lat, $lng, $radius]) {
            $out[$code] = Location::create([
                'organization_id' => $org->id,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'city' => $city,
                'country' => $org->country,
                'latitude' => $lat,
                'longitude' => $lng,
                'geofence_radius' => $radius,
                'timezone' => $org->timezone,
                'is_active' => true,
            ]);
        }

        return $out;
    }

    /**
     * @param  array<int,array{0:string,1:string,2:?OrgUnit,3:int,4:bool}>  $rows
     * @return array<string,Position>
     */
    private function positions(Organization $org, array $rows): array
    {
        $out = [];

        foreach ($rows as [$title, $code, $unit, $grade, $isManagement]) {
            $out[$code] = Position::create([
                'organization_id' => $org->id,
                'org_unit_id' => $unit?->id,
                'title' => $title,
                'code' => $code,
                'grade' => $grade,
                'is_management' => $isManagement,
                'is_active' => true,
            ]);
        }

        return $out;
    }

    private function person(
        Organization $org,
        string $employeeNo,
        string $firstName,
        string $lastName,
        OrgUnit $unit,
        Position $position,
        ?Employee $supervisor,
        Location $location,
        Role $role,
        string $emailLocalPart,
    ): Employee {
        $email = $emailLocalPart.'@'.$org->slug.'.example';

        // is_super_admin, must_change_password and the verification stamp are deliberately NOT
        // mass-assignable on User — a privilege flag that can arrive from a request payload is a
        // privilege-escalation bug waiting to happen. They are set explicitly, here and nowhere
        // reachable from user input.
        $user = new User([
            'organization_id' => $org->id,
            'name' => $firstName.' '.$lastName,
            'username' => $emailLocalPart,
            'email' => $email,
            'password' => $this->password,
            'is_active' => true,
            'timezone' => $org->timezone,
        ]);

        $user->email_verified_at = now();
        $user->is_super_admin = false;
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();

        $employee = Employee::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'employee_no' => $employeeNo,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $firstName.' '.$lastName,
            'org_unit_id' => $unit->id,
            'position_id' => $position->id,
            'supervisor_id' => $supervisor?->id,
            'location_id' => $location->id,
            'work_email' => $email,
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'hired_on' => now()->subMonths(random_int(6, 96))->startOfMonth(),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $employee;
    }

    /** @param array<int,int> $map orgUnitId => employeeId */
    private function setHeads(array $map): void
    {
        foreach ($map as $unitId => $employeeId) {
            OrgUnit::withoutGlobalScopes()->where('id', $unitId)
                ->update(['head_employee_id' => $employeeId]);
        }
    }
}
