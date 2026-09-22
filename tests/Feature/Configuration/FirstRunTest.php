<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Console\Commands\InstallOrganization;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Standing the platform up on an empty database, the way a deployment actually does it (§73, §92).
 *
 * WHAT THIS FILE IS FOR. Every other test in the suite starts from a tenant somebody built in
 * `setUp()`. That is the right way to test the application and it is structurally incapable of
 * noticing that nothing in the application can build the FIRST one. `OrganizationProvisioner`
 * creates a complete company and no user; the only account with `is_super_admin` anywhere in the
 * repository was hand-built inside `NewCompanyWalkthroughTest` so that the walkthrough would have
 * an administrator to walk. A real install following docs/12 ended at a login screen and stopped.
 *
 * So this file makes no fixture. It runs the console command against a bare migrated database and
 * asks the question a deployment asks: can somebody now log in and configure this company?
 *
 * THE SEEDER IS NOT RUN CLASS-WIDE, deliberately. `PermissionSeeder` is seeded inside the tests
 * that need it, because the order of those two steps is itself under test — an administrator made
 * before the catalogue exists is a working login attached to an empty permission set, and the
 * first test here is that the command refuses rather than producing one.
 *
 * @see InstallOrganization for why each guard exists
 */
final class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_before_the_permission_catalogue_has_been_seeded(): void
    {
        // Emptied rather than assumed empty. `RefreshDatabase` seeds once, outside the
        // per-test transaction, for the whole process — so whether this table has rows in it
        // depends on which class PHPUnit reached first, and a test whose precondition is set
        // by the alphabet is a test that passes alone and reports nothing in a suite.
        //
        // What is left is `migrate --force` and nothing else: a state every deployment passes
        // through, and the one an operator is most likely to run the next command from.
        DB::table('permissions')->delete();

        $this->assertSame(self::FAILURE, $this->install([
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
        ]));

        // And it left nothing behind. A half-installed company is worse than no company: the
        // second attempt would then trip the "already holds a company" guard and refuse as well,
        // which is an operator locked out by two guards disagreeing about what state they are in.
        $this->assertSame(0, Organization::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, User::query()->withoutGlobalScopes()->withTrashed()->count());
    }

    #[Test]
    public function it_installs_a_company_whose_administrator_can_sign_in_and_configure_it(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertSame(self::SUCCESS, $this->install([
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
            '--timezone' => 'Africa/Lagos',
            '--currency' => 'NGN',
        ]));

        $password = $this->printedPassword();

        $organization = Organization::query()->withoutGlobalScopes()->sole();

        $this->assertSame('Harmattan Engineering', $organization->name);
        $this->assertSame('harmattan-engineering', $organization->slug);
        $this->assertSame('Africa/Lagos', $organization->timezone);
        $this->assertSame('NGN', $organization->currency);

        // The tenant is PROVISIONED, not merely created. These two are the cheapest proof that the
        // command went through the provisioner rather than inserting a row: roles and statuses are
        // seeded configuration, and a company without them has no vocabulary to configure with.
        $this->assertGreaterThan(0, Role::query()->withoutGlobalScopes()->count(), 'No starter roles.');
        $this->assertGreaterThan(0, Status::query()->withoutGlobalScopes()->count(), 'No status vocabulary.');

        $user = User::query()->withoutGlobalScopes()->sole();

        $this->assertSame('adaeze@harmattan.example', $user->email);
        $this->assertTrue($user->is_super_admin, 'The account cannot administer the company it owns.');
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password, 'A console-printed password outlived its single use.');
        $this->assertNotNull($user->email_verified_at, 'An install that cannot send mail locked its own administrator out.');

        // And the account works. Signing in with the printed password is the claim; being held on
        // the change-password screen first is §92 — the string the operator can read in their
        // scroll-back must stop being a credential the moment it has been used.
        $this->post(route('login.store'), [
            'email' => 'adaeze@harmattan.example',
            'password' => $password,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $this->get(route('configuration.index'))->assertRedirect(route('password.edit'));

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => $password,
                'password' => 'quay-wall-settlement-2026',
                'password_confirmation' => 'quay-wall-settlement-2026',
            ])
            ->assertSessionHasNoErrors();

        // Now the §73 claim: this administrator can reach the screens the whole walkthrough is
        // conducted from. `NewCompanyWalkthroughTest` proves what can be built from here; this
        // proves there is a supported way to arrive here at all.
        $this->actingAs($user->fresh())
            ->get(route('configuration.index'))
            ->assertOk();
    }

    #[Test]
    public function it_will_not_quietly_add_a_second_company_to_a_live_database(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->installCompany('Harmattan Engineering', 'adaeze@harmattan.example');

        // The dangerous reading of a repeated install is "repair the one that is there". It does
        // not do that and cannot, so it stops and says what it found.
        $this->assertSame(self::FAILURE, $this->install([
            '--name' => 'Lagos Harbour Works',
            '--admin-name' => 'Tunde Bello',
            '--admin-email' => 'tunde@harbour.example',
        ]));

        // Naming what it found is half the guard. "Refused" on its own sends an operator looking
        // for a broken installer; the list tells them the database is not the one they thought.
        $this->assertStringContainsString('Harmattan Engineering', $this->output);

        $this->assertSame(1, Organization::query()->withoutGlobalScopes()->count());

        // A second tenant is a supported thing to want. `--force` is how an operator says they
        // meant it, and then it goes through — the guard is against ambiguity, not against
        // multi-tenancy.
        $this->installCompany('Lagos Harbour Works', 'tunde@harbour.example', ['--force' => true]);

        $this->assertSame(2, Organization::query()->withoutGlobalScopes()->count());

        // Each company got its own provisioned configuration rather than sharing the first's (§75).
        $slugs = Organization::query()->withoutGlobalScopes()->pluck('slug')->all();
        $this->assertSame(['harmattan-engineering', 'lagos-harbour-works'], $slugs);
    }

    #[Test]
    public function there_is_no_default_password_to_guess(): void
    {
        $this->seed(PermissionSeeder::class);

        $first = $this->installCompany('Harmattan Engineering', 'adaeze@harmattan.example');
        $second = $this->installCompany('Lagos Harbour Works', 'tunde@harbour.example', ['--force' => true]);

        $this->assertNotSame($first, $second, 'Two installs produced the same password.');
        $this->assertGreaterThanOrEqual(20, strlen($first));

        // Each password opens exactly one of the two accounts. The point is not that they differ as
        // strings — it is that neither is a value anybody could have known before running the
        // command, which is the whole of §92 for an installer.
        $adaeze = User::query()->withoutGlobalScopes()->where('email', 'adaeze@harmattan.example')->sole();
        $tunde = User::query()->withoutGlobalScopes()->where('email', 'tunde@harbour.example')->sole();

        $this->assertTrue(Hash::check($first, $adaeze->password));
        $this->assertFalse(Hash::check($second, $adaeze->password));
        $this->assertTrue(Hash::check($second, $tunde->password));
    }

    #[Test]
    public function a_password_the_operator_chooses_still_has_to_pass_the_policy(): void
    {
        $this->seed(PermissionSeeder::class);

        // Eight characters clears Laravel's own floor and not the platform's, which is set from
        // `security.password_min_length` — the same rule the change-password screen applies. An
        // installer with a weaker floor than the screen is a way to put a password into production
        // that the application would have refused from a user.
        $this->assertSame(self::FAILURE, $this->install([
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
            '--password' => 'trench12',
        ]));

        $this->assertSame(0, Organization::query()->withoutGlobalScopes()->count());

        $this->assertSame(self::SUCCESS, $this->install([
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
            '--password' => 'trench-box-detail-2026',
        ]));

        // And it was not echoed back. A password the operator already has is one they chose;
        // reprinting it only widens where it ends up — a build log, a shared terminal, a ticket.
        $this->assertStringNotContainsString('trench-box-detail-2026', $this->output);

        $user = User::query()->withoutGlobalScopes()->sole();

        $this->assertTrue(Hash::check('trench-box-detail-2026', $user->password));
        $this->assertTrue($user->must_change_password, 'A chosen password is still a handover, not a credential.');
    }

    #[Test]
    public function a_rerun_after_a_half_finished_install_is_refused_by_name(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->installCompany('Harmattan Engineering', 'adaeze@harmattan.example');

        // The operator's most likely second command. Something went wrong — a mail setting, a
        // typo, a connection dropped mid-run — and they reach for the same line with `--force` on
        // it, reading force as "do it properly this time".
        $this->assertSame(self::FAILURE, $this->install([
            '--force' => true,
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
        ]));

        // It is not, and the slug is what catches it. Two companies cannot share one, and a second
        // "Harmattan Engineering" beside the first is a worse outcome than a refusal: both would
        // be real, only one would hold the configuration, and nothing on a sign-in screen tells
        // you which you are looking at.
        $this->assertSame(1, Organization::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, User::query()->withoutGlobalScopes()->count());

        // And the message has to be about the slug, because "--force didn't work" sends an
        // operator to the wrong problem. A different name — or an explicit --slug — is the fix.
        $this->assertStringContainsString('--slug', $this->output);

        $this->assertSame(self::SUCCESS, $this->install([
            '--force' => true,
            '--name' => 'Harmattan Engineering',
            '--slug' => 'harmattan-engineering-lagos',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
        ]));
    }

    #[Test]
    public function it_will_not_generate_a_password_it_cannot_show_anybody(): void
    {
        $this->seed(PermissionSeeder::class);

        // `--quiet` is the natural thing to reach for in a deploy script, and every other command
        // in this directory honours it. Here it would silence the single line on which the whole
        // install depends: the password exists in the terminal and in a bcrypt hash, nowhere else.
        // A "successful" quiet run therefore produces a company whose only account nobody can sign
        // in to, and no way back in short of a second company or a hand-written UPDATE.
        $this->assertSame(self::FAILURE, $this->install([
            '--quiet' => true,
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
        ]));

        $this->assertSame(0, Organization::query()->withoutGlobalScopes()->count());

        // And the refusal has to be audible on a run that asked for silence, or it is the same
        // failure with an extra step: an operator watching an empty terminal and a non-zero exit
        // code, with nothing saying which of the guards stopped it.
        $this->assertStringContainsString('Refusing to run quietly', $this->output);
        $this->assertStringContainsString('--password', $this->output);

        // Because that is the combination that makes sense: nothing needs printing when the
        // operator already knows the password.
        $this->assertSame(self::SUCCESS, $this->install([
            '--quiet' => true,
            '--name' => 'Harmattan Engineering',
            '--admin-name' => 'Adaeze Okonkwo',
            '--admin-email' => 'adaeze@harmattan.example',
            '--password' => 'trench-box-detail-2026',
        ]));

        $this->assertTrue(Hash::check(
            'trench-box-detail-2026',
            User::query()->withoutGlobalScopes()->sole()->password,
        ));
    }
    // ------------------------------------------------------------------ the walk's furniture

    private const SUCCESS = 0;

    private const FAILURE = 1;

    /** Everything the last run printed. */
    private string $output = '';

    /**
     * One run of the installer, non-interactively, with its output kept.
     *
     * `Artisan::call` rather than `$this->artisan()`, for one reason: the console is the only
     * place a generated password is ever written, and the pending-command helper mocks the output
     * away. A test that cannot read the output cannot check the claim this file exists to check.
     *
     * `--no-interaction` is how a deploy script runs it, and is the mode the command's "anything
     * required must be an option" rule is written for — under a prompt, a missing option is a
     * hung build rather than an error.
     *
     * @param  array<string,mixed>  $options
     */
    private function install(array $options): int
    {
        $code = Artisan::call('engops:install', array_merge(['--no-interaction' => true], $options));

        $this->output = Artisan::output();

        return $code;
    }

    /**
     * A successful install, returning the password it printed.
     *
     * @param  array<string,mixed>  $extra
     */
    private function installCompany(string $company, string $email, array $extra = []): string
    {
        $this->assertSame(self::SUCCESS, $this->install(array_merge([
            '--name' => $company,
            '--admin-name' => 'Installer',
            '--admin-email' => $email,
        ], $extra)), 'The install failed: '.$this->output);

        return $this->printedPassword();
    }

    /**
     * The generated password, read back off the terminal.
     *
     * Scraping stdout is the point rather than a shortcut. The password exists in exactly two
     * places — this line of output and a hash nobody can reverse — so recovering it any other way
     * would prove something other than what an operator can do with what the command told them.
     */
    private function printedPassword(): string
    {
        if (! preg_match('/^\s*Password\s+(\S.*?)\s*$/m', $this->output, $match)) {
            $this->fail('The command printed no password:'.PHP_EOL.$this->output);
        }

        return $match[1];
    }
}
