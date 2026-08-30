<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Organization\OrgUnitService;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\ProjectType;
use App\Models\Role;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTeamService;
use App\Services\TaskChecklistService;
use App\Services\TaskDependencyService;
use App\Services\TaskService;
use Illuminate\Database\Seeder;
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

    private StatusMachine $statuses;

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

        // The rows above were written straight to the database, so nothing observed them happening.
        // A demo whose record timelines are all blank teaches the wrong lesson about Engine 4 — the
        // timeline is meant to be the first thing you see on a record, and an empty one looks like
        // a missing feature rather than a missing history.
        $this->seedHistory($meridian);
        $this->seedHistory($kestrel);

        $this->command?->newLine();
        $this->command?->info('Demo organizations created:');
        $this->command?->table(
            ['Organization', 'Structure', 'Units', 'People', 'Projects', 'Tasks', 'Sign in with'],
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
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function tally(Organization $organization): array
    {
        return $this->context->within($organization, fn () => [
            $organization->orgUnits()->count(),
            $organization->employees()->count(),
            Project::query()->count(),
            Task::query()->count(),
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

            $this->person($organization, 'MEI-0021', 'Sadiq', 'Bello', $stores, $positions['STRO'],
                $prcManager, $locations['WH-01'], $roles['store-officer'], 's.bello');

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

            $opsManager = $this->person($organization, 'KFM-002', 'Priti', 'Shah', $ops, $positions['OM'],
                $md, $locations['MCR'], $roles['operations-manager'], 'p.shah');

            $maintManager = $this->person($organization, 'KFM-003', 'Duncan', 'Reilly', $maintenance, $positions['MM'],
                $md, $locations['LDS'], $roles['department-head'], 'd.reilly');

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

        $start = now()->subDays($overDays)->setTime(8, 30);
        $span = max(60, $overDays * 24 * 60 - 180);   // finish a few hours short of now
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

    // ================================================================= narrative history

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
