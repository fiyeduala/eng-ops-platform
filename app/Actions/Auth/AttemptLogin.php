<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Engines\Access\Scopes\OrganizationScope;
use App\Engines\Audit\AuditLogger;
use App\Engines\Configuration\SettingsRepository;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sign a user in (§76).
 *
 * Not Auth::attempt(), and the reason is Gate 1. Auth::attempt() hands the credentials to the
 * user provider, which does `where('email', ?)->first()` — but email is unique PER ORGANIZATION
 * here, so `first()` would silently pick whichever tenant sorted lowest and refuse a perfectly
 * valid password belonging to the other one. Worse, which tenant "wins" would depend on row
 * order, which is not a property anyone should be authenticating against.
 *
 * So the candidate set is resolved explicitly and every candidate is checked. The account that
 * matches determines the tenant; the tenant is never asked for, guessed, or read from the request.
 *
 * Three things this deliberately does NOT do:
 *
 *   - It does not say whether an address is registered. Every failure produces the same message
 *     and the same timing, because "no such user" and "wrong password" told apart is an account
 *     enumeration oracle — and on a per-tenant address space it would also reveal which companies
 *     employ whom.
 *   - It does not reveal that an account is locked to someone who did not supply the right
 *     password. Lockout state is only disclosed once the password is proven.
 *   - It does not log the attempted password, ever, in any branch.
 */
class AttemptLogin
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @throws ValidationException on any failure
     */
    public function __invoke(Request $request, string $email, string $password, bool $remember = false): User
    {
        $candidates = User::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->where('email', $email)
            ->get();

        $user = $this->matchPassword($candidates, $password);

        if ($user === null) {
            $this->penalise($candidates, $request);

            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match our records.'),
            ]);
        }

        // The password is proven from here down, so it is safe — and necessary — to explain
        // precisely why a correct password is still not getting them in.
        $this->assertAccountUsable($user);

        $this->clearFailures($user, $request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $this->audit->log(
            action: 'logged_in',
            subject: $user,
            description: 'Signed in from '.($request->ip() ?? 'unknown address'),
            actor: $user,
        );

        return $user;
    }

    /**
     * Check every candidate, in constant time with respect to how many there are.
     *
     * The loop never breaks early on a mismatch and a dummy hash runs when the candidate set is
     * empty, so the response time of "unknown address" and "known address, wrong password" does
     * not separate them.
     *
     * @param  Collection<int,User>  $candidates
     */
    private function matchPassword($candidates, string $password): ?User
    {
        $matched = null;

        foreach ($candidates as $candidate) {
            if (Hash::check($password, $candidate->password) && $matched === null) {
                $matched = $candidate;
            }
        }

        if ($candidates->isEmpty()) {
            // Same work as a real check, so an unknown address is not measurably faster.
            Hash::check($password, '$2y$12$'.str_repeat('0', 53));
        }

        return $matched;
    }

    /**
     * A correct password still does not admit a disabled account, a locked one, or one whose
     * whole organization has been suspended.
     */
    private function assertAccountUsable(User $user): void
    {
        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => __('This account is temporarily locked after too many failed sign-in attempts. Try again in :minutes minutes.', [
                    'minutes' => max(1, (int) now()->diffInMinutes($user->locked_until, absolute: true)),
                ]),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => __('This account has been disabled. Please contact your administrator.'),
            ]);
        }

        if ($user->organization === null || ! $user->organization->is_active) {
            throw ValidationException::withMessages([
                'email' => __('This organization is not active. Please contact your administrator.'),
            ]);
        }
    }

    /**
     * Record the failure against every account that shares the address.
     *
     * Counting per account rather than per address is what stops a lockout on one tenant's user
     * from denying service to an unrelated person in another tenant who happens to share an email
     * domain policy — and it still bounds an attacker to N attempts per real account.
     *
     * @param  Collection<int,User>  $candidates
     */
    private function penalise($candidates, Request $request): void
    {
        foreach ($candidates as $candidate) {
            $organizationId = (int) $candidate->organization_id;

            $max = $this->settings->int('security.max_login_attempts', 5, $organizationId);
            $minutes = $this->settings->int('security.lockout_minutes', 15, $organizationId);

            $attempts = (int) $candidate->failed_login_attempts + 1;
            $lockedUntil = $attempts >= $max ? now()->addMinutes($minutes) : $candidate->locked_until;

            // saveQuietly: a failed sign-in is not a change to the person's record, and routing it
            // through the model observers would fill the audit trail's field-diff view with
            // counter increments and bury the entry that actually matters — the one below.
            $candidate->forceFill([
                'failed_login_attempts' => $attempts,
                'locked_until' => $lockedUntil,
            ])->saveQuietly();

            $this->audit->log(
                action: $attempts >= $max ? 'account_locked' : 'login_failed',
                subject: $candidate,
                description: sprintf(
                    '%s sign-in attempt %d of %d from %s',
                    $attempts >= $max ? 'Account locked after' : 'Failed',
                    $attempts,
                    $max,
                    $request->ip() ?? 'unknown address',
                ),
                actor: $candidate,
            );
        }
    }

    private function clearFailures(User $user, Request $request): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();
    }
}
