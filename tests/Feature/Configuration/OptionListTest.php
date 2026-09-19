<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\OptionList;
use App\Models\OptionListItem;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Company-defined option lists (Phase 7, Part 2B).
 *
 * THE THING BEING DEFENDED IS A STORED VALUE. Custom field values keep the option's `value`
 * string, not its id — so the definition and the copies are independent, and every write to this
 * screen is a chance to strand data that will not complain. A record holding "mech" after the
 * option has been rekeyed to "mechanical" does not error; it quietly means nothing, and nobody
 * finds out until a report comes back short.
 *
 * So the tests are grouped by what would be stranded:
 *
 *   - the wording is free to change, because nothing stores it
 *   - the value is frozen once stored, because everything stores it
 *   - an option in use is retired, never deleted — and there is always that alternative
 *   - a list a field depends on cannot be deleted or rekeyed out from under it
 *
 * Plus the constants: another company's lists do not exist (§75), an option from another list
 * cannot be edited through this one (§84), and reading is not writing (§6).
 */
final class OptionListTest extends TestCase
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
            $this->makeRole($this->org, 'Configurator', [
                'custom_field.view' => 'organization',
                'custom_field.manage' => 'organization',
            ]),
        ]);

        $this->reader = $this->makePerson($this->org, 'Bem Tarkighir', null, [
            $this->makeRole($this->org, 'Onlooker', ['custom_field.view' => 'organization']),
        ]);
    }

    // ================================================================== reading

    #[Test]
    public function a_new_company_arrives_with_a_starter_vocabulary_rather_than_an_empty_screen(): void
    {
        // §73. A configuration screen that is blank on day one teaches a non-developer that the
        // feature does not work. §92: real disciplines, not "Option 1".
        $this->actingFresh($this->admin)->get(route('configuration.option-lists.index'))
            ->assertOk()
            ->assertSee('Disciplines')
            ->assertSee('Document classes');

        $this->actingFresh($this->admin)->get(route('configuration.option-lists.show', $this->list('disciplines')))
            ->assertOk()
            ->assertSee('Instrumentation')
            ->assertSee('geotechnical');
    }

    #[Test]
    public function the_starter_lists_belong_to_the_company_and_can_be_deleted_entirely(): void
    {
        // NOT marked is_system, deliberately: nothing in code names a discipline, so a company
        // that works only in marine civils must be free to throw the whole list away.
        $list = $this->list('disciplines');

        $this->assertFalse($list->isSystem());

        $this->actingFresh($this->admin)
            ->delete(route('configuration.option-lists.destroy', $list))
            ->assertRedirect(route('configuration.option-lists.index'));

        $this->assertSame(0, $this->countLists('disciplines'));
        $this->assertSame(0, DB::table('option_list_items')->where('option_list_id', $list->getKey())->count());
    }

    // ================================================================== creating

    #[Test]
    public function a_company_can_add_a_list_and_fill_it(): void
    {
        $this->actingFresh($this->admin)
            ->post(route('configuration.option-lists.store'), [
                'name' => 'Incident causes',
                'description' => 'Why it happened, in our words.',
            ])
            ->assertSessionHasNoErrors();

        // The key was derived from the name, because a non-developer should not have to invent a
        // machine name to get started (§73).
        $list = $this->list('incident_causes');

        $this->assertSame('Incident causes', $list->name);
        $this->assertSame((int) $this->org->getKey(), (int) $list->organization_id);
        $this->assertFalse($list->isSystem(), 'a list a person created must never be system');

        $this->actingFresh($this->admin)
            ->post(route('configuration.option-lists.options.store', $list), [
                'label' => 'Slip, trip or fall',
                'color' => 'warning',
            ])
            ->assertSessionHasNoErrors();

        $item = $this->item($list, 'slip_trip_or_fall');

        $this->assertSame('Slip, trip or fall', $item->label);
        $this->assertSame('warning', $item->color);
    }

    #[Test]
    public function a_colour_is_a_design_token_and_nothing_else(): void
    {
        // §84. This string reaches a class attribute; anything that is not one of the five tones
        // is dropped rather than stored, so configuration cannot style the page.
        $list = $this->list('disciplines');

        $this->actingFresh($this->admin)
            ->post(route('configuration.option-lists.options.store', $list), [
                'label' => 'Marine',
                'color' => 'bg-[url(https://elsewhere/x.png)]',
            ])
            ->assertSessionHasErrors('color');

        $this->assertSame(0, DB::table('option_list_items')
            ->where('option_list_id', $list->getKey())->where('label', 'Marine')->count());
    }

    #[Test]
    public function two_options_in_one_list_cannot_share_a_value(): void
    {
        $list = $this->list('disciplines');

        $this->actingFresh($this->admin)
            ->post(route('configuration.option-lists.options.store', $list), [
                'label' => 'Civil works',
                'value' => 'civil',
            ])
            ->assertSessionHasErrors('configuration');
    }

    // ================================================================== the stored value

    #[Test]
    public function the_wording_is_free_to_change_because_nothing_stores_it(): void
    {
        $list = $this->list('disciplines');
        $item = $this->item($list, 'mechanical');

        $this->recordAgainst($list, 'mechanical', 40);

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.options.update', [$list, $item]), [
                'label' => 'Mechanical & Piping',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->item($list, 'mechanical');

        $this->assertSame('Mechanical & Piping', $fresh->label);
        $this->assertSame('mechanical', $fresh->value, 'the value must not follow the label');
    }

    #[Test]
    public function the_value_is_frozen_once_a_record_has_stored_it(): void
    {
        $list = $this->list('disciplines');
        $item = $this->item($list, 'mechanical');

        $this->recordAgainst($list, 'mechanical', 40);

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.options.update', [$list, $item]), [
                'label' => 'Mechanical',
                'value' => 'mech',
            ])
            ->assertSessionHasErrors('configuration');

        $this->assertSame('mechanical', $this->item($list, 'mechanical')->value);

        // The refusal has to say how many records and offer the alternative, or an administrator
        // reads it as a bug and goes looking for a way round it.
        $this->assertStringContainsString('40', $this->refusal());
    }

    #[Test]
    public function a_value_nothing_has_stored_can_still_be_corrected(): void
    {
        // The guard is about stranding data, not about making the screen read-only. A typo caught
        // the same afternoon must be fixable.
        $list = $this->list('disciplines');
        $item = $this->item($list, 'geotechnical');

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.options.update', [$list, $item]), [
                'label' => 'Geotechnical',
                'value' => 'geotech',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('geotech', $this->item($list, 'geotech')->value);
    }

    // ================================================================== retire, do not delete

    #[Test]
    public function an_option_records_are_using_is_retired_rather_than_deleted(): void
    {
        $list = $this->list('disciplines');
        $item = $this->item($list, 'process');

        $this->recordAgainst($list, 'process', 3);

        $this->actingFresh($this->admin)
            ->delete(route('configuration.option-lists.options.destroy', [$list, $item]))
            ->assertSessionHasErrors('configuration');

        $this->assertStringContainsString('Switch it off instead', $this->refusal());

        // And the alternative the refusal names actually works — a guard that points at a door
        // that does not open is worse than no guard.
        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.options.update', [$list, $item]), [
                'label' => 'Process',
                'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->item($list, 'process')->is_active);

        // Retired means "not offered on new records", not "gone".
        $this->assertSame(1, DB::table('option_list_items')
            ->where('option_list_id', $list->getKey())->where('value', 'process')->count());
    }

    #[Test]
    public function an_option_nobody_has_chosen_is_simply_deleted(): void
    {
        $list = $this->list('disciplines');
        $item = $this->item($list, 'architectural');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.option-lists.options.destroy', [$list, $item]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('option_list_items')
            ->where('option_list_id', $list->getKey())->where('value', 'architectural')->count());
    }

    // ================================================================== lists a field depends on

    #[Test]
    public function a_list_a_field_offers_cannot_be_deleted_or_rekeyed(): void
    {
        $list = $this->list('disciplines');

        $this->fieldOffering($list, 'Lead discipline');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.option-lists.destroy', $list))
            ->assertSessionHasErrors('configuration');

        $this->assertStringContainsString('Lead discipline', $this->refusal());
        $this->assertSame(1, $this->countLists('disciplines'));

        // Rekeying is the same failure by another route: fields point at a list BY KEY, so a new
        // key leaves the field pointing at nothing.
        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.update', $list), [
                'name' => 'Disciplines',
                'key' => 'engineering_disciplines',
            ])
            ->assertSessionHasErrors('configuration');

        $this->assertSame('disciplines', $this->freshList($list)->key);

        // The NAME is still free, which is what makes the refusal above proportionate.
        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.update', $list), [
                'name' => 'Engineering disciplines',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Engineering disciplines', $this->freshList($list)->name);
    }

    #[Test]
    public function a_built_in_list_keeps_its_key_and_its_existence(): void
    {
        // Nothing provisions a system list today, so one is made here directly — the contract has
        // to hold before the first caller relies on it, not after.
        $list = $this->withinOrganization($this->org, function () {
            $list = OptionList::create([
                'organization_id' => $this->org->getKey(),
                'key' => 'approval_outcomes',
                'name' => 'Approval outcomes',
                'is_system' => true,
                'is_active' => true,
            ]);

            return $list;
        });

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.update', $list), [
                'name' => 'Approval outcomes',
                'key' => 'outcomes',
            ])
            ->assertSessionHasErrors('configuration');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.option-lists.destroy', $list))
            ->assertSessionHasErrors('configuration');

        $this->assertSame('approval_outcomes', $this->freshList($list)->key);

        // Renaming is allowed, because the label is nobody's dependency.
        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.update', $list), ['name' => 'Decisions'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Decisions', $this->freshList($list)->name);
    }

    // ================================================================== isolation

    #[Test]
    public function an_option_from_another_list_cannot_be_edited_through_this_one(): void
    {
        // §84. Same company, real id, wrong list — without the check the activity entry would name
        // Disciplines while a document class was rewritten.
        $disciplines = $this->list('disciplines');
        $classes = $this->list('document_classes');
        $drawing = $this->item($classes, 'drawing');

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.options.update', [$disciplines, $drawing]), [
                'label' => 'Rewritten',
            ])
            ->assertNotFound();

        $this->assertSame('Drawing', $this->item($classes, 'drawing')->label);
    }

    #[Test]
    public function another_companys_lists_do_not_exist(): void
    {
        // §75.
        $other = $this->makeOrganization('Brightwater Civil');
        $this->provisionConfiguration($other);

        $theirs = $this->withinOrganization($other,
            fn () => OptionList::query()->forKey('disciplines')->firstOrFail());

        $this->actingFresh($this->admin)
            ->get(route('configuration.option-lists.show', $theirs))
            ->assertNotFound();

        $this->actingFresh($this->admin)
            ->put(route('configuration.option-lists.update', $theirs), ['name' => 'Taken over'])
            ->assertNotFound();

        $this->assertSame('Disciplines', $this->withinOrganization($other,
            fn () => OptionList::query()->whereKey($theirs->getKey())->firstOrFail())->name);
    }

    #[Test]
    public function reordering_touches_nothing_outside_the_list_in_the_url(): void
    {
        $disciplines = $this->list('disciplines');
        $classes = $this->list('document_classes');

        $foreign = $this->item($classes, 'drawing');
        $foreignSortBefore = $foreign->sort;

        $ids = $this->withinOrganization($this->org,
            fn () => OptionListItem::query()->where('option_list_id', $disciplines->getKey())
                ->orderBy('sort')->pluck('id')->all());

        // The foreign id is posted in the middle of a legitimate order. It must be dropped, and
        // the rest must still reorder — a guard that refuses the whole request would be a denial
        // of service anybody could trigger.
        $reversed = array_reverse($ids);
        array_splice($reversed, 1, 0, [$foreign->getKey()]);

        $this->actingFresh($this->admin)
            ->post(route('configuration.option-lists.options.reorder', $disciplines), ['order' => $reversed])
            ->assertSessionHasNoErrors();

        $after = $this->withinOrganization($this->org,
            fn () => OptionListItem::query()->where('option_list_id', $disciplines->getKey())
                ->orderBy('sort')->pluck('id')->all());

        $this->assertSame(array_reverse($ids), $after);
        $this->assertSame($foreignSortBefore, $this->item($classes, 'drawing')->sort);
    }

    // ================================================================== reading is not writing

    #[Test]
    public function reading_the_vocabulary_is_not_permission_to_rewrite_it(): void
    {
        // §6. The lists render — their labels appear on every record that carries a custom field —
        // but every write is refused server-side.
        $list = $this->list('disciplines');
        $item = $this->item($list, 'civil');

        $this->actingFresh($this->reader)->get(route('configuration.option-lists.index'))->assertOk();
        $this->actingFresh($this->reader)->get(route('configuration.option-lists.show', $list))->assertOk();

        $this->actingFresh($this->reader)
            ->post(route('configuration.option-lists.store'), ['name' => 'Mine now'])->assertForbidden();
        $this->actingFresh($this->reader)
            ->put(route('configuration.option-lists.update', $list), ['name' => 'Mine now'])->assertForbidden();
        $this->actingFresh($this->reader)
            ->delete(route('configuration.option-lists.destroy', $list))->assertForbidden();
        $this->actingFresh($this->reader)
            ->post(route('configuration.option-lists.options.store', $list), ['label' => 'Mine'])->assertForbidden();
        $this->actingFresh($this->reader)
            ->put(route('configuration.option-lists.options.update', [$list, $item]), ['label' => 'Mine'])
            ->assertForbidden();
        $this->actingFresh($this->reader)
            ->delete(route('configuration.option-lists.options.destroy', [$list, $item]))->assertForbidden();
        $this->actingFresh($this->reader)
            ->post(route('configuration.option-lists.options.reorder', $list), ['order' => [$item->getKey()]])
            ->assertForbidden();

        $this->assertSame('Disciplines', $this->freshList($list)->name);
        $this->assertSame('Civil', $this->item($list, 'civil')->label);
    }

    // ================================================================== helpers

    private function list(string $key): OptionList
    {
        return $this->withinOrganization($this->org,
            fn () => OptionList::query()->forKey($key)->firstOrFail());
    }

    private function freshList(OptionList $list): OptionList
    {
        return $this->withinOrganization($this->org,
            fn () => OptionList::query()->whereKey($list->getKey())->firstOrFail());
    }

    private function countLists(string $key): int
    {
        return $this->withinOrganization($this->org,
            fn () => OptionList::query()->forKey($key)->count());
    }

    private function item(OptionList $list, string $value): OptionListItem
    {
        return $this->withinOrganization($this->org, fn () => OptionListItem::query()
            ->where('option_list_id', $list->getKey())->where('value', $value)->firstOrFail());
    }

    /**
     * A custom field that offers this list.
     *
     * Written straight to the table rather than through a service, because the custom-field
     * builder is Part 2C and this guard has to hold the moment the table exists — not the moment
     * a screen for it does.
     */
    private function fieldOffering(OptionList $list, string $label): int
    {
        return (int) DB::table('custom_fields')->insertGetId([
            'organization_id' => $list->organization_id,
            'entity_type' => 'project',
            'key' => str_replace(' ', '_', strtolower($label)),
            'label' => $label,
            'type' => 'select',
            'option_list_key' => $list->key,
            'is_required' => false,
            'is_filterable' => false,
            'is_active' => true,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** $count records that have stored one option of this list. */
    private function recordAgainst(OptionList $list, string $value, int $count): void
    {
        $fieldId = $this->fieldOffering($list, 'Lead discipline');

        for ($i = 1; $i <= $count; $i++) {
            DB::table('custom_field_values')->insert([
                'organization_id' => $list->organization_id,
                'custom_field_id' => $fieldId,
                'subject_type' => 'project',
                'subject_id' => $i,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** The sentence the administrator actually reads. */
    private function refusal(): string
    {
        return (string) session('errors')?->first('configuration');
    }
}
