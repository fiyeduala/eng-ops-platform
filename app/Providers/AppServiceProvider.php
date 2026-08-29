<?php

declare(strict_types=1);

namespace App\Providers;

use App\Engines\Access\AccessResolver;
use App\Engines\Access\ActorProfile;
use App\Engines\Access\OrganizationContext;
use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\AuditContext;
use App\Engines\Audit\AuditLogger;
use App\Engines\Configuration\SettingsRepository;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Organization;
use App\Models\Position;
use App\Models\RecordPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped singletons. Every one of these memoizes per request; sharing the
        // instance is what keeps a page that authorizes forty records from re-walking the org
        // tree forty times.
        $this->app->singleton(OrganizationContext::class);
        $this->app->singleton(AuditContext::class);
        $this->app->singleton(ActorProfile::class);
        $this->app->singleton(AccessResolver::class);
        $this->app->singleton(ScopeCompiler::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(SettingsRepository::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureMorphMap();
        $this->configureBlade();
        $this->configureGates();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    private function configureModels(): void
    {
        // Lazy loading is not a performance nuisance here — it is a correctness risk. A relation
        // loaded implicitly inside a Blade loop bypasses the eager-loaded, scope-filtered query
        // the controller built, which is exactly how an authorization-filtered list starts
        // rendering rows it filtered out. Fail loudly outside production instead.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Stable morph keys.
     *
     * Polymorphic columns appear in audit_logs, activity_logs and record_permissions — all of
     * which are permanent. Storing class names there would mean a namespace refactor silently
     * orphans years of audit history and, worse, detaches record ACL rows from their records.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'organization' => Organization::class,
            'org_unit' => OrgUnit::class,
            'user' => User::class,
            'employee' => Employee::class,
            'position' => Position::class,
            'location' => Location::class,
            'role' => Role::class,
            'record_permission' => RecordPermission::class,
            'audit_log' => AuditLog::class,
            'activity_log' => ActivityLog::class,
        ]);
    }

    private function configureBlade(): void
    {
        // Gate 2 in a template. Deliberately named differently from @can so it is obvious at a
        // glance which checks are permission-only and which are record-aware — hiding a button
        // is a usability affordance, never the authorization itself (section 6).
        Blade::if('permission', function (string ...$permissions) {
            $user = auth()->user();

            return $user !== null && app(AccessResolver::class)->hasAny($user, ...$permissions);
        });

        Blade::if('allPermissions', function (string ...$permissions) {
            $user = auth()->user();

            return $user !== null && app(AccessResolver::class)->hasAll($user, ...$permissions);
        });
    }

    private function configureGates(): void
    {
        // Note on denial logging: it is NOT done with Gate::after, because @can in a template
        // fires the same gate and a single page renders dozens of them. Logging there would bury
        // real probing under thousands of "this button is hidden" entries. Only a thrown
        // AuthorizationException — an action actually attempted and refused — is recorded, in
        // bootstrap/app.php.

        Gate::define('access-admin', fn (User $user) => app(AccessResolver::class)
            ->hasAny($user, 'organization.configure', 'role.manage', 'user.manage')
            ? Response::allow()
            : Response::deny('Administration is restricted.'));
    }
}
