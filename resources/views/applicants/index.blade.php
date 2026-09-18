<x-layouts.app title="Applicants">
    <x-page-header title="Applicants" description="People who applied, and where each one got to. Applications are restricted by default."/>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['live' => 'Still in play', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('applicants.index', ['view' => $key, 'vacancy' => $filters['vacancy']]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>
    <form method="GET" action="{{ route('applicants.index') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
        <x-field label="Search" name="q" class="min-w-48 flex-1">
            {{-- Number and name only. Email, phone and notes are deliberately not searchable, so a
                 contact list cannot be dragged out of a search box one letter at a time (§61). --}}
            <x-input name="q" :value="$filters['q']" placeholder="Number or name"/>
        </x-field>
        <x-field label="Vacancy" name="vacancy" class="min-w-56">
            <x-select name="vacancy" :options="$vacancies" :selected="$filters['vacancy']" placeholder="Any vacancy"/>
        </x-field>
        <x-field label="Status" name="status" class="min-w-40">
            <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
        </x-field>
        <button class="btn btn-secondary btn-sm">Filter</button>
        <a href="{{ route('applicants.index') }}" class="btn btn-ghost btn-sm">Clear</a>
    </form>
    <x-register.table :records="$applicants" icon="user" title="No applicants" description="No application within your scope matches these filters.">
        <x-slot:head><tr><th>Number</th><th>Name</th><th>Vacancy</th><th>Source</th><th>Applied</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($applicants as $applicant)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('applicants.show', $applicant) }}">{{ $applicant->number }}</a></td>
                    <td>{{ $applicant->fullName() }}</td>
                    <td>{{ $applicant->vacancy?->title ?? '—' }}</td>
                    <td>{{ $applicant->source ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $applicant->created_at?->format('j M Y') }}</td>
                    <td>@if ($applicant->status)<x-badge :tone="$applicant->status->tone()">{{ $applicant->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
