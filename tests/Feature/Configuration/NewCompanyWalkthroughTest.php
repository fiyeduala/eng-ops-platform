<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * §73, walked end to end: a company sets itself up without anybody opening a database.
 *
 * THE SURFACES ARE ALL PROVEN ALREADY AND THAT IS EXACTLY THE PROBLEM. There is a test for
 * creating an org unit, a test for numbering, a test for statuses, a test for building a workflow
 * through the UI, a test for the document register and a test for reports. Every one of them
 * arranges the world it needs with a factory and then exercises one screen. None of them can fail
 * when the JOIN between two screens is missing — when the workflow builder cannot see the register
 * it is meant to route, when the category screen is unreachable for the only role that would use
 * it, when the thing the previous step produced does not appear in the dropdown of the next. A
 * platform assembled from individually-correct screens is §90's "collection of unconnected CRUD
 * modules", and the only test that can tell the difference is one that walks the whole way.
 *
 * WHAT THIS ONE FOUND. Before it was written, a company provisioned from the role templates had no
 * assignable role holding `document_category.manage`. Every screen behind it worked; every test of
 * those screens passed, because they all acted as a user the fixture had handed the permission to
 * directly. In a real company the Configuration Center simply did not show the tile, to anybody,
 * and the register at the centre of this walk could not be created at all.
 *
 * TWO ACTORS, AND THE SPLIT IS THE POINT.
 *
 *   IFEOMA ADEYEMI is the organization owner — the person who signed the company up. She does one
 *   thing: creates accounts and hands out roles. She has to, and it is not a gap: RolePolicy
 *   forbids granting a permission you do not hold yourself, so staffing a company with roles the
 *   administrator deliberately lacks is the owner's job by design, not by omission.
 *
 *   ADAEZE OKONKWO holds the seeded System Administrator role and nothing else. `is_super_admin`
 *   is false, which is what makes this a test: with it true the resolver short-circuits before it
 *   ever looks at a role, every permission answers yes, and the walk would pass against a
 *   completely empty set of grants. The final test in this file guards that flag, because a
 *   fixture that quietly promoted her would turn this whole file green and meaningless.
 *
 * Nothing below creates a status, a scheme, a category, a workflow, a stage, a document or a report
 * through a model or a service. Every one of them is a POST to the endpoint the screen posts to,
 * and the ones that are meant to be reachable from the previous screen are asserted to be on it.
 *
 * @see RoleTemplateCoverageTest for the standing guard that no permission is grantable by nobody
 * @see ReorganizationWalkthroughTest for §74 — what survives moving a department mid-approval
 */
final class NewCompanyWalkthroughTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** The organization owner: signs the company up, staffs it, and does nothing else. */
    private User $ifeoma;

    /** System Administrator, and deliberately not a super admin. */
    private User $adaeze;

    protected function setUp(): void
    {
        parent::setUp();

        // A company signs up. This is the one act that is not a screen — it is what the sign-up
        // produces — and everything the provisioner leaves behind is fair game for the walk.
        $this->org = app(OrganizationProvisioner::class)->provision([
            'name' => 'Harmattan Engineering Services',
            'legal_name' => 'Harmattan Engineering Services Limited',
            'industry' => 'Civil and structural engineering',
            'country' => 'NG',
            'timezone' => 'Africa/Lagos',
            'currency' => 'NGN',
        ]);

        $this->ifeoma = $this->makeOwner('Ifeoma Adeyemi');
        $this->adaeze = $this->personHolding('Adaeze Okonkwo', 'system-administrator');
    }

    /**
     * §96, and the first thing a new administrator actually sees.
     *
     * The hub is built from permissions, so it is also an inventory of what the signed-in person
     * can reach. If a tile the walkthrough depends on is missing here, the walk does not fail
     * later with a 403 — it fails at the point where a real administrator gives up, because the
     * screen they needed was never offered to them.
     */
    #[Test]
    public function the_configuration_centre_offers_the_administrator_every_screen_the_walk_needs(): void
    {
        // What the administrator has to be able to reach to do steps 2 through 8 of the walk.
        $this->assertHubOffers($this->adaeze, 'System Administrator', [
            'Statuses &amp; transitions' => 'the vocabulary records move through (§63)',
            'Numbering schemes' => 'the format their numbers take (§64)',
            'Document registers' => 'the categories the register is built from',
            'Approval workflows' => 'the route a document travels (§78)',
            'Renewals &amp; expiry' => 'what has to be chased before it lapses',
            'Roles &amp; permissions' => 'adjusting what the seeded roles mean (§5)',
        ]);

        // And what the person who READS the register back has to reach, which is deliberately a
        // different person. The administrator holds no report, measure or board permissions: the
        // template's whole design is that it builds the machine without operating it.
        $this->assertHubOffers($this->personHolding('Tunde Bello', 'managing-director'), 'Managing Director', [
            'Reports' => 'reading the register back (§55)',
            'Measures' => 'what the company counts (§56)',
            'Boards' => 'where those measures are shown (§52)',
        ]);
    }

    #[Test]
    public function a_new_company_becomes_a_routed_numbered_reportable_register_without_touching_the_database(): void
    {
        // ---- 1. Adaeze draws the org chart. --------------------------------------------------
        $this->actingFresh($this->adaeze)
            ->post(route('org-units.store'), [
                'name' => 'Engineering',
                'type' => 'division',
                'code' => 'ENG',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $engineering = $this->unit('Engineering');

        $this->actingFresh($this->adaeze)
            ->post(route('org-units.store'), [
                'name' => 'Civil',
                'type' => 'department',
                'code' => 'CIV',
                'parent_id' => (string) $engineering->getKey(),
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $civil = $this->unit('Civil');

        // The department the screen just created is on the screen it redirected to, and it is
        // under the division — not a second root that happens to share a name.
        $this->actingFresh($this->adaeze)->get(route('org-units.index'))
            ->assertOk()->assertSee('Civil')->assertSee('Engineering');

        $this->assertSame((int) $engineering->getKey(), (int) $civil->parent_id);
        $this->assertTrue($civil->isDescendantOf($engineering));

        // ---- 2. And gives it a job title. ----------------------------------------------------
        $this->actingFresh($this->adaeze)
            ->post(route('positions.store'), [
                'title' => 'Civil Design Engineer',
                'code' => 'CDE',
                'org_unit_id' => (string) $civil->getKey(),
                'grade' => 4,
                'is_management' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $position = $this->within(fn () => Position::query()->where('title', 'Civil Design Engineer')->sole());

        $this->assertSame((int) $civil->getKey(), (int) $position->org_unit_id,
            'The position was created against a different unit than the one chosen on the form.');

        // ---- 3. The owner staffs the chart Adaeze just drew. ---------------------------------
        // Three people, three seeded roles. The owner does this because RolePolicy::mayGrantAll()
        // forbids handing out a permission you do not hold — the System Administrator template
        // carries no authority over business records, which is the separation it exists to draw.
        //
        // AN ACCOUNT IS NOT A PERSON, and this order is not cosmetic. `users.store` creates a
        // sign-in; it does not put anybody in a department. A role scoped to `department` — which
        // is most of them — resolves its reach through the employee record, so an account with a
        // department-scoped role and no employee behind it holds a permission that reaches nothing:
        // the approval lands on them, their inbox is empty, and neither screen says why. The org
        // chart therefore has to exist before the people do.
        $ngozi = $this->ownerCreates('Ngozi Eze', 'ngozi.eze@harmattan.example', 'document-controller', $civil);
        $chidi = $this->ownerCreates('Chidi Nwosu', 'chidi.nwosu@harmattan.example', 'engineering-manager', $civil);
        $tunde = $this->ownerCreates('Tunde Bello', 'tunde.bello@harmattan.example', 'managing-director', $engineering);

        // ---- 4. The vocabulary this register will use (§63). ----------------------------------
        $this->actingFresh($this->adaeze)
            ->post(route('configuration.statuses.store', 'document'), [
                'label' => 'With the client',
                'color' => 'brand',
            ])
            ->assertSessionHasNoErrors();

        $withClient = $this->within(
            fn () => Status::query()->forEntity('document')->where('key', 'with_the_client')->sole());

        $this->assertFalse($withClient->isSystem(),
            'A status a company invented arrived marked as built in, which would make it undeletable.');

        // ---- 5. And the format its numbers take (§64). ----------------------------------------
        // The company does not call its documents DOC-2026-0001. It calls them HES-DOC-0001, and
        // §64 is only satisfied if saying so is a form and not a migration.
        $this->actingFresh($this->adaeze)
            ->put(route('configuration.numbering.update', 'document'), [
                'label' => 'Document numbering',
                'pattern' => '{PREFIX}-{SEQ}',
                'prefix' => 'HES-DOC',
                'reset_period' => 'none',
                'padding' => 4,
                'start_at' => 1,
            ])
            ->assertSessionHasNoErrors();

        // ---- 6. The register itself. The step that was impossible. ---------------------------
        $this->actingFresh($this->adaeze)->get(route('document-categories.index'))->assertOk();

        $this->actingFresh($this->adaeze)
            ->post(route('document-categories.store'), [
                'name' => 'Method Statements',
                'code' => 'MS',
                'description' => 'How each activity will actually be carried out on site.',
                'revision_scheme' => 'alpha',
                'default_confidentiality' => 'internal',
                'default_kind' => 'native',
                'is_controlled' => '1',
                'requires_approval' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $methodStatements = $this->within(
            fn () => DocumentCategory::query()->where('name', 'Method Statements')->sole());

        $this->assertNull($methodStatements->workflow_id,
            'A register created before any route exists must be unrouted, not silently attached to one.');

        // ---- 7. The route that carries it (§78). ----------------------------------------------
        $this->actingFresh($this->adaeze)
            ->post(route('workflows.store'), [
                'name' => 'Method statement approval',
                'description' => 'Engineering review, then the register is issued.',
                'entity_type' => 'document',
                'is_default' => '0',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $workflow = $this->within(fn () => Workflow::query()->where('name', 'Method statement approval')->sole());

        $this->assertNull($workflow->published_at, 'A route must be born a draft.');

        $engineeringManager = $this->role('engineering-manager');

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.stages.store', $workflow), [
                'name' => 'Engineering review',
                'instructions' => 'Check the method against the specification and the permit regime.',
                'assignee_type' => 'role',
                'assignee_ref' => (string) $engineeringManager->getKey(),
                'sla_hours' => '48',
                'action_type' => 'approve',
                'quorum' => 'all',
                'on_reject' => 'return_to_owner',
                'allow_delegate' => '1',
                'allow_self_approval' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingFresh($this->adaeze)
            ->post(route('workflows.publish', $workflow))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($workflow->refresh()->published_at,
            'Publishing did not take — a route that will not publish routes nothing.');

        // ---- 8. Joining the two. The seam this whole file exists to test. ---------------------
        // The register was made before the route existed, so the only way to connect them is the
        // category form — and the route has to be visible on it. A published workflow that does
        // not appear in this dropdown is a register that can never be routed through the UI.
        $this->actingFresh($this->adaeze)
            ->get(route('document-categories.edit', $methodStatements))
            ->assertOk()
            ->assertSee('Method statement approval');

        $this->actingFresh($this->adaeze)
            ->put(route('document-categories.update', $methodStatements), [
                'name' => 'Method Statements',
                'code' => 'MS',
                'revision_scheme' => 'alpha',
                'default_confidentiality' => 'internal',
                'default_kind' => 'native',
                'workflow_id' => (string) $workflow->getKey(),
                'is_controlled' => '1',
                'requires_approval' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame((int) $workflow->getKey(), (int) $methodStatements->refresh()->workflow_id);

        // ---- 9. Adaeze's part is over. A real document goes through what she built. -----------
        $this->actingFresh($ngozi)
            ->post(route('documents.store'), [
                'title' => 'Shoring and excavation below 1.2 m',
                'description' => 'Battered excavation, trench boxes below 1.2 m, daily inspection.',
                'document_category_id' => (string) $methodStatements->getKey(),
                'org_unit_id' => (string) $civil->getKey(),
                'confidentiality' => 'internal',
                'kind' => 'native',
                'is_controlled' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $document = $this->within(
            fn () => Document::query()->where('title', 'Shoring and excavation below 1.2 m')->sole());

        // The number carries the format Adaeze typed into a form in step 5, and nothing else.
        $this->assertMatchesRegularExpression('/^HES-DOC-\d{4}$/', (string) $document->number,
            sprintf('The register issued "%s". The scheme the administrator configured says HES-DOC-0001.',
                $document->number));

        $this->makeNativeVersion($this->org, $document, $ngozi, '<p>Shore any excavation below 1.2 m.</p>');

        $this->actingFresh($ngozi)->post(route('documents.submit', $document))
            ->assertRedirect()->assertSessionHasNoErrors();

        // ---- 10. It is routed to a role, resolved to a person, and approved. ------------------
        $stage = $this->openStageFor($document);

        $this->assertNotNull($stage,
            'Submitting produced no open stage: the register was not picked up by the route it was pointed at.');
        $this->assertSame('Engineering review', $stage->stage_name);
        $this->assertTrue($stage->isAssignedTo($chidi),
            'The stage was assigned to a ROLE. Chidi holds that role and is not on the stage, so the '
            .'resolution from role to person did not happen.');
        $this->assertFalse($stage->isAssignedTo($ngozi),
            'The author is standing on the approval of their own document.');

        $this->actingFresh($chidi)->get(route('approvals.index'))
            ->assertOk()->assertSee('Shoring and excavation below 1.2 m');

        $this->actingFresh($chidi)
            ->post(route('approvals.decide', $stage), [
                'decision' => 'approve',
                'comment' => 'Trench box detail matches the ground investigation.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($document->refresh()->currentVersion?->approved_at,
            'The route completed without the version being marked approved.');

        // ---- 11. And it can be read back. -----------------------------------------------------
        $this->actingFresh($tunde)
            ->post(route('configuration.reports.store'), [
                'name' => 'Method statements on file',
                'description' => 'Everything issued against the new register.',
                'entity_type' => 'document',
                'columns' => ['number', 'title', 'org_unit_id'],
                'sort_field' => 'created_at',
                'sort_direction' => 'desc',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $report = $this->within(
            fn () => ReportDefinition::query()->where('name', 'Method statements on file')->sole());

        $this->actingFresh($tunde)->get(route('configuration.reports.show', $report))
            ->assertOk()
            ->assertSee('Shoring and excavation below 1.2 m')
            ->assertSee((string) $document->number);
    }

    /**
     * The guard on the test above, and the reason it is worth anything.
     *
     * `AccessResolver` short-circuits on `is_super_admin` before it looks at a single role, so an
     * administrator who was quietly made an owner passes every step of the walk while holding no
     * grants at all. That failure is invisible — the walk goes green — which is precisely the kind
     * this phase exists to make loud.
     */
    #[Test]
    public function the_administrator_walked_it_on_a_seeded_role_and_not_on_owner_privileges(): void
    {
        $this->assertFalse((bool) $this->adaeze->is_super_admin,
            'The administrator in this file is an organization owner, so the walk above proves nothing '
            .'about what a role can do.');

        $slugs = $this->within(fn () => $this->adaeze->roles()->pluck('slug')->all());

        $this->assertSame(['system-administrator'], $slugs,
            'The administrator holds roles beyond the seeded System Administrator, so the walk is not '
            .'evidence that a provisioned company can be configured by a provisioned role.');

        // And the role is the template's, unedited — not one the fixture widened on its way past.
        $this->assertFalse((bool) $this->role('system-administrator')->is_system,
            'The seeded roles must stay editable (§5): a company whose org chart is nothing like this '
            .'one has to be able to rewrite them.');
    }

    /**
     * @param  array<string,string>  $tiles  tile label => why the walk needs it
     */
    private function assertHubOffers(User $user, string $role, array $tiles): void
    {
        $html = $this->actingFresh($user)->get(route('configuration.index'))->assertOk()->getContent();

        foreach ($tiles as $tile => $why) {
            $this->assertStringContainsString($tile, (string) $html,
                "The Configuration Center does not offer the {$role} “{$tile}” — {$why}.\n\n"
                ."A tile is hidden when the viewer holds none of its permissions, so this is not a view\n"
                ."bug: it means no permission behind that tile is on the {$role} template, and a company\n"
                .'provisioned from the templates cannot reach that screen at all.');
        }
    }

    // ================================================================== fixture

    /**
     * The account the sign-up produced. Deliberately hand-built: `is_super_admin` is not fillable,
     * and no request payload may ever set it.
     */
    private function makeOwner(string $name): User
    {
        return $this->withinOrganization($this->org, function () use ($name) {
            $user = new User;
            $user->organization_id = $this->org->getKey();
            $user->name = $name;
            $user->email = Str::slug($name).'@harmattan.example';
            $user->password = Hash::make('correct-horse-battery-staple');
            $user->is_active = true;
            $user->is_super_admin = true;
            $user->must_change_password = false;
            $user->password_changed_at = now();
            $user->email_verified_at = now();
            $user->save();

            return $user->fresh();
        });
    }

    /**
     * Somebody holding exactly one seeded role, attached directly.
     *
     * Used only for Adaeze, who has to exist before there is anybody to create her through a
     * screen. Everyone else in the walk is created by the owner through `users.store`.
     */
    private function personHolding(string $name, string $roleSlug): User
    {
        return $this->makePerson($this->org, $name, null, [$this->role($roleSlug)]);
    }

    /**
     * The owner creates an account, a personnel record and a role assignment — all three through
     * the screens, in the order the screens make possible.
     */
    private function ownerCreates(string $name, string $email, string $roleSlug, OrgUnit $unit): User
    {
        $this->actingFresh($this->ifeoma)
            ->post(route('users.store'), [
                'name' => $name,
                'email' => $email,
                'timezone' => 'Africa/Lagos',
                'locale' => 'en',
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->within(fn () => User::query()->where('email', $email)->sole());

        // The personnel record, which is what puts them in a department. See the note at step 3.
        $this->actingFresh($this->ifeoma)
            ->post(route('employees.store'), [
                // Typed rather than generated, and deliberately so: a staff number usually predates
                // the platform and carries a payroll meaning the register must not invent.
                'employee_no' => 'HES-'.Str::upper(Str::substr(Str::after($name, ' '), 0, 3)).'-01',
                'first_name' => Str::before($name, ' '),
                'last_name' => Str::after($name, ' '),
                'user_id' => (string) $account->getKey(),
                'org_unit_id' => (string) $unit->getKey(),
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'hired_on' => now()->subMonths(3)->toDateString(),
                'is_active' => '1',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $role = $this->role($roleSlug);

        $this->actingFresh($this->ifeoma)
            ->put(route('users.roles.update', $account), [
                'roles' => [(string) $role->getKey()],
                'scope_type' => [],
                'scope_id' => [],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $account = $account->fresh(['roles']);

        $this->assertContains($roleSlug, $this->within(fn () => $account->roles()->pluck('slug')->all()),
            "The owner's role assignment for {$name} did not take, so every later step would fail as a 403 "
            .'for a reason that has nothing to do with what it is testing.');

        return $account;
    }

    private function role(string $slug): Role
    {
        return $this->within(fn () => Role::query()->where('slug', $slug)->sole());
    }

    private function unit(string $name): OrgUnit
    {
        return $this->within(fn () => OrgUnit::query()->where('name', $name)->sole());
    }

    private function openStageFor(Document $document): ?WorkflowStageInstance
    {
        return $this->within(function () use ($document) {
            $instance = WorkflowInstance::query()
                ->where('subject_type', 'document')
                ->where('subject_id', $document->getKey())
                ->whereNull('completed_at')
                ->orderByDesc('id')
                ->first();

            return $instance === null
                ? null
                : WorkflowStageInstance::query()->whereKey($instance->current_stage_instance_id)->first();
        });
    }

    /**
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    private function within(callable $work): mixed
    {
        return $this->withinOrganization($this->org, $work);
    }
}
