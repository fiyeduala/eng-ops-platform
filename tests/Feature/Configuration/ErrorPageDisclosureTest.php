<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * What the system says when it refuses, and what it must never say while refusing (§75, §84, §94).
 *
 * UNTIL NOW THERE WERE NO ERROR VIEWS AT ALL. `resources/views/errors/` did not exist, so with
 * `APP_DEBUG=false` every refusal in production rendered Symfony's stock page — the framework's
 * own wording, its own branding, and nothing about this system — and with `APP_DEBUG` left true by
 * an operator who copied `.env.example`, a 500 rendered a full stack trace: file paths, the failing
 * query, environment values, a fragment of the row being written. The whole suite was green
 * throughout, because every other test asserts a STATUS CODE and a status code is identical either
 * way.
 *
 * SO THE ASSERTIONS HERE ARE ABOUT THE BODY. Two things, repeatedly:
 *
 *   1. The page is ours and it is useful. A person who hits a 403 should learn that access comes
 *      from a role somebody can grant, not read the word "Forbidden" and file a bug.
 *   2. The page discloses nothing. And the sharpest form of that is not "no stack trace" — it is
 *      that a record belonging to ANOTHER COMPANY and a record that never existed must produce the
 *      same bytes. Any difference between those two responses is a confirmation, and a prober
 *      walking sequential ids reads confirmations for a living (§94).
 *
 * The last test is the one that keeps the others honest: an error page that needs the asset build
 * or the database to render is an error page that is not there on the two occasions you need it
 * most — a deploy where `public/build` was not uploaded, and a database that is down.
 */
