<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Engines\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AttemptLogin $attempt): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        try {
            $attempt(
                $request,
                (string) $request->string('email'),
                (string) $request->string('password'),
                $request->boolean('remember'),
            );
        } catch (ValidationException $e) {
            $request->recordFailure();

            throw $e;
        }

        $request->clearRateLimit();

        // intended() honours the URL the user was refused before signing in, which is the
        // difference between "log in and you are back where you were" and "log in and start
        // over". The default is the dashboard, never a URL from the request body.
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $audit->log(action: 'logged_out', subject: $user, actor: $user);
        }

        Auth::logout();

        // Both are required. invalidate() drops the server-side session data; regenerateToken()
        // issues a fresh CSRF token so the login form the user lands on is not still carrying the
        // token of the session just ended.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
