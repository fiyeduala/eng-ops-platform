<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * One box across every register (§61), and the promise docs/05 makes about it: global search
 * returns exactly the same set as the index pages.
 *
 * PARITY IS TESTED AS PARITY, not as two lists that happen to agree. Several cases below ask the
 * same question twice — once of the register's own index page and once of the search box — and
 * assert the same answer, because the whole design is that there is one query underneath. If
 * somebody ever reimplements matching inside the search engine, these are the tests that fail.
 *
 * AND THE INTERESTING HALF IS WHAT IS ABSENT. A register the reader holds no permission for has no
 * heading on the results page, not a heading with nothing under it: a heading names a module,
 * spells it the company's way, and reports whether a term matched inside it — three answers
 * somebody without the permission was never entitled to. Narrowing to such a register by editing
 * the URL is a 404 rather than an empty page, for the reason the drill-down gives (§84, §94).
 *
 *   Civil (department)    Ngozi   documents in Civil, and no task permission at all
 *   (company)             Tunde   everything
 */
final class GlobalSearchTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $tunde;

    private User $ngozi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $this->engineering);

        $director = $this->makeRole($this->org, 'Operations Director', [
            'document.view' => 'organization',
            'document.view_confidential' => 'organization',
            'document.create' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
            'memo.view' => 'organization',
            'memo.create' => 'organization',
            'project.view' => 'organization',
            'project.create' => 'organization',
            'org_unit.view' => 'organization',
            'employee.view' => 'organization',
            'dashboard.view' => 'organization',
        ]);

        // Documents in her own department, and nothing on the task register. Both halves are used.
        $departmental = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'org_unit.view' => 'department',
            'dashboard.view' => 'organization',
        ]);

        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', null, [$director]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$departmental]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== parity

    #[Test]
    public function the_box_returns_what_the_registers_own_index_page_returns(): void
    {
        $this->threeProcedures();

        // The claim docs/05 makes, asked twice of the same person. The register's index page and
        // the narrowed search page are the same query with the same term.
        $this->actingFresh($this->ngozi)->get(route('documents.index', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertSee('Scaffolding inspection procedure')
            ->assertDontSee('Hydrotest procedure');

        $this->actingFresh($this->ngozi)->get(route('search', ['q' => 'procedure', 'type' => 'document']))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertSee('Scaffolding inspection procedure')
            ->assertDontSee('Hydrotest procedure');
    }

    #[Test]
    public function one_term_gives_each_reader_their_own_answer(): void
    {
        $this->threeProcedures();

        // Same box, same words, two companies' worth of difference inside one company.
        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Hydrotest procedure');

        $this->actingFresh($this->ngozi)->get(route('search', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertDontSee('Hydrotest procedure');
    }

    #[Test]
    public function the_employee_register_is_searched_by_the_rule_its_own_page_uses(): void
    {
        // The rule moved from EmployeeController onto the model in this part. Both screens must
        // still answer with the person, which is what proves the move did not change anything.
        $this->actingFresh($this->tunde)->get(route('employees.index', ['q' => 'Ngozi']))
            ->assertOk()
            ->assertSee('Ngozi');

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'Ngozi']))
            ->assertOk()
            ->assertSee('Ngozi');
    }

    // ================================================================== what is absent

    #[Test]
    public function a_register_the_reader_cannot_read_has_no_heading_at_all(): void
    {
        $this->makeTask($this->org, $this->tunde, 'Hydrotest procedure walkdown', [
            'org_unit_id' => $this->civil->getKey(),
        ]);

        $this->documentIn($this->civil, 'Lifting operations procedure');

        // §61, §6. Ngozi holds nothing on the task register, so "Tasks" is not a heading with
        // nothing under it — it is not on the page, which survives a screenshot and a print view.
        $this->actingFresh($this->ngozi)->get(route('search', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertDontSee('Hydrotest procedure walkdown')
            ->assertDontSee('>Tasks<', false);

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Hydrotest procedure walkdown')
            ->assertSee('>Tasks<', false);
    }

    #[Test]
    public function narrowing_to_a_register_the_reader_cannot_read_is_not_found(): void
    {
        $this->makeTask($this->org, $this->tunde, 'Hydrotest procedure walkdown');

        // §84. The type is a query parameter, so it is somebody's suggestion. An empty list here
        // would be indistinguishable from "no task matches", which is a different statement.
        $this->actingFresh($this->ngozi)->get(route('search', ['q' => 'procedure', 'type' => 'task']))
            ->assertNotFound();

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure', 'type' => 'task']))
            ->assertOk()
            ->assertSee('Hydrotest procedure walkdown');
    }

    #[Test]
    public function a_register_that_is_not_searchable_is_not_found(): void
    {
        // Neither a typo nor a register — a payslip has no `search()` scope, so it has no index
        // page behaviour to be at parity with and is not offered here.
        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure', 'type' => 'payslip']))
            ->assertNotFound();

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure', 'type' => 'not_a_register']))
            ->assertNotFound();
    }

    #[Test]
    public function a_memo_body_is_not_searched(): void
    {
        $this->makeMemo($this->org, $this->tunde, 'Site access arrangements', [
            'body' => 'Circulate to the full distribution list before Friday.',
        ]);

        // Memo::scopeSearch matches subject and number and refuses the body, and the search box
        // inherits that refusal rather than overruling it from a catalogue that never heard the
        // argument (§61). Tunde wrote the memo, so this is not visibility doing the work.
        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'distribution']))
            ->assertOk()
            ->assertDontSee('Site access arrangements');

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'Site access']))
            ->assertOk()
            ->assertSee('Site access arrangements');
    }

    // ================================================================== boundaries

    #[Test]
    public function another_companys_records_are_never_reachable_through_the_box(): void
    {
        $other = $this->makeOrganization('Kaduna Fabricators');

        $theirDirector = $this->makePerson($other, 'Bilkisu Sani', null, [
            $this->makeRole($other, 'Director', [
                'document.view' => 'organization',
                'document.create' => 'organization',
                'dashboard.view' => 'organization',
            ]),
        ]);

        $theirCategory = $this->makeDocumentCategory($other, 'Procedures', ['requires_approval' => false]);

        $this->makeDocument($other, $theirDirector, 'Kaduna welding procedure', [
            'document_category_id' => $theirCategory->getKey(),
        ]);

        $this->documentIn($this->civil, 'Lifting operations procedure');

        // §75. One term, one box, and the tenant boundary is the same SQL it is everywhere else.
        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'procedure']))
            ->assertOk()
            ->assertSee('Lifting operations procedure')
            ->assertDontSee('Kaduna welding procedure');
    }

    #[Test]
    public function a_single_character_is_not_a_search(): void
    {
        $this->threeProcedures();

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'p']))
            ->assertOk()
            ->assertSee('Type at least 2 characters')
            ->assertDontSee('Lifting operations procedure');
    }

    #[Test]
    public function a_term_that_matches_nothing_says_so_rather_than_showing_every_register(): void
    {
        $this->threeProcedures();

        $this->actingFresh($this->tunde)->get(route('search', ['q' => 'zzzznothing']))
            ->assertOk()
            ->assertSee('Nothing matches')
            ->assertDontSee('Lifting operations procedure');
    }

    #[Test]
    public function the_box_is_on_every_page(): void
    {
        // §96 in its simplest form: the shortcut is where somebody will look for it, and it needs
        // no permission because everything underneath it has its own.
        $this->actingFresh($this->ngozi)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('search'));
    }

    // ================================================================== fixtures

    private function threeProcedures(): void
    {
        $this->documentIn($this->civil, 'Lifting operations procedure');
        $this->documentIn($this->civil, 'Scaffolding inspection procedure');
        $this->documentIn($this->piping, 'Hydrotest procedure');
    }

    private function documentIn(OrgUnit $unit, string $title): void
    {
        $this->makeDocument($this->org, $this->tunde, $title, [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $unit->getKey(),
        ]);
    }
}
