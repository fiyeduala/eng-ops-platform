{{--
    Approval routes.

    The list an administrator opens to answer "what actually happens when somebody submits a
    document" — so the module, the version and whether it is LIVE are given more room than the name.
    A draft that looks published is the failure this screen is most responsible for preventing.

    Versions are listed as separate rows rather than collapsed under a name, because that is what
    they are: version 2 is a different definition, and the approvals that travelled version 1 are
    still readable against it. Hiding superseded versions behind a disclosure would make the audit
    trail harder to follow, which is the one thing versioning exists to make easier.

    Expects: $workflows, $entities, $views, $filters, $canCreate
--}}
<x-layouts.app title="Approval routes">

    <x-page-header
        title="Approval routes"
        description="What happens when somebody submits something. Defined here, entirely — no developer edits a file, and nothing is deployed.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('workflows.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New route
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('workflows.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label for="entity" class="label">Module</label>
                <x-select name="entity" :options="$entities" :selected="$filters['entity']" placeholder="Every module"/>
            </div>

            <div>
                <label for="view" class="label">Show</label>
                <x-select name="view" :options="$views" :selected="$filters['view']"/>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
                </button>
                @if ($filters['entity'] || $filters['view'] !== 'current')
                    <a href="{{ route('workflows.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($workflows->isEmpty())
            <x-empty-state
                icon="target"
                title="No approval routes here"
                description="Until a module has a published route, records in it are approved by whoever the module's own default says — which is usually nobody. Define a route and it takes effect for new submissions immediately.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('workflows.create') }}" class="btn btn-primary btn-sm">Define a route</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Route</th>
                            <th>Module</th>
                            <th>Version</th>
                            <th>Stages</th>
                            <th>In flight</th>
                            <th>Status</th>
                            <th>Published</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workflows as $workflow)
                            <tr>
                                <td>
                                    <a href="{{ route('workflows.show', $workflow) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $workflow->name }}
                                    </a>
                                    @if ($workflow->is_default)
                                        <x-badge tone="brand" class="ml-2">Default</x-badge>
                                    @endif
                                    @if ($workflow->description)
                                        <p class="mt-0.5 max-w-md truncate text-xs text-[var(--text-muted)]">
                                            {{ $workflow->description }}
                                        </p>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $entities[$workflow->entity_type] ?? $workflow->entity_type }}
                                </td>
                                <td class="font-mono text-[var(--text-muted)]">v{{ $workflow->version }}</td>
                                <td class="text-[var(--text-muted)]">{{ $workflow->stages_count }}</td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $workflow->instances_count }}
                                </td>
                                <td>
                                    <x-badge :tone="match ($workflow->statusLabel()) {
                                        'Published' => 'success',
                                        'Draft' => 'warning',
                                        default => 'neutral',
                                    }">{{ $workflow->statusLabel() }}</x-badge>
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $workflow->published_at?->format('j M Y') ?? '—' }}
                                    @if ($workflow->publisher)
                                        <span class="block text-xs">{{ $workflow->publisher->name }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($workflows->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $workflows->links() }}
                </div>
            @endif
        @endif
    </div>

    <p class="mt-4 text-xs text-[var(--text-muted)]">
        A superseded route is never deleted. The approvals that travelled it are only readable against
        the definition they were written for, so taking one out of use stops new records entering and
        leaves everything already in flight alone.
    </p>

</x-layouts.app>
