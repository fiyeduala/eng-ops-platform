<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * RFIs — the register where the answer is the asset.
 *
 * An RFI is worth keeping for exactly one sentence: the answer. Everything this file tests follows
 * from that, and from the fact that the answer is frequently the document a claim is later built on.
 *
 *   · IT IS ANSWERED ONCE, AND THEN THE QUESTION HARDENS. `RfiPolicy::respond()` refuses a second
 *     answer and `update`/`delete` refuse outright once `answered_at` is set — at any scope, for
 *     anybody. A register in which the question can be reworded after the answer came back is a
 *     register that cannot be produced in an argument, which is the only situation it exists for.
 *
 *   · THREE ABILITIES, THREE PEOPLE. `rfi.submit` sends the query, `rfi.respond` states the
 *     organization's position, `rfi.close` accepts the answer and is also what it takes to correct
 *     one. The last of those is the point: the person who gave an answer cannot decide their own
 *     answer was satisfactory, and cannot quietly rewrite it either.
 *
 *   · A CORRECTION IS AN EVENT, NOT AN OVERWRITE. Amending demands a reason, which goes to the
 *     timeline, and it does NOT re-fire the answered notification — a second "your query was
 *     answered" for the same query is how people learn to ignore the first (§59).
 *
 *   · THE NOTIFICATION CARRIES THE FLAGS AND NOT THE TEXT (§68). "Answered, and it costs money" is
 *     the half that decides whether somebody reads it today. The sentence itself stays on the
 *     record, where the access engine can still reach it, rather than in an email preview line.
 *
 * Three actors, deliberately not overlapping: Ada logs and sends queries, Bola answers them, Femi
 * accepts or corrects the answers. Every refusal below is a refusal one of them meets while doing
 * something entirely reasonable from their own point of view.
 */
final class RfiAnswerTest extends TestCase
{
    use BuildsEngineering, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private Project $project;

    private User $ada;      // document controller: raises and sends, never answers

    private User $bola;     // discipline engineer: answers, and that is all

