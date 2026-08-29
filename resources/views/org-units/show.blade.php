{{--
    One organization unit.

    The breadcrumb is built from `$ancestors`, which the controller filtered through the access
    scope — so it stops where the viewer's reach stops. A department head sees "Mechanical" with no
    parent above it rather than the name of a division they are not permitted to know about.
--}}
<x-layouts.app :title="$unit->name">

    {{-- ---------------------------------------------------------------- breadcrumb --}}
    <nav aria-label="Breadcrumb" class="mb-4 flex flex-wrap items-center gap-1 text-sm text-[var(--text-muted)]">
        <a href="{{ route('org-units.index') }}" class="hover:text-brand-600">Organization</a>
        @foreach ($ancestors as $ancestor)
            <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
            <a href="{{ route('org-units.show', $ancestor) }}" class="hover:text-brand-600">{{ $ancestor->name }}</a>
        @endforeach
        <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
        <span class="font-medium text-[var(--text-strong)]">{{ $unit->name }}</span>
    </nav>

    <x-page-header :title="$unit->name" :description="$unit->description">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('org-units.edit', $unit) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            @if ($canDelete)
                <x-delete-form :action="route('org-units.destroy', $unit)"
                               label="Delete unit"
                               confirm="Delete {{ $unit->name }}?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ detail --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Detail</h3>
                @unless ($unit->is_active)
                    <x-badge tone="neutral">Inactive</x-badge>
                @endunless
            </div>

            <dl class="space-y-4 p-5">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Level</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $unit->type?->defaultLabel() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Code</dt>
                    <dd class="mt-1 font-mono text-sm text-[var(--text-strong)]">{{ $unit->code ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Reports into</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">
                        @if ($unit->parent && $ancestors->contains('id', $unit->parent->id))
                            <a href="{{ route('org-units.show', $unit->parent) }}" class="text-brand-600 hover:underline">{{ $unit->parent->name }}</a>
                        @elseif ($unit->parent)
                            <span class="text-[var(--text-muted)]">A unit above your level of access</span>
                        @else
                            Nothing — this is a top-level unit
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Head of unit</dt>
                    <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $unit->head?->fullName() ?? 'Not appointed' }}</dd>
                </div>
            </dl>
        </div>

        {{-- ------------------------------------------------------------ children --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">Units inside {{ $unit->name }}</h3>
                <span class="text-xs text-[var(--text-muted)]">{{ $children->count() }} visible</span>
            </div>

            @if ($children->isEmpty())
                <x-empty-state
                    icon="sitemap"
                    title="Nothing below this unit"
                    description="Either this unit has no sub-units, or none that you are permitted to see."/>
            @else
                <ul class="divide-y divide-[var(--surface-border)]">
                    @foreach ($children as $child)
                        <li>
                            <a href="{{ route('org-units.show', $child) }}"
                               class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-[var(--surface-muted)]">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-[var(--text-strong)]">{{ $child->name }}</span>
                                    <span class="block text-xs text-[var(--text-muted)]">
                                        {{ $child->type?->defaultLabel() }}{{ $child->code ? ' · '.$child->code : '' }}
                                    </span>
                                </span>
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- people --}}
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">People in this unit</h3>
            @if ($people->isNotEmpty())
                <a href="{{ route('employees.index', ['org_unit' => $unit->id]) }}" class="btn btn-sm btn-ghost">See all</a>
            @endif
        </div>

        @if ($people->isEmpty())
            <x-empty-state
                icon="users"
                title="Nobody to show"
                description="This unit has no active personnel you are permitted to see. That is not the same as the unit being empty."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Employee no.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $person)
                            <tr>
                                <td class="font-medium text-[var(--text-strong)]">{{ $person->fullName() }}</td>
                                <td class="text-[var(--text-muted)]">{{ $person->position?->title ?? '—' }}</td>
                                <td class="font-mono text-xs text-[var(--text-muted)]">{{ $person->employee_no }}</td>
                                <td class="text-right">
                                    <a href="{{ route('employees.show', $person) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($people->count() === 50)
                <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--text-muted)]">
                    Showing the first 50. Use the personnel list for the full set.
                </p>
            @endif
        @endif
    </div>

</x-layouts.app>
