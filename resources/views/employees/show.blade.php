{{--
    One personnel record.

    A personnel record is not one uniform blob of sensitivity. Three blocks below are gated
    separately: org-chart data is visible to anyone who can open the record at all, contact details
    need employee.view_contact, and identity data needs employee.view_confidential. The gate is the
    policy — `$canSeeContact` and `$canSeeConfidential` are its answer, not a second opinion.
--}}
<x-layouts.app :title="$employee->fullName()">

    <x-page-header
        :title="$employee->fullName()"
        :description="trim(($employee->position?->title ?? 'No position recorded').($employee->orgUnit ? ' · '.$employee->orgUnit->name : ''))">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('employees.edit', $employee) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            @if ($canDelete)
                <x-delete-form :action="route('employees.destroy', $employee)"
                               label="Remove"
                               confirm="Remove {{ $employee->fullName() }}?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ identity card --}}
        <div class="space-y-6">
            <div class="card">
                <div class="flex items-center gap-4 p-5">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brand-50 text-lg font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300">
                        {{ str($employee->first_name)->substr(0, 1)->upper() }}{{ str($employee->last_name)->substr(0, 1)->upper() }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-[var(--text-strong)]">{{ $employee->fullName() }}</p>
                        <p class="truncate font-mono text-xs text-[var(--text-muted)]">{{ $employee->employee_no }}</p>
                        @php
                            $tone = match ($employee->employment_status) {
                                'active' => 'success',
                                'probation' => 'brand',
                                'on_leave' => 'warning',
                                'suspended', 'exited' => 'danger',
                                default => 'neutral',
                            };
                        @endphp
                        <x-badge :tone="$tone" class="mt-1.5">
                            {{ ucwords(str_replace('_', ' ', (string) $employee->employment_status)) }}
                        </x-badge>
                    </div>
                </div>

                <dl class="space-y-4 border-t border-[var(--surface-border)] p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Unit</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($employee->orgUnit)
                                <a href="{{ route('org-units.show', $employee->orgUnit) }}" class="text-brand-600 hover:underline">{{ $employee->orgUnit->name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Base location</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->location?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Employment type</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ ucwords(str_replace('_', ' ', (string) $employee->employment_type)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Joined</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->hired_on?->format('j M Y') ?? '—' }}</dd>
                    </div>
                    @if ($employee->contract_ends_on)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Contract ends</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->contract_ends_on->format('j M Y') }}</dd>
                        </div>
                    @endif
                    @if ($employee->exited_on)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Left</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">
                                {{ $employee->exited_on->format('j M Y') }}
                                @if ($employee->exit_reason)
                                    <span class="block text-xs text-[var(--text-muted)]">{{ $employee->exit_reason }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- ---------------------------------------------------- login account --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Login account</h3>
                </div>
                <div class="p-5">
                    @if ($employee->user)
                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $employee->user->name }}</p>
                        <p class="text-xs text-[var(--text-muted)]">{{ $employee->user->email }}</p>
                        @can('view', $employee->user)
                            <a href="{{ route('users.show', $employee->user) }}" class="btn btn-sm btn-ghost mt-3">
                                <x-icon name="key" class="h-3.5 w-3.5"/> Manage access
                            </a>
                        @endcan
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            This person has no login account. They exist on the org chart and in records, but cannot sign in.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- ---------------------------------------------------- contact --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Contact</h3>
                    @unless ($canSeeContact)
                        <x-badge tone="neutral"><x-icon name="lock" class="h-3 w-3"/> Restricted</x-badge>
                    @endunless
                </div>

                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Work email</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($employee->work_email)
                                <a href="mailto:{{ $employee->work_email }}" class="text-brand-600 hover:underline">{{ $employee->work_email }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Work phone</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->work_phone ?: '—' }}</dd>
                    </div>

                    @if ($canSeeContact)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Personal email</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->personal_email ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Personal phone</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->personal_phone ?: '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Home address</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-[var(--text-strong)]">{{ $employee->address ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Emergency contact</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->emergency_contact_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Emergency phone</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->emergency_contact_phone ?: '—' }}</dd>
                        </div>
                    @else
                        <p class="text-sm text-[var(--text-muted)] sm:col-span-2">
                            Personal contact details, home address and next of kin are not shown. Seeing them needs the
                            <span class="font-medium text-[var(--text-strong)]">employee.view_contact</span> permission.
                        </p>
                    @endif
                </dl>
            </div>

            {{-- ---------------------------------------------------- confidential --}}
            @if ($canSeeConfidential)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Personal identity</h3>
                        <x-badge tone="warning"><x-icon name="shield" class="h-3 w-3"/> Confidential</x-badge>
                    </div>

                    <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Date of birth</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->date_of_birth?->format('j M Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">National ID</dt>
                            <dd class="mt-1 font-mono text-sm text-[var(--text-strong)]">{{ $employee->national_id ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Gender</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->gender ? ucfirst((string) $employee->gender) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Nationality</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $employee->nationality ?: '—' }}</dd>
                        </div>
                    </dl>

                    <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        Opening this record is recorded in the audit trail.
                    </p>
                </div>
            @endif

            {{-- ---------------------------------------------------- reporting line --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Reporting line</h3>
                </div>

                <div class="p-5">
                    @if ($chain === [])
                        <p class="text-sm text-[var(--text-muted)]">
                            @if ($employee->supervisor_id)
                                This person reports upward, but not to anyone within your access.
                            @else
                                This person does not report to anybody on record.
                            @endif
                        </p>
                    @else
                        <ol class="space-y-0">
                            @foreach (array_reverse($chain) as $link)
                                <li class="relative flex items-start gap-3 pb-4 last:pb-0">
                                    @unless ($loop->last)
                                        <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                                    @endunless
                                    <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[var(--surface-border)]"></span>
                                    <a href="{{ route('employees.show', $link) }}" class="text-sm text-brand-600 hover:underline">{{ $link->fullName() }}</a>
                                </li>
                            @endforeach
                            <li class="flex items-start gap-3">
                                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-brand-500"></span>
                                <span class="text-sm font-medium text-[var(--text-strong)]">{{ $employee->fullName() }}</span>
                            </li>
                        </ol>
                    @endif
                </div>
            </div>

            {{-- ---------------------------------------------------- reports --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Direct reports</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $reports->count() }} visible</span>
                </div>

                @if ($reports->isEmpty())
                    <x-empty-state
                        icon="users"
                        title="No direct reports"
                        description="Nobody visible to you reports to this person."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($reports as $report)
                            <li>
                                <a href="{{ route('employees.show', $report) }}"
                                   class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-[var(--surface-muted)]">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-[var(--text-strong)]">{{ $report->fullName() }}</span>
                                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $report->employee_no }}</span>
                                    </span>
                                    <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
