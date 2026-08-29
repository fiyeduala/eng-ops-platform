{{--
    Your own account.

    Everything editable here addresses the signed-in user and nothing else — there is no id in the
    route to tamper with. The fields that are NOT here are the point: unit, position, supervisor,
    employment status and employee number are HR data with access consequences, so they are shown
    read-only and changed by somebody with the authority to change them.
--}}
<x-layouts.app title="Your profile">

    <x-page-header
        title="Your profile"
        description="Your contact details and display preferences. Anything that affects what you can see is maintained by an administrator."/>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ editable --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('profile.update') }}" class="card">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h3 class="card-title">Account</h3>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <x-field label="Full name" name="name" required class="sm:col-span-2">
                        <x-input name="name" :value="$user->name" required autocomplete="name"/>
                    </x-field>

                    <x-field label="Sign-in email" name="email_display"
                             help="Changing the address an account signs in with is an identity change, not a preference. Ask an administrator.">
                        <input type="email" id="email_display" value="{{ $user->email }}" class="input" disabled>
                    </x-field>

                    <x-field label="Work phone" name="phone">
                        <x-input name="phone" type="tel" :value="$user->phone" autocomplete="tel"/>
                    </x-field>

                    <x-field label="Time zone" name="timezone"
                             help="Dates and times throughout the system are shown in this zone.">
                        <x-select name="timezone"
                                  :selected="$user->timezone"
                                  placeholder="Use the company default"
                                  :options="collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz])->all()"/>
                    </x-field>

                    <x-field label="Language" name="locale">
                        <x-select name="locale" :selected="$user->locale" :options="['en' => 'English']"/>
                    </x-field>
                </div>

                @if ($employee)
                    <div class="card-header border-t border-[var(--surface-border)]">
                        <h3 class="card-title">Personal contact</h3>
                    </div>

                    <div class="grid gap-5 p-5 sm:grid-cols-2">
                        <x-field label="Personal phone" name="personal_phone">
                            <x-input name="personal_phone" type="tel" :value="$employee->personal_phone"/>
                        </x-field>

                        <div class="hidden sm:block"></div>

                        <x-field label="Emergency contact" name="emergency_contact_name">
                            <x-input name="emergency_contact_name" :value="$employee->emergency_contact_name"/>
                        </x-field>

                        <x-field label="Emergency contact phone" name="emergency_contact_phone">
                            <x-input name="emergency_contact_phone" type="tel" :value="$employee->emergency_contact_phone"/>
                        </x-field>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                    <a href="{{ route('password.edit') }}" class="btn btn-sm btn-ghost">
                        <x-icon name="lock" class="h-3.5 w-3.5"/> Change password
                    </a>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>

        {{-- ------------------------------------------------------------ read-only --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Your place in the organization</h3>
                </div>

                @if ($employee)
                    <dl class="space-y-4 p-5">
                        @foreach ([
                            'Employee number' => $employee->employee_no,
                            'Position' => $employee->position?->title,
                            'Unit' => $employee->orgUnit?->name,
                            'Reports to' => $employee->supervisor?->fullName(),
                            'Base location' => $employee->location?->name,
                            'Employment type' => $employee->employment_type ? str($employee->employment_type)->replace('_', ' ')->title() : null,
                            'Joined' => $employee->hired_on?->format('j M Y'),
                        ] as $label => $value)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">{{ $label }}</dt>
                                <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $value ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <x-empty-state
                        icon="user"
                        title="No personnel record"
                        description="Your account is not linked to an employee record yet, so there is nothing to show here."/>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Your roles</h3>
                </div>

                <div class="p-5">
                    @forelse ($roles as $role)
                        <div class="mb-3 flex items-start gap-2 last:mb-0">
                            <x-icon name="key" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"/>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-[var(--text-strong)]">{{ $role->name }}</p>
                                @if ($role->description)
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $role->description }}</p>
                                @endif
                                @if ($role->pivot->scope_type === 'org_unit' && $role->pivot->scope_id)
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">Limited to one unit.</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-[var(--text-muted)]">You hold no roles. Only an administrator can assign them.</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Sign-in</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Last signed in</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $user->last_login_at?->format('j M Y, H:i') ?? 'This is your first session.' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Password last changed</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $user->password_changed_at?->format('j M Y') ?? 'Never' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

</x-layouts.app>
