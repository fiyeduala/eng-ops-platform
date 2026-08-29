{{--
    One role, read-only.

    `$catalogue` arrives already filtered to what the role actually carries, so this page answers
    the question somebody opens it with — what does holding this let me do — instead of showing four
    hundred unticked boxes. The full catalogue is one click away, in the editor.

    `$holders` is filtered through the VIEWER's own `user.view` reach, not the role's. Being allowed
    to read a role definition is not a staff directory (§61), so the count and the list disagree on
    purpose, and the page says so rather than hiding the difference.
--}}
<x-layouts.app :title="$role->name">

    <x-page-header :title="$role->name" :description="$role->description">
        <x-slot:actions>
            <a href="{{ route('roles.index') }}" class="btn btn-sm btn-ghost">All roles</a>
            @if ($canEdit)
                <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            @if ($canDelete)
                <x-delete-form :action="route('roles.destroy', $role)"
                               label="Delete role"
                               confirm="Delete {{ $role->name }}?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ permissions --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">What this role can do</h3>
                    <div class="flex items-center gap-2">
                        @unless ($role->is_active)
                            <x-badge tone="neutral">Withdrawn</x-badge>
                        @endunless
                        @if ($role->is_system)
                            <x-badge tone="neutral">Built in</x-badge>
                        @endif
                    </div>
                </div>

                @if ($catalogue === [])
                    <x-empty-state
                        icon="key"
                        title="This role carries nothing"
                        description="Anyone holding it can sign in and see almost nothing. That is a valid starting point — and a problem if it was not intended."/>
                @else
                    @foreach ($catalogue as $group => $modules)
                        <div class="border-t border-[var(--surface-border)] first:border-t-0">
                            <p class="bg-[var(--surface-muted)] px-5 py-2 text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
                                {{ $group }}
                            </p>

                            @foreach ($modules as $module)
                                <div class="border-t border-[var(--surface-border)] px-5 py-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">{{ $module['label'] }}</p>

                                    <ul class="mt-2 space-y-2">
                                        @foreach ($module['permissions'] as $permission)
                                            <li class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                                                <span class="flex flex-wrap items-center gap-2 text-sm text-[var(--text-strong)]">
                                                    {{ $permission['label'] }}
                                                    @if ($permission['sensitive'])
                                                        <x-badge tone="warning">Sensitive</x-badge>
                                                    @endif
                                                </span>

                                                <span class="flex shrink-0 flex-wrap items-center gap-1.5 text-xs">
                                                    @if ($permission['scope'])
                                                        <x-badge tone="brand">{{ $scopeLabels[$permission['scope']] ?? $permission['scope'] }}</x-badge>
                                                    @endif
                                                    @if ($permission['project'])
                                                        <x-badge tone="neutral">Projects they belong to</x-badge>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------ side --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Definition</h3>
                </div>
                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Reference name</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-[var(--text-strong)]">{{ $role->slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Seniority</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $role->level }}
                            <span class="text-[var(--text-muted)]">— ordering only, not access</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Held by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $holderCount }} {{ Str::plural('account', $holderCount) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who holds it</h3>
                </div>

                <div class="p-5">
                    @forelse ($holders as $holder)
                        <div class="mb-3 flex items-start gap-2 last:mb-0">
                            <x-icon name="user" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"/>
                            <div class="min-w-0">
                                <a href="{{ route('users.show', $holder) }}"
                                   class="block truncate text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $holder->name }}</a>
                                <p class="truncate text-xs text-[var(--text-muted)]">{{ $holder->email }}</p>
                            </div>
                            @unless ($holder->is_active)
                                <x-badge tone="neutral" class="ml-auto shrink-0">Disabled</x-badge>
                            @endunless
                        </div>
                    @empty
                        <p class="text-sm text-[var(--text-muted)]">
                            @if ($holderCount > 0)
                                {{ $holderCount }} {{ Str::plural('account', $holderCount) }} hold this role, none of
                                them within your access to people.
                            @else
                                Nobody holds this role yet.
                            @endif
                        </p>
                    @endforelse

                    @if ($holders->isNotEmpty() && $holderCount > $holders->count())
                        <p class="mt-4 border-t border-[var(--surface-border)] pt-3 text-xs text-[var(--text-muted)]">
                            Showing {{ $holders->count() }} of {{ $holderCount }} — the rest are outside your access to
                            people, or beyond the first fifty.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
