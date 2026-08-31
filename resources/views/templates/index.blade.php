{{--
    Templates — the house style, owned by the company rather than by whoever last saved a .docx to
    the shared drive.

    Grouped by kind because that is how somebody looks for one: "where is our letter template". The
    default for each kind is called out, since that is the one most documents will silently inherit
    and therefore the one most worth being able to find.

    Expects: $templates (paginator), $kinds, $filters, $canManage
--}}
<x-layouts.app title="Templates">

    <x-page-header
        title="Templates"
        description="Letterheads, page setup and standard clauses. A document created from a template starts with its content already in place.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('templates.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New template
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('templates.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label for="kind" class="label">Kind</label>
                <x-select name="kind" :options="$kinds" :selected="$filters['kind']" placeholder="Every kind"/>
            </div>

            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($filters['inactive'])
                           class="h-4 w-4 rounded border-[var(--surface-border)]">
                    <span class="text-[var(--text-body)]">Include retired templates</span>
                </label>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
                </button>
                @if ($filters['kind'] || $filters['inactive'])
                    <a href="{{ route('templates.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($templates->isEmpty())
            <x-empty-state
                icon="file-text"
                title="No templates here"
                description="Without one, a new document starts empty and every author invents the layout again. One letterhead and one document body is usually enough to begin with.">
                @if ($canManage)
                    <x-slot:actions>
                        <a href="{{ route('templates.create') }}" class="btn btn-primary btn-sm">Create a template</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Template</th>
                            <th>Kind</th>
                            <th>Register</th>
                            <th>Page</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr>
                                <td>
                                    <a href="{{ route('templates.show', $template) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $template->name }}
                                    </a>
                                    @if ($template->is_default)
                                        <x-badge tone="brand" class="ml-2">Default</x-badge>
                                    @endif
                                    @if ($template->description)
                                        <p class="mt-0.5 max-w-md truncate text-xs text-[var(--text-muted)]">
                                            {{ $template->description }}
                                        </p>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $template->kindLabel() }}</td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $template->category?->name ?? 'Every register' }}
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $template->page_size }},
                                    {{ $template->isLandscape() ? 'landscape' : 'portrait' }}
                                </td>
                                <td>
                                    <x-badge :tone="$template->is_active ? 'success' : 'neutral'">
                                        {{ $template->is_active ? 'In use' : 'Retired' }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($templates->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $templates->links() }}
                </div>
            @endif
        @endif
    </div>

    <p class="mt-4 text-xs text-[var(--text-muted)]">
        Placeholders are resolved once, when a document is created — never when it is read. An approved
        procedure keeps saying the project name it was approved under, even after the project is
        renamed, which is the difference between a template and a mail merge.
    </p>

</x-layouts.app>
