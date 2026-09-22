<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The line between application structure and sample data, and the flag that once crossed it.
 *
 * WHY THIS FILE EXISTS. `DatabaseSeeder`'s guard used to read `--force`, and that was exactly
 * backwards. `--force` is Laravel's "yes, I know this is production, skip the confirmation" flag:
 * it is the flag an operator MUST pass to seed anything at all on a live install, and docs/12 tells
 * them to pass it. Reading it as consent to sample data meant the documented production command
 * would have loaded Meridian Engineering, Kestrel Facilities Management and ninety fictional
 * employees into a real company's database — the guard inverting under precisely the conditions it
 * existed for. It was corrected at the close of Phase 8 and nothing pinned it, which is how a fix
 * like that gets undone by somebody tidying a conditional.
 *
 * SO THESE TESTS RUN THE DOCUMENTED COMMAND. Not `$this->seed()`, which would prove something about
 * a test helper — `db:seed --force`, character for character what an operator types on a cPanel
 * shell, with the environment set to what it will be set to when they type it.
 *
 * The demo seeder is replaced by a spy rather than allowed to run. Whether it produces good data is
 * DemoSeederTest's question and takes ten thousand lines to answer; the question here is only
 * whether it was reached, and a spy answers that in milliseconds and answers it unambiguously.
 */
final class SeedGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Everything the last run printed. */
    private string $output = '';

    #[Test]
    public function the_documented_production_command_loads_the_catalogue_and_no_sample_data(): void
    {
        $demo = $this->spyOnTheDemoSeeder();

        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->assertSame(0, $this->seedFromTheCommandLine());

        $this->assertFalse($demo->ran, 'The demo tenants were seeded into a production database.');
        $this->assertSame(0, DB::table('organizations')->count());

        // The catalogue is NOT sample data and must be there. Skipping it in production would fail
        // in the opposite direction and just as quietly: `AccessResolver` fails closed, so every
        // account signs in successfully and reaches nothing.
        $this->assertGreaterThan(0, DB::table('permissions')->count());
    }

    #[Test]
    public function force_is_permission_to_seed_production_and_not_permission_to_seed_demo_data(): void
    {
        // THE INVERSION, PINNED. `--force` is present here — it has to be, or the command will not
        // run unattended against a production environment at all — and it must still not be read as
        // wanting Meridian and Kestrel. If this test ever fails, the guard has been rewritten to
        // consult the wrong flag again.
        $demo = $this->spyOnTheDemoSeeder();

        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->seedFromTheCommandLine();

        $this->assertFalse($demo->ran);
        $this->assertStringContainsString('demo data skipped', $this->output);

        // And the refusal names the deliberate way round it. A guard that simply declines teaches
        // the next developer to delete the guard.
        $this->assertStringContainsString('--class=DemoSeeder', $this->output);
    }

    #[Test]
    public function anywhere_that_is_not_production_gets_the_sample_tenants(): void
    {
        // The other half, and the reason the guard cannot simply be "never". A developer who runs
        // `migrate:fresh --seed` locally and gets an empty database has no system to look at, and
        // the demo is how §73 is demonstrated rather than asserted.
        $demo = $this->spyOnTheDemoSeeder();

        $this->assertSame('testing', $this->app->environment());

        $this->assertSame(0, $this->seedFromTheCommandLine());

        $this->assertTrue($demo->ran, 'Outside production the sample tenants should be seeded.');
    }

    /**
     * Stand in for the real seeder so the branch can be observed without paying for it.
     *
     * `Seeder::call()` resolves the class out of the container, so an instance binding is enough —
     * and it has to be an instance rather than a class binding, because the thing being asserted on
     * afterwards is state on the object the seeder actually reached.
     */
    private function spyOnTheDemoSeeder(): Seeder
    {
        $spy = new class extends Seeder
        {
            public bool $ran = false;

            public function run(): void
            {
                $this->ran = true;
            }
        };

        $this->app->instance(DemoSeeder::class, $spy);

        return $spy;
    }

    /**
     * `db:seed --force`, as docs/12 step 5 gives it.
     */
    private function seedFromTheCommandLine(): int
    {
        $code = Artisan::call('db:seed', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->output = Artisan::output();

        return $code;
    }
}
