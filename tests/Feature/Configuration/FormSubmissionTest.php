<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\NumberingScheme;
use App\Models\Organization;
use App\Models\Status;
use App\Models\User;
use App\Services\FormDefinitionService;
use App\Services\OptionListService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Filling a form in, over HTTP (Phase 7, Part 3D).
 *
 * THE FOUR THINGS THIS FILE EXISTS TO HOLD:
 *
 *   THE DEFINITION IS THE VALIDATION (§84). Every rule a posted answer meets is compiled from the
 *   stored version on the way in — not from the page that was served. A select that accepts a value
 *   nobody put on its list is a text box wearing a select's clothes, and a number column that
 *   accepts 'abc' is a column no report can ever SUM. Both are tested by posting past the screen.
 *
 *   A SUBMISSION MEANS WHAT IT MEANT ON THE DAY. The version is bound once and never moves, so a
 *   sheet opened before a republish is still asking the questions the person was shown. The failure
 *   this prevents is silent, which is why it is tested rather than trusted.
 *
 *   SAVING AND SUBMITTING ARE DIFFERENT ACTS, held on different permissions. A draft may be as
 *   half-finished as a draft is; a submission may not be, and the refusal must not cost the person
 *   what they had just typed.
 *
 *   WHAT WAS WRITTEN INTO A FORM IS THE SENSITIVE PART (§61, §68). It is not searchable, and it is
 *   not repeated into a timeline that a wider audience can read than the record itself.
 */
