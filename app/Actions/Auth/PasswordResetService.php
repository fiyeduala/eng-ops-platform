<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Engines\Access\Scopes\OrganizationScope;
use App\Engines\Audit\AuditLogger;
use App\Models\User;
use App\Notifications\PasswordResetRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issue and redeem password reset tokens (§76).
 *
 * Laravel's own broker is not used, for the reason set out in the password_resets migration: its
 * table is keyed by email address, and email here is unique only within an organization. A broker
 * that cannot tell two tenants' identical addresses apart cannot be trusted to reset the right
 * account.
 *
 * The token is a 64-character random string. Only its SHA-256 hash is stored, so a database read
 * does not yield working reset links. SHA-256 rather than bcrypt is correct here and not a
 * shortcut: the secret is 256 bits of CSPRNG output, not a human-chosen password, so there is no
 * guessable keyspace for a slow hash to protect — and redemption has to look the token up by
 * value, which a per-row salt would make impossible.
 */
class PasswordResetService
{
    /** Deliberately short. A reset link is a bearer credential for one account. */
    private const TTL_MINUTES = 60;

    /** Requests per address per hour, across all tenants. */
    private const MAX_REQUESTS_PER_HOUR = 5;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Send a reset link to every active account holding this address.
     *
     * "Every" is not a leak: each link is bound to one user id, so a person with accounts at two
     * companies gets two links and each resets only its own account. The caller always reports
     * the same neutral message regardless of what happened here — whether an address is
     * registered is not something an unauthenticated request gets to learn (§68).
     */
    public function request(string $email, ?string $ip = null): void
    {
        $users = User::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->where('email', $email)
            ->get()
            ->filter(fn (User $user) => $user->canLogIn() && ($user->organization?->is_active ?? false));

        foreach ($users as $user) {
            if ($this->isThrottled($user)) {
                continue;
            }

            $token = Str::random(64);

            DB::table('password_resets')->insert([
                'user_id' => $user->id,
                'token' => hash('sha256', $token),
                'requested_ip' => $ip,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'created_at' => now(),
            ]);

            $user->notify(new PasswordResetRequested($token, self::TTL_MINUTES));

            $this->audit->log(
                action: 'password_reset_requested',
                subject: $user,
                description: 'Password reset link requested from '.($ip ?? 'unknown address'),
                actor: $user,
            );
        }
    }

    /**
     * Issue a reset link for ONE named account.
     *
     * This is the administrator's path (§4), and it is deliberately separate from request(). That
     * method resolves an address, which may belong to accounts at several companies, and mails all
     * of them; an administrator resetting a colleague must reach exactly the account they chose and
     * no other tenant's. The throttle still applies, so this cannot be used to mail-bomb someone.
     *
     * The administrator never learns the password. They trigger a link, the account holder sets
     * their own — which is what keeps "reset a password" from meaning "take over an account", and
     * is why the audit entry below names the actor rather than the subject.
     */
    public function issueFor(User $user, ?string $ip = null, ?User $actor = null): bool
    {
        if ($this->isThrottled($user)) {
            return false;
        }

        $token = Str::random(64);

        DB::table('password_resets')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'requested_ip' => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

        $user->notify(new PasswordResetRequested($token, self::TTL_MINUTES));

        $this->audit->log(
            action: 'password_reset_issued',
            subject: $user,
            description: 'A password reset link was issued by '.($actor?->name ?? 'an administrator'),
            actor: $actor,
        );

        return true;
    }

    /**
     * Resolve a token to its user, or null if it is unknown, spent or expired.
     *
     * The user id travels in the URL alongside the token so the lookup is indexed, but it is NOT
     * trusted: the row must match both, so supplying someone else's id with your own token finds
     * nothing.
     */
    public function resolve(int $userId, string $token): ?User
    {
        $row = DB::table('password_resets')
            ->where('user_id', $userId)
            ->where('token', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return null;
        }

        $user = User::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with('organization')
            ->find($userId);

        // The account may have been disabled, or the whole tenant suspended, in the hour since
        // the link was sent. A valid token is not a standing exemption from either.
        if ($user === null || ! $user->canLogIn() || ! ($user->organization?->is_active ?? false)) {
            return null;
        }

        return $user;
    }

    /**
     * Set the new password and spend every outstanding token for the account.
     *
     * Spending all of them, not just the one redeemed, matters: if an attacker requested a reset
     * too, their unused link must die the moment the real owner completes theirs.
     */
    public function reset(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->saveQuietly();

            DB::table('password_resets')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        });

        $this->audit->log(
            action: 'password_reset',
            subject: $user,
            description: 'Password was reset via an emailed link',
            actor: $user,
        );
    }

    /**
     * Change your own password, having proved the current one.
     */
    public function change(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->saveQuietly();

        $this->audit->log(
            action: 'password_changed',
            subject: $user,
            description: 'Password changed by the account holder',
            actor: $user,
        );
    }

    /**
     * Remove spent and expired rows. Called by the scheduler; see routes/console.php.
     */
    public function prune(): int
    {
        return DB::table('password_resets')
            ->where('expires_at', '<', now()->subDay())
            ->orWhere(fn ($q) => $q->whereNotNull('used_at')->where('used_at', '<', now()->subDay()))
            ->delete();
    }

    private function isThrottled(User $user): bool
    {
        return DB::table('password_resets')
            ->where('user_id', $user->id)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count() >= self::MAX_REQUESTS_PER_HOUR;
    }
}
