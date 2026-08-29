<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Rules\OrganizationPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgotten-password flow (§76).
 *
 * Every response on the request side is identical whether or not the address exists. That
 * uniformity is the control: a differing message, status code or response time would turn this
 * form into an account-enumeration oracle, and on a per-tenant address space it would also answer
 * "does this person work there".
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request, PasswordResetService $service): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));

        // Throttled on the IP alone, not on the address: keying it to the address would let an
        // attacker probe whether one exists by watching which submissions get throttled.
        $key = 'password-reset:'.$request->ip();

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            RateLimiter::hit($key, 900);
            $service->request($email, $request->ip());
        }

        return back()->with('status', __(
            'If that address belongs to an account, a reset link is on its way. Check your inbox, including spam.'
        ));
    }

    /**
     * The reset form. Reached only through a signed URL, so the link cannot be edited to point at
     * a different account — and the token is checked here too, so a valid signature alone is not
     * enough.
     */
    public function edit(Request $request, PasswordResetService $service, int $user, string $token): View|RedirectResponse
    {
        if ($service->resolve($user, $token) === null) {
            return redirect()->route('password.request')->withErrors([
                'email' => __('That reset link is invalid or has expired. Please request a new one.'),
            ]);
        }

        return view('auth.reset-password', ['userId' => $user, 'token' => $token]);
    }

    public function update(Request $request, PasswordResetService $service): RedirectResponse
    {
        $validated = $request->validate([
            'user' => ['required', 'integer'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'string'],
        ]);

        $user = $service->resolve((int) $validated['user'], $validated['token']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'password' => __('That reset link is invalid or has expired. Please request a new one.'),
            ]);
        }

        // The strength rule is read from the ACCOUNT's organization, not from the request and not
        // from whatever tenant happens to be in context — there is none, this is unauthenticated.
        $request->validate([
            'password' => [OrganizationPassword::rules((int) $user->organization_id)],
        ]);

        $service->reset($user, $validated['password']);

        return redirect()->route('login')->with('status', __(
            'Your password has been reset. You can sign in with it now.'
        ));
    }
}
