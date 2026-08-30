<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Meetings: one record, four separate authorities (§19).
 *
 * Every other module in the system has one permission for "change this". A meeting has four, because
 * in a real organization four different people do these four things:
 *
 *   ARRANGING     `meeting.edit`            books the room, moves the time, invites people
 *   RECORDING     `meeting.record_minutes`  writes down what happened; often a junior person
 *   CIRCULATING   `meeting.circulate`       releases the record everyone will later quote back
 *   CANCELLING    `meeting.edit`            calls it off, with a reason attached
 *
 * The fixture below gives each of them to a different person and to nobody twice, so any collapse of
 * one into another shows up as a test that starts passing for the wrong actor. That matters most for
 * the recording/circulating pair: if the typist can release her own minutes, the chair's sign-off has
 * silently stopped existing.
 *
 * The second thing under test is the one FIELD-level rule in the application. Uncirculated minutes
 * are hidden from people who can see the meeting perfectly well — hiding them in SQL would take the
 * whole appointment away from everybody entitled to know it happened, so Meeting::minutesVisibleTo()
 * is consulted at render time and MeetingPolicy::viewMinutes() composes it with the record gate.
 *
 *   Ada      arranges. No `record_minutes`, no `circulate`.
 *   Sade     records. No `edit`, no `circulate` — she cannot release her own minutes.
 *   Emeka    circulates. No `record_minutes` — he signs off, he does not type.
 *   Ifeoma   attends, and that is all: `meeting.view: assigned`.
 *   Bode     sits in Commercial and reaches none of it.
 */
