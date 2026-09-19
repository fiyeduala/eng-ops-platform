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
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The form builder as a person meets it — over HTTP (Phase 7, Part 3C).
 *
 * WHAT THIS FILE TESTS THAT FormVersioningTest DOES NOT. That file exercises the rules directly,
 * because they have to hold for an importer and a console command as well as for a screen. This
 * one tests the three things a service cannot see:
 *
 *   AUTHORIZATION (§6). Reading a definition, editing one and PUBLISHING one are three different
 *   authorities, and the split is the reason FormPolicy exists. Somebody who may draft an
 *   inspection sheet is not thereby somebody who may decide every site supervisor in the company
 *   fills it in tomorrow.
 *
 *   THAT A PATH SEGMENT IS NOT EVIDENCE (§84, §94). Every builder URL names two things — the form
 *   whose page you are on and the section or question you are acting on — and route model binding
 *   resolves them independently. The policy is asked about the FORM. So a question id belonging to
 *   another form, posted through this form's URL, passes every gate unless something checks; and
 *   the damage is not just the edit but the audit entry, which would name the wrong form and make
 *   the change unfindable afterwards.
 *
 *   THAT ONE COMPANY CANNOT REACH ANOTHER (§75).
 */
final class FormBuilderTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** Holds everything: view, create, edit, publish, delete. */
    private User $admin;

    /** May build a form but not decide the company uses it. */
    private User $author;

    /** May read the questions and nothing else. */
    private User $reader;

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
                'form.delete' => 'organization',
                'form.publish' => 'organization',
            ]),
        ]);

        $this->author = $this->makePerson($this->org, 'Tunde Balogun', null, [
            $this->makeRole($this->org, 'Form author', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
            ]),
        ]);

        $this->reader = $this->makePerson($this->org, 'Chiamaka Nwosu', null, [
            $this->makeRole($this->org, 'Onlooker', ['form.view' => 'organization']),
        ]);
    }

    // ================================================================== reading

    #[Test]
    public function the_list_names_every_form_and_says_which_edition_is_live(): void
    {
        [$form] = $this->publishedForm();

        $this->actingFresh($this->reader)->get(route('forms.index'))
            ->assertOk()
            ->assertSee('Scaffold inspection')
            ->assertSee('scaffold_inspection')
            // Three states people confuse constantly, so the list says which one this is.
            ->assertSee('Live');

        $this->assertNotNull($form->key);
    }

    #[Test]
    public function a_reader_is_not_offered_the_new_form_button(): void
    {
        // Gate 2 only — a courtesy. The route is the control, and the next test proves it.
        $this->actingFresh($this->reader)->get(route('forms.index'))
            ->assertOk()
            ->assertDontSee(route('forms.create'));
    }

    #[Test]
    public function a_reader_who_types_the_url_is_still_refused(): void
    {
        // §84: hiding a link that a hand-typed URL would open is not authorization.
        $this->actingFresh($this->reader)->get(route('forms.create'))->assertForbidden();

        $this->actingFresh($this->reader)
            ->post(route('forms.store'), ['name' => 'Smuggled form'])
            ->assertForbidden();

        $this->assertSame(0, $this->countForms('smuggled_form'));
    }

    #[Test]
    public function the_builder_opens_on_the_draft_when_there_is_one(): void
    {
        [$form, , $section] = $this->formWithDraft();

        $this->actingFresh($this->admin)->get(route('forms.show', $form))
            ->assertOk()
            ->assertSee($section->title)
            ->assertSee('is a draft');
    }

    #[Test]
    public function the_builder_opens_on_the_live_edition_when_there_is_no_draft(): void
    {
        [$form] = $this->publishedForm();

        $this->actingFresh($this->admin)->get(route('forms.show', $form))
            ->assertOk()
            ->assertSee('Inspector name')
            ->assertSee('is live');
    }

    // ================================================================== building

    #[Test]
    public function a_form_can_be_built_and_published_entirely_through_the_screen(): void
    {
        // §73 in one test: a non-developer gets from nothing to a live form without leaving the UI.
        $this->actingFresh($this->admin)
            ->post(route('forms.store'), [
                'name' => 'Scaffold inspection',
                'category' => 'HSE',
                'description' => 'Completed before any scaffold is handed over.',
            ])
            ->assertRedirect();

        $form = $this->form('scaffold_inspection');
        $section = $this->sectionsOf($form)->first();

        $this->actingFresh($this->admin)
            ->post(route('forms.fields.store', [$form, $section]), [
                'label' => 'Inspector name',
                'type' => 'text',
                'is_required' => '1',
            ])
            ->assertRedirect();

        $version = $this->draftOf($form);

        $this->actingFresh($this->admin)
            ->post(route('forms.versions.publish', [$form, $version]))
            ->assertRedirect();

        $version->refresh();

        $this->assertTrue($version->isPublished());
        $this->assertSame((int) $this->admin->getKey(), (int) $version->published_by);
    }

    #[Test]
    public function a_section_can_be_added_renamed_and_removed_from_a_draft(): void
    {
        [$form, $version] = $this->formWithDraft();

        $this->actingFresh($this->admin)
            ->post(route('forms.sections.store', [$form, $version]), [
                'title' => 'Scaffold condition',
                'description' => 'Everything below is about the scaffold itself.',
            ])
            ->assertRedirect();

        $added = $this->sectionsOf($form)->firstWhere('title', 'Scaffold condition');
        $this->assertNotNull($added);

        $this->actingFresh($this->admin)
            ->put(route('forms.sections.update', [$form, $added]), ['title' => 'Scaffold'])
            ->assertRedirect();

        $this->assertSame('Scaffold', $this->sectionsOf($form)->firstWhere('id', $added->id)->title);

        $this->actingFresh($this->admin)
            ->delete(route('forms.sections.destroy', [$form, $added]))
            ->assertRedirect();

        $this->assertNull($this->sectionsOf($form)->firstWhere('id', $added->id));
    }

    #[Test]
    public function the_questions_can_be_put_in_a_different_order(): void
    {
        [$form, , $section] = $this->formWithDraft();

        foreach (['First', 'Second'] as $label) {
            $this->actingFresh($this->admin)
                ->post(route('forms.fields.store', [$form, $section]), ['label' => $label, 'type' => 'text'])
                ->assertRedirect();
        }

        $fields = $this->fieldsOf($form);
        $ids = $fields->pluck('id')->all();

        $this->actingFresh($this->admin)
            ->post(route('forms.fields.reorder', [$form, $section]), ['order' => array_reverse($ids)])
            ->assertRedirect();

        $this->assertSame(['Second', 'First'], $this->fieldsOf($form)->pluck('label')->all());
    }

    // ================================================================== the wording line, over HTTP

    #[Test]
    public function a_published_question_can_be_reworded_through_the_screen(): void
    {
        [$form, , , $field] = $this->publishedForm();

        // The payload the builder actually posts on a published edition: the frozen values ride
        // along in hidden inputs so the request still validates and the service can compare them.
        $this->actingFresh($this->admin)
            ->put(route('forms.fields.update', [$form, $field]), [
                'label' => "Inspector's name",
                'key' => $field->key,
                'type' => $field->type,
                'is_required' => $field->is_required ? '1' : '0',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame("Inspector's name", $this->fieldsOf($form)->firstWhere('id', $field->id)->label);
    }

    #[Test]
    public function a_published_question_cannot_be_re_keyed_through_the_screen(): void
    {
        // The builder renders the key read-only on a published edition. A hand-posted payload does
        // not read CSS, which is why the refusal lives in the service and is asserted here (§84).
        [$form, , , $field] = $this->publishedForm();

        $this->actingFresh($this->admin)
            ->put(route('forms.fields.update', [$form, $field]), [
                'label' => 'Inspector name',
                'key' => 'inspector_full_name',
                'type' => $field->type,
                'is_required' => $field->is_required ? '1' : '0',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $this->assertSame('inspector', $this->fieldsOf($form)->firstWhere('id', $field->id)->key);
    }

    #[Test]
    public function a_question_cannot_be_added_to_a_live_edition_through_the_screen(): void
    {
        [$form, , $section] = $this->publishedForm();

        $this->actingFresh($this->admin)
            ->post(route('forms.fields.store', [$form, $section]), ['label' => 'Smuggled', 'type' => 'text'])
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $this->assertSame(1, $this->fieldsOf($form)->count());
    }

    #[Test]
    public function the_second_version_will_not_publish_without_a_change_note(): void
    {
        [$form] = $this->publishedForm();

        $this->actingFresh($this->admin)->post(route('forms.draft', $form))->assertRedirect();

        $draft = $this->draftOf($form);

        $this->actingFresh($this->admin)
            ->post(route('forms.versions.publish', [$form, $draft]))
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $draft->refresh();
        $this->assertTrue($draft->isDraft());

        $this->actingFresh($this->admin)
            ->post(route('forms.versions.publish', [$form, $draft]), [
                'change_note' => 'Added the tag number, after the near miss on Block C.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertTrue($draft->isPublished());
    }

    // ================================================================== authorization

    #[Test]
    public function an_author_may_build_a_form_but_not_decide_the_company_uses_it(): void
    {
        // The whole reason FormPolicy is not a copy of CustomFieldPolicy.
        [$form, $version, $section] = $this->formWithDraft();

        $this->actingFresh($this->author)
            ->post(route('forms.fields.store', [$form, $section]), ['label' => 'Inspector name', 'type' => 'text'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->author)
            ->post(route('forms.versions.publish', [$form, $version]))
            ->assertForbidden();

        $version->refresh();
        $this->assertTrue($version->isDraft());
    }

    #[Test]
    public function an_author_cannot_delete_a_form(): void
    {
        [$form] = $this->formWithDraft();

        $this->actingFresh($this->author)
            ->delete(route('forms.destroy', $form))
            ->assertForbidden();

        $this->assertSame(1, $this->countForms('scaffold_inspection'));
    }

    #[Test]
    public function a_reader_cannot_touch_the_questions(): void
    {
        [$form, , $section] = $this->formWithDraft();

        $this->actingFresh($this->reader)
            ->post(route('forms.fields.store', [$form, $section]), ['label' => 'Smuggled', 'type' => 'text'])
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->post(route('forms.sections.store', [$form, $this->draftOf($form)]), ['title' => 'Smuggled'])
            ->assertForbidden();

        $this->assertSame(0, $this->fieldsOf($form)->count());
    }

    // ================================================================== the id in the URL (§84, §94)

    #[Test]
    public function a_question_from_another_form_cannot_be_edited_through_this_forms_url(): void
    {
        [$mine] = $this->formWithDraft('Scaffold inspection');
        [, , $theirSection] = $this->formWithDraft('Plant handover');

        $theirField = $this->build(fn (FormDefinitionService $service) => $service->addField(
            $theirSection, ['label' => 'Operator name', 'type' => 'text'], $this->admin
        ));

        // Every gate passes: the admin may edit `$mine`, and the field is in the same company.
        // Only the ownership check stands between this payload and a question on another form
        // being rewritten while the audit entry names the wrong one.
        $this->actingFresh($this->admin)
            ->put(route('forms.fields.update', [$mine, $theirField]), [
                'label' => 'Rewritten from the wrong page',
                'type' => 'text',
            ])
            ->assertNotFound();

        $this->assertSame('Operator name', $this->refreshField($theirField)->label);
    }

    #[Test]
    public function a_section_from_another_form_cannot_be_renamed_through_this_forms_url(): void
    {
        [$mine] = $this->formWithDraft('Scaffold inspection');
        [, , $theirSection] = $this->formWithDraft('Plant handover');

        $this->actingFresh($this->admin)
            ->put(route('forms.sections.update', [$mine, $theirSection]), ['title' => 'Renamed from elsewhere'])
            ->assertNotFound();

        $this->assertSame('Details', $this->refreshSection($theirSection)->title);
    }

    #[Test]
    public function a_version_of_another_form_cannot_be_published_through_this_forms_url(): void
    {
        [$mine] = $this->formWithDraft('Scaffold inspection');
        [$theirs, $theirVersion, $theirSection] = $this->formWithDraft('Plant handover');

        $this->build(fn (FormDefinitionService $service) => $service->addField(
            $theirSection, ['label' => 'Operator name', 'type' => 'text'], $this->admin
        ));

        $this->actingFresh($this->admin)
            ->post(route('forms.versions.publish', [$mine, $theirVersion]))
            ->assertNotFound();

        $theirVersion->refresh();

        $this->assertTrue($theirVersion->isDraft());
        $this->assertNotNull($theirs->key);
    }

    #[Test]
    public function asking_for_a_version_of_another_form_shows_this_forms_instead(): void
    {
        // A query-string id is a claim, not evidence. It is looked up WITHIN the form, so a
        // stranger's id finds nothing and the page falls back rather than rendering somebody
        // else's questions under this form's heading.
        [$mine, , $mySection] = $this->formWithDraft('Scaffold inspection');
        [, $theirVersion, $theirSection] = $this->formWithDraft('Plant handover');

        $this->build(fn (FormDefinitionService $service) => $service->addField(
            $theirSection, ['label' => 'Operator name', 'type' => 'text'], $this->admin
        ));
        $this->build(fn (FormDefinitionService $service) => $service->addField(
            $mySection, ['label' => 'Inspector name', 'type' => 'text'], $this->admin
        ));

        $this->actingFresh($this->admin)
            ->get(route('forms.show', ['form' => $mine, 'version' => $theirVersion->getKey()]))
            ->assertOk()
            ->assertSee('Inspector name')
            ->assertDontSee('Operator name');
    }

    #[Test]
    public function one_company_cannot_open_anothers_form(): void
    {
        // §75. The organization scope means the record is not merely refused — it is not there.
        [$mine] = $this->formWithDraft();

        $other = $this->makeOrganization('Kestrel Marine');
        $this->provisionConfiguration($other);

        $outsider = $this->makePerson($other, 'Ezra Whitfield', null, [
            $this->makeRole($other, 'Configurator', [
                'form.view' => 'organization',
                'form.edit' => 'organization',
                'form.publish' => 'organization',
            ]),
        ]);

        $this->actingFresh($outsider)->get(route('forms.show', $mine))->assertNotFound();

        $this->actingFresh($outsider)->post(route('forms.retire', $mine))->assertNotFound();

        $this->assertTrue($this->form('scaffold_inspection')->is_active);
    }

    // ================================================================== winding down

    #[Test]
    public function a_form_that_has_been_filled_in_cannot_be_deleted_through_the_screen(): void
    {
        [$form, $version] = $this->publishedForm();

        $this->submit($form, $version);

        $this->actingFresh($this->admin)
            ->delete(route('forms.destroy', $form))
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $this->assertSame(1, $this->countForms('scaffold_inspection'));
    }

    #[Test]
    public function taking_a_form_out_of_service_needs_the_publishing_authority(): void
    {
        // Same decision as publishing, in the other direction: whoever may say the company uses
        // this form may say it stops.
        [$form] = $this->publishedForm();

        $this->actingFresh($this->author)->post(route('forms.retire', $form))->assertForbidden();

        $this->assertTrue($this->form('scaffold_inspection')->is_active);

        $this->actingFresh($this->admin)->post(route('forms.retire', $form))->assertRedirect();

        $this->assertFalse($this->form('scaffold_inspection')->is_active);
    }

    #[Test]
    public function a_draft_can_be_thrown_away_and_the_live_edition_carries_on(): void
    {
        [$form, $live] = $this->publishedForm();

        $this->actingFresh($this->admin)->post(route('forms.draft', $form))->assertRedirect();

        $draft = $this->draftOf($form);

        $this->actingFresh($this->admin)
            ->delete(route('forms.versions.discard', [$form, $draft]))
            ->assertRedirect();

        $live->refresh();

        $this->assertNull($this->draftOf($form));
        $this->assertTrue($live->isPublished());
    }

    #[Test]
    public function a_live_edition_cannot_be_discarded(): void
    {
        [$form, $live] = $this->publishedForm();

        $this->actingFresh($this->admin)
            ->delete(route('forms.versions.discard', [$form, $live]))
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $live->refresh();
        $this->assertTrue($live->isPublished());
    }

    // ================================================================== scaffolding

    /** Run something inside the tenant, as the service would be run by a request. */
    private function build(callable $callback): mixed
    {
        return $this->withinOrganization($this->org,
            fn () => $callback(app(FormDefinitionService::class)));
    }

    /** @return array{0:Form,1:FormVersion,2:FormSection} */
    private function formWithDraft(string $name = 'Scaffold inspection'): array
    {
        $form = $this->build(fn (FormDefinitionService $service) => $service->createForm([
            'name' => $name,
            'category' => 'HSE',
        ], $this->admin));

        $version = $this->withinOrganization($this->org, fn () => $form->versions()->first());
        $section = $this->withinOrganization($this->org, fn () => $version->sections()->first());

        return [$form, $version, $section];
    }

    /** @return array{0:Form,1:FormVersion,2:FormSection,3:FormField} */
    private function publishedForm(string $name = 'Scaffold inspection'): array
    {
        [$form, $version, $section] = $this->formWithDraft($name);

        $field = $this->build(fn (FormDefinitionService $service) => $service->addField(
            $section, ['label' => 'Inspector name', 'key' => 'inspector', 'type' => 'text'], $this->admin
        ));

        $this->build(fn (FormDefinitionService $service) => $service->publish($version, $this->admin));

        $version->refresh();

        return [$form, $version, $section, $field];
    }

    /** A submission written straight to the table — FormSubmissionService arrives in 3D. */
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

    private function form(string $key): Form
    {
        return $this->withinOrganization($this->org,
            fn () => Form::query()->where('key', $key)->firstOrFail());
    }

    private function countForms(string $key): int
    {
        return $this->withinOrganization($this->org,
            fn () => Form::query()->where('key', $key)->count());
    }

    private function draftOf(Form $form): ?FormVersion
    {
        return $this->withinOrganization($this->org,
            fn () => FormVersion::query()->where('form_id', $form->getKey())->draft()->first());
    }

    /** @return Collection<int,FormSection> */
    private function sectionsOf(Form $form): Collection
    {
        return $this->withinOrganization($this->org, fn () => FormSection::query()
            ->whereIn('form_version_id', FormVersion::query()->where('form_id', $form->getKey())->pluck('id'))
            ->orderBy('sort')->orderBy('id')->get());
    }

    /** @return Collection<int,FormField> */
    private function fieldsOf(Form $form): Collection
    {
        return $this->withinOrganization($this->org, fn () => FormField::query()
            ->whereIn('form_version_id', FormVersion::query()->where('form_id', $form->getKey())->pluck('id'))
            ->orderBy('sort')->orderBy('id')->get());
    }

    private function refreshField(FormField $field): FormField
    {
        return $this->withinOrganization($this->org,
            fn () => FormField::query()->whereKey($field->getKey())->firstOrFail());
    }

    private function refreshSection(FormSection $section): FormSection
    {
        return $this->withinOrganization($this->org,
            fn () => FormSection::query()->whereKey($section->getKey())->firstOrFail());
    }
}
