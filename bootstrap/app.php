<?php

use App\Engines\Audit\AuditContext;
use App\Engines\Audit\AuditLogger;
use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The tenant is established on EVERY web request, authenticated or not. On an
        // unauthenticated request it is explicitly cleared, so the global scope fails closed
        // rather than inheriting whatever the previous request left behind.
        $middleware->web(append: [
            EnsureOrganizationContext::class,
        ]);

        $middleware->alias([
            'permission' => RequirePermission::class,
        ]);
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
