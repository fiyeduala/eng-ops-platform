{{--
    Registers.

    The screen that makes one company's document control different from another's. A register decides
    numbering, revision labelling, what may be uploaded, whether its documents are controlled, and
    which approval route they take — so it is shown as a tree, because that is how document control
    standards are actually written: a discipline sits inside a project series, which sits inside a
    company series.

    The counts matter more than they look. "0 documents" is what tells an administrator a register is
    safe to delete; anything else and the platform refuses, and offers deactivation instead.

    Expects: $categories (already ordered, flat, with parent/workflow loaded and counts), $canManage
--}}
@php
    /*
     * Flatten the tree into rows with a depth, so the table renders in one pass.
     *
     * Registers nest, but not deeply — two or three levels covers every document-control standard
     * worth the name — and a flat ordered list with an indent is easier to read, and to scan, than a
     * partial that includes itself. Orphans (a child whose parent this user cannot see) are appended
     * at the root rather than dropped: §61 hides the parent, not the register itself.
     */
    $byParent = $categories->groupBy(fn ($c) => (int) ($c->parent_id ?? 0));
    $visible = $categories->keyBy('id');

    $rows = [];
    $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent) {
        foreach ($byParent[$parentId] ?? [] as $category) {
            $rows[] = ['category' => $category, 'depth' => $depth];
            $walk((int) $category->id, $depth + 1);
        }
    };
    $walk(0, 0);

    foreach ($categories as $category) {
        if ($category->parent_id !== null && ! $visible->has($category->parent_id)) {
            $rows[] = ['category' => $category, 'depth' => 0];
        }
    }
@endphp

<x-layouts.app title="Registers">

    <x-page-header
        title="Registers"
        description="How documents are numbered, revised, approved and controlled. Configured here — no developer, no deployment.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('document-categories.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New register
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="card overflow-hidden">
        @if ($categories->isEmpty())
            <x-empty-state
                icon="archive"
                title="No registers yet"
                description="A register is the smallest amount of configuration a document needs: what to call it, how to number it, and who approves it. Most organisations start with two or three — drawings, procedures, correspondence — and grow from there.">
                @if ($canManage)
                    <x-slot:actions>
                        <a href="{{ route('document-categories.create') }}" class="btn btn-primary btn-sm">Create the first one</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Register</th>
                            <th>Revisions</th>
                            <th>Approval route</th>
                            <th>Documents</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($category = $row['category'])
                            <tr>
                                <td>
                                    <div style="padding-left: {{ $row['depth'] * 20 }}px">
                                        <a href="{{ route('document-categories.show', $category) }}"
                                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                            {{ $category->name }}
                                        </a>
                                        @if ($category->code)
                                            <span class="ml-2 font-mono text-xs text-[var(--text-muted)]">{{ $category->code }}</span>
                                        @endif
                                        @if ($category->is_controlled)
                                            <x-badge tone="brand" class="ml-2">Controlled</x-badge>
                                        @endif
                                        @if ($category->description)
                                            <p class="mt-0.5 max-w-md truncate text-xs text-[var(--text-muted)]">
                                                {{ $category->description }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ \App\Engines\Document\RevisionSequencer::SCHEMES[$category->revision_scheme] ?? $category->revision_scheme }}
                                    @if ($category->hasDraftSeries())
                                        <span class="block text-xs">Draft series &ldquo;{{ $category->draft_revision_prefix }}&rdquo;</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    @if ($category->workflow)
                                        {{ $category->workflow->name }}
                                        <span class="text-xs">v{{ $category->workflow->version }}</span>
                                    @elseif ($category->requires_approval)
                                        <span class="text-amber-600">Approval required, no route set</span>
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $category->documents_count }}</td>
                                <td>
                                    <x-badge :tone="$category->is_active ? 'success' : 'neutral'">
                                        {{ $category->is_active ? 'In use' : 'Closed' }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs text-[var(--text-muted)]">
        A register that holds documents cannot be deleted, and that is not an oversight: deleting it
        would either orphan its documents or take them with it. Close it instead — new documents stop
        being raised into it, and everything already there stays exactly where it is.
    </p>

</x-layouts.app>
