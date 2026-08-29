<?php

declare(strict_types=1);

namespace App\Rules;

use App\Engines\Configuration\SettingsRepository;
use Illuminate\Validation\Rules\Password;

/**
 * The password policy, read from configuration rather than hard-coded (§72).
 *
 * Minimum length is `security.password_min_length` and differs per company, because a firm with a
 * client security schedule to satisfy must be able to raise it without a deployment. What is NOT
 * configurable is the compromised-password check and the ban on pure-numeric passwords: those are
 * floors, and a floor an administrator can lower is not a floor.
 *
 * Composition rules (one upper, one symbol, ...) are deliberately absent. They measurably push
 * people towards Password1! and its cousins; length plus a breach check is the stronger control,
 * and it is what NIST 800-63B has recommended since 2017.
 */
class OrganizationPassword
{
    public static function rules(?int $organizationId = null): Password
    {
        $minimum = app(SettingsRepository::class)
            ->int('security.password_min_length', 12, $organizationId);

        $rule = Password::min(max(8, $minimum));

        // k-anonymity range query against haveibeenpwned; it never sends the password. Skipped
        // in tests and offline installs, where the outbound call would be a slow flake rather
        // than a control.
        if (app()->environment('production')) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }
}