    private User $femi;     // engineering manager: accepts an answer, or corrects one

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        // Everything needed to run the register, and nothing that speaks for the company.
        $controller = $this->makeRole($this->org, 'Document Controller', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'rfi.view' => 'organization',
            'rfi.create' => 'organization',
            'rfi.edit' => 'organization',
            'rfi.delete' => 'organization',
            'rfi.submit' => 'organization',
        ]);

        // Note what is absent: no `rfi.edit`. Answering a query and correcting the register are
        // different jobs, and the engineer who does the first has no business doing the second.
        $engineer = $this->makeRole($this->org, 'Discipline Engineer', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'rfi.view' => 'organization',
            'rfi.respond' => 'organization',
        ]);

        // The heaviest ability in the module, and the narrowest role holding it.
        $manager = $this->makeRole($this->org, 'Engineering Manager', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'rfi.view' => 'organization',
            'rfi.close' => 'organization',
        ]);

        $this->ada = $this->makePerson($this->org, 'Ada Okonkwo', $this->civil, [$controller]);
        $this->bola = $this->makePerson($this->org, 'Bola Adeyemi', $this->civil, [$engineer]);
        $this->femi = $this->makePerson($this->org, 'Femi Lawal', $this->civil, [$manager]);

        $this->project = $this->makeProject($this->org, $this->ada, 'Terminal Expansion');
    }

    // ================================================================== sending

    #[Test]
    public function sending_stamps_the_date_and_the_person_who_asked(): void
    {
        $rfi = $this->query();

        $this->assertNull($rfi->raised_on);
        $this->assertNull($rfi->raised_by);

        $this->actingFresh($this->ada)
            ->post(route('rfis.send', $rfi))
            ->assertSessionHasNoErrors();

        $rfi->refresh();

        // Both columns are filled in on the way out rather than at creation: a draft sitting in the
        // register for three weeks was not "raised" on the day somebody started typing it, and the
        // date on it is the one the response clock is later measured against.
        $this->assertNotNull($rfi->raised_on);
        $this->assertSame((int) $this->ada->id, (int) $rfi->raised_by);
        $this->assertSame('open', $rfi->status?->key);
    }

    // ================================================================== answering

    #[Test]
    public function a_query_is_answered_once(): void
    {
        $rfi = $this->sent();

        $this->actingFresh($this->bola)
            ->post(route('rfis.respond', $rfi), [
                'answer' => 'Use grade 60 reinforcement throughout the pilecap.',
            ])->assertSessionHasNoErrors();

        $rfi->refresh();

        $this->assertTrue($rfi->isAnswered());
        $this->assertSame((int) $this->bola->id, (int) $rfi->answered_by);
        $this->assertSame('answered', $rfi->status?->key);

        // Second attempt. Bola still holds `rfi.respond` at organization scope; the refusal is that
        // the record has hardened, which the policy checks before it consults a single grant.
        $this->actingFresh($this->bola)
            ->post(route('rfis.respond', $rfi), [
                'answer' => 'On reflection, grade 40 is sufficient.',
            ])->assertForbidden();

        $this->assertSame(
            'Use grade 60 reinforcement throughout the pilecap.',
            $rfi->refresh()->answer,
        );
    }

    #[Test]
    public function answering_takes_respond_and_not_edit(): void
    {
        $rfi = $this->sent();

        // Ada may create, edit, delete and send this record. She may not state what the company's
        // technical position is, and holding every other ability in the module does not add up to it.
        $this->actingFresh($this->ada)
            ->post(route('rfis.respond', $rfi), [
                'answer' => 'Proceed as drawn.',
            ])->assertForbidden();

        $this->assertFalse($rfi->refresh()->isAnswered());
    }

    #[Test]
    public function an_answered_query_can_no_longer_be_edited_or_deleted(): void
    {
        $rfi = $this->answered();

        // A complete, valid body. A form request authorizes at the request level and validates
        // before the controller's policy call runs, so a half-filled payload would come back
        // 302-with-errors and this test would pass without ever reaching the rule it exists for.
        $this->actingFresh($this->ada)
            ->put(route('rfis.update', $rfi), $this->body(['subject' => 'Rewritten after the fact']))
            ->assertForbidden();

        $this->actingFresh($this->ada)
            ->delete(route('rfis.destroy', $rfi))
            ->assertForbidden();

        $rfi->refresh();

        $this->assertSame('Pilecap reinforcement grade', $rfi->subject);
        $this->assertNotNull(Rfi::query()->find($rfi->id));
    }

    #[Test]
    public function an_answer_cannot_be_smuggled_in_through_an_ordinary_edit(): void
    {
        $rfi = $this->sent();

        // The legitimate half of this request is a subject correction, which Ada may make. The rest
        // is an attempt to become the person who answered the query by naming herself in a form
        // field — the exact shape §84 says never to trust.
        $this->actingFresh($this->ada)
            ->put(route('rfis.update', $rfi), $this->body([
                'subject' => 'Pilecap reinforcement grade (rev A)',
                'answer' => 'Proceed as drawn, at no cost.',
                'answered_by' => $this->ada->id,
                'answered_at' => now()->toDateTimeString(),
            ]))->assertSessionHasNoErrors();

        $rfi->refresh();

        $this->assertSame('Pilecap reinforcement grade (rev A)', $rfi->subject);
        $this->assertNull($rfi->answer);
        $this->assertNull($rfi->answered_by);
        $this->assertFalse($rfi->isAnswered());
    }

    // ================================================================== amending

    #[Test]
    public function correcting_an_answer_takes_close_rather_than_respond(): void
    {
        $rfi = $this->answered();

        // Bola gave this answer. Rewriting it is a heavier act than giving it — somebody has
        // already built work on the first version — so it sits behind the ability the person who
        // has to live with the consequences holds, not the one the author holds.
        $this->actingFresh($this->bola)
            ->post(route('rfis.amend', $rfi), [
                'answer' => 'Grade 40 after all.',
                'reason' => 'I changed my mind.',
            ])->assertForbidden();

        $this->actingFresh($this->femi)
            ->post(route('rfis.amend', $rfi), [
                'answer' => 'Use grade 50 reinforcement throughout the pilecap.',
                'reason' => 'The original response quoted the wrong specification clause.',
            ])->assertSessionHasNoErrors();

        $rfi->refresh();

        $this->assertSame('Use grade 50 reinforcement throughout the pilecap.', $rfi->answer);
        $this->assertSame((int) $this->femi->id, (int) $rfi->answered_by);
    }

    #[Test]
    public function an_amendment_must_say_why(): void
    {
        $rfi = $this->answered();

        $this->actingFresh($this->femi)
            ->post(route('rfis.amend', $rfi), [
                'answer' => 'Use grade 50 reinforcement throughout the pilecap.',
            ])->assertSessionHasErrors('reason');

        // Rewriting a position the organization already stated without recording why is the entry
        // that becomes an argument nobody can settle. Nothing moved.
        $this->assertSame(
            'Use grade 60 reinforcement throughout the pilecap.',
            $rfi->refresh()->answer,
        );
    }

    #[Test]
    public function there_is_nothing_to_amend_before_an_answer_exists(): void
    {
        $rfi = $this->sent();

        // Femi holds `rfi.close` at organization scope, so the refusal is entirely about state:
        // an "amendment" to a query nobody has answered would be an answer given by the back door,
        // by somebody who deliberately does not hold `rfi.respond`.
        $this->actingFresh($this->femi)
            ->post(route('rfis.amend', $rfi), [
                'answer' => 'Proceed as drawn.',
                'reason' => 'Filling in the gap.',
            ])->assertForbidden();

        $this->assertFalse($rfi->refresh()->isAnswered());
    }

    // ================================================================== closing

    #[Test]
    public function an_unanswered_query_cannot_be_closed(): void
    {
        $rfi = $this->sent();

        // Closing records that a question was resolved. If it is going away unanswered it is being
        // withdrawn, which is a status transition carrying a reason — not a close.
        $this->actingFresh($this->femi)
            ->post(route('rfis.close', $rfi))
            ->assertForbidden();

        $this->assertSame('open', $rfi->refresh()->status?->key);
    }

    #[Test]
    public function the_asker_closes_the_query_and_the_person_who_answered_it_cannot(): void
    {
        $rfi = $this->answered();

        // An answer is not acceptance. `rfi.close` is deliberately not implied by `rfi.respond`,
        // so the responder cannot mark their own answer satisfactory.
        $this->actingFresh($this->bola)
            ->post(route('rfis.close', $rfi))
            ->assertForbidden();

        $this->actingFresh($this->femi)
            ->post(route('rfis.close', $rfi), ['note' => 'Accepted; issued to site.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('closed', $rfi->refresh()->status?->key);
    }

    // ================================================================== notification

    #[Test]
    public function the_person_who_asked_is_told_when_the_answer_arrives(): void
    {
        $rfi = $this->answered();

        $notification = $this->answeredNotifications($this->ada)->latest('id')->first();

        $this->assertNotNull($notification, 'The person who raised the query was never told it had been answered.');
        $this->assertStringContainsString($rfi->number, (string) $notification->title);

        // Bola answered it, and answering something is not news to the person who did it.
        $this->assertSame(0, $this->answeredNotifications($this->bola)->count());

        // Femi can see the whole register and had nothing to do with this query. Most RFIs are
        // answered in a day and matter to two people; a register that told the entire discipline
        // about every one of them would be muted inside a week.
        $this->assertSame(0, $this->answeredNotifications($this->femi)->count());
    }

    #[Test]
    public function the_notification_carries_the_impact_flags_but_never_the_answer_text(): void
    {
        $rfi = $this->sent();

        $secret = 'That was our error; proceed and we will bear the additional cost.';

        $this->actingFresh($this->bola)
            ->post(route('rfis.respond', $rfi), [
                'answer' => $secret,
                'cost_impact' => 1,
                'schedule_impact' => 1,
            ])->assertSessionHasNoErrors();

        $notification = $this->answeredNotifications($this->ada)->latest('id')->firstOrFail();

        // The flags travel, because "answered, and it costs money" is what decides whether this is
        // read today or on Friday. A flag is not the amount, and the amount belongs on the change
        // request the answer provokes.
        $this->assertStringContainsString('cost', strtolower((string) $notification->body));
        $this->assertStringContainsString('programme', strtolower((string) $notification->body));

        // The sentence itself does not. A body is copied into an email, a push payload and a
        // preview line, and the access engine cannot take any of those back (§68).
        $this->assertStringNotContainsString($secret, (string) $notification->title.' '.$notification->body);
        $this->assertStringNotContainsString('bear the additional cost', (string) $notification->body);
    }

    #[Test]
    public function correcting_an_answer_does_not_announce_it_a_second_time(): void
    {
        $rfi = $this->answered();

        $this->assertSame(1, $this->answeredNotifications($this->ada)->count());

        $this->actingFresh($this->femi)
            ->post(route('rfis.amend', $rfi), [
                'answer' => 'Use grade 50 reinforcement throughout the pilecap.',
                'reason' => 'The original response quoted the wrong specification clause.',
            ])->assertSessionHasNoErrors();

        // A correction is a change to a conversation the interested parties are already reading.
        // A second "your query was answered" for the same query is how people learn to ignore the
        // first — the amendment goes to the timeline instead, where anyone re-reading it will see it.
        $this->assertSame(1, $this->answeredNotifications($this->ada)->count());
    }

    // ================================================================== fixtures

    /** A draft in the register: raised by nobody yet, and not sent. */
    private function query(): Rfi
    {
        return $this->makeRfi($this->org, $this->ada, 'Pilecap reinforcement grade', [
            'project_id' => $this->project->id,
            'kind' => 'rfi',
            'direction' => 'outgoing',
            'question' => 'Confirm the reinforcement grade for the pilecaps on grid line C.',
        ]);
    }

    /** Sent, so it is open and the clock is running. */
    private function sent(): Rfi
    {
        $rfi = $this->query();

        $this->actingFresh($this->ada)
            ->post(route('rfis.send', $rfi))
            ->assertSessionHasNoErrors();

        return $rfi->refresh();
    }

    /** Sent and answered by Bola, with no impact flagged. */
    private function answered(): Rfi
    {
        $rfi = $this->sent();

        $this->actingFresh($this->bola)
            ->post(route('rfis.respond', $rfi), [
                'answer' => 'Use grade 60 reinforcement throughout the pilecap.',
            ])->assertSessionHasNoErrors();

        return $rfi->refresh();
    }

    /**
     * A complete, valid update payload — see the frozen-record test for why it has to be complete.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'subject' => 'Pilecap reinforcement grade',
            'question' => 'Confirm the reinforcement grade for the pilecaps on grid line C.',
            'kind' => 'rfi',
            'direction' => 'outgoing',
            'project_id' => $this->project->id,
            'confidentiality' => 'internal',
            ...$overrides,
        ];
    }

    /**
     * The `rfi.answered` rows sitting in one person's inbox.
     *
     * Global scopes are dropped deliberately: the assertion is about what was written, and running
     * it through the tenant scope would make a test that passes because the query found nothing
     * indistinguishable from one that passes because nothing was sent.
     *
     * @return Builder<Notification>
     */
    private function answeredNotifications(User $person): Builder
    {
        return Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $person->id)
            ->where('key', 'rfi.answered');
    }
}