final class FormSubmissionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** Builds the forms, and may sign a sheet off. */
    private User $supervisor;

    /** Fills sheets in. May not assert one is true. */
    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $this->supervisor = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [
            $this->makeRole($this->org, 'Site supervisor', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
                'form.delete' => 'organization',
                'form.publish' => 'organization',
                'form.submit' => 'organization',
                'form.review' => 'organization',
                'form.manage' => 'organization',
            ]),
        ]);

        $this->technician = $this->makePerson($this->org, 'Tunde Balogun', null, [
            $this->makeRole($this->org, 'Technician', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
            ]),
        ]);
    }

    // ================================================================== starting one

    #[Test]
    public function starting_a_submission_numbers_it_and_binds_it_to_the_live_edition(): void
    {
        $form = $this->publishedForm();

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect();

        $submission = $this->latestSubmission();

        $this->assertNotNull($submission);
        $this->assertSame('FRM-2026-00001', $submission->number);
        $this->assertSame($this->liveVersionOf($form)->getKey(), $submission->form_version_id);
        $this->assertSame($this->technician->getKey(), $submission->created_by);
        $this->assertSame($this->org->getKey(), $submission->organization_id);
        $this->assertNull($submission->submitted_at);
    }

    #[Test]
    public function a_company_can_put_the_form_in_the_number_without_a_line_of_code(): void
    {
        // §64 and §73 together, and the obligation Part 3A took on when it chose one series for
        // every form: a company that wants the form named in its references adds {FORM} to the
        // pattern on the numbering screen. Nothing here is form-specific code.
        $form = $this->publishedForm();

        $this->withinOrganization($this->org, fn () => NumberingScheme::query()
            ->where('entity_type', 'form_submission')
            ->firstOrFail()
            ->update(['pattern' => '{PREFIX}-{FORM}-{SEQ}']));

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect();

        $this->assertSame('FRM-scaffold_inspection-00001', $this->latestSubmission()->number);
    }

    #[Test]
    public function a_form_that_was_never_published_cannot_be_filled_in(): void
    {
        // The mistake people actually make: built this morning, never published, and then hunted
        // for on the fill-in list. The refusal says which of the two halves failed.
        $form = $this->formWithDraft();

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect()
            ->assertSessionHasErrors('form_id');

        $this->assertSame(0, $this->countSubmissions());
    }

    #[Test]
    public function a_form_taken_out_of_service_stops_being_offered(): void
    {
        $form = $this->publishedForm();

        $this->withinOrganization($this->org,
            fn () => app(FormDefinitionService::class)->retire($form, $this->supervisor));

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect()
            ->assertSessionHasErrors('form_id');

        $this->actingFresh($this->technician)->get(route('form-submissions.create'))
            ->assertOk()
            ->assertDontSee('Scaffold inspection');

        $this->assertSame(0, $this->countSubmissions());
    }

    #[Test]
    public function another_companys_project_does_not_ride_in_on_the_payload(): void
    {
        // §74, §75. The id arrives from a select box, which is to say from whatever the browser
        // posted. A submission filed against a stranger's project would carry that company's
        // access axis, and become readable across the tenancy boundary.
        $form = $this->publishedForm();

        $other = $this->makeOrganization('Kestrel Marine');
        $stranger = $this->makePerson($other, 'Bola Adeyemi', null, [
            $this->makeRole($other, 'Planner', ['project.view' => 'organization']),
        ]);
        $theirs = $this->withinOrganization($other,
            fn () => $this->makeProject($other, $stranger, 'Berth 4 refurbishment'));

        $this->actingFresh($this->technician)->post(route('form-submissions.store'), [
            'form_id' => $form->getKey(),
            'project_id' => $theirs->getKey(),
        ])->assertRedirect();

        $this->assertNull($this->latestSubmission()->project_id);
    }

    // ================================================================== the edition it asks

    #[Test]
    public function a_submission_keeps_asking_the_questions_it_was_opened_with(): void
    {
        // The failure the whole versioning design exists to prevent, and it is invisible until
        // somebody is audited: a half-finished sheet whose questions changed under the person
        // holding the tablet.
        $form = $this->publishedForm();
        $submission = $this->start($form);
        $first = $submission->form_version_id;

        $this->rewordAndRepublish($form, 'Inspector name', 'Name of competent person');

        $this->actingFresh($this->technician)->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Inspector name')
            ->assertDontSee('Name of competent person');

        $this->assertSame($first, $this->refreshSubmission($submission)->form_version_id);
    }

    // ================================================================== what is accepted

    #[Test]
    public function a_draft_may_be_as_half_finished_as_a_draft_is(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['notes' => 'Bay 3 not reachable today.'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Bay 3 not reachable today.', $this->answerTo($submission, 'notes'));
    }

    #[Test]
    public function a_number_question_refuses_words_even_in_a_draft(): void
    {
        // Type rules hold at every moment. A draft carrying 'abc' in a number column is a draft
        // that cannot be submitted and a column a report will one day be asked to total.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['height' => 'about nine metres'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('answers.height');

        $this->assertNull($this->answerTo($submission, 'height'));
    }

    #[Test]
    public function a_number_question_holds_the_bounds_its_author_set(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => ['height' => 400]])
            ->assertRedirect()
            ->assertSessionHasErrors('answers.height');

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => ['height' => 9.5]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('9.5', $this->answerTo($submission, 'height'));
    }

    #[Test]
    public function a_select_refuses_a_value_that_is_not_on_its_list(): void
    {
        // §84. Without the `in` rule a select is a text box with a nicer control on top, and the
        // controlled vocabulary every later report groups by is a suggestion.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['outcome' => 'probably_fine'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('answers.outcome');

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => ['outcome' => 'pass']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('pass', $this->answerTo($submission, 'outcome'));
    }

    #[Test]
    public function a_question_the_payload_never_mentions_is_left_exactly_as_it_was(): void
    {
        // A partial save — a workflow action, a screen that posts one section — must not read as
        // "everything else was cleared".
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->save($submission, ['inspector' => 'Ngozi Eze', 'notes' => 'First pass.']);
        $this->save($submission, ['notes' => 'Second pass.']);

        $this->assertSame('Ngozi Eze', $this->answerTo($submission, 'inspector'));
        $this->assertSame('Second pass.', $this->answerTo($submission, 'notes'));
    }

    #[Test]
    public function an_answer_cleared_out_stops_existing_rather_than_becoming_an_empty_one(): void
    {
        // "Never answered" and "answered blank" must not become two different kinds of nothing
        // that every later report has to tell apart.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->save($submission, ['notes' => 'Something.']);
        $this->assertSame(1, $this->answerRows($submission, 'notes'));

        $this->save($submission, ['notes' => '']);
        $this->assertSame(0, $this->answerRows($submission, 'notes'));
    }

    // ================================================================== repeating tables

    #[Test]
    public function a_repeating_table_stores_one_row_per_bay_and_drops_the_blanks(): void
    {
        // Twelve scaffold bays are twelve rows a report can count, not a JSON blob it has to
        // parse. The blank rows the screen offers to type into are not rows.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->save($submission, ['bays' => [
            0 => ['bay' => 'A1', 'remarks' => 'Sound'],
            1 => ['bay' => '', 'remarks' => ''],
            2 => ['bay' => 'A3', 'remarks' => 'Toe board missing'],
        ]]);

        $rows = $this->tableRows($submission, 'bays');

        $this->assertCount(2, $rows);
        $this->assertSame(['bay' => 'A1', 'remarks' => 'Sound'], $rows[0]);
        $this->assertSame(['bay' => 'A3', 'remarks' => 'Toe board missing'], $rows[1]);
    }

    #[Test]
    public function deleting_a_row_leaves_no_hole_and_no_orphan(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->save($submission, ['bays' => [
            ['bay' => 'A1', 'remarks' => 'Sound'],
            ['bay' => 'A2', 'remarks' => 'Sound'],
            ['bay' => 'A3', 'remarks' => 'Sound'],
        ]]);

        $this->save($submission, ['bays' => [['bay' => 'A2', 'remarks' => 'Sound']]]);

        $rows = $this->tableRows($submission, 'bays');

        $this->assertCount(1, $rows);
        $this->assertSame('A2', $rows[0]['bay']);

        // Rows 1 and 2 are gone from the table, not merely hidden — an eleventh bay left behind
        // would be invisible on screen and present in every export.
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => FormAnswer::query()
            ->where('form_submission_id', $submission->getKey())
            ->where('row_index', '>', 0)
            ->count()));
    }

    #[Test]
    public function a_posted_row_number_is_a_position_and_not_an_identifier(): void
    {
        // §84. The index in the field name is where a row lands in this payload, and the compiler
        // renumbers from zero on save. A posted index of 900 changes nothing but the order.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->save($submission, ['bays' => [
            900 => ['bay' => 'B1', 'remarks' => 'Sound'],
            7 => ['bay' => 'B2', 'remarks' => 'Sound'],
        ]]);

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => FormAnswer::query()
            ->where('form_submission_id', $submission->getKey())
            ->where('row_index', '>', 1)
            ->count()));

        $this->assertCount(2, $this->tableRows($submission, 'bays'));
    }

    // ================================================================== submitting

    #[Test]
    public function an_incomplete_sheet_is_refused_and_nothing_typed_is_lost(): void
    {
        // The refusal must not cost the person what they had just typed — on a fifty-question
        // sheet filled in on a tablet in a yard that is a loss people do not come back from.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => ['notes' => 'Weather closed us down.'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertNull($this->refreshSubmission($submission)->submitted_at);
        $this->assertSame('Weather closed us down.', $this->answerTo($submission, 'notes'));
    }

    #[Test]
    public function a_required_question_that_does_not_apply_is_not_asked_for(): void
    {
        // "What was the defect" is required of a failed inspection and meaningless on a passed
        // one. A question hidden where it applies is data silently not captured; one offered
        // where it does not can be left blank.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => $this->completeAnswers(['outcome' => 'pass']),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->refreshSubmission($submission)->submitted_at);
    }

    #[Test]
    public function the_same_question_is_insisted_on_once_it_does_apply(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => $this->completeAnswers(['outcome' => 'fail']),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertNull($this->refreshSubmission($submission)->submitted_at);
    }

    #[Test]
    public function submitting_stamps_who_and_when_and_moves_the_status(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => $this->completeAnswers(),
            ])
            ->assertRedirect(route('form-submissions.show', $submission));

        $fresh = $this->refreshSubmission($submission);

        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame($this->supervisor->getKey(), $fresh->submitted_by);
        $this->assertSame('submitted', $this->statusKeyOf($fresh));
    }

    #[Test]
    public function a_submitted_sheet_stops_accepting_answers(): void
    {
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['notes' => 'Actually, let me change that.'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('answers');

        $this->assertNull($this->answerTo($submission, 'notes'));
    }

    // ================================================================== the permission split

    #[Test]
    public function recording_readings_is_not_signing_the_sheet_off(): void
    {
        // A technician who records and a supervisor who asserts is an arrangement companies
        // routinely want, and one permission for both makes it unexpressible. The button being
        // absent is a courtesy; this is the control (§6, §84).
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => $this->completeAnswers(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertForbidden();

        $this->assertNull($this->refreshSubmission($submission)->submitted_at);
    }

    #[Test]
    public function the_fill_in_screen_does_not_offer_a_submit_button_to_somebody_who_cannot(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        // The button's own wording rather than its URL: the endpoint reaches the page through
        // @js(), which escapes every slash, so matching the route would be matching the encoding.
        $this->actingFresh($this->technician)->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertDontSee('Yes — submit it');

        $this->actingFresh($this->supervisor)->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Yes — submit it');
    }

    #[Test]
    public function the_screen_says_what_is_still_needed_before_the_button_is_pressed(): void
    {
        // The difference between a form and an argument with a form.
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Still needed before this can be submitted')
            ->assertSee('Inspector name');
    }

    // ================================================================== review

    #[Test]
    public function sending_a_sheet_back_makes_it_editable_again_and_keeps_the_stamps(): void
    {
        // Who submitted it, and when, is what happened. A record that erased it on return would
        // lose the fact that a supervisor was once told it was ready.
        $form = $this->publishedForm();
        $submission = $this->submitted($form);
        $submittedAt = $this->refreshSubmission($submission)->submitted_at;

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.send-back', $submission), [
                'reason' => 'Bay A3 needs a photograph attaching.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $this->refreshSubmission($submission);

        $this->assertSame('returned', $this->statusKeyOf($fresh));
        $this->assertNotNull($fresh->submitted_by);
        $this->assertEquals($submittedAt, $fresh->submitted_at);

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['notes' => 'Photograph taken.'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Photograph taken.', $this->answerTo($submission, 'notes'));
    }

    #[Test]
    public function sending_a_sheet_back_without_saying_why_is_refused(): void
    {
        // "Sent back" with no explanation is a message that makes somebody guess.
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.send-back', $submission), ['reason' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors('reason');

        $this->assertSame('submitted', $this->statusKeyOf($this->refreshSubmission($submission)));
    }

    #[Test]
    public function whoever_filled_it_in_cannot_review_their_own_sheet(): void
    {
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.send-back', $submission), ['reason' => 'Let me at it.'])
            ->assertForbidden();

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.status', $submission), [
                'status_id' => $this->statusId('accepted'),
            ])
            ->assertForbidden();

        $this->assertSame('submitted', $this->statusKeyOf($this->refreshSubmission($submission)));
    }

    #[Test]
    public function an_accepted_sheet_is_closed_to_everybody(): void
    {
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.status', $submission), [
                'status_id' => $this->statusId('accepted'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Not even the supervisor who accepted it: what it says is the record of what was
        // accepted, and an editable record of an accepted thing is not a record.
        $this->actingFresh($this->supervisor)
            ->put(route('form-submissions.update', $submission), [
                'answers' => ['notes' => 'One more thought.'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('answers');

        $this->assertNull($this->answerTo($submission, 'notes'));
    }

    // ================================================================== deleting

    #[Test]
    public function an_unsubmitted_sheet_can_be_thrown_away_and_is_only_soft_deleted(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->delete(route('form-submissions.destroy', $submission))
            ->assertRedirect(route('form-submissions.index'));

        $this->assertNull($this->refreshSubmission($submission));
        $this->assertNotNull($this->withinOrganization($this->org, fn () => FormSubmission::withTrashed()
            ->whereKey($submission->getKey())->first()));
    }

    #[Test]
    public function a_submitted_sheet_cannot_be_deleted(): void
    {
        // §59. "Delete" on a screen means "take this off my list", and a permit checklist somebody
        // signed is not a thing a button press should be able to destroy.
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $this->actingFresh($this->supervisor)
            ->delete(route('form-submissions.destroy', $submission))
            ->assertRedirect()
            ->assertSessionHasErrors('submission');

        $this->assertNotNull($this->refreshSubmission($submission));
    }

    // ================================================================== what stays private

    #[Test]
    public function the_search_box_does_not_read_the_answers(): void
    {
        // §61. A search that matched what people wrote into forms would report, through the result
        // count alone, whether a phrase appears in submissions the reader may list but not open —
        // which is how a search box becomes a way to read an incident report one guess at a time.
        $form = $this->publishedForm();
        $submission = $this->start($form);
        $this->save($submission, ['notes' => 'Contractor smelled of alcohol.']);

        // The row is not matched. Asserting the word itself is absent would prove nothing — the
        // search box echoes back whatever was typed into it.
        $this->actingFresh($this->supervisor)
            ->get(route('form-submissions.index', ['q' => 'alcohol']))
            ->assertOk()
            ->assertDontSee($submission->number);

        $this->actingFresh($this->supervisor)
            ->get(route('form-submissions.index', ['q' => 'FRM-2026']))
            ->assertOk()
            ->assertSee($submission->number);
    }

    #[Test]
    public function the_timeline_says_which_questions_changed_and_never_what_was_written(): void
    {
        // §68. An activity entry is readable by anybody who can see the record's timeline, and the
        // answers on a form are frequently the sensitive part — an incident account, somebody's
        // health declaration.
        $form = $this->publishedForm();
        $submission = $this->start($form);
        $this->save($submission, ['notes' => 'Contractor smelled of alcohol.']);

        $entries = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', 'form_submission')
            ->where('subject_id', $submission->getKey())
            ->get());

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('alcohol', (string) $entry->description);
            $this->assertStringNotContainsString('alcohol', (string) json_encode($entry->properties));
        }
    }

    // ================================================================== the tenancy boundary

    #[Test]
    public function another_company_cannot_open_a_submission(): void
    {
        // §75, §94. Not a 403 — a 404, the same answer an id that does not exist gets, because
        // confirming the record exists is itself a disclosure (§61).
        $form = $this->publishedForm();
        $submission = $this->submitted($form);

        $other = $this->makeOrganization('Kestrel Marine');
        $outsider = $this->makePerson($other, 'Bola Adeyemi', null, [
            $this->makeRole($other, 'Everything', [
                'form.view' => 'organization',
                'form.edit' => 'organization',
                'form.submit' => 'organization',
                'form.review' => 'organization',
                'form.delete' => 'organization',
            ]),
        ]);

        $this->actingFresh($outsider)
            ->get(route('form-submissions.show', $submission))->assertNotFound();

        $this->actingFresh($outsider)
            ->get(route('form-submissions.edit', $submission))->assertNotFound();

        $this->actingFresh($outsider)
            ->put(route('form-submissions.update', $submission), ['answers' => ['notes' => 'Mine now.']])
            ->assertNotFound();

        $this->actingFresh($outsider)
            ->delete(route('form-submissions.destroy', $submission))->assertNotFound();

        $this->assertNotNull($this->refreshSubmission($submission));
    }

    #[Test]
    public function somebody_with_no_form_permission_at_all_is_refused_every_door(): void
    {
        $form = $this->publishedForm();
        $submission = $this->start($form);

        $nobody = $this->makePerson($this->org, 'Chiamaka Nwosu', null, [
            $this->makeRole($this->org, 'Onlooker', ['project.view' => 'organization']),
        ]);

        $this->actingFresh($nobody)->get(route('form-submissions.index'))->assertForbidden();
        $this->actingFresh($nobody)->get(route('form-submissions.create'))->assertForbidden();
        $this->actingFresh($nobody)->get(route('form-submissions.show', $submission))->assertForbidden();
        $this->actingFresh($nobody)
            ->put(route('form-submissions.update', $submission), ['answers' => []])
            ->assertForbidden();
    }

    // ================================================================== scaffolding

    /**
     * The inspection sheet every test here fills in.
     *
     * Deliberately not minimal: a required text, a bounded number, a select drawn from an option
     * list, a free-text note, a conditional question and a repeating table are the shapes a real
     * form is made of, and the interesting failures live where they meet.
     */
    private function publishedForm(): Form
    {
        return $this->withinOrganization($this->org, function () {
            $forms = app(FormDefinitionService::class);
            $lists = app(OptionListService::class);

            $outcomes = $lists->createList(
                ['key' => 'inspection_outcome', 'name' => 'Inspection outcome'], $this->supervisor
            );
            $lists->createItem($outcomes, ['value' => 'pass', 'label' => 'Pass'], $this->supervisor);
            $lists->createItem($outcomes, ['value' => 'fail', 'label' => 'Fail'], $this->supervisor);

            $form = $forms->createForm([
                'name' => 'Scaffold inspection',
                'category' => 'HSE',
            ], $this->supervisor);

            $version = $form->versions()->first();
            $section = $version->sections()->first();

            $forms->addField($section, [
                'label' => 'Inspector name', 'key' => 'inspector', 'type' => 'text',
                'is_required' => true,
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'Height', 'key' => 'height', 'type' => 'number',
                'config' => ['min' => 0, 'max' => 60],
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'Outcome', 'key' => 'outcome', 'type' => 'select',
                'option_list_key' => 'inspection_outcome', 'is_required' => true,
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'What was the defect', 'key' => 'defect', 'type' => 'textarea',
                'is_required' => true,
                'applies_when_key' => 'outcome', 'applies_when_value' => 'fail',
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'Notes', 'key' => 'notes', 'type' => 'textarea',
            ], $this->supervisor);

            $table = $forms->addField($section, [
                'label' => 'Bays', 'key' => 'bays', 'type' => 'table',
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'Bay', 'key' => 'bay', 'type' => 'text',
                'parent_field_id' => $table->getKey(),
            ], $this->supervisor);

            $forms->addField($section, [
                'label' => 'Remarks', 'key' => 'remarks', 'type' => 'text',
                'parent_field_id' => $table->getKey(),
            ], $this->supervisor);

            $forms->publish($version, $this->supervisor);

            return $form->refresh();
        });
    }

    /** The same form with its first edition left as a draft. */
    private function formWithDraft(): Form
    {
        return $this->withinOrganization($this->org, fn () => app(FormDefinitionService::class)
            ->createForm(['name' => 'Lifting plan', 'category' => 'HSE'], $this->supervisor));
    }

    /** Reword one question and publish the result as the next edition. */
    private function rewordAndRepublish(Form $form, string $from, string $to): void
    {
        $this->withinOrganization($this->org, function () use ($form, $from, $to) {
            $service = app(FormDefinitionService::class);
            $draft = $service->openDraft($form, $this->supervisor);

            $field = FormField::query()
                ->where('form_version_id', $draft->getKey())
                ->where('label', $from)
                ->firstOrFail();

            $service->updateField($field, ['label' => $to], $this->supervisor);
            $service->publish($draft, $this->supervisor, 'Reworded one question.');
        });
    }

    /** Start one through the controller, as a person would. */
    private function start(Form $form): FormSubmission
    {
        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect();

        return $this->latestSubmission();
    }

    /** Start one and put it through the door marked Submit. */
    private function submitted(Form $form): FormSubmission
    {
        $submission = $this->start($form);

        $this->actingFresh($this->supervisor)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => $this->completeAnswers(),
            ])
            ->assertSessionHasNoErrors();

        return $submission;
    }

    /**
     * Enough to satisfy every question that is genuinely being asked.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completeAnswers(array $overrides = []): array
    {
        return [
            'inspector' => 'Ngozi Eze',
            'height' => 8,
            'outcome' => 'pass',
            'bays' => [['bay' => 'A1', 'remarks' => 'Sound']],
            ...$overrides,
        ];
    }

    /** @param array<string,mixed> $answers */
    private function save(FormSubmission $submission, array $answers): void
    {
        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => $answers])
            ->assertSessionHasNoErrors();
    }

    private function latestSubmission(): ?FormSubmission
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->orderByDesc('id')->first());
    }

    private function refreshSubmission(FormSubmission $submission): ?FormSubmission
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->whereKey($submission->getKey())->first());
    }

    private function countSubmissions(): int
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->count());
    }

    private function liveVersionOf(Form $form): FormVersion
    {
        return $this->withinOrganization($this->org, fn () => FormVersion::query()
            ->where('form_id', $form->getKey())->published()->firstOrFail());
    }

    private function statusKeyOf(FormSubmission $submission): ?string
    {
        return $this->withinOrganization($this->org,
            fn () => $submission->status()->value('key'));
    }

    private function statusId(string $key): int
    {
        return (int) $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('form_submission')->where('key', $key)->value('id'));
    }

    /** The stored text of one top-level answer, or null when there is no row for it. */
    private function answerTo(FormSubmission $submission, string $key): ?string
    {
        return $this->withinOrganization($this->org, fn () => FormAnswer::query()
            ->where('form_submission_id', $submission->getKey())
            ->whereIn('form_field_id', $this->fieldIds($submission, $key))
            ->value('value'));
    }

    private function answerRows(FormSubmission $submission, string $key): int
    {
        return $this->withinOrganization($this->org, fn () => FormAnswer::query()
            ->where('form_submission_id', $submission->getKey())
            ->whereIn('form_field_id', $this->fieldIds($submission, $key))
            ->count());
    }

    /**
     * One repeating table's rows, in stored order, keyed by column key.
     *
     * @return array<int,array<string,string>>
     */
    private function tableRows(FormSubmission $submission, string $tableKey): array
    {
        return $this->withinOrganization($this->org, function () use ($submission, $tableKey) {
            $fresh = FormSubmission::query()->whereKey($submission->getKey())->firstOrFail();

            $table = FormField::query()
                ->where('form_version_id', $fresh->form_version_id)
                ->where('key', $tableKey)
                ->firstOrFail();

            $columns = FormField::query()
                ->where('parent_field_id', $table->getKey())
                ->orderBy('sort')->get()->keyBy('id');

            $rows = [];

            foreach (FormAnswer::query()
                ->where('form_submission_id', $fresh->getKey())
                ->whereIn('form_field_id', $columns->keys())
                ->orderBy('row_index')->get() as $answer) {
                $rows[(int) $answer->row_index][$columns[$answer->form_field_id]->key] = $answer->value;
            }

            ksort($rows);

            return array_values($rows);
        });
    }

    /** @return array<int,int> */
    private function fieldIds(FormSubmission $submission, string $key): array
    {
        return $this->withinOrganization($this->org, function () use ($submission, $key) {
            $fresh = FormSubmission::query()->whereKey($submission->getKey())->firstOrFail();

            return FormField::query()
                ->where('form_version_id', $fresh->form_version_id)
                ->where('key', $key)
                ->pluck('id')->all();
        });
    }
}
