<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Configuration\CustomFieldRegistry;
use App\Models\CustomField;
use App\Models\OptionList;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Company-defined fields (Phase 7, Part 2C).
 *
 * A FIELD DEFINITION IS NOT CONFIGURATION IN THE WAY A COLOUR IS. It decides what a form demands,
 * what a column means and what an export contains, and it is edited by somebody who cannot see the
 * records it already governs. Two dangers, and the tests are grouped by them.
 *
 * THE FIRST IS THE REQUIRED-FIELD TRAP. Ticking "required" on a register holding a year of records
 * makes every one of them fail validation the next time anybody edits them, over a question nobody
 * was asked. It is defended twice: CustomFieldService refuses the configuration change, and
 * RegisterService enforces `required` on create but only on an update that actually posted the
 * section — so a status change is never broken by a checkbox in configuration.
 *
 * THE SECOND IS THAT EVERY VALUE LANDS IN ONE TEXT COLUMN. The declared type is the only thing
 * between that column and whatever arrived in the request, so the rules are compiled server-side
 * from the definitions and `Rule::in()` is what makes a select a controlled vocabulary (§84).
 *
 * Plus the constants: another company's fields do not exist (§75), organization_id comes from the
 * record and never the form (§74), a field from another register cannot be driven through this
 * register's page (§84), and reading is not writing (§6).
 */