final class ErrorPageDisclosureTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private const SECRET_PROJECT = 'Bonny Island LNG Train 7 berth upgrade';

    private Organization $ours;

    private Organization $theirs;

    private User $ngozi;         // ours, may read projects

    private User $obinna;        // ours, may read nothing

    private Project $farAway;    // theirs

    protected function setUp(): void
    {
        parent::setUp();

        $this->ours = $this->makeOrganization('Harmattan Engineering');
        $ourUnit = $this->makeUnit($this->ours, 'Civil', OrgUnitType::Department);

        $reader = $this->makeRole($this->ours, 'Project Reader', [
            'project.view' => 'organization',
        ]);

        $nobody = $this->makeRole($this->ours, 'Visitor Desk', [
            'visitor.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->ours, 'Ngozi Eze', $ourUnit, [$reader]);
        $this->obinna = $this->makePerson($this->ours, 'Obinna Udo', $ourUnit, [$nobody]);

        // A second company, with a project whose name is distinctive enough that if one character
        // of it ever reaches the other tenant's browser, a string search will find it.
        $this->theirs = $this->makeOrganization('Lagos Harbour Works');
        $theirUnit = $this->makeUnit($this->theirs, 'Marine', OrgUnitType::Department);
        $theirLead = $this->makeRole($this->theirs, 'Project Reader', ['project.view' => 'organization']);
        $yusuf = $this->makePerson($this->theirs, 'Yusuf Danladi', $theirUnit, [$theirLead]);

        $this->farAway = $this->makeProject($this->theirs, $yusuf, self::SECRET_PROJECT);
    }

    #[Test]
    public function another_company_record_and_a_record_that_never_existed_are_the_same_page(): void
    {
        // An id that is real, and somebody else's. The tenant scope removes it before route
        // binding resolves it, so it arrives at the renderer as an ordinary miss (§75).
        $crossTenant = $this->actingFresh($this->ngozi)
            ->get(route('projects.show', $this->farAway->id));

        // An id that was never issued to anyone.
        $imaginary = $this->actingFresh($this->ngozi)
            ->get(route('projects.show', 99_999_999));

        $crossTenant->assertNotFound();
        $imaginary->assertNotFound();

        // THE ASSERTION THIS FILE EXISTS FOR. Not "both are 404" — every framework does that —
        // but that the two bodies are indistinguishable. A difference of one word between them
        // is an oracle: ask for an id, read the page, learn whether the record exists.
        $this->assertSame(
            $crossTenant->getContent(),
            $imaginary->getContent(),
            'A real id and an imaginary one produce different pages, which tells a prober which is which.',
        );

        $this->assertStringNotContainsString(self::SECRET_PROJECT, (string) $crossTenant->getContent());
        $this->assertStringNotContainsString('Lagos Harbour Works', (string) $crossTenant->getContent());

        // And it is our page, not the framework's.
        $crossTenant->assertSee('That page is not here');
        $this->assertNoFrameworkDisclosure($crossTenant->getContent());
    }

    #[Test]
    public function a_refusal_explains_where_access_comes_from_and_names_no_record(): void
    {
        $ours = $this->makeProject($this->ours, $this->ngozi, 'Trans-Amadi workshop extension');

        $response = $this->actingFresh($this->obinna)->get(route('projects.show', $ours));

        $response->assertForbidden();
        $response->assertSee('You do not have access to this');

        // The point of the wording. Somebody refused here needs to know it is a role, that a named
        // person can change it, and that nothing needs to be built — otherwise a permission
        // question becomes a support ticket about a broken page (§6, §10).
        $response->assertSee('ask whoever administers this system', false);

        // A 403 already confirms the record exists, which is unavoidable once a policy has run on
        // it. What must not follow is WHAT it is. The name is the whole value of the disclosure.
        $this->assertStringNotContainsString('Trans-Amadi', (string) $response->getContent());
        $this->assertNoFrameworkDisclosure($response->getContent());
    }

    #[Test]
    public function a_failure_with_debug_off_shows_nothing_about_the_failure(): void
    {
        // The production setting. An operator who copies `.env.example` gets `APP_DEBUG=true`, so
        // this is also the line docs/12 has to be emphatic about.
        config(['app.debug' => false]);

        Route::middleware('web')->get('/__explodes', function (): void {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: payroll_runs');
        });

        $response = $this->actingFresh($this->ngozi)->get('/__explodes');

        $response->assertStatus(500);
        $response->assertSee('Something went wrong at our end');

        $body = (string) $response->getContent();

        // The exception message is the disclosure. This one names a table and a driver, which is
        // exactly the shape of message a real failure produces and exactly what an attacker wants.
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('payroll_runs', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertNoFrameworkDisclosure($body);

        // And it is still useful: a timestamp the user can read out turns "it broke today" into a
        // second of the log. That is the whole reason the page is worth having over a blank one.
        $response->assertSee('Quote this if you report it', false);
    }

    #[Test]
    public function the_pages_people_meet_most_tell_them_what_to_do_next(): void
    {
        foreach ([419, 429, 503] as $status) {
            Route::middleware('web')->get('/__status/'.$status, fn () => abort($status));
        }

        // 419 is the one real users actually hit — a long form left open past the session
        // lifetime. "Page expired" and nothing else is how somebody loses an afternoon of typing.
        $this->get('/__status/419')
            ->assertStatus(419)
            ->assertSee('This page sat open too long')
            ->assertSee('Go back in your browser');

        // 429 must not say how many attempts remain or how long the window is — both are useful
        // only to whoever is guessing — while still pointing a locked-out user at the reset door.
        $expired = $this->get('/__status/429');
        $expired->assertStatus(429)->assertSee('Too many attempts');
        $this->assertStringNotContainsString('attempts remaining', (string) $expired->getContent());

        // 503 is `php artisan down` around `migrate --force`, so it has to read as planned rather
        // than as an outage, or the first person to see it raises a ticket about two minutes.
        $this->get('/__status/503')
            ->assertStatus(503)
            ->assertSee('Down for a few minutes')
            ->assertSee('nothing is lost while it runs');
    }

    #[Test]
    public function every_error_page_renders_with_no_database_and_no_asset_build(): void
    {
        // THE TWO MOMENTS THESE PAGES ARE FOR, and both of them break the normal way of rendering
        // one. `public/build` is produced on a machine that is not the server and uploaded
        // separately (docs/12), so a forgotten upload makes `@vite` throw — inside the page whose
        // job is to explain the failure. And a 500 is very often the database itself, so a page
        // that reads the organization's name to draw its own header recurses into a raw trace.
        //
        // The database is made UNREACHABLE rather than disconnected. `DB::disconnect()` proves the
        // same thing and then leaves `RefreshDatabase` holding a transaction on a connection that
        // no longer exists: every class after this one dies in `setUp` with "There is already an
        // active transaction", which is a green class inside a red directory and took a while to
        // read. Repointing the default connection at a path that cannot be opened makes any query
        // throw just as hard, while the open connection this test is running inside is untouched.
        $live = config('database.default');

        config([
            'database.connections.unreachable' => [
                'driver' => 'sqlite',
                'database' => '/nowhere/that/exists/engops.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'unreachable',
        ]);

        try {
            // Proof the trap is armed. Without this, a change that quietly restored the database
            // would leave six assertions passing and nothing being tested.
            try {
                DB::connection()->select('select 1');
                $this->fail('The database is still reachable, so this test proves nothing.');
            } catch (QueryException) {
                // As intended.
            }

            foreach (['403', '404', '419', '429', '500', '503'] as $page) {
                $html = view('errors.'.$page)->render();

                $this->assertStringNotContainsString(
                    '/build/',
                    $html,
                    'errors.'.$page.' references the asset build, which may not be on the server.',
                );

                $this->assertStringContainsString('<main class="panel">', $html);
                $this->assertNoFrameworkDisclosure($html);
            }
        } finally {
            // Before `RefreshDatabase` rolls back on the way out — it rolls back the DEFAULT
            // connection, so leaving this pointed at nowhere would replace one cascade with another.
            config(['database.default' => $live]);
        }
    }

    /**
     * Nothing about what we are built on.
     *
     * A framework name and version is a shopping list: it tells somebody which published
     * vulnerabilities to try before they try anything else. Symfony's default page carries both,
     * which is one of the two reasons these views exist at all.
     */
    private function assertNoFrameworkDisclosure(?string $body): void
    {
        $body = (string) $body;

        foreach (['Whoops', 'Symfony', 'Illuminate\\', 'vendor/laravel', 'stack trace', 'Stack trace'] as $tell) {
            $this->assertStringNotContainsString($tell, $body, 'The error page discloses "'.$tell.'".');
        }
    }
}
