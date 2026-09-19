{{--
    Every form this company has built for itself.

    THE STATE BADGE IS THE COLUMN PEOPLE READ. "Live", "Draft only", "Draft open" and "Out of
    service" are four genuinely different situations, and three of them are situations where
    somebody's form is not being filled in and they do not know why. A list that showed only names
    would generate one support question per row.

    Expects: $forms, $search, $category, $categories, $canCreate
--}}
<x-layouts.app title="Forms">

    <x-page-header
        title="Forms"
        description="The questions your company asks on paper today — inspection sheets, sign-in books, handover checklists — built here and versioned, so what somebody answered last March still reads as an answer to what was asked last March.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('forms.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New form
                </a>
            @endif
            <a href="{{ route('configuration.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Configuration
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That change was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('forms.index') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label class="label" for="q">Search</label>
            <input type="search" id="q" name="q" class="input" value="{{ $search }}"
                   placeholder="Name or key" maxlength="80">
        </div>

        @if ($categories !== [])
            <div class="min-w-40">
                <label class="label" for="category">Group</label>
                <select id="category" name="category" class="select">
                    <option value="">All groups</option>
                    @foreach ($categories as $name)
                        <option value="{{ $name }}" @selected($category === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="flex gap-2">
            <button type="submit" class="btn btn-secondary btn-sm">
                <x-icon name="search" class="h-3.5 w-3.5"/> Search
            </button>
            @if ($search !== '' || $category !== '')
                <a href="{{ route('forms.index') }}" class="btn btn-ghost btn-sm">Clear</a>
            @endif
        </div>
    </form>

    <div class="card overflow-hidden">
        @if ($forms->isEmpty())
            <x-empty-state
                icon="file-text"
                title="No forms yet"
                description="{{ $search !== '' || $category !== ''
                    ? 'Nothing matches that. Forms you have not been given permission to read are not counted here either.'
                    : 'A form is anything your company currently fills in on paper. Build one here and it becomes a record with a reference number, an owner and an approval route.' }}">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('forms.create') }}" class="btn btn-primary btn-sm">Build the first one</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Form</th>
                        <th>Group</th>
                        <th>State</th>
                        <th class="text-right">Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forms as $row)
                        @php
                            // Eager-loaded on the controller's query, so reading them per row costs
                            // nothing (§89).
                            $live = $row->publishedVersion;
                            $draft = $row->draftVersion;
                        @endphp

                        <tr>
                            <td>
                                <a href="{{ route('forms.show', $row) }}"
                                   class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $row->name }}</a>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $row->key }}</span>
                                @if ($row->description)
                                    <span class="mt-1 block max-w-xl text-xs text-[var(--text-muted)]">{{ $row->description }}</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-[var(--text-body)]">{{ $row->category ?? '—' }}</td>

                            <td class="whitespace-nowrap">
                                @if (! $row->is_active)
                                    <x-badge tone="neutral">Out of service</x-badge>
                                @elseif ($live)
                                    <x-badge tone="success">Live &middot; v{{ $live->version }}</x-badge>
                                @else
                                    <x-badge tone="warning">Never published</x-badge>
                                @endif

                                @if ($draft)
                                    <span class="mt-1 block">
                                        <x-badge tone="brand">Draft v{{ $draft->version }} open</x-badge>
                                    </span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                {{ $row->submissions_count }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($forms->hasPages())
                <div class="border-t border-[var(--surface-border)] p-4">{{ $forms->links() }}</div>
            @endif
        @endif
    </div>

</x-layouts.app>
