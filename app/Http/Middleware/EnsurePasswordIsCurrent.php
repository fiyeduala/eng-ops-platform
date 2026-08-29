<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Engines\Configuration\SettingsRepository;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password change before anything else in the application is reachable.
 *
 * Two triggers: an administrator issued the account a temporary password
 * (`must_change_password`), or the company has set `security.password_expiry_days` and the
 * current one is older than that.
 *
 * Placed in the authenticated middleware group rather than checked in a controller, because a
 * "you must change your password" that only guards the pages someone remembered to guard is a
 * suggestion. The change-password and sign-out routes are excepted, or the user would be locked
 * in a redirect loop with no way out.
 */
class EnsurePasswordIsCurrent
{
    /** Routes a user with a stale password may still reach. */
    private const ALLOWED = ['password.edit', 'password.update', 'logout'];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        if ($this->mustChange($user)) {
            if ($request->expectsJson()) {
                abort(403, 'Your password must be changed before you can continue.');
            }

            return redirect()->route('password.edit');
        }

        return $next($request);
    }

    private function mustChange(User $user): bool
    {
        if ($user->must_change_password) {
            return true;
        }

        $days = $this->settings->int('security.password_expiry_days', 0, (int) $user->organization_id);

        // 0 means never. Forced rotation on a schedule is off by default on purpose — it reliably
        // produces Summer2026! followed by Autumn2026! — but a company with an external
        // compliance obligation can switch it on without a deployment.
        if ($days <= 0) {
            return false;
        }

        // A never-set timestamp counts as expired: it means the password predates the policy, and
        // failing closed is the right side to err on.
        return $user->password_changed_at === null
            || $user->password_changed_at->addDays($days)->isPast();
    }
}
