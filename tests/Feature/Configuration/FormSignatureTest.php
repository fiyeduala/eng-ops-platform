<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Configuration\FormCompiler;
use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Signature;
use App\Models\Status;
use App\Models\User;
use App\Services\FormDefinitionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Signing a filled-in form, and adding rows to one (Phase 7, Part 3E).
 *
 * WHAT THIS FILE IS DEFENDING:
 *
 *   A SIGNATURE IS BOUND TO WHAT IT SIGNED (§28). The hash is taken over the answers as recorded,
 *   so a permit whose readings were edited after the supervisor signed is visibly a permit nobody
 *   approved — and, on a required block, one that cannot be submitted until somebody signs the
 *   version that now exists. This is the failure the whole mechanism is for, and it is silent if
 *   nothing tests it.
 *
 *   THERE IS ONE SIGNATURE STACK, NOT TWO. Submissions write to the document module's Signature
 *   table, with its written-once rule and its designation snapshot. A withdrawal is a new row; the
 *   original is never touched, because "she signed it on Tuesday and withdrew it on Wednesday" is
 *   two facts an auditor needs and a delete leaves them with neither (§59).
 *
 *   ONLY THE SIGNATORY WITHDRAWS. Somebody else taking your name off a sheet is forgery with extra
 *   steps, whatever permissions they hold.
 *
 *   A FIELD ID IN A URL IS NOT AUTHORITY (§84, §75). The block is looked for among the fields of
 *   this submission's own edition, so another form's signature block — or another company's — is
 *   simply not there to sign.
 *
 *   ROWS ARE ADDED ON THE PAGE, AND THE AUTHOR'S CEILING IS STILL THE AUTHOR'S CEILING. What the
 *   screen offers is bounded by the same min_rows / max_rows the compiler enforces.
 */
