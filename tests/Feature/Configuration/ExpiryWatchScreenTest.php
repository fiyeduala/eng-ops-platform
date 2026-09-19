<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\ExpiryWatch;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The renewals editor (Phase 7, Part 5E).
 *
 * ExpiryWatchTest proves the engine warns the right people on the right days. This file is about
 * the screen in front of it, and everything here follows from one fact: a watch is a standing
 * instruction to E-MAIL PEOPLE ABOUT RECORDS. That makes the form a way to address an audience, and
 * a form that addresses an audience is a security surface, not a settings page.
 *
 * So the four assertions that matter are:
 *
 *   THE AUDIENCE IS THIS COMPANY'S (§74, §75). Role and user ids come from checkboxes, which is to
 *   say from the browser. A watch naming another company's role would be a permanent instruction to
 *   mail strangers about your certificates, renewed every morning by the scheduler.
 *
 *   A WATCH WITH NO AUDIENCE IS REFUSED. It would run, find everything, and tell nobody — passing
 *   every engine test while showing the company a screen that says it is being looked after.
 *
 *   THE DATE COLUMN IS WHITELISTED (§84). It ends up in a `where` clause every morning.
 *
 *   READING IS NOT WRITING (§6), AND ANOTHER COMPANY'S WATCH DOES NOT EXIST (§94).
 */
