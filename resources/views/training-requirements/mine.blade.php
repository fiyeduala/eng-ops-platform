<x-layouts.app title="My training">
    <x-page-header title="My training" description="What your job requires you to hold, and what the register says you hold."/>

    @if ($employee === null)
        <x-empty-state icon="user" title="No personnel record"
                       description="Your sign-in is not linked to an employee record, so there is nothing to check your training against. HR can link it."/>
    @elseif ($cells === [])
        <x-empty-state icon="badge" title="Nothing required"
                       description="No training requirement applies to your position or department. That is not the same as holding nothing — your certificates are in the training register."/>
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr><th class="text-left">Course</th><th class="text-left">Required</th><th class="text-left">State</th><th class="text-left">Certificate</th></tr></thead>
                <tbody>
                    @foreach ($cells as $cell)
                        <tr>
                            <td class="font-medium text-[var(--text-strong)]">{{ $cell['course']?->name ?? '—' }}</td>
                            <td>{{ $cell['requirement']->is_mandatory ? 'Mandatory' : 'Recommended' }}</td>
                            <td>@include('training-requirements.partials.state', ['cell' => $cell])</td>
                            <td>
                                @if ($cell['record'])
                                    {{ $cell['record']->completed_on?->format('j M Y') ?? '—' }}
                                    @if ($cell['record']->result)<span class="text-[var(--text-muted)]"> · {{ $cell['record']->result }}</span>@endif
                                @else
                                    <span class="text-[var(--text-muted)]">Nothing on file</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-[var(--text-muted)]">
            Read from the training register. If a certificate you hold is not here, it has not been entered yet — send it to HSE rather than assuming it is recorded.
        </p>
    @endif
</x-layouts.app>
