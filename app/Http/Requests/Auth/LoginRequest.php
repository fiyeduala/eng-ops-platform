<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in input, plus the IP-level rate limit.
 *
 * Two throttles guard login and they answer different questions. This one is per (address, IP)
 * and stops a fast online guessing run. The per-account lockout in AttemptLogin is per user and
 * stops a slow distributed one. Neither substitutes for the other: an attacker rotating IPs
 * defeats this alone, and an attacker spraying one password across many accounts defeats that
 * alone.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 10)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('Too many sign-in attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    public function recordFailure(): void
    {
        RateLimiter::hit($this->throttleKey(), 300);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->lower().'|'.$this->ip());
    }
}
