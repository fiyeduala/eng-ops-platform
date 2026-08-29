{{--
    One login account.

    The audit panel at the bottom is present only when `$trail` is not null — the controller sets it
    to null for anyone without audit_log.view. Being able to open an account page is not the same
    authority as reading its history of sign-ins, lockouts and permission changes (§59).
--}}
<x-layouts.app :title="$account->name">

    <x-page-header :title="$account->name" :description="$account->email">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('users.edit', $account) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            @if ($canResetPassword)
                <form method="POST" action="{{ route('users.password.reset', $account) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <x-icon name="mail" class="h-3.5 w-3.5"/> Email a reset link
                    </button>
                </form>
            @endif
            @if ($canDelete)
                <x-delete-form :action="route('users.destroy', $account)"
                               label="Disable account"
                               confirm="Disable {{ $account->name }}?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ account --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Account</h3>
                @if (! $account->is_active)
                    <x-badge tone="neutral">Disabled</x-badge>
                @elseif ($account->isLocked())
                    <x-badge tone="danger"><x-icon name="lock" class="h-3 w-3"/> Locked out</x-badge>
                @else
                    <x-badge tone="success">Active</x-badge>
                @endif
            </div>

            <dl class="space-y-4 p-5">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Email</dt>
                    <dd class="mt-1 break-all text-sm text-[var(--text-strong)]">{{ $account->email }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Username</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $account->username ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Phone</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $account->phone ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Time zone</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $account->timezone ?: 'Company default' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Last signed in</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">
                        {{ $account->last_login_at?->format('j M Y, H:i') ?? 'Never' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Password last set</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">
                        {{ $account->password_changed_at?->format('j M Y') ?? 'Not yet — the invitation is still outstanding' }}
                    </dd>
                </div>
                @if ($account->isLocked())
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Locked until</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $account->locked_until->format('j M Y, H:i') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- ------------------------------------------------------------ person --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Person</h3>
            </div>

            <div class="p-5">
                @if ($account->employee)
                    <p class="text-sm font-medium text-[var(--text-strong)]">{{ $account->employee->fullName() }}</p>
                    <p class="mt-0.5 text-sm text-[var(--text-muted)]">
                        {{ $account->employee->position?->title ?? 'No position' }}
                        @if ($account->employee->orgUnit)
                            · {{ $account->employee->orgUnit->name }}
                        @endif
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $account->employee->employee_no }}</p>

                    @can('view', $account->employee)
                        <a href="{{ route('employees.show', $account->employee) }}" class="btn btn-sm btn-ghost mt-3">
                            <x-icon name="user" class="h-3.5 w-3.5"/> Open personnel record
                        </a>
                    @endcan
                @else
                    <p class="text-sm text-[var(--text-muted)]">
                        This account is not linked to a personnel record. It can sign in, but it has no place on the
                        org chart — which means no supervisor, no unit, and no visibility that derives from either.
                    </p>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------ roles --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Roles</h3>
                @if ($canAssignRoles)
                    <a href="{{ route('users.edit', $account) }}#roles" class="btn btn-sm btn-ghost">Change</a>
                @endif
            </div>

            <div class="p-5">
                @forelse ($roles as $role)
                    <div class="mb-3 flex items-start gap-2 last:mb-0">
                        <x-icon name="key" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"/>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[var(--text-strong)]">{{ $role->name }}</p>
                            @if (! empty($scopeLabels[$role->id]))
                                <p class="mt-0.5 text-xs text-[var(--text-muted)]">Limited to {{ $scopeLabels[$role->id] }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-[var(--text-muted)]">
                        No roles. This account can sign in and see almost nothing — which is the correct starting state.
                    </p>
                @endforelse

                @unless ($canAssignRoles)
                    <p class="mt-4 border-t border-[var(--surface-border)] pt-3 text-xs text-[var(--text-muted)]">
                        @if ((int) $account->id === (int) auth()->id())
                            You cannot change your own roles. Somebody else with the authority has to — which is what
                            makes the audit entry mean anything.
                        @else
                            Changing roles needs the <span class="font-medium">role.assign</span> permission.
                        @endif
                    </p>
                @endunless
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- audit --}}
    @if ($trail !== null)
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Audit trail</h3>
                <a href="{{ route('audit.index', ['q' => $account->name]) }}" class="btn btn-sm btn-ghost">See all</a>
            </div>

            @if ($trail->isEmpty())
                <x-empty-state icon="scroll" title="Nothing recorded yet" description="This account has no audit history."/>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trail as $entry)
                                <tr>
                                    <td class="whitespace-nowrap text-[var(--text-muted)]">{{ $entry->created_at->format('j M Y, H:i') }}</td>
                                    <td>
                                        <span class="text-[var(--text-strong)]">{{ $entry->description ?? str($entry->action)->replace('_', ' ') }}</span>
                                        @if ($entry->is_privileged)
                                            <x-badge tone="warning" class="ml-2">Privileged</x-badge>
                                        @endif
                                    </td>
                                    <td class="text-[var(--text-muted)]">{{ $entry->actorName() }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('audit.show', $entry) }}" class="btn btn-sm btn-ghost">Detail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

</x-layouts.app>
