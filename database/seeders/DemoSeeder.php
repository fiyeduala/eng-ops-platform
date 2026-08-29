<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Organization\OrganizationProvisioner;
use App\Engines\Organization\OrgUnitService;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
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

        // The rows above were written straight to the database, so nothing observed them happening.
        // A demo whose record timelines are all blank teaches the wrong lesson about Engine 4 — the
        // timeline is meant to be the first thing you see on a record, and an empty one looks like
        // a missing feature rather than a missing history.
        $this->seedHistory($meridian);
        $this->seedHistory($kestrel);

        $this->command?->newLine();
        $this->command?->info('Demo organizations created:');
        $this->command?->table(
            ['Organization', 'Structure', 'Units', 'People', 'Sign in with'],
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
     * @return array{0:int,1:int}
     */
    private function tally(Organization $organization): array
    {
        return $this->context->within($organization, fn () => [
            $organization->orgUnits()->count(),
            $organization->employees()->count(),
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
