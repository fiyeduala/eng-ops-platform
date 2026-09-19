{{--
    Everything filled in that this person may see.

    THE LIST IS ALREADY NARROWED BEFORE A FILTER IS APPLIED. The access engine decides what is
    in the query; these controls only narrow it further, and a submission nobody may see cannot
    be revealed by guessing a number in the search box (§61).

    Expects: $submissions, $forms, $statuses, $filters, $projects, $canCreate
--}}
<x-layouts.app title="Filled-in forms">

    <x-page-header title="Filled-in forms"
                   description="Inspections, checklists and sign-in sheets completed against the forms this company built.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('form-submissions.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Fill in a form
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 border-l-4 border-l-[var(--tone-success)] p-4 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <form method="GET" action="{{ route('form-submissions.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-4">
            <div>
                <label class="label" for="q">Search</label>
                <input type="search" id="q" name="q" class="input" value="{{ $filters['q'] }}"
                       placeholder="Reference number">
                <p class="help">Numbers only — what people wrote is not searchable.</p>
            </div>

            <div>
                <label class="label" for="form">Form</label>
                <select id="form" name="form" class="select">
                    <option value="">Any form</option>
                    @foreach ($forms as $id => $name)
                        <option value="{{ $id }}" @selected($filters['form'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="status">Status</label>
                <select id="status" name="status" class="select">
                    <option value="">Any status</option>
                    @foreach ($statuses as $id => $label)
                        <option value="{{ $id }}" @selected($filters['status'] === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="project">Project</label>
                <select id="project" name="project" class="select">
                    <option value="">Any project</option>
                    @foreach ($projects as $id => $name)
                        <option value="{{ $id }}" @selected($filters['project'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                <input type="checkbox" name="view" value="mine" @checked($filters['view'] === 'mine')>
                Only the ones I started
            </label>
            <div class="flex items-center gap-2">
                <a href="{{ route('form-submissions.index') }}" class="btn btn-ghost btn-sm">Clear</a>
                <button type="submit" class="btn btn-secondary btn-sm">
                    <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
                </button>
            </div>
        </div>
    </form>

    <div class="card">
        @if ($submissions->isEmpty())
            <x-empty-state title="Nothing here"
                           description="No filled-in forms match this — and a list that is
                                        permission-filtered can be empty because there is nothing
                                        to show you, not only because nothing exists."/>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Form</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($submissions as $submission)
                        <tr>
                            <td>
                                <a href="{{ route('form-submissions.show', $submission) }}"
                                   class="font-mono text-sm font-medium">{{ $submission->number ?? '—' }}</a>
                            </td>
                            <td>{{ $submission->form?->name ?? '—' }}</td>
                            <td>{{ $submission->project?->name ?? '—' }}</td>
                            <td>
                                <x-badge :tone="$submission->status?->color ?? 'neutral'">
                                    {{ $submission->status?->label ?? 'No status' }}
                                </x-badge>
                            </td>
                            <td class="text-sm text-[var(--text-muted)]">
                                @if ($submission->isSubmitted())
                                    {{ $submission->submitted_at?->format('j M Y') }}
                                    by {{ $submission->submitter?->name ?? 'somebody' }}
                                @else
                                    Not submitted
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($submissions->hasPages())
        <div class="mt-4">{{ $submissions->links() }}</div>
    @endif

</x-layouts.app>
