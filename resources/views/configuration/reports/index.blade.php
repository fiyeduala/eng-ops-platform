{{--
    The questions this company asks of its own records.

    Expects: $reports, $entities, $canCreate, $canEdit

    WHAT THIS LIST SHOWS IS DEFINITIONS, NOT ROWS. Every report here can be opened by anybody who
    holds `report.view`, and each will answer with the records that person is permitted to see —
    which for somebody with no access to the register underneath is none at all. That is said out
    loud on the page, because "why is Tunde's copy of this report longer than mine" is otherwise
    filed as a bug rather than understood as the system working.
--}}
<x-layouts.app title="Reports">

    <x-page-header
        title="Reports"
        description="Questions you want answered the same way every time. Each one is built once and answers for whoever runs it, over the records they are allowed to see.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('configuration.reports.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New report
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
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That report was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p>
            A report lists the records <span class="font-medium">you</span> are allowed to see.
            Somebody with a wider view of the company will get more rows from the same report —
            nothing here is a company-wide list unless your own access is.
        </p>
    </div>

    @if ($reports->isEmpty())
        <div class="card p-6 text-sm text-[var(--text-muted)]">
            <p class="mb-2 font-medium text-[var(--text-strong)]">No reports yet.</p>
            <p class="max-w-2xl">
                A report is a question with an answer you want to be able to reproduce: which
                deliverables are overdue, which RFIs are still open, what went out to a client last
                month. Build it once, and everybody reads their own part of the answer.
            </p>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Lists</th>
                        <th>Built by</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td>
                                <a href="{{ route('configuration.reports.show', $report) }}"
                                   class="font-medium text-[var(--text-strong)] hover:underline">
                                    {{ $report->name }}
                                </a>
                                @unless ($report->is_active)
                                    {{-- The same words the measures list and the renewals list use
                                         for the same state. Three configuration screens spelling one
                                         idea three ways leaves somebody wondering which they read. --}}
                                    <x-badge tone="warning">Switched off</x-badge>
                                @endunless
                                @if ($report->description)
                                    <p class="help mt-0.5 max-w-xl">{{ $report->description }}</p>
                                @endif
                            </td>
                            <td class="text-sm text-[var(--text-body)]">
                                {{ $entities[$report->entity_type] ?? $report->entity_type }}
                                <p class="help mt-0.5">
                                    {{ count($report->selectedColumns()) }}
                                    {{ \Illuminate\Support\Str::plural('column', count($report->selectedColumns())) }}@if (count($report->baseFilters()) > 0),
                                        {{ count($report->baseFilters()) }}
                                        {{ \Illuminate\Support\Str::plural('condition', count($report->baseFilters())) }}@endif
                                </p>
                            </td>
                            <td class="text-sm text-[var(--text-muted)]">
                                {{ $report->author?->name ?? '—' }}
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('configuration.reports.show', $report) }}"
                                       class="btn btn-secondary btn-sm">Run</a>

                                    @if ($canEdit)
                                        <a href="{{ route('configuration.reports.edit', $report) }}"
                                           class="btn btn-ghost btn-sm">Edit</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-layouts.app>