final class MeetingAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private const MINUTES = 'The contractor confirmed the eastern abutment will be re-set on Thursday.';

    private Organization $org;

    private OrgUnit $civil;

    private User $ada;       // arranges

    private User $sade;      // records

    private User $emeka;     // circulates

    private User $ifeoma;    // attends

    private User $bode;      // another department

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $commercial = $this->makeUnit($this->org, 'Commercial', OrgUnitType::Department);

        $organiserRole = $this->makeRole($this->org, 'Project Administrator', [
            'meeting.view' => ['own', 'assigned'],
            'meeting.create' => 'own',
            'meeting.edit' => 'own',
            'meeting.delete' => 'own',
            'user.view' => 'organization',
            'task.view' => 'organization',
            'task.create' => 'organization',
        ]);

        $secretaryRole = $this->makeRole($this->org, 'Secretary', [
            'meeting.view' => 'assigned',
            'meeting.record_minutes' => 'assigned',
            'user.view' => 'organization',
        ]);

        $chairRole = $this->makeRole($this->org, 'Chair', [
            'meeting.view' => 'organization',
            'meeting.circulate' => 'organization',
        ]);

        $attendeeRole = $this->makeRole($this->org, 'Engineer', [
            'meeting.view' => 'assigned',
        ]);

        $outsiderRole = $this->makeRole($this->org, 'Commercial Lead', [
            'meeting.view' => 'department',
        ]);

        $this->ada = $this->makePerson($this->org, 'Ada Okonkwo', $this->civil, [$organiserRole]);
        $this->sade = $this->makePerson($this->org, 'Sade Bello', $this->civil, [$secretaryRole]);
        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->civil, [$chairRole]);
        $this->ifeoma = $this->makePerson($this->org, 'Ifeoma Nnaji', $this->civil, [$attendeeRole]);
        $this->bode = $this->makePerson($this->org, 'Bode Martins', $commercial, [$outsiderRole]);
    }

    // ------------------------------------------------------------------ the four authorities

    #[Test]
    public function arranging_and_recording_are_not_the_same_authority(): void
    {
        $meeting = $this->meeting();

        // Ada books the room and cannot write the record.
        $this->actingFresh($this->ada)->get(route('meetings.edit', $meeting))->assertOk();
        $this->actingFresh($this->ada)->get(route('meetings.minutes', $meeting))->assertForbidden();

        // Sade writes the record and cannot move the meeting.
        $this->actingFresh($this->sade)->get(route('meetings.minutes', $meeting))->assertOk();
        $this->actingFresh($this->sade)->get(route('meetings.edit', $meeting))->assertForbidden();

        // Emeka signs the record off and does not type it.
        $this->actingFresh($this->emeka)->get(route('meetings.minutes', $meeting))->assertForbidden();
    }

    #[Test]
    public function the_person_who_writes_the_minutes_cannot_release_them(): void
    {
        $meeting = $this->minuted();

        $this->actingFresh($this->sade)
            ->post(route('meetings.status', $meeting), ['status_id' => $this->statusFor('minuted')->id])
            ->assertForbidden();

        $this->assertFalse($this->reload($meeting)->minutesCirculated());
    }

    #[Test]
    public function the_chair_releases_them(): void
    {
        $meeting = $this->minuted();

        $this->actingFresh($this->emeka)
            ->post(route('meetings.status', $meeting), ['status_id' => $this->statusFor('minuted')->id])
            ->assertRedirect(route('meetings.show', $meeting));

        $this->assertTrue($this->reload($meeting)->minutesCirculated());
    }

    /**
     * Circulating an empty record would notify every attendee about a document that does not exist,
     * and that notification would be indistinguishable from the real one when it later arrived.
     */
    #[Test]
    public function there_must_be_minutes_before_they_can_be_circulated(): void
    {
        $meeting = $this->held();

        $this->actingFresh($this->emeka)
            ->post(route('meetings.status', $meeting), ['status_id' => $this->statusFor('minuted')->id])
            ->assertForbidden();
    }

    #[Test]
    public function taking_the_register_belongs_to_recording_rather_than_to_arranging(): void
    {
        $meeting = $this->meeting();
        $row = $this->participant($meeting, $this->ifeoma);

        $this->actingFresh($this->ada)
            ->post(route('meetings.attendance', $meeting), ['attendance' => [$row->id => '1']])
            ->assertForbidden();

        $this->actingFresh($this->sade)
            ->post(route('meetings.attendance', $meeting), ['attendance' => [$row->id => '1']])
            ->assertRedirect();

        $this->assertTrue($this->participant($meeting, $this->ifeoma)->attended);
    }

    /**
     * An action arising is an ordinary task, not a private line in the minutes — it lands on
     * somebody's list and in their notifications. So it needs both permissions, and Sade holds only
     * one of them: minuting a meeting is not authority to put work on people.
     */
    #[Test]
    public function raising_an_action_needs_the_authority_to_open_work_as_well(): void
    {
        $meeting = $this->meeting();

        $this->actingFresh($this->sade)
            ->post(route('meetings.actions.store', $meeting), [
                'title' => 'Reissue drawing S-104',
                'assignee_id' => $this->ifeoma->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Task::query()->count()));
    }

    // ------------------------------------------------------------------ the field-level rule

    #[Test]
    public function uncirculated_minutes_are_withheld_from_the_room(): void
    {
        $meeting = $this->minuted();

        // Ifeoma was in the room. She can see that the meeting happened and cannot read a draft
        // record of what was said in it.
        $this->actingFresh($this->ifeoma)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee($meeting->title)
            ->assertDontSee(self::MINUTES);

        // The person holding the pen can read her own draft.
        $this->actingFresh($this->sade)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee(self::MINUTES);

        // So can the person who has to decide whether to release it — he cannot sign off a document
        // he is not allowed to read.
        $this->actingFresh($this->emeka)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee(self::MINUTES);
    }

    #[Test]
    public function circulating_is_what_hands_the_room_the_record(): void
    {
        $meeting = $this->circulated();

        $this->actingFresh($this->ifeoma)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee(self::MINUTES);
    }

    /**
     * A decision is minutes content, so it follows the same curtain. Recording one before the
     * minutes are released must not become a side channel that publishes the outcome early.
     */
    #[Test]
    public function a_decision_recorded_early_is_withheld_with_the_minutes(): void
    {
        $meeting = $this->minuted();

        $this->actingFresh($this->sade)
            ->post(route('meetings.decisions.store', $meeting), [
                'decision' => 'Agreed to re-set the abutment rather than shim it',
                'rationale' => 'Shimming would not survive the next flood season.',
            ])
            ->assertRedirect();

        $this->actingFresh($this->ifeoma)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertDontSee('rather than shim it');

        $circulated = $this->circulate($meeting);

        $this->actingFresh($this->ifeoma)
            ->get(route('meetings.show', $circulated))
            ->assertOk()
            ->assertSee('rather than shim it');
    }

    #[Test]
    public function once_circulated_the_record_is_frozen(): void
    {
        $meeting = $this->circulated();

        // The meeting itself can no longer be moved: a record saying a minuted meeting is scheduled
        // for next month is worse than no record.
        $this->actingFresh($this->ada)->get(route('meetings.edit', $meeting))->assertForbidden();
        $this->actingFresh($this->ada)
            ->put(route('meetings.update', $meeting), $this->payload($meeting, [
                'starts_at' => now()->addMonth()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addMonth()->setTime(11, 30)->format('Y-m-d H:i:s'),
            ]))
            ->assertForbidden();

        // And the minutes cannot be quietly rewritten under the people who were sent them. A
        // correction is a fresh set of minutes, which is how minutes have always worked.
        $this->actingFresh($this->sade)->get(route('meetings.minutes', $meeting))->assertForbidden();
        $this->actingFresh($this->sade)
            ->put(route('meetings.minutes.store', $meeting), ['minutes' => 'Something else entirely.'])
            ->assertForbidden();

        $this->assertSame(self::MINUTES, $this->reload($meeting)->minutes);
    }

    #[Test]
    public function a_cancelled_meeting_produced_nothing_to_minute(): void
    {
        $meeting = $this->meeting();

        $this->actingFresh($this->ada)
            ->post(route('meetings.status', $meeting), [
                'status_id' => $this->statusFor('cancelled')->id,
                'comment' => 'The contractor could not attend and there was nothing else on the agenda.',
            ])
            ->assertRedirect();

        $cancelled = $this->reload($meeting);
        $this->assertTrue($cancelled->isCancelled());

        $this->actingFresh($this->sade)->get(route('meetings.minutes', $cancelled))->assertForbidden();
    }

    // ------------------------------------------------------------------ the invitation as reach

    #[Test]
    public function an_invitation_is_a_participants_only_reach(): void
    {
        $mine = $this->meeting();

        $other = $this->makeMeeting($this->org, $this->ada, 'Commercial review', [
            'org_unit_id' => $this->civil->id,
        ], [$this->sade]);

        $this->actingFresh($this->ifeoma)->get(route('meetings.show', $mine))->assertOk();
        $this->actingFresh($this->ifeoma)->get(route('meetings.show', $other))->assertForbidden();
    }

    #[Test]
    public function another_departments_meeting_stays_shut(): void
    {
        $this->actingFresh($this->bode)->get(route('meetings.show', $this->meeting()))->assertForbidden();
    }

    #[Test]
    public function apologies_are_your_own_invitation_and_nobody_elses(): void
    {
        $meeting = $this->meeting();

        $mine = $this->participant($meeting, $this->ifeoma);
        $sades = $this->participant($meeting, $this->sade);

        $this->actingFresh($this->ifeoma)
            ->post(route('meetings.participants.respond', [$meeting, $mine]), [
                'apologies' => 'On site at the abutment that morning.',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('On site', (string) $this->participant($meeting, $this->ifeoma)->apologies);

        // Sending apologies on somebody else's behalf would put words in their mouth on the record
        // of a meeting. The policy asks about the ROW, not the meeting (§84).
        $this->actingFresh($this->ifeoma)
            ->post(route('meetings.participants.respond', [$meeting, $sades]), [
                'apologies' => 'Cannot make it.',
            ])
            ->assertForbidden();

        $this->assertNull($this->participant($meeting, $this->sade)->apologies);
    }

    #[Test]
    public function a_participant_row_cannot_be_reached_through_another_meetings_url(): void
    {
        $mine = $this->meeting();

        $other = $this->makeMeeting($this->org, $this->ada, 'Commercial review', [
            'org_unit_id' => $this->civil->id,
        ], [$this->ifeoma]);

        $theirRow = $this->participant($other, $this->ifeoma);

        // Scoped bindings resolve `{participant}` through the meeting in the URL, so this is a 404
        // at the router rather than a write against another meeting's register (§94).
        $this->actingFresh($this->ada)
            ->delete(route('meetings.participants.destroy', [$mine->id, $theirRow->id]))
            ->assertNotFound();

        $this->assertNotNull($this->withinOrganization($this->org, fn () => MeetingParticipant::find($theirRow->id)));
    }

    // ------------------------------------------------------------------ deletion

    #[Test]
    public function a_meeting_that_produced_a_record_is_not_deleted(): void
    {
        $booked_by_mistake = $this->makeMeeting($this->org, $this->ada, 'Duplicate booking', [
            'org_unit_id' => $this->civil->id,
        ]);

        $this->actingFresh($this->ada)
            ->delete(route('meetings.destroy', $booked_by_mistake))
            ->assertRedirect();

        $this->assertNull($this->withinOrganization($this->org, fn () => Meeting::find($booked_by_mistake->id)));

        $minuted = $this->minuted();

        $this->actingFresh($this->ada)->delete(route('meetings.destroy', $minuted))->assertForbidden();
        $this->assertNotNull($this->withinOrganization($this->org, fn () => Meeting::find($minuted->id)));
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function another_organization_gets_a_404_rather_than_a_refusal(): void
    {
        $other = $this->makeOrganization('Kestrel Infrastructure');
        $unit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Project Administrator', [
            'meeting.view' => 'organization',
            'meeting.create' => 'organization',
            'meeting.edit' => 'organization',
            'meeting.record_minutes' => 'organization',
            'meeting.circulate' => 'organization',
            'user.view' => 'organization',
        ]);

        $stranger = $this->makePerson($other, 'Ruth Iwu', $unit, [$role]);

        $theirs = $this->makeMeeting($other, $stranger, 'Their weekly review', [
            'org_unit_id' => $unit->id,
        ], [$stranger]);

        $this->actingFresh($this->emeka)->get(route('meetings.show', $theirs->id))->assertNotFound();
        $this->actingFresh($this->emeka)->get(route('meetings.minutes', $theirs->id))->assertNotFound();
        $this->actingFresh($stranger)->get(route('meetings.show', $this->meeting()->id))->assertNotFound();
    }

    // ------------------------------------------------------------------ fixture helpers

    /** A scheduled meeting with the whole cast in the room. */
    private function meeting(): Meeting
    {
        return $this->makeMeeting($this->org, $this->ada, 'Weekly progress review', [
            'org_unit_id' => $this->civil->id,
        ], [$this->sade, $this->emeka, $this->ifeoma]);
    }

    /** The same meeting, now held. Minutes cannot be written for one that has not happened. */
    private function held(): Meeting
    {
        return $this->moveTo($this->org, 'meeting', $this->meeting(), 'held', $this->ada);
    }

    /** Held, with minutes written and NOT yet released. */
    private function minuted(): Meeting
    {
        $meeting = $this->held();

        $this->actingFresh($this->sade)
            ->put(route('meetings.minutes.store', $meeting), ['minutes' => self::MINUTES])
            ->assertRedirect();

        return $this->reload($meeting);
    }

    /** Held, minuted and circulated by the chair. */
    private function circulated(): Meeting
    {
        return $this->circulate($this->minuted());
    }

    private function circulate(Meeting $meeting): Meeting
    {
        $this->actingFresh($this->emeka)
            ->post(route('meetings.status', $meeting), ['status_id' => $this->statusFor('minuted')->id])
            ->assertRedirect();

        return $this->reload($meeting);
    }

    /**
     * The whole form, with the given fields changed — see the note on AnnouncementAccessTest.
     *
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function payload(Meeting $meeting, array $changes = []): array
    {
        return [
            'title' => $meeting->title,
            'agenda' => $meeting->agenda,
            'confidentiality' => Confidentiality::Internal->value,
            'starts_at' => $meeting->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $meeting->ends_at?->format('Y-m-d H:i:s'),
            'location' => $meeting->location,
            'org_unit_id' => $meeting->org_unit_id,
            'project_id' => $meeting->project_id,
            ...$changes,
        ];
    }

    private function participant(Meeting $meeting, User $user): MeetingParticipant
    {
        return $this->withinOrganization($this->org, fn () => MeetingParticipant::query()
            ->where('meeting_id', $meeting->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail());
    }

    private function reload(Meeting $meeting): Meeting
    {
        return $this->withinOrganization($this->org, fn () => Meeting::findOrFail($meeting->id));
    }

    private function statusFor(string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('meeting')
            ->where('key', $key)
            ->firstOrFail());
    }
}
