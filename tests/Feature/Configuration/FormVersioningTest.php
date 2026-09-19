<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\FormDefinitionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The form builder's versioning rules (Phase 7, Part 3B).
 *
 * EVERY TEST IN THIS FILE IS THE SAME TEST FROM A DIFFERENT ANGLE: March's answers keep meaning what
 * they meant in March. A submission binds to a VERSION, so a published version is a promise about
 * what was asked — and the service's job is to keep that promise against an administrator who is
 * looking at a screen rather than at eleven months of submissions.
 *
 * The interesting half is the line between WORDING and MEANING. A published version accepts a
 * corrected label and refuses a changed key, type, option list, default, condition or required flag.
 * Both halves are tested, because getting the first wrong makes people publish a version to fix a
 * typo — which fragments a year of inspection data for no reason anybody can later reconstruct —
 * and getting the second wrong silently rewrites what people were asked.
 *
 * These exercise FormDefinitionService DIRECTLY rather than through HTTP. The controllers arrive in
 * 3C and get their own authorization tests; what is under test here is the rule itself, which has
 * to hold for a console command and an importer as well as for a screen.
 */
final class FormVersioningTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $this->admin = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [
            $this->makeRole($this->org, 'Configurator', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
                'form.manage' => 'organization',
                'form.publish' => 'organization',
            ]),
        ]);
    }

    // ================================================================== starting out

    #[Test]
    public function a_new_form_opens_with_a_draft_and_somewhere_to_type(): void
    {
        $form = $this->build(fn (FormDefinitionService $service) => $service->createForm([
            'name' => 'Scaffold inspection',
            'category' => 'HSE',
        ], $this->admin));

        $this->assertSame('scaffold_inspection', $form->key);

        $version = $this->withinOrganization($this->org, fn () => $form->versions()->first());

        $this->assertSame(1, (int) $version->version);
        $this->assertTrue($version->isDraft());

        // A builder that opens with nowhere to type makes the person's first act an administrative
        // one about a concept they have not been introduced to.
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => $version->sections()->count()));
    }

    #[Test]
    public function two_forms_cannot_share_a_key(): void
    {
        $this->build(fn (FormDefinitionService $s) => $s->createForm(['name' => 'Scaffold inspection'], $this->admin));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already a form with the key "scaffold_inspection"');

        $this->build(fn (FormDefinitionService $s) => $s->createForm(['name' => 'Scaffold Inspection'], $this->admin));
    }

    // ================================================================== publishing

    #[Test]
    public function a_version_that_asks_nothing_cannot_be_published(): void
    {
        [$form, $version] = $this->formWithDraft();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('asks nothing');

        $this->build(fn (FormDefinitionService $s) => $s->publish($version, $this->admin));
    }

    #[Test]
    public function a_heading_on_its_own_is_not_a_question(): void
    {
        [$form, $version, $section] = $this->formWithDraft();

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Before you start', 'type' => 'heading',
        ], $this->admin));

        // A form of nothing but headings renders, and cannot be submitted into. The refusal has to
        // count what people can ANSWER, not what is on the page.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('asks nothing');

        $this->build(fn (FormDefinitionService $s) => $s->publish($version, $this->admin));
    }

    #[Test]
    public function a_choice_question_pointing_at_no_list_cannot_be_published(): void
    {
        [$form, $version, $section] = $this->formWithDraft();

        // The service refuses this at the door too — so the field is written behind its back, which
        // is the only way to reach the state publishing has to catch.
        $this->rawField($version, $section, [
            'label' => 'Discipline', 'key' => 'discipline',
            'type' => 'select', 'option_list_key' => 'trades_we_never_created',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no answer anybody could give');

        $this->build(fn (FormDefinitionService $s) => $s->publish($version, $this->admin));
    }

    #[Test]
    public function publishing_freezes_the_version_and_records_who(): void
    {
        [$form, $version] = $this->publishedForm();

        $version->refresh();

        $this->assertTrue($version->isPublished());
        $this->assertNotNull($version->published_at);
        $this->assertSame($this->admin->getKey(), $version->published_by);
        $this->assertFalse($version->isEditable());
    }

    #[Test]
    public function publishing_the_next_version_retires_the_one_before_it(): void
    {
        [$form, $first] = $this->publishedForm();

        $second = $this->build(fn (FormDefinitionService $s) => $s->openDraft($form->fresh(), $this->admin));

        $this->build(fn (FormDefinitionService $s) => $s->publish(
            $second, $this->admin, 'Added the tag-check question the client asked for.'
        ));

        $first->refresh();
        $second->refresh();

        $this->assertTrue($first->isRetired());
        $this->assertTrue($second->isPublished());

        // The whole reason `forms` has no `current_version_id`: "the current version" is a question
        // with exactly one answer, asked of the versions table.
        $this->assertSame(1, $this->withinOrganization($this->org,
            fn () => $form->versions()->published()->count()));
    }

    #[Test]
    public function the_second_version_has_to_say_what_changed(): void
    {
        [$form] = $this->publishedForm();

        $second = $this->build(fn (FormDefinitionService $s) => $s->openDraft($form->fresh(), $this->admin));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Say what changed');

        $this->build(fn (FormDefinitionService $s) => $s->publish($second, $this->admin));
    }

    // ================================================================== drafts

    #[Test]
    public function opening_a_draft_twice_hands_back_the_same_one(): void
    {
        [$form] = $this->publishedForm();

        $first = $this->build(fn (FormDefinitionService $s) => $s->openDraft($form->fresh(), $this->admin));
        $second = $this->build(fn (FormDefinitionService $s) => $s->openDraft($form->fresh(), $this->admin));

        // Two drafts would mean two people editing the same form in different directions, and
        // whichever published last would discard the other's work without either of them seeing it.
        $this->assertTrue($first->is($second));
        $this->assertSame(1, $this->withinOrganization($this->org,
            fn () => $form->versions()->draft()->count()));
    }

    #[Test]
    public function a_new_draft_is_a_deep_copy_including_the_columns_of_a_table(): void
    {
        [$form, $published, $section] = $this->formWithDraft();

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Inspector name', 'key' => 'inspector', 'type' => 'text',
        ], $this->admin));

        $table = $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Bays', 'key' => 'bays', 'type' => 'table',
        ], $this->admin));

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Bay number', 'key' => 'bay_number', 'type' => 'number',
            'parent_field_id' => $table->getKey(),
        ], $this->admin));

        $this->build(fn (FormDefinitionService $s) => $s->publish($published, $this->admin));

        $draft = $this->build(fn (FormDefinitionService $s) => $s->openDraft($form->fresh(), $this->admin));

        $copied = $this->withinOrganization($this->org, fn () => FormField::query()
            ->where('form_version_id', $draft->getKey())->get()->keyBy('key'));

        $this->assertCount(3, $copied);

        $copiedTable = $copied->get('bays');
        $copiedColumn = $copied->get('bay_number');

        // A column re-parented onto the OLD version's table would be an edit to a published edition
        // through the back door.
        $this->assertSame((int) $copiedTable->getKey(), (int) $copiedColumn->parent_field_id);
        $this->assertNotSame((int) $table->getKey(), (int) $copiedTable->getKey());

        $this->assertSame(2, (int) $draft->version);
        $this->assertTrue($published->fresh()->isPublished());
    }

    #[Test]
    public function a_published_version_cannot_be_discarded(): void
    {
        [$form, $version] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cannot be discarded');

        $this->build(fn (FormDefinitionService $s) => $s->discardDraft($version, $this->admin));
    }

    // ================================================================== wording vs meaning

    #[Test]
    public function a_published_question_can_be_reworded(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->build(fn (FormDefinitionService $s) => $s->updateField($field, [
            'label' => 'Inspected by',
            'help' => 'Your name as it appears on your card.',
        ], $this->admin));

        $field->refresh();

        $this->assertSame('Inspected by', $field->label);

        // Forbidding this outright is the rule that fails in practice: a company that must publish
        // v4 to fix a spelling mistake will do exactly that.
        $this->assertSame('inspector', $field->key);
    }

    #[Test]
    public function rewording_a_published_question_records_what_it_used_to_say(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->build(fn (FormDefinitionService $s) => $s->updateField($field, [
            'label' => 'Inspected by',
        ], $this->admin));

        $entry = $this->withinOrganization($this->org, fn () => \DB::table('activity_logs')
            ->where('subject_type', 'form')
            ->where('description', 'like', '%reworded%')
            ->first());

        $this->assertNotNull($entry, 'A reworded question on a live form must leave a trace.');

        $properties = json_decode((string) $entry->properties, true);

        // "Scaffold tagged?" reworded to "Scaffold untagged?" inverts every recorded answer, and a
        // wording-only edit cannot tell that apart from fixing a typo. So it is made visible (§59).
        $this->assertSame('Inspector name', $properties['was']);
        $this->assertSame('Inspected by', $properties['now']);
    }

    #[Test]
    public function a_published_question_cannot_change_its_key(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('its key cannot change');

        $this->build(fn (FormDefinitionService $s) => $s->updateField($field, [
            'label' => 'Inspector name', 'key' => 'inspected_by',
        ], $this->admin));
    }

    #[Test]
    public function a_published_question_cannot_change_its_type(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('the kind of question it is cannot change');

        $this->build(fn (FormDefinitionService $s) => $s->updateField($field, [
            'label' => 'Inspector name', 'type' => 'number',
        ], $this->admin));
    }

    #[Test]
    public function a_published_question_cannot_become_required(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be answered cannot change now');

        $this->build(fn (FormDefinitionService $s) => $s->updateField($field, [
            'label' => 'Inspector name', 'is_required' => true,
        ], $this->admin));
    }

    #[Test]
    public function a_question_cannot_be_added_to_a_published_version(): void
    {
        [$form, $version, $section] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Start a new version');

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Weather', 'type' => 'text',
        ], $this->admin));
    }

    #[Test]
    public function a_question_cannot_be_removed_from_a_published_version(): void
    {
        [$form, $version, $section, $field] = $this->publishedForm();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Start a new version');

        $this->build(fn (FormDefinitionService $s) => $s->deleteField($field, $this->admin));
    }

    // ================================================================== structure

    #[Test]
    public function two_questions_on_one_version_cannot_share_a_key(): void
    {
        [$form, $version, $section] = $this->formWithDraft();

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Inspector name', 'key' => 'inspector', 'type' => 'text',
        ], $this->admin));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already has a question with the key "inspector"');

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Inspector', 'key' => 'inspector', 'type' => 'text',
        ], $this->admin));
    }

    #[Test]
    public function a_repeating_table_cannot_contain_a_repeating_table(): void
    {
        [$form, $version, $section] = $this->formWithDraft();

        $table = $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Bays', 'key' => 'bays', 'type' => 'table',
        ], $this->admin));

        // A table's answers are rows against one `row_index`. A table inside a table would need a
        // second index the storage does not have.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('cannot be a column inside a repeating table');

        $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Lifts', 'key' => 'lifts', 'type' => 'table',
            'parent_field_id' => $table->getKey(),
        ], $this->admin));
    }

    #[Test]
    public function a_parent_from_another_form_is_not_a_parent(): void
    {
        [$otherForm, $otherVersion, $otherSection] = $this->formWithDraft('Lifting plan');

        $otherTable = $this->build(fn (FormDefinitionService $s) => $s->addField($otherSection, [
            'label' => 'Slings', 'key' => 'slings', 'type' => 'table',
        ], $this->admin));

        [$form, $version, $section] = $this->formWithDraft('Scaffold inspection');

        $field = $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Bay number', 'key' => 'bay_number', 'type' => 'number',
            'parent_field_id' => $otherTable->getKey(),
        ], $this->admin));

        // §84: the id came from a form, which is not evidence that it belongs to the version being
        // edited. It is matched against this version's own fields and comes back as nothing.
        $this->assertNull($field->parent_field_id);
    }

    #[Test]
    public function the_last_section_cannot_be_removed(): void
    {
        [$form, $version, $section] = $this->formWithDraft();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('at least one section');

        $this->build(fn (FormDefinitionService $s) => $s->deleteSection($section, $this->admin));
    }

    #[Test]
    public function a_section_can_be_retitled_on_a_published_version(): void
    {
        [$form, $version, $section] = $this->publishedForm();

        // A section carries nothing but wording — its title and description are read by the person
        // filling the form in and stored by nothing.
        $this->build(fn (FormDefinitionService $s) => $s->updateSection($section, [
            'title' => 'Before you start',
        ], $this->admin));

        $this->assertSame('Before you start', $section->fresh()->title);
    }

    // ================================================================== once it is in use

    #[Test]
    public function a_form_that_has_been_filled_in_cannot_be_rekeyed(): void
    {
        [$form, $version] = $this->publishedForm();

        $this->submit($form, $version);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('what exports, reports and anything reading this form by name call it');

        $this->build(fn (FormDefinitionService $s) => $s->updateForm($form, [
            'name' => 'Scaffold inspection', 'key' => 'scaffold_check',
        ], $this->admin));
    }

    #[Test]
    public function a_form_that_has_been_filled_in_cannot_be_deleted(): void
    {
        [$form, $version] = $this->publishedForm();

        $this->submit($form, $version);

        // The one place in the configuration screens where deletion is refused rather than
        // confirmed: a form's submissions ARE the records, not a column beside one.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Switch the form off instead');

        $this->build(fn (FormDefinitionService $s) => $s->deleteForm($form, $this->admin));
    }

    #[Test]
    public function taking_a_form_out_of_service_keeps_every_submission(): void
    {
        [$form, $version] = $this->publishedForm();

        $submission = $this->submit($form, $version);

        $this->build(fn (FormDefinitionService $s) => $s->retire($form, $this->admin));

        $form->refresh();
        $version->refresh();

        $this->assertFalse((bool) $form->is_active);
        $this->assertTrue($version->isRetired());

        // Retired, not deleted: the submission still has to be readable against the questions it
        // answered.
        $this->assertNotNull($this->withinOrganization($this->org,
            fn () => FormSubmission::query()->find($submission->getKey())));
    }

    // ================================================================== scaffolding

    /** Run something inside the tenant, as the service would be run by a request. */
    private function build(callable $callback): mixed
    {
        return $this->withinOrganization($this->org,
            fn () => $callback(app(FormDefinitionService::class)));
    }

    /**
     * A form with an unpublished first version.
     *
     * @return array{0:Form,1:FormVersion,2:FormSection}
     */
    private function formWithDraft(string $name = 'Scaffold inspection'): array
    {
        $form = $this->build(fn (FormDefinitionService $s) => $s->createForm(['name' => $name], $this->admin));

        return $this->withinOrganization($this->org, function () use ($form) {
            $version = $form->versions()->first();

            return [$form, $version, $version->sections()->first()];
        });
    }

    /**
     * A form with one published version carrying one question.
     *
     * @return array{0:Form,1:FormVersion,2:FormSection,3:FormField}
     */
    private function publishedForm(string $name = 'Scaffold inspection'): array
    {
        [$form, $version, $section] = $this->formWithDraft($name);

        $field = $this->build(fn (FormDefinitionService $s) => $s->addField($section, [
            'label' => 'Inspector name', 'key' => 'inspector', 'type' => 'text',
        ], $this->admin));

        $this->build(fn (FormDefinitionService $s) => $s->publish($version, $this->admin));

        return [$form, $version->refresh(), $section, $field];
    }

    /**
     * A field written straight to the table.
     *
     * Deliberately not through the service: some of these tests are about what PUBLISHING refuses,
     * and the arrangement has to be able to reach states the builder would never create. A test
     * whose arrange step goes through the code under test proves less than it looks.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function rawField(FormVersion $version, FormSection $section, array $attributes): FormField
    {
        return $this->withinOrganization($this->org, fn () => FormField::create([
            'organization_id' => $this->org->getKey(),
            'form_version_id' => $version->getKey(),
            'form_section_id' => $section->getKey(),
            'type' => 'text',
            'is_required' => false,
            'sort' => 0,
            ...$attributes,
        ]));
    }

    /** Somebody filled the form in. FormSubmissionService arrives in 3D; this is the row it writes. */
    private function submit(Form $form, FormVersion $version): FormSubmission
    {
        return $this->withinOrganization($this->org, fn () => FormSubmission::create([
            'organization_id' => $this->org->getKey(),
            'form_id' => $form->getKey(),
            'form_version_id' => $version->getKey(),
            'number' => 'FRM-2026-00001',
            'created_by' => $this->admin->getKey(),
            'submitted_by' => $this->admin->getKey(),
            'submitted_at' => now(),
        ]));
    }
}
