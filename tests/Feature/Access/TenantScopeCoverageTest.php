<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\OrganizationContext;
use App\Engines\Access\Scopes\OrganizationScope;
use App\Engines\Configuration\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Gate 1, enforced against the schema rather than against a list somebody maintains.
 *
 * Tenant isolation is only as good as its least careful model. One table with an organization_id
 * whose model forgot the trait is one table with no global scope — and it will not look wrong,
 * because every query against it returns data and every page renders. The leak is invisible until
 * a second tenant exists.
 *
 * So this test does not check the models it knows about. It reads every table in the database,
 * finds the ones carrying organization_id, and insists each has a model that opts into the scope.
 * A migration added in Phase 6 fails the build on the day it is written, which is the only time
 * fixing it is cheap.
 */
final class TenantScopeCoverageTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /**
     * Tables that carry organization_id and deliberately have no Eloquent model.
     *
     * Kept deliberately short and individually justified. Anything added here has left the
     * protection of the global scope, so the reason had better be that nothing queries it through
     * Eloquent at all.
     *
     * @var array<string,string>
     */
    private const MODELLESS = [
        'sessions' => 'Written and read by the session driver, never by application code.',
    ];

    /**
     * Tables whose model deliberately omits the trait because the global scope would be wrong.
     *
     * A far more dangerous list than MODELLESS, because these tables ARE queried through Eloquent.
     * Nothing belongs here that does not have a test elsewhere in this file proving the scoping is
     * done by hand, on every read and every write — the entry names that test.
     *
     * @var array<string,string>
     */
    private const HAND_SCOPED = [
        'settings' => 'A row with a null organization_id is the platform default that a tenant row '
            .'of the same key overrides; the global scope would hide it from everyone and every '
            .'default in the installation would silently disappear. SettingsRepository scopes both '
            .'directions by hand — proved by the_settings_table_is_scoped_by_hand_instead.',
    ];

    #[Test]
    public function every_tenant_scoped_table_has_a_model_that_applies_the_scope(): void
    {
        $models = $this->models();
        $unprotected = [];

        foreach ($this->tenantTables() as $table) {
            if (array_key_exists($table, self::MODELLESS) || array_key_exists($table, self::HAND_SCOPED)) {
                continue;
            }

            $model = $models[$table] ?? null;

            if ($model === null) {
                $unprotected[] = $table.' — no model maps to this table, so nothing applies the '
                    .'tenant scope to it. Add one, or justify it in TenantScopeCoverageTest::MODELLESS.';

                continue;
            }

            if (! in_array(BelongsToOrganization::class, class_uses_recursive($model), true)) {
                $unprotected[] = $table.' — '.class_basename($model).' carries organization_id but '
                    .'does not use BelongsToOrganization, so every query against it crosses tenants.';
            }
        }

        $this->assertSame([], $unprotected, "\n".implode("\n", $unprotected)."\n");
    }

    #[Test]
    public function the_scope_is_actually_registered_on_every_model_that_uses_the_trait(): void
    {
        // The trait is the declaration; the global scope is the mechanism. Booting each model and
        // asking the query builder what it applied is the difference between "the developer wrote
        // the right `use` line" and "the right SQL comes out".
        $checked = 0;

        foreach ($this->models() as $table => $class) {
            if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $sql = $class::query()->toSql();

            $this->assertStringContainsString(
                'organization_id',
                $sql,
                class_basename($class).' uses BelongsToOrganization but its default query carries no '
                .'organization_id predicate: '.$sql,
            );

            $this->assertArrayHasKey(
                OrganizationScope::class,
                (new $class)->getGlobalScopes(),
                class_basename($class).' is missing the OrganizationScope.',
            );

            $checked++;
        }

        $this->assertGreaterThan(5, $checked, 'the model enumeration found almost nothing — check models()');
    }

    #[Test]
    public function a_model_that_uses_the_trait_has_the_column_to_back_it(): void
    {
        // The reverse direction. A trait applied to a table with no organization_id compiles fine
        // and then fails at runtime, on whichever page first queries it — usually in production,
        // because nothing in development had two tenants.
        foreach ($this->models() as $table => $class) {
            if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $this->assertTrue(
                Schema::hasColumn($table, 'organization_id'),
                class_basename($class).' applies the tenant scope to `'.$table.'`, which has no organization_id column.',
            );
        }
    }

    /**
     * The audit trail is scoped like everything else.
     *
     * Stated separately because it is the table most likely to be treated as an exception — it is
     * infrastructure, it is written by a service rather than a controller, and it holds
     * before/after values for records across every module. An unscoped audit_logs would be the
     * single richest cross-tenant leak in the system (§59, §75).
     */
    #[Test]
    #[TestWith(['audit_logs'])]
    #[TestWith(['activity_logs'])]
    #[TestWith(['record_permissions'])]
    public function the_engine_tables_are_scoped_too(string $table): void
    {
        $this->assertTrue(Schema::hasColumn($table, 'organization_id'), $table.' has no organization_id');

        $model = $this->models()[$table] ?? null;

        $this->assertNotNull($model, $table.' has no model, so nothing scopes it');
        $this->assertContains(BelongsToOrganization::class, class_uses_recursive($model), $table.' is not tenant scoped');
    }

    /**
     * The exception list is a list of tables, not a licence.
     *
     * An entry that names a table which no longer exists, or which quietly acquired the trait after
     * all, is an entry nobody has read in a year — and the next person to read it will take it as
     * evidence that somebody thought about the table recently. Better it fails.
     */
    #[Test]
    public function the_exception_lists_still_describe_the_schema_they_were_written_against(): void
    {
        foreach (self::MODELLESS as $table => $reason) {
            $this->assertTrue(Schema::hasTable($table), 'MODELLESS names `'.$table.'`, which no longer exists.');
            $this->assertArrayNotHasKey($table, $this->models(), '`'.$table.'` has a model now — remove it from MODELLESS.');
        }

        foreach (self::HAND_SCOPED as $table => $reason) {
            $this->assertTrue(Schema::hasTable($table), 'HAND_SCOPED names `'.$table.'`, which no longer exists.');

            $model = $this->models()[$table] ?? null;

            $this->assertNotNull($model, '`'.$table.'` has no model at all — it belongs in MODELLESS, not HAND_SCOPED.');
            $this->assertNotContains(
                BelongsToOrganization::class,
                class_uses_recursive($model),
                class_basename($model).' applies the global scope now, so `'.$table.'` no longer needs an exception.',
            );
        }
    }

    /**
     * The one table that opts out, tested harder than the ones that do not.
     *
     * `settings` cannot carry the global scope: a row with a null organization_id is the platform
     * default every tenant inherits, and a scope that hid it would delete every default in the
     * installation. So the isolation has to be written by hand in SettingsRepository — and hand
     * written isolation is exactly the kind that is right on the day it ships and wrong two
     * refactors later. Four separate ways of getting it wrong, each asserted:
     *
     *   1. reading another tenant's value
     *   2. writing over another tenant's value
     *   3. a tenant write escaping upward into the platform default
     *   4. a write with no tenant bound at all becoming everybody's default
     */
    #[Test]
    public function the_settings_table_is_scoped_by_hand_instead(): void
    {
        $meridian = $this->makeOrganization('Meridian Engineering');
        $kestrel = $this->makeOrganization('Kestrel Industrial');

        $settings = app(SettingsRepository::class);

        // The installation-wide fallback, written where the intent is in the method name.
        $settings->setPlatformDefault('project.code.prefix', 'PRJ');

        $this->withinOrganization($meridian, fn () => $settings->set('project.code.prefix', 'MER'));
        $this->withinOrganization($kestrel, fn () => $settings->set('project.code.prefix', 'KES'));

        // 1. Each tenant reads its own value, and the platform row still stands underneath both.
        $this->assertSame('MER', $this->withinOrganization($meridian, fn () => $settings->get('project.code.prefix')));
        $this->assertSame('KES', $this->withinOrganization($kestrel, fn () => $settings->get('project.code.prefix')));

        // 2 & 3. Three rows, not one: neither tenant overwrote the other, and neither reached up
        // and rewrote the default. A repository that dropped the organization_id from its where
        // clause would leave exactly one row here and both tenants would read 'KES'.
        $this->assertSame(1, DB::table('settings')->whereNull('organization_id')->where('key', 'project.code.prefix')->count());
        $this->assertSame('"PRJ"', (string) DB::table('settings')->whereNull('organization_id')->where('key', 'project.code.prefix')->value('value'));
        $this->assertSame(2, DB::table('settings')->whereNotNull('organization_id')->where('key', 'project.code.prefix')->count());

        // A key only the platform sets still resolves for a tenant that has never heard of it —
        // the fallback is the whole reason this table opts out, so it is asserted rather than
        // assumed.
        $settings->setPlatformDefault('project.code.width', 5);
        $this->assertSame(5, $this->withinOrganization($meridian, fn () => $settings->int('project.code.width')));

        // 4. The dangerous one. Off the HTTP path — a queued job, a console command — nothing binds
        // a tenant, and a repository that treated "no tenant" as "the platform" would take a write
        // meant for one company and apply it to every company on the installation.
        app(OrganizationContext::class)->forget();

        $this->expectException(\RuntimeException::class);
        $settings->set('project.code.prefix', 'LEAK');
    }

    // ------------------------------------------------------------------ enumeration

    /**
     * Every table in the schema carrying an organization_id column.
     *
     * @return array<int,string>
     */
    private function tenantTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            // SQLite qualifies listings as `main.users` on some driver versions.
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (Schema::hasColumn($table, 'organization_id')) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Every concrete Eloquent model in the application, keyed by the table it maps to.
     *
     * @return array<string,class-string<Model>>
     */
    private function models(): array
    {
        static $models = null;

        if ($models !== null) {
            return $models;
        }

        $models = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }

            $models[(new $class)->getTable()] = $class;
        }

        return $models;
    }
}
