<?php

use App\Engines\Audit\AuditContext;
use App\Engines\Audit\AuditLogger;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listener discovery OFF. Laravel otherwise scans app/Listeners and wires each handle()
    // signature to its event automatically — which means a file added, renamed or moved silently
    // changes who gets told what, and a class registered both ways gets registered twice and sends
    // everything twice. Notifications are the wrong place for that kind of magic. The map lives in
    // AppServiceProvider::configureEventListeners(), where an absence is visible.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // The tenant is established on EVERY web request, authenticated or not. On an
        // unauthenticated request it is explicitly cleared, so the global scope fails closed
        // rather than inheriting whatever the previous request left behind.
        //
        // SubstituteBindings is pulled out of its default position and re-appended LAST, because
        // route model binding runs Eloquent — and Eloquent carries the tenant global scope. Bound
        // before the context exists, every `/org-units/{org_unit}` lookup would compile to
        // `organization_id = 0` and 404, so the application would appear to contain no records at
        // all. Binding must happen after the tenant is known, never before.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                // AuthenticateSession binds the session to the password hash, so re-hashing the
                // password (Auth::logoutOtherDevices, or an administrator forcing a reset)
                // invalidates every OTHER session immediately. Without it, "change your password"
                // would leave a stolen session working, which is the one thing a password change
                // must not do.
                AuthenticateSession::class,
                EnsureOrganizationContext::class,
                SubstituteBindings::class,
            ],
        );

        // The group order above is only half the story: Laravel re-sorts middleware that appears
        // in the priority list, and SubstituteBindings is in it while EnsureOrganizationContext is
        // not — so the sorter would happily hoist binding back above the context. Naming the
        // context in the priority list, immediately before binding, makes the order an invariant
        // rather than a coincidence of array position.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureOrganizationContext::class,
        );

        $middleware->alias([
            'permission' => RequirePermission::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.current' => EnsurePasswordIsCurrent::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every refused action is a security event. Repeated denials against sequential ids are
        // precisely the signature of someone walking /projects/1, /projects/2, /projects/3 — the
        // IDOR probe section 94 requires us to detect, not merely to survive.
        $exceptions->report(function (AuthorizationException $e): void {
            if (Auth::check()) {
                app(AuditLogger::class)->logDenied($e->getMessage() ?: 'unauthorized');
            }
        });

        // A 404 rather than a 403 for cross-tenant ids would be better still, but a uniform
        // message matters more: differing responses tell a prober which ids exist.
        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This action is not authorized.'], 403);
            }

            return null;
        });
    })
    ->booted(function (Application $app): void {
        // Scheduler- and console-driven changes must still be attributable in the audit trail.
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($app): void {
            $app->make(AuditContext::class)->fromConsole($event->command ?? 'artisan');
        });
    })
    ->create();
