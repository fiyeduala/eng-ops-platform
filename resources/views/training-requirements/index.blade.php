<x-layouts.app title="Training requirements">
    <x-page-header title="Training requirements" description="Which courses each job, each department or the whole company has to hold. The matrix reads these against the certificates on file.">
        <x-slot:actions>
            <a href="{{ route('training-requirements.matrix') }}" class="btn btn-secondary btn-sm">Matrix</a>
            @if ($canCreate)
                <a href="{{ route('training-requirements.create') }}" class="btn btn-primary btn-sm">Add requirement</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64">
            <label class="label" for="course">Course</label>
            <x-select name="course" :options="$courses" :selected="$filters['course'] ?: null" placeholder="Any course"/>
        </div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        @if ($filters['course'])<a href="{{ route('training-requirements.index') }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    <x-register.table :records="$requirements" icon="sliders" title="No requirements"
                      description="Nothing has been said about what any job requires yet.">
        <x-slot:head><tr><th>Course</th><th>Applies to</th><th>Required</th><th>Certificate valid</th><th>Grace</th><th>Active</th><th></th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($requirements as $requirement)
                <tr>
                    <td class="font-medium text-[var(--text-strong)]">{{ $requirement->course?->name ?? '—' }}</td>
                    <td>
                        @switch ($requirement->scope)
                            @case (\App\Models\TrainingRequirement::SCOPE_POSITION)
                                {{ $requirement->position?->title ?? 'A position that no longer exists' }}
                                @break
                            @case (\App\Models\TrainingRequirement::SCOPE_ORG_UNIT)
                                {{ $requirement->orgUnit?->name ?? 'A department that no longer exists' }}
                                @break
                            @default
                                Everybody
                        @endswitch
                    </td>
                    <td>{{ $requirement->is_mandatory ? 'Mandatory' : 'Recommended' }}</td>
                    {{-- Read off the COURSE, not the requirement: how long a ticket lasts is a fact
                         about the certificate, not about which job needs it. "Does not expire" is a
                         real answer here and not a blank one. --}}
                    <td>{{ $requirement->course?->validity_months ? $requirement->course->validity_months.' months' : 'Does not expire' }}</td>
                    <td>{{ $requirement->grace_days ? $requirement->grace_days.' days' : '—' }}</td>
                    <td>@if ($requirement->is_active)<x-badge tone="success">Active</x-badge>@else<x-badge tone="muted">Retired</x-badge>@endif</td>
                    <td class="text-right">
                        @can ('update', $requirement)
                            <a href="{{ route('training-requirements.edit', $requirement) }}" class="btn btn-ghost btn-sm">Edit</a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