final class FormSignatureTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    /** Issues permits, and may sign one. */
    private User $issuer;

    /** Fills sheets in. May not assert one is true, and therefore may not sign one. */
    private User $technician;

    /** Also holds the submit permission, so "somebody else" is a real person and not a stranger. */
    private User $deputy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->provisionConfiguration($this->org);

        $full = $this->makeRole($this->org, 'Permit issuer', [
            'form.view' => 'organization',
            'form.create' => 'organization',
            'form.edit' => 'organization',
            'form.delete' => 'organization',
            'form.publish' => 'organization',
            'form.submit' => 'organization',
            'form.review' => 'organization',
            'form.manage' => 'organization',
        ]);

        $this->issuer = $this->makePerson($this->org, 'Adaeze Okonkwo', null, [$full]);
        $this->deputy = $this->makePerson($this->org, 'Chidi Nwosu', null, [$full]);

        $this->technician = $this->makePerson($this->org, 'Tunde Balogun', null, [
            $this->makeRole($this->org, 'Technician', [
                'form.view' => 'organization',
                'form.create' => 'organization',
                'form.edit' => 'organization',
            ]),
        ]);
    }

    // ================================================================== signing

    #[Test]
    public function signing_records_who_signed_what_and_when(): void
    {
        $this->issuer = $this->giveAPosition($this->issuer, 'HSE Manager');

        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());

        $this->actingFresh($this->issuer)
            ->post($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasNoErrors();

        $signature = $this->signatures($submission)->sole();

        $this->assertSame('sign', $signature->action);
        $this->assertSame('Adaeze Okonkwo', $signature->name_snapshot);
        // The job title held ON THE DAY, snapshotted rather than joined — a promotion next year
        // must not rewrite who signed this permit as what they have since become.
        $this->assertSame('HSE Manager', $signature->designation_snapshot);
        $this->assertSame($this->issuer->getKey(), $signature->user_id);
        $this->assertSame('internal', $signature->provider);
        $this->assertSame($this->org->getKey(), $signature->organization_id);
        $this->assertNotNull($signature->signed_at);

        // Against the submission, in the same table the document module signs into.
        $this->assertSame($submission->getKey(), $signature->signable_id);
        $this->assertSame($submission->getMorphClass(), $signature->signable_type);

        // And against a named block on it, by id and by the words that were on it.
        $this->assertSame(
            $this->fieldId($submission, 'authorisation'),
            $signature->provider_reference['field_id']
        );
        $this->assertSame('Authorisation', $signature->provider_reference['field_label']);
    }

    #[Test]
    public function a_signature_is_bound_to_the_answers_as_they_were_recorded(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $signature = $this->signatures($submission)->sole();

        $this->assertTrue($signature->matches($this->hashOf($submission)));

        // One reading changed, and the signature no longer covers the sheet.
        $this->save($submission, ['readings' => [
            ['gas' => '4%', 'time' => '08:00'],
            ['gas' => '0%', 'time' => '12:00'],
        ]]);

        $this->assertFalse($signature->matches($this->hashOf($submission)));
    }

    #[Test]
    public function a_second_signature_does_not_disturb_the_first(): void
    {
        // Two people signing the same sheet must not invalidate each other: what is hashed is the
        // answers, and a counter-signature is not a change to what was signed.
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());

        $this->sign($submission, 'authorisation', $this->issuer);
        $this->sign($submission, 'authorisation', $this->deputy);

        $signatures = $this->signatures($submission);

        $this->assertCount(2, $signatures);
        $this->assertTrue($signatures->every(fn (Signature $one) => $one->matches($this->hashOf($submission))));
    }

    #[Test]
    public function the_same_person_cannot_sign_the_same_block_twice(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->issuer)
            ->post($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasErrors('signature');

        $this->assertCount(1, $this->signatures($submission));
    }

    // ================================================================== withdrawing

    #[Test]
    public function withdrawing_adds_an_event_and_edits_nothing(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $original = $this->signatures($submission)->sole();

        $this->actingFresh($this->issuer)
            ->delete($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasNoErrors();

        $rows = $this->signatures($submission);

        $this->assertCount(2, $rows);

        // The first row is exactly as it was written. A signature is never edited (§59).
        $untouched = $rows->firstWhere('id', $original->id);
        $this->assertSame('sign', $untouched->action);
        $this->assertSame($original->content_hash, $untouched->content_hash);
        $this->assertSame($original->signed_at->toString(), $untouched->signed_at->toString());

        $this->assertSame('reject', $rows->firstWhere('id', '!=', $original->id)->action);
    }

    #[Test]
    public function a_withdrawn_signature_stops_counting_as_a_signature(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);
        $this->withdraw($submission, 'authorisation', $this->issuer);

        $this->assertCount(0, $this->standing($submission, 'authorisation'));
    }

    #[Test]
    public function nobody_can_withdraw_somebody_elses_signature(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->deputy)
            ->delete($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasErrors('signature');

        // Still signed, and by the person who signed it.
        $this->assertCount(1, $this->standing($submission, 'authorisation'));
    }

    #[Test]
    public function withdrawing_a_signature_that_was_never_given_is_refused(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->issuer)
            ->delete($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasErrors('signature');

        $this->assertCount(0, $this->signatures($submission));
    }

    // ================================================================== completeness

    #[Test]
    public function a_required_block_nobody_signed_holds_the_sheet_up(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), [
                'answers' => $this->completeAnswers(),
            ])
            ->assertSessionHasErrors('answers');

        $this->assertNull($this->refresh($submission)->submitted_at);

        // And the screen says which block, before anybody presses anything.
        $this->actingFresh($this->issuer)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Authorisation — not signed yet');
    }

    #[Test]
    public function an_optional_block_is_never_what_is_holding_a_sheet_up(): void
    {
        // The second block on this form is not required. An author who added a signature block and
        // did not tick "required" has said the sheet is complete without it, and the platform is
        // not the place to overrule them.
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->refresh($submission)->submitted_at);
        $this->assertCount(0, $this->standing($submission, 'acknowledgement'));
    }

    #[Test]
    public function answers_changed_after_signing_put_the_block_back_on_the_list(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        // The readings move underneath the person who signed them.
        $this->save($submission, ['holder' => 'Somebody else entirely']);

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertSessionHasErrors('answers');

        $this->assertNull($this->refresh($submission)->submitted_at);

        $this->actingFresh($this->issuer)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Authorisation — the answers changed after this was signed', false);
    }

    #[Test]
    public function signing_the_sheet_that_now_exists_lets_it_through(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);
        $this->save($submission, ['holder' => 'Somebody else entirely']);

        // The way back is to withdraw and sign again — never to edit the signature that exists.
        $this->withdraw($submission, 'authorisation', $this->issuer);
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->refresh($submission)->submitted_at);
    }

    #[Test]
    public function the_stale_signature_is_named_on_the_record_screen_in_words(): void
    {
        // Two hex strings that disagree is not something anybody notices by eye.
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);
        $this->save($submission, ['holder' => 'Somebody else entirely']);

        $this->actingFresh($this->issuer)
            ->get(route('form-submissions.show', $submission))
            ->assertOk()
            ->assertSee('The answers have changed since this was signed');
    }

    // ================================================================== who may sign

    #[Test]
    public function somebody_who_may_fill_a_sheet_in_may_not_sign_it(): void
    {
        // The whole reason `submit` is a permission of its own: a technician who records readings
        // and a supervisor who signs them off is an arrangement companies routinely want.
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->post($this->signRoute($submission, 'authorisation'))
            ->assertForbidden();

        $this->assertCount(0, $this->signatures($submission));
    }

    #[Test]
    public function the_sign_button_is_not_drawn_for_somebody_who_cannot_sign(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertDontSee('Sign this block');

        $this->actingFresh($this->issuer)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Sign this block');
    }

    #[Test]
    public function a_signature_block_belonging_to_another_form_cannot_be_signed_onto_this_sheet(): void
    {
        $submission = $this->started();
        $other = $this->permitForm('Confined space entry', 'entry');

        $stray = $this->withinOrganization($this->org, fn () => FormField::query()
            ->where('form_version_id', $other->versions()->published()->firstOrFail()->getKey())
            ->where('key', 'authorisation')
            ->value('id'));

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.sign', [$submission, $stray]))
            ->assertSessionHasErrors('signature');

        $this->assertCount(0, $this->signatures($submission));
    }

    #[Test]
    public function a_question_that_is_not_a_signature_block_cannot_be_signed(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.sign', [$submission, $this->fieldId($submission, 'holder')]))
            ->assertSessionHasErrors('signature');

        $this->assertCount(0, $this->signatures($submission));
    }

    #[Test]
    public function another_company_cannot_sign_a_submission(): void
    {
        $submission = $this->started();

        $elsewhere = $this->makeOrganization('Harcourt Fabrication');
        $this->provisionConfiguration($elsewhere);
        $stranger = $this->makePerson($elsewhere, 'Ifeoma Chukwu', null, [
            $this->makeRole($elsewhere, 'Supervisor', [
                'form.view' => 'organization',
                'form.submit' => 'organization',
            ]),
        ]);

        $this->actingFresh($stranger)
            ->post($this->signRoute($submission, 'authorisation'))
            ->assertNotFound();

        $this->assertCount(0, $this->signatures($submission));
    }

    #[Test]
    public function a_closed_sheet_takes_no_more_signatures(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertSessionHasNoErrors();

        $this->accept($submission);

        $this->actingFresh($this->deputy)
            ->post($this->signRoute($submission, 'authorisation'))
            ->assertSessionHasErrors('signature');

        $this->assertCount(1, $this->signatures($submission));
    }

    #[Test]
    public function a_submitted_sheet_can_still_be_counter_signed(): void
    {
        // Signing after submission is the ordinary case, not an edge one — it is what the person
        // reviewing a permit does, and it is why `sign` and `submit` are separate acts.
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());
        $this->sign($submission, 'authorisation', $this->issuer);

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.submit', $submission), ['answers' => []])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->deputy)
            ->post($this->signRoute($submission, 'acknowledgement'))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->standing($submission, 'acknowledgement'));
    }

    // ================================================================== what is said about it

    #[Test]
    public function the_timeline_says_which_block_was_signed_and_never_what_the_sheet_says(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers(['holder' => 'Emeka Adeyemi']));
        $this->sign($submission, 'authorisation', $this->issuer);

        $entry = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', $submission->getMorphClass())
            ->where('subject_id', $submission->getKey())
            ->where('verb', 'signed')
            ->firstOrFail());

        $this->assertStringContainsString('Authorisation', $entry->description);

        // The answers are frequently the sensitive part, and a timeline is read by a wider
        // audience than the record itself (§68).
        $this->assertStringNotContainsString('Emeka Adeyemi', $entry->description);
        $this->assertSame(['block' => 'Authorisation'], $entry->properties);
    }

    #[Test]
    public function the_panel_says_what_a_signature_here_is_and_is_not(): void
    {
        // §28. A system that lets people believe they have more legal weight than they do is worse
        // than one that has none, so the scope statement is on the panel and not only in docs/09.
        $submission = $this->started();

        $this->actingFresh($this->issuer)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Not a qualified signature under eIDAS or ESIGN.');
    }

    // ================================================================== rows on the page

    #[Test]
    public function a_table_offers_rows_to_add_without_going_back_to_the_server(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('Add a row')
            ->assertSee('Remove this row');
    }

    #[Test]
    public function the_screen_starts_with_as_many_rows_as_the_author_demanded(): void
    {
        // min_rows is 2 on this table, so two rows are on screen before anybody presses anything.
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('shown: 2')
            ->assertSee('answers[readings][1][gas]');
    }

    #[Test]
    public function the_screen_never_offers_more_rows_than_the_author_allowed(): void
    {
        // max_rows is 3. The page must not contain a fourth row to reveal — and the compiler
        // refuses a fourth whatever the page contained, which is the control (§84).
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('answers[readings][2][gas]')
            ->assertDontSee('answers[readings][3][gas]');
    }

    #[Test]
    public function a_fourth_row_posted_past_the_screen_is_refused(): void
    {
        $submission = $this->started();

        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => [
                'readings' => [
                    ['gas' => '0%', 'time' => '06:00'],
                    ['gas' => '0%', 'time' => '08:00'],
                    ['gas' => '0%', 'time' => '10:00'],
                    ['gas' => '0%', 'time' => '12:00'],
                ],
            ]])
            ->assertSessionHasErrors('answers.readings');
    }

    #[Test]
    public function a_sheet_with_rows_in_it_still_offers_one_more(): void
    {
        $submission = $this->started();
        $this->save($submission, $this->completeAnswers());

        // Two rows answered, a ceiling of three: one spare, and the screen says so.
        $this->actingFresh($this->technician)
            ->get(route('form-submissions.edit', $submission))
            ->assertOk()
            ->assertSee('shown: 2')
            ->assertSee('answers[readings][2][gas]')
            ->assertDontSee('answers[readings][3][gas]');
    }

    // ================================================================== scaffolding

    /**
     * A permit to work: one question, a bounded repeating table, and two signature blocks.
     *
     * The second block is deliberately NOT required, so that "required" and "present" are tested as
     * different things rather than assumed to be the same one.
     */
    private function permitForm(string $name = 'Permit to work', string $key = 'permit'): Form
    {
        return $this->withinOrganization($this->org, function () use ($name, $key) {
            $forms = app(FormDefinitionService::class);

            $form = $forms->createForm([
                'name' => $name,
                'key' => $key,
                'category' => 'HSE',
            ], $this->issuer);

            $version = $form->versions()->first();
            $section = $version->sections()->first();

            $forms->addField($section, [
                'label' => 'Permit holder', 'key' => 'holder', 'type' => 'text',
                'is_required' => true,
            ], $this->issuer);

            $table = $forms->addField($section, [
                'label' => 'Gas readings', 'key' => 'readings', 'type' => 'table',
                'config' => ['min_rows' => 2, 'max_rows' => 3],
            ], $this->issuer);

            $forms->addField($section, [
                'label' => 'Gas', 'key' => 'gas', 'type' => 'text',
                'parent_field_id' => $table->getKey(), 'is_required' => true,
            ], $this->issuer);

            $forms->addField($section, [
                'label' => 'Time', 'key' => 'time', 'type' => 'text',
                'parent_field_id' => $table->getKey(),
            ], $this->issuer);

            $forms->addField($section, [
                'label' => 'Authorisation', 'key' => 'authorisation', 'type' => 'signature',
                'is_required' => true,
                'config' => ['designation_hint' => 'Signed by the permit issuer.'],
            ], $this->issuer);

            $forms->addField($section, [
                'label' => 'Acknowledgement', 'key' => 'acknowledgement', 'type' => 'signature',
            ], $this->issuer);

            $forms->publish($version, $this->issuer);

            return $form->refresh();
        });
    }

    /** Start one through the controller, as a person would. */
    private function started(): FormSubmission
    {
        $form = $this->permitForm();

        $this->actingFresh($this->technician)
            ->post(route('form-submissions.store'), ['form_id' => $form->getKey()])
            ->assertRedirect();

        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->orderByDesc('id')->firstOrFail());
    }

    /**
     * Enough to satisfy every question that is genuinely being asked, signatures aside.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completeAnswers(array $overrides = []): array
    {
        return [
            'holder' => 'Ngozi Eze',
            'readings' => [
                ['gas' => '0%', 'time' => '08:00'],
                ['gas' => '0%', 'time' => '12:00'],
            ],
            ...$overrides,
        ];
    }

    /** @param  array<string,mixed>  $answers */
    private function save(FormSubmission $submission, array $answers): void
    {
        $this->actingFresh($this->technician)
            ->put(route('form-submissions.update', $submission), ['answers' => $answers])
            ->assertSessionHasNoErrors();
    }

    private function sign(FormSubmission $submission, string $key, User $actor): void
    {
        $this->actingFresh($actor)
            ->post($this->signRoute($submission, $key))
            ->assertSessionHasNoErrors();
    }

    private function withdraw(FormSubmission $submission, string $key, User $actor): void
    {
        $this->actingFresh($actor)
            ->delete($this->signRoute($submission, $key))
            ->assertSessionHasNoErrors();
    }

    private function signRoute(FormSubmission $submission, string $key): string
    {
        return route('form-submissions.sign', [$submission, $this->fieldId($submission, $key)]);
    }

    private function fieldId(FormSubmission $submission, string $key): int
    {
        return (int) $this->withinOrganization($this->org, fn () => FormField::query()
            ->where('form_version_id', $submission->form_version_id)
            ->where('key', $key)
            ->value('id'));
    }

    /**
     * Every signature row against a submission, in the order they were written.
     *
     * @return Collection<int,Signature>
     */
    private function signatures(FormSubmission $submission): Collection
    {
        return $this->withinOrganization($this->org, fn () => Signature::query()
            ->where('signable_type', $submission->getMorphClass())
            ->where('signable_id', $submission->getKey())
            ->orderBy('id')
            ->get());
    }

    /**
     * The signatures that STAND against one block — what the compiler reads.
     *
     * @return Collection<int,Signature>
     */
    private function standing(FormSubmission $submission, string $key): Collection
    {
        return $this->withinOrganization($this->org, function () use ($submission, $key) {
            $fresh = FormSubmission::query()->whereKey($submission->getKey())->firstOrFail();

            $field = FormField::query()
                ->where('form_version_id', $fresh->form_version_id)
                ->where('key', $key)
                ->firstOrFail();

            return app(FormCompiler::class)->signaturesOn($fresh, $field);
        });
    }

    /** What a signature written right now would be bound to. */
    private function hashOf(FormSubmission $submission): string
    {
        return $this->withinOrganization($this->org, function () use ($submission) {
            $fresh = FormSubmission::query()->whereKey($submission->getKey())->firstOrFail();

            return app(FormCompiler::class)->contentHash($fresh);
        });
    }

    private function refresh(FormSubmission $submission): FormSubmission
    {
        return $this->withinOrganization($this->org,
            fn () => FormSubmission::query()->whereKey($submission->getKey())->firstOrFail());
    }

    /**
     * Give somebody a job title.
     *
     * The user is handed back rather than mutated in place: `makePerson` returns a User with its
     * employee ALREADY LOADED, so the instance the test is holding would otherwise keep answering
     * with the position it had before this ran.
     */
    private function giveAPosition(User $user, string $title): User
    {
        return $this->withinOrganization($this->org, function () use ($user, $title) {
            $position = Position::query()->create([
                'organization_id' => $this->org->getKey(),
                'title' => $title,
            ]);

            $user->employee()->update(['position_id' => $position->getKey()]);

            return $user->fresh(['roles', 'employee']);
        });
    }

    /** Accept the sheet, through the endpoint a reviewer would use rather than behind its back. */
    private function accept(FormSubmission $submission): void
    {
        $accepted = $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('form_submission')
            ->where('key', 'accepted')
            ->firstOrFail());

        $this->actingFresh($this->issuer)
            ->post(route('form-submissions.status', $submission), [
                'status_id' => $accepted->getKey(),
            ])
            ->assertSessionHasNoErrors();
    }
}
