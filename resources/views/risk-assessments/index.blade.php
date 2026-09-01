{{--
    Risk assessments.

    IT OPENS ON "APPROVED AND CURRENT" because that is the only version anybody is allowed to work
    to. A draft assessment on a wall is worse than none: it looks like a control and is not one.

    LAPSE DATE IS THE SORT. An assessment nobody has revisited for three years describes a job that
    no longer exists, and the register's first duty is to say which ones those are.

    THE RESIDUAL SCORE IS THE COLUMN, NOT THE INITIAL ONE. Anybody can write a high initial score;
    what the reader needs is the number that is left after the controls the sheet promises. When that
    number is still high the row says so in red — the controls are not enough and somebody has to
    know before the work starts.

    Expects: $assessments, $statuses, $projects, $types, $views, $filters, $canCreate
--}}
<x-layouts.app title="Risk assessments">

    <x-page-header
        title="Risk assessments"
        description="What could go wrong, what is in place, and what is left after it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('risk-assessments.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Write an assessment
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="risk-assessments.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="risk-assessments.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or activity">
        <x-slot:extra>
            <div>
                <label for="assessment_type" class="label">Type</label>
                <x-select name="assessment_type" :options="$types"
                          :selected="$filters['assessment_type']" placeholder="Any type"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$assessments" icon="shield"
                      title="No risk assessments"
                      description="Either nothing matches these filters, or the assessments that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('risk-assessments.create') }}" class="btn btn-primary btn-sm">Write an assessment</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Activity</th>
                <th>Type</th>
                <th class="text-right">Hazards</th>
                <th>Highest residual</th>
                <th>Valid to</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($assessments as $assessment)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('risk-assessments.show', $assessment) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $assessment->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $assessment->assessed_on?->format('j M Y') ?? 'Undated' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('risk-assessments.show', $assessment) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $assessment->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($assessment->activity) {{ $assessment->activity }} @endif
                            @if ($assessment->project) · {{ $assessment->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $assessment->assessment_type ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        {{ $assessment->hazards_count }}
                    </td>

                    {{-- The band, not the raw number. A "12" means nothing without the matrix
                         behind it; "high" means the same thing to everybody who reads it. --}}
                    <td class="whitespace-nowrap">
                        @php
                            $score = $assessment->highestResidualScore();
                            $band = $assessment->highestResidualBand();
                        @endphp
                        @if ($score === null)
                            <span class="text-[var(--text-muted)]">—</span>
                        @else
                            <x-badge :tone="match ($band) { 'high' => 'danger', 'medium' => 'warning', default => 'neutral' }">
                                {{ ucfirst($band) }} ({{ $score }})
                            </x-badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($assessment->valid_to)
                            @php $lapsed = $assessment->hasExpired(); @endphp
                            <span @class([
                                'text-sm',
                                'font-medium text-rose-600' => $lapsed,
                                'text-amber-600' => ! $lapsed && $assessment->expiresWithin(30),
                                'text-[var(--text-muted)]' => ! $lapsed && ! $assessment->expiresWithin(30),
                            ])>{{ $assessment->valid_to->format('j M Y') }}</span>
                            @if ($lapsed)
                                <span class="block text-xs text-rose-600">lapsed</span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">No end date</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($assessment->status)
                            <x-badge :tone="$assessment->status->tone()">{{ $assessment->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