final class CustomFieldTest extends TestCase
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
                'project.view' => 'organization',
                'project.create' => 'organization',
                'project.edit' => 'organization',
            ]),
        ]);

        $this->reader = $this->makePerson($this->org, 'Bem Tarkighir', null, [
            $this->makeRole($this->org, 'Onlooker', ['custom_field.view' => 'organization']),
        ]);
    }

    // ================================================================== the screen

    #[Test]
    public function the_registers_a_field_can_be_added_to_are_the_ones_that_can_actually_render_it(): void
    {
        // §73. The catalogue is a hand-maintained list rather than a scan of the models directory,
        // precisely so this page never offers a register whose forms would ignore the field.
        $this->actingFresh($this->admin)->get(route('configuration.custom-fields.index'))
            ->assertOk()
            ->assertSee('Projects')
            ->assertSee('Incidents')
            ->assertSee('Purchase Orders');
    }

    #[Test]
    public function a_register_nobody_defined_is_not_a_register(): void
    {
        // §84. The URL said so, which is not evidence.
        $this->actingFresh($this->admin)
            ->get(route('configuration.custom-fields.show', 'option_list'))
            ->assertNotFound();
    }

    #[Test]
    public function a_company_can_add_a_field_without_inventing_a_machine_name(): void
    {
        $this->actingFresh($this->admin)
            ->post(route('configuration.custom-fields.store'), [
                'entity_type' => 'project',
                'label' => 'Client cost code',
                'type' => 'text',
                'help' => 'As it appears on their purchase order.',
            ])
            ->assertSessionHasNoErrors();

        $field = $this->field('project', 'client_cost_code');

        $this->assertSame('Client cost code', $field->label);
        $this->assertSame('text', $field->type);
        $this->assertTrue((bool) $field->is_active);

        // §74. Never from input, even though nothing on the form offered it.
        $this->assertSame((int) $this->org->getKey(), (int) $field->organization_id);
    }

    #[Test]
    public function a_kind_of_field_this_platform_cannot_render_is_refused(): void
    {
        // §84. `type` decides how every later read casts the column.
        $this->actingFresh($this->admin)
            ->post(route('configuration.custom-fields.store'), [
                'entity_type' => 'project',
                'label' => 'Anything',
                'type' => 'sql',
            ])
            ->assertSessionHasErrors('type');
    }

    #[Test]
    public function a_field_people_choose_from_must_name_a_list_that_exists(): void
    {
        // A select pointing at nothing is a required question with no permitted answer — a form
        // that cannot be submitted, from a screen that gave no warning.
        $this->actingFresh($this->admin)
            ->post(route('configuration.custom-fields.store'), [
                'entity_type' => 'project',
                'label' => 'Lead discipline',
                'type' => 'select',
            ])
            ->assertSessionHasErrors('configuration');

        $this->actingFresh($this->admin)
            ->post(route('configuration.custom-fields.store'), [
                'entity_type' => 'project',
                'label' => 'Lead discipline',
                'type' => 'select',
                'option_list_key' => 'not_a_list',
            ])
            ->assertSessionHasErrors('configuration');

        $this->assertSame(0, $this->countFields('project'));
    }

    // ================================================================== the required-field trap

    #[Test]
    public function a_field_can_be_made_required_before_anybody_has_been_asked(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $this->assertSame(0, $this->countRecords());

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['is_required' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->freshField($field)->is_required);
    }

    #[Test]
    public function a_field_cannot_be_made_required_over_records_that_were_never_asked(): void
    {
        // THE ONE THAT BITES. Two projects exist, neither answers the field, and ticking the box
        // would make both fail validation the next time anybody touched them.
        $field = $this->makeField('project', 'Client cost code');

        $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade');
        $this->makeProject($this->org, $this->admin, 'Escravos Pipeline Tie-in');

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['is_required' => '1']))
            ->assertSessionHasErrors('configuration');

        $this->assertFalse((bool) $this->freshField($field)->is_required);

        // And the refusal says how many and what to do about it, rather than "not allowed".
        $this->assertStringContainsString('2 records', $this->refusal());
        $this->assertStringContainsString('default', $this->refusal());
    }

    #[Test]
    public function a_default_answers_on_behalf_of_the_records_nobody_asked(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade');

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, [
                'is_required' => '1',
                'default_value' => 'TBC',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->freshField($field)->is_required);
    }

    #[Test]
    public function a_required_field_added_today_does_not_break_a_record_created_last_year(): void
    {
        // The second half of the defence. The configuration change is legitimate here — the field
        // carries a default — but the old project has no answer, and a save that does not mention
        // the custom-field section must still go through: a status change, a workflow action, an
        // internal correction. Otherwise one checkbox in configuration freezes the register.
        $project = $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade');

        $field = $this->makeField('project', 'Client cost code', ['default_value' => 'TBC']);

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, [
                'is_required' => '1',
                'default_value' => 'TBC',
            ]))
            ->assertSessionHasNoErrors();

        $updated = $this->withinOrganization($this->org, fn () => app(ProjectService::class)
            ->update($this->freshProject($project), ['name' => 'Bonny Terminal Upgrade (Phase 2)'], $this->admin));

        $this->assertSame('Bonny Terminal Upgrade (Phase 2)', $updated->name);
        $this->assertSame(0, $this->answerCount($field));
    }

    #[Test]
    public function a_new_record_must_answer_a_required_field(): void
    {
        // Create is where `required` is actually enforced, and the rules always run there.
        $this->makeField('project', 'Client cost code', ['is_required' => true]);

        $this->expectException(ValidationException::class);

        $this->makeProject($this->org, $this->admin, 'Escravos Pipeline Tie-in');
    }

    // ================================================================== what the column holds

    #[Test]
    public function an_answer_is_stored_against_the_record_and_read_back_in_its_declared_type(): void
    {
        $number = $this->makeField('project', 'Contract value', ['type' => 'number']);
        $flag = $this->makeField('project', 'Offshore', ['type' => 'boolean']);

        $project = $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['contract_value' => '1250.50', 'offshore' => '1'],
        ]);

        $answers = $this->withinOrganization($this->org,
            fn () => $this->freshProject($project)->customFieldAnswers());

        $this->assertSame(1250.5, $answers['contract_value']);
        $this->assertTrue($answers['offshore']);

        // §74. The value is filed under the RECORD's company, never the request's and never the
        // actor's, so an answer can never end up in a different company from what it describes.
        $this->assertSame((int) $this->org->getKey(), (int) DB::table('custom_field_values')
            ->where('custom_field_id', $number->getKey())->value('organization_id'));
    }

    #[Test]
    public function a_select_accepts_only_what_its_list_offers(): void
    {
        // §84. Without Rule::in() a select is a text box with a nicer control on top of it.
        $this->assertDisciplinesExist();

        $this->makeField('project', 'Lead discipline', [
            'type' => 'select',
            'option_list_key' => 'disciplines',
        ]);

        $this->expectException(ValidationException::class);

        $this->makeProject($this->org, $this->admin, 'Escravos Pipeline Tie-in', [
            'custom_fields' => ['lead_discipline' => 'whatever_i_typed'],
        ]);
    }

    #[Test]
    public function a_save_that_does_not_mention_a_field_does_not_erase_a_year_of_answers(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $project = $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->withinOrganization($this->org, fn () => app(ProjectService::class)
            ->update($this->freshProject($project), ['name' => 'Bonny Terminal Upgrade (Phase 2)'], $this->admin));

        $this->assertSame('CC-8842', $this->storedAnswer($field, $project));
    }

    #[Test]
    public function an_answer_posted_empty_is_no_answer_rather_than_an_empty_one(): void
    {
        // "never answered" and "answered blank" must not become two different kinds of nothing in
        // every later report.
        $field = $this->makeField('project', 'Client cost code');

        $project = $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->withinOrganization($this->org, fn () => app(ProjectService::class)->update(
            $this->freshProject($project),
            ['custom_fields' => ['client_cost_code' => '']],
            $this->admin,
        ));

        $this->assertNull($this->storedAnswer($field, $project));
        $this->assertSame(0, $this->answerCount($field));
    }

    // ================================================================== frozen once answered

    #[Test]
    public function the_key_is_frozen_once_a_record_has_answered(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['key' => 'cost_code']))
            ->assertSessionHasErrors('configuration');

        $this->assertSame('client_cost_code', $this->freshField($field)->key);
    }

    #[Test]
    public function the_label_is_free_to_change_because_nothing_stores_it(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field),
                $this->payload($field, ['label' => "Client's cost code"]))
            ->assertSessionHasNoErrors();

        $fresh = $this->freshField($field);

        $this->assertSame("Client's cost code", $fresh->label);
        $this->assertSame('client_cost_code', $fresh->key, 'the key must not follow the label');
    }

    #[Test]
    public function the_type_is_frozen_once_a_record_has_answered(): void
    {
        // Every value is text; changing 'text' to 'number' converts nothing, it just means the
        // next read casts 'approx 40' to null.
        $field = $this->makeField('project', 'Client cost code');

        $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['type' => 'number']))
            ->assertSessionHasErrors('configuration');

        $this->assertSame('text', $this->freshField($field)->type);
    }

    #[Test]
    public function switching_a_field_off_keeps_the_answers_and_deleting_it_destroys_them(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $project = $this->makeProject($this->org, $this->admin, 'Bonny Terminal Upgrade', [
            'custom_fields' => ['client_cost_code' => 'CC-8842'],
        ]);

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['is_active' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->freshField($field)->is_active);
        $this->assertSame('CC-8842', $this->storedAnswer($field, $project), 'the reversible door keeps the data');

        $this->actingFresh($this->admin)
            ->delete(route('configuration.custom-fields.destroy', $field))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->countFields('project'));
        $this->assertSame(0, $this->answerCount($field));
    }

    // ================================================================== the constants

    #[Test]
    public function a_field_from_another_register_cannot_be_driven_through_this_ones_page(): void
    {
        // §84. An id from Projects, submitted through the Incidents page, must not be rewritten
        // while the redirect and the audit entry name Incidents.
        $field = $this->makeField('project', 'Client cost code');

        $this->actingFresh($this->admin)
            ->post(route('configuration.custom-fields.reorder', 'hse_incident'), [
                'order' => [$field->getKey()],
            ])
            ->assertSessionHasNoErrors();

        // Untouched: reorder matched the id against the register in the URL and found nothing.
        $this->assertSame((int) $field->sort, (int) $this->freshField($field)->sort);
        $this->assertSame('project', $this->freshField($field)->entity_type);
    }

    #[Test]
    public function a_field_cannot_be_moved_between_registers_by_posting_a_different_one(): void
    {
        $field = $this->makeField('project', 'Client cost code');

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $field),
                $this->payload($field, ['entity_type' => 'hse_incident']))
            ->assertSessionHasNoErrors();

        // The answers are filed against records of one kind; moving the definition would leave
        // them describing records of another.
        $this->assertSame('project', $this->freshField($field)->entity_type);
    }

    #[Test]
    public function another_companys_fields_do_not_exist(): void
    {
        // §75.
        $other = $this->makeOrganization('Ridgeline Contracting');
        $this->provisionConfiguration($other);

        $theirs = $this->withinOrganization($other, fn () => CustomField::create([
            'organization_id' => $other->getKey(),
            'entity_type' => 'project',
            'key' => 'their_field',
            'label' => 'Their field',
            'type' => 'text',
            'is_required' => false,
            'is_filterable' => false,
            'is_active' => true,
            'sort' => 0,
        ]));

        $this->actingFresh($this->admin)
            ->put(route('configuration.custom-fields.update', $theirs), [
                'entity_type' => 'project',
                'label' => 'Mine now',
                'type' => 'text',
            ])
            ->assertNotFound();

        $this->actingFresh($this->admin)
            ->delete(route('configuration.custom-fields.destroy', $theirs))
            ->assertNotFound();

        $this->assertSame('Their field', $this->withinOrganization($other,
            fn () => CustomField::query()->whereKey($theirs->getKey())->firstOrFail())->label);

        // And their field is not asked on our forms, which is the part a tenant scope on the
        // screen alone would not prove.
        $this->actingFresh($this->admin)
            ->get(route('configuration.custom-fields.show', 'project'))
            ->assertOk()
            ->assertDontSee('Their field');
    }

    #[Test]
    public function reading_the_definitions_is_not_permission_to_rewrite_them(): void
    {
        // §6. Hiding the buttons is a courtesy; the route is the control.
        $field = $this->makeField('project', 'Client cost code');

        $this->actingFresh($this->reader)
            ->get(route('configuration.custom-fields.show', 'project'))
            ->assertOk()
            ->assertSee('Client cost code');

        $this->actingFresh($this->reader)
            ->post(route('configuration.custom-fields.store'), [
                'entity_type' => 'project',
                'label' => 'Smuggled',
                'type' => 'text',
            ])
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->put(route('configuration.custom-fields.update', $field), $this->payload($field, ['label' => 'Changed']))
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->delete(route('configuration.custom-fields.destroy', $field))
            ->assertForbidden();

        $this->assertSame('Client cost code', $this->freshField($field)->label);
        $this->assertSame(1, $this->countFields('project'));
    }

    // ================================================================== helpers

    /**
     * A definition written straight to the table.
     *
     * Deliberately NOT through CustomFieldService: most of these tests are about what the service
     * refuses, so the arrangement has to be able to set up states the service would not create —
     * and a test whose arrange step goes through the code under test proves less than it looks.
     *
     * The flush is the price of that. The registry memoizes a register's fields per instance, and
     * the container's scoped bindings survive from one test request to the next, so a field written
     * behind the registry's back is a field the next save does not know about.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function makeField(string $entityType, string $label, array $attributes = []): CustomField
    {
        $field = $this->withinOrganization($this->org, fn () => CustomField::create([
            'organization_id' => $this->org->getKey(),
            'entity_type' => $entityType,
            'key' => str_replace([' ', "'"], ['_', ''], strtolower($label)),
            'label' => $label,
            'type' => 'text',
            'is_required' => false,
            'is_filterable' => false,
            'is_active' => true,
            'sort' => 0,
            ...$attributes,
        ]));

        app(CustomFieldRegistry::class)->flush();

        return $field;
    }

    private function field(string $entityType, string $key): CustomField
    {
        return $this->withinOrganization($this->org, fn () => CustomField::query()
            ->forEntity($entityType)->where('key', $key)->firstOrFail());
    }

    private function freshField(CustomField $field): CustomField
    {
        return $this->withinOrganization($this->org, fn () => CustomField::query()
            ->whereKey($field->getKey())->firstOrFail());
    }

    private function freshProject(Project $project): Project
    {
        return $this->withinOrganization($this->org, fn () => Project::query()
            ->whereKey($project->getKey())->firstOrFail());
    }

    private function countFields(string $entityType): int
    {
        return $this->withinOrganization($this->org,
            fn () => CustomField::query()->forEntity($entityType)->count());
    }

    private function countRecords(): int
    {
        return $this->withinOrganization($this->org, fn () => Project::query()->count());
    }

    private function answerCount(CustomField $field): int
    {
        return (int) DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())->count();
    }

    private function storedAnswer(CustomField $field, Project $project): ?string
    {
        $value = DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->where('subject_type', 'project')
            ->where('subject_id', $project->getKey())
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * What the edit form actually posts, with one thing changed.
     *
     * The form carries every attribute on every save, so a test that posted only the changed key
     * would be testing a request the screen never makes — and would pass while the real form
     * silently blanked the other twelve.
     *
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function payload(CustomField $field, array $changes = []): array
    {
        return [
            'entity_type' => $field->entity_type,
            'label' => $field->label,
            'key' => $field->key,
            'type' => $field->type,
            'option_list_key' => $field->option_list_key,
            'help' => $field->help,
            'placeholder' => $field->placeholder,
            'default_value' => $field->default_value,
            'is_required' => $field->is_required ? '1' : '0',
            'is_filterable' => $field->is_filterable ? '1' : '0',
            'is_active' => $field->is_active ? '1' : '0',
            ...$changes,
        ];
    }

    /** The sentence the administrator actually reads. */
    private function refusal(): string
    {
        return (string) session('errors')?->first('configuration');
    }

    /** Guards against the starter vocabulary quietly losing the list these tests choose from. */
    private function assertDisciplinesExist(): void
    {
        $this->assertTrue($this->withinOrganization($this->org,
            fn () => OptionList::query()->forKey('disciplines')->where('is_active', true)->exists()));
    }
}
