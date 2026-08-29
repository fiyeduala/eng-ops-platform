<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Rules\OrganizationPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Changing your own password, including the forced change after an administrator issues one.
 *
 * Proving the current password is required even though the session is already authenticated.
 * A live session that has been walked away from is exactly the case this defends against, and it
 * is what stops session theft from becoming permanent account takeover.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request, PasswordResetService $service): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'string', OrganizationPassword::rules((int) $user->organization_id)],
        ]);

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('That is not your current password.'),
            ]);
        }

        if (Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Please choose a password you have not used before.'),
            ]);
        }

        $service->change($user, (string) $request->input('password'));

        // A password change is a good moment to invalidate any other session riding on the old
        // one. logoutOtherDevices re-hashes the remember token, which the session guard checks.
        Auth::logoutOtherDevices((string) $request->input('password'));

        return redirect()->route('dashboard')->with('status', __('Your password has been changed.'));
    }
}