final class ExpiryWatchScreenTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private User $admin;

    private User $reader;

    private Role $controllers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department);

        $this->controllers = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'expiry_watch.view' => 'organization',
            'expiry_watch.manage' => 'organization',
        ]);

        $this->admin = $this->makePerson($this->org, 'Adaeze Okonkwo', $this->civil, [$this->controllers]);

        $this->reader = $this->makePerson($this->org, 'Bem Tarkighir', $this->civil, [
            $this->makeRole($this->org, 'Onlooker', ['expiry_watch.view' => 'organization']),
        ]);
    }

    // ================================================================== reading

    #[Test]
    public function the_page_shows_what_is_being_watched_and_who_will_hear_about_it(): void
    {
        $this->watch(['name' => 'Insurance certificate due to expire']);

        $this->actingFresh($this->admin)->get(route('configuration.expiry-watches.index'))
            ->assertOk()
            ->assertSee('Insurance certificate due to expire')
            // The milestones in words rather than as a JSON array, because "30, 7, 1" on a screen
            // is a setting and "[30,7,1]" is a developer's note somebody else has to decode.
            ->assertSee('30 days before')
            // §68 stated on the screen, not only enforced in the dispatcher. An administrator who
            // does not know that recipients are filtered will read the silence as a broken feature.
            ->assertSee('cannot open the record is left off', false);
    }

    #[Test]
    public function somebody_with_no_business_here_cannot_open_the_page(): void
    {
        $stranger = $this->makePerson($this->org, 'Chidi Okafor', $this->civil, [
            $this->makeRole($this->org, 'Storeman', ['document.view' => 'own']),
        ]);

        $this->actingFresh($stranger)->get(route('configuration.expiry-watches.index'))
            ->assertForbidden();
    }

    #[Test]
    public function reading_the_watches_is_not_permission_to_change_them(): void
    {
        $watch = $this->watch();

        $this->actingFresh($this->reader)->get(route('configuration.expiry-watches.index'))->assertOk();

        // Gate 1. The page hides the buttons from this person as a courtesy; the route is what
        // stops them, which is why the test posts rather than looking for a missing button.
        $this->actingFresh($this->reader)
            ->post(route('configuration.expiry-watches.store'), $this->payload())
            ->assertForbidden();

        $this->actingFresh($this->reader)
            ->delete(route('configuration.expiry-watches.destroy', $watch))
            ->assertForbidden();
    }

    // ================================================================== writing

    #[Test]
    public function a_watch_set_up_on_the_form_warns_exactly_what_was_typed(): void
    {
        $this->actingFresh($this->admin)
            ->post(route('configuration.expiry-watches.store'), $this->payload([
                'name' => 'Document due for review',
                'lead_days' => '60, 14, 0',
                'warn_when_overdue' => '1',
            ]))
            ->assertRedirect(route('configuration.expiry-watches.index'));

        $watch = $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->sole());

        // Typed as text, stored as numbers, largest first — and 0 survives, because "on the day"
        // is a real answer and a validator that treated it as empty would silently drop it.
        $this->assertSame([60, 14, 0], $watch->leadDays());
        $this->assertTrue($watch->warn_when_overdue);
        $this->assertSame($this->org->getKey(), $watch->organization_id);
    }

    #[Test]
    public function a_watch_may_not_name_another_company_s_people(): void
    {
        $rival = $this->makeOrganization('Continental Fabricators');
        $rivalUnit = $this->makeUnit($rival, 'Works', OrgUnitType::Department);
        $rivalRole = $this->makeRole($rival, 'Director', ['document.view' => 'organization']);
        $rivalBoss = $this->makePerson($rival, 'Ada Nwosu', $rivalUnit, [$rivalRole]);

        $this->actingFresh($this->admin)
            ->post(route('configuration.expiry-watches.store'), $this->payload([
                'notify_role_ids' => [$rivalRole->getKey(), $this->controllers->getKey()],
                'notify_user_ids' => [$rivalBoss->getKey(), $this->admin->getKey()],
            ]))
            ->assertRedirect(route('configuration.expiry-watches.index'));

        $watch = $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->sole());

        // The stranger is gone and this company's own choices survive. Dropped rather than refused:
        // those ids cannot have come from the form this screen renders, so there is no honest
        // message to show about them — and refusing would tell the sender which ids exist.
        $this->assertSame([$this->controllers->getKey()], $watch->notifyRoleIds());
        $this->assertSame([$this->admin->getKey()], $watch->notifyUserIds());
    }

    #[Test]
    public function a_watch_that_would_tell_nobody_is_refused(): void
    {
        $this->actingFresh($this->admin)
            ->post(route('configuration.expiry-watches.store'), $this->payload([
                'notify_owner' => null,
                'notify_followers' => null,
                'notify_role_ids' => [],
                'notify_user_ids' => [],
            ]))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->count()));
    }

    #[Test]
    public function a_date_the_platform_does_not_offer_cannot_be_watched(): void
    {
        foreach ([
            ['entity_type' => 'document', 'date_field' => 'deleted_at'],
            ['entity_type' => 'document', 'date_field' => 'password'],
            ['entity_type' => 'user', 'date_field' => 'created_at'],
        ] as $attempt) {
            $this->actingFresh($this->admin)
                ->post(route('configuration.expiry-watches.store'), $this->payload($attempt))
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->count()));
    }

    #[Test]
    public function one_date_is_watched_once(): void
    {
        $this->watch();

        $this->actingFresh($this->admin)
            ->post(route('configuration.expiry-watches.store'), $this->payload())
            ->assertSessionHasErrors('name');

        // Two watches on one column are not twice the safety. They are two e-mails about one
        // certificate, and the second is what teaches people to filter the first into a folder.
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->count()));
    }

    #[Test]
    public function switching_a_watch_off_silences_it_without_forgetting_it(): void
    {
        $watch = $this->watch();

        $this->actingFresh($this->admin)
            ->put(route('configuration.expiry-watches.toggle', $watch))
            ->assertRedirect();

        $watch = $this->withinOrganization($this->org, fn () => ExpiryWatch::query()->sole());

        $this->assertFalse($watch->is_active);
        // The settings are still there, which is the whole reason to prefer this to deleting: a
        // watch switched off says "we decided to stop chasing this", a deleted one says nothing.
        $this->assertSame([30, 7, 1], $watch->leadDays());
    }

    #[Test]
    public function another_company_s_watch_does_not_exist(): void
    {
        $rival = $this->makeOrganization('Continental Fabricators');
        $rivalUnit = $this->makeUnit($rival, 'Works', OrgUnitType::Department);
        $rivalBoss = $this->makePerson($rival, 'Ada Nwosu', $rivalUnit, [
            $this->makeRole($rival, 'Director', [
                'expiry_watch.view' => 'organization',
                'expiry_watch.manage' => 'organization',
            ]),
        ]);

        $theirs = $this->withinOrganization($rival, fn () => ExpiryWatch::create([
            'organization_id' => $rival->getKey(),
            'entity_type' => 'document',
            'date_field' => 'next_review_on',
            'name' => 'Their review dates',
            'lead_days' => [30],
            'notify_owner' => true,
            'is_active' => true,
        ]));

        // Not 403 but 404 (§94). A forbidden response would confirm the row exists, and "how many
        // watches does Continental have" is answerable by counting the ids that give 403.
        $this->actingFresh($this->admin)
            ->put(route('configuration.expiry-watches.toggle', $theirs))
            ->assertNotFound();

        $this->actingFresh($this->admin)
            ->delete(route('configuration.expiry-watches.destroy', $theirs))
            ->assertNotFound();

        // And it is not on the page either.
        $this->actingFresh($this->admin)->get(route('configuration.expiry-watches.index'))
            ->assertOk()
            ->assertDontSee('Their review dates');

        $this->assertTrue($rivalBoss->exists);
    }

    // ================================================================== helpers

    /** @param  array<string,mixed>  $attributes */
    private function payload(array $attributes = []): array
    {
        return array_filter([
            'entity_type' => 'document',
            'date_field' => 'next_review_on',
            'name' => 'Document due for review',
            'lead_days' => '30, 7, 1',
            'notify_owner' => '1',
            'notify_role_ids' => [],
            'notify_user_ids' => [],
            ...$attributes,
        ], fn ($value) => $value !== null);
    }

    /** @param  array<string,mixed>  $attributes */
    private function watch(array $attributes = []): ExpiryWatch
    {
        return $this->withinOrganization($this->org, fn () => ExpiryWatch::create([
            'organization_id' => $this->org->getKey(),
            'entity_type' => 'document',
            'date_field' => 'next_review_on',
            'name' => 'Document due for review',
            'lead_days' => [30, 7, 1],
            'warn_when_overdue' => false,
            'notify_role_ids' => [$this->controllers->getKey()],
            'notify_user_ids' => [],
            'notify_owner' => true,
            'notify_followers' => false,
            'is_active' => true,
            ...$attributes,
        ]));
    }
}
