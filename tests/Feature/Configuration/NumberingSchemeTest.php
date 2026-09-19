<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Configuration\NumberGenerator;
use App\Models\NumberingScheme;
use App\Models\NumberSequence;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The reference-numbering editor (Phase 7, Part 2A).
 *
 * EVERY TEST HERE IS ABOUT ONE FAILURE: two records bearing the same reference. That failure is
 * permanent in a way most bugs are not — the duplicate is already in a client's inbox and printed
 * in a title block by the time anyone notices — so the screen is built to refuse rather than warn,
 * and these tests hold it to that.
 *
 * The four ways an administrator can cause it, each with a test:
 *
 *   - a pattern with no counter in it, so every record renders the same string
 *   - a counter that restarts without a date in the pattern, so next year repeats this year
 *   - a starting number wound back below what has already been issued
 *   - a scheme deleted and re-provisioned from 1 (there is no delete, and a test says so)
 *
 * Plus the usual three: the register in the URL is not evidence (§84), another company's schemes
 * do not exist (§75), and reading is not writing (§6).
 */
final class NumberingSchemeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $admin;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $this->admin = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [
            $this->makeRole($this->org, 'Document Controller', [
                'numbering.view' => 'organization',
                'numbering.manage' => 'organization',
            ]),
        ]);

        $this->reader = $this->makePerson($this->org, 'Bem Tarkighir', null, [
            $this->makeRole($this->org, 'Onlooker', ['numbering.view' => 'organization']),
        ]);
    }

    // ================================================================== reading

    #[Test]
    public function the_list_shows_every_numbered_register_and_what_its_next_reference_looks_like(): void
    {
        $this->actingFresh($this->admin)->get(route('configuration.numbering.index'))
            ->assertOk()
            ->assertSee('Project')
            ->assertSee('{PREFIX}-{YYYY}-{SEQ}')
            // The rendered example, not the specification. A screen showing only patterns would be
            // a screen for developers.
            ->assertSee('PRJ-'.now()->format('Y').'-0001');
    }

    #[Test]
    public function opening_a_register_provisions_the_scheme_the_platform_is_already_using(): void
    {
        // A company provisioned before this register existed has no scheme row for it — but
        // numbers ARE issued for it, from the platform default, the moment somebody creates one.
        // An editor showing an empty page would be lying about that.
        $this->withinOrganization($this->org,
            fn () => NumberingScheme::query()->forEntity('purchase_order')->delete());

        $this->assertSame(0, $this->countSchemes('purchase_order'));

        $this->actingFresh($this->admin)->get(route('configuration.numbering.show', 'purchase_order'))
            ->assertOk()
            ->assertSee('PO', false);

        $scheme = $this->schemeFor('purchase_order');

        $this->assertSame('{PREFIX}-{YYYY}-{SEQ}', $scheme->pattern);
        $this->assertSame('PO', $scheme->prefix);
        $this->assertSame((int) $this->org->getKey(), (int) $scheme->organization_id);
    }

    #[Test]
    public function a_register_the_platform_does_not_number_is_not_a_register(): void
    {
        // §84. Without the check, schemeFor() would happily invent a scheme for whatever the URL
        // said and the list would grow a row per typo.
        $this->actingFresh($this->admin)
            ->get(route('configuration.numbering.show', 'whatever_i_typed'))
            ->assertNotFound();

        $this->assertSame(0, $this->countSchemes('whatever_i_typed'));
    }

    // ================================================================== the duplicate-reference guards

    #[Test]
    public function a_pattern_that_does_not_use_the_counter_is_refused(): void
    {
        $scheme = $this->schemeFor('project');

        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}-{YYYY}',
            ]))
            ->assertSessionHasErrors('pattern');

        $this->assertSame($scheme->pattern, $this->schemeFor('project')->pattern);
    }

    #[Test]
    public function a_counter_that_restarts_must_carry_the_date_that_tells_the_periods_apart(): void
    {
        // THE FAILURE THIS PREVENTS ARRIVES TWELVE MONTHS LATE. PRJ-0001 in January 2026 and
        // PRJ-0001 again in January 2027, long after anybody remembers changing the setting.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}-{SEQ}',
                'reset_period' => 'yearly',
            ]))
            ->assertSessionHasErrors('pattern');

        // A monthly reset needs the month too — a year alone would have February reissuing
        // January's references.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}-{YYYY}-{SEQ}',
                'reset_period' => 'monthly',
            ]))
            ->assertSessionHasErrors('pattern');

        // The same pattern with the month in it is fine, which is what makes the two above a
        // guard rather than a blanket refusal.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}-{YYYY}-{MM}-{SEQ}',
                'reset_period' => 'monthly',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('monthly', $this->schemeFor('project')->reset_period);
    }

    #[Test]
    public function a_token_the_platform_cannot_fill_in_is_refused_rather_than_silently_dropped(): void
    {
        // NumberGenerator strips tokens it cannot resolve. Without this guard an administrator who
        // types {DEPARTMENT} gets a pattern that ignores the thing they were trying to add, with
        // no error and no clue — the worst possible outcome, because it looks like it worked.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}-{DEPARTMENT}-{YYYY}-{SEQ}',
            ]))
            ->assertSessionHasErrors('pattern');

        $this->assertStringNotContainsString('DEPARTMENT', $this->schemeFor('project')->pattern);
    }

    #[Test]
    public function the_counter_cannot_be_wound_back_below_a_number_already_issued(): void
    {
        $this->withinOrganization($this->org, function () {
            app(NumberGenerator::class)->next('project');
            app(NumberGenerator::class)->next('project');
            app(NumberGenerator::class)->next('project');
        });

        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'start_at' => 2,
            ]))
            ->assertSessionHasErrors('pattern');

        $this->assertSame(1, $this->schemeFor('project')->start_at);

        // The unchanged value passes even though it is below the counter, and that is the whole
        // reason the guard asks whether start_at is MOVING: the form posts every field on every
        // save, so refusing a start_at that was already there would make the screen read-only the
        // moment a company created its first record.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'prefix' => 'PRJX',
            ]))
            ->assertSessionHasNoErrors();

        // Forward is fine: a company adopting a new series from 5000 is doing something sensible.
        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'start_at' => 5000,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(5000, $this->schemeFor('project')->start_at);
    }

    #[Test]
    public function there_is_no_way_to_delete_a_scheme(): void
    {
        // Not an omission. Deleting one would cause the next created record to provision a fresh
        // scheme starting at 1 and reissue references that are already on paper — so the only
        // observable effect of a delete button would be the duplicate this module exists to stop.
        $this->assertNull(app('router')->getRoutes()->getByName('configuration.numbering.destroy'));

        $scheme = $this->schemeFor('project');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.numbering.show', 'project'))
            ->assertStatus(405);

        $this->assertTrue($this->withinOrganization($this->org, fn () => NumberingScheme::query()
            ->whereKey($scheme->getKey())->exists()));
    }

    // ================================================================== what editing does and does not touch

    #[Test]
    public function changing_the_pattern_renames_nothing_that_was_already_issued(): void
    {
        $issued = $this->withinOrganization($this->org, fn () => app(NumberGenerator::class)->next('project'));

        $this->assertSame('PRJ-'.now()->format('Y').'-0001', $issued);

        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'pattern' => '{PREFIX}/{YYYY}/{SEQ}',
                'prefix' => 'PROJ',
                'padding' => 6,
            ]))
            ->assertSessionHasNoErrors();

        // §64: the counter is untouched by a pattern change, so the next number continues the
        // series rather than restarting it in the new shape.
        $next = $this->withinOrganization($this->org, fn () => app(NumberGenerator::class)->next('project'));

        $this->assertSame('PROJ/'.now()->format('Y').'/000002', $next);
    }

    #[Test]
    public function the_organization_is_taken_from_the_actor_not_from_the_form(): void
    {
        $other = $this->makeOrganization('Brightwater Civil');

        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                // §74. Posted, ignored, and not merely because the column is guarded — the request
                // never offers it to the service in the first place.
                'organization_id' => $other->getKey(),
                'entity_type' => 'contract',
            ]))
            ->assertSessionHasNoErrors();

        $scheme = $this->schemeFor('project');

        $this->assertSame((int) $this->org->getKey(), (int) $scheme->organization_id);
        $this->assertSame('project', $scheme->entity_type);
    }

    #[Test]
    public function another_companys_numbering_does_not_exist(): void
    {
        // §75. The second tenant has its own project scheme; editing "project" here must reach
        // this company's row and no other.
        $other = $this->makeOrganization('Brightwater Civil');
        $this->provisionConfiguration($other);

        $theirs = $this->withinOrganization($other,
            fn () => app(NumberGenerator::class)->schemeFor('project'));

        $this->actingFresh($this->admin)
            ->put(route('configuration.numbering.update', 'project'), $this->form([
                'prefix' => 'TAKEOVER',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('PRJ', $this->withinOrganization($other,
            fn () => NumberingScheme::query()->whereKey($theirs->getKey())->firstOrFail())->prefix);

        $this->assertSame('TAKEOVER', $this->schemeFor('project')->prefix);
    }

    // ================================================================== the preview

    #[Test]
    public function the_preview_answers_with_the_engines_own_rendering_and_consumes_nothing(): void
    {
        $this->withinOrganization($this->org, fn () => app(NumberGenerator::class)->next('project'));

        $before = $this->nextValueFor('project');

        $this->actingFresh($this->admin)
            ->postJson(route('configuration.numbering.preview', 'project'), [
                'pattern' => '{PREFIX}/{YY}/{SEQ:6}',
                'prefix' => 'PROJ',
                'reset_period' => 'yearly',
                'padding' => 4,
            ])
            ->assertOk()
            // The LIVE counter, not 1 — a preview built from the start value would look right for
            // a scheme already at 431 and be wrong about every number it would actually issue.
            // {SEQ:6} overrides the padding field, which is exactly the subtlety a hand-written
            // JavaScript preview would get wrong.
            ->assertJson(['example' => 'PROJ/'.now()->format('y').'/000002', 'problem' => null]);

        $this->assertSame($before, $this->nextValueFor('project'), 'the preview took a number');
    }

    #[Test]
    public function the_preview_refuses_with_the_same_sentence_the_save_would_use(): void
    {
        // A preview that happily renders something the save then rejects teaches an administrator
        // to stop reading the preview.
        $response = $this->actingFresh($this->admin)
            ->postJson(route('configuration.numbering.preview', 'project'), [
                'pattern' => '{PREFIX}-{YYYY}',
                'reset_period' => 'yearly',
            ])
            ->assertOk()
            ->assertJson(['example' => null]);

        $this->assertStringContainsString('{SEQ}', $response->json('problem'));
    }

    // ================================================================== reading is not writing

    #[Test]
    public function reading_the_numbering_is_not_permission_to_rewrite_it(): void
    {
        // §6. The page renders — a reference number appears on every register in the application
        // and a 403 on reading its shape would be wrong — but every write is refused.
        $this->actingFresh($this->reader)->get(route('configuration.numbering.index'))->assertOk();
        $this->actingFresh($this->reader)->get(route('configuration.numbering.show', 'project'))->assertOk();

        // The preview is a POST and a write-shaped request, so it is worth proving that `view` is
        // genuinely enough for it: it saves nothing and takes nothing.
        $this->actingFresh($this->reader)
            ->postJson(route('configuration.numbering.preview', 'project'), ['pattern' => '{PREFIX}-{SEQ}'])
            ->assertOk();

        $this->actingFresh($this->reader)
            ->put(route('configuration.numbering.update', 'project'), $this->form(['prefix' => 'NOPE']))
            ->assertForbidden();

        $this->assertSame('PRJ', $this->schemeFor('project')->prefix);
    }

    #[Test]
    public function an_account_holding_no_numbering_permission_cannot_reach_the_screen_at_all(): void
    {
        $nobody = $this->makePerson($this->org, 'Wale Adeyemi', null);

        $this->actingFresh($nobody)->get(route('configuration.numbering.index'))->assertForbidden();
        $this->actingFresh($nobody)->get(route('configuration.numbering.show', 'project'))->assertForbidden();
        $this->actingFresh($nobody)
            ->post(route('configuration.numbering.preview', 'project'), ['pattern' => '{SEQ}'])
            ->assertForbidden();
    }

    // ================================================================== helpers

    /**
     * A complete, valid submission, overridden field by field.
     *
     * The form posts every field on every save, so a test that posted only the field under
     * examination would be exercising a request this screen never makes.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Project numbering',
            'pattern' => '{PREFIX}-{YYYY}-{SEQ}',
            'prefix' => 'PRJ',
            'reset_period' => 'yearly',
            'padding' => 4,
            'start_at' => 1,
        ], $overrides);
    }

    private function schemeFor(string $entityType): NumberingScheme
    {
        return $this->withinOrganization($this->org,
            fn () => NumberingScheme::query()->forEntity($entityType)->firstOrFail());
    }

    /**
     * How many schemes this company has for a register.
     *
     * The query is BUILT AND RUN inside the tenant closure. A builder returned from one and
     * executed outside it resolves its scope against no organization at all, which is the failure
     * mode every fixture in this suite is written to avoid.
     */
    private function countSchemes(string $entityType): int
    {
        return $this->withinOrganization($this->org,
            fn () => NumberingScheme::query()->forEntity($entityType)->count());
    }

    private function nextValueFor(string $entityType): int
    {
        return $this->withinOrganization($this->org, fn () => (int) NumberSequence::query()
            ->where('numbering_scheme_id', $this->schemeFor($entityType)->getKey())
            ->max('next_value'));
    }
}
