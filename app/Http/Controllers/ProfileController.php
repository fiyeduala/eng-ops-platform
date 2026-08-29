<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Audit\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Your own account and personnel record.
 *
 * No permission middleware and no policy call, because there is no id here to authorize: every
 * statement addresses $request->user() and nothing else. That is the safest shape available —
 * there is no parameter an attacker could change to point this at somebody else.
 *
 * What a person may edit about themselves is deliberately narrow. Contact details and display
 * preferences, yes. Their org unit, position, supervisor, employment status or employee number,
 * no: those are HR data with access consequences, and self-service editing of them would be a
 * privilege-escalation path dressed up as a convenience.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('profile.show', [
            'user' => $user,
            'employee' => $user->employee?->loadMissing(['position', 'orgUnit', 'supervisor', 'location']),
            'roles' => $user->activeRoles()->get(),
        ]);
    }

    public function update(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'locale' => ['nullable', 'string', 'max:8', Rule::in(['en'])],

            // Personal contact details live on the employee record, not the account.
            'personal_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ]);

        // Email is absent from the rules on purpose. Changing the address an account signs in
        // with is an identity change, not a preference: it needs administrator involvement and a
        // verification round-trip, so it belongs to the user-management screen.
        $user->fill(collect($validated)
            ->only(['name', 'phone', 'timezone', 'locale'])
            ->all());

        $user->save();

        if ($employee = $user->employee) {
            $employee->fill(collect($validated)
                ->only(['personal_phone', 'emergency_contact_name', 'emergency_contact_phone'])
                ->all());

            if ($employee->isDirty()) {
                $employee->save();
                $activity->record($employee, 'updated', $user->name.' updated their own contact details');
            }
        }

        return redirect()->route('profile.show')->with('status', 'Your profile has been updated.');
    }
}
