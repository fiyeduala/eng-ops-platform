<x-layouts.app title="Review cycles">
    <x-page-header title="Review cycles" description="Appraisal rounds — the period they cover, the scale they use and the questions they ask.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('review-cycles.create') }}" class="btn btn-primary btn-sm">New cycle</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'Current', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('review-cycles.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <x-register.filters route="review-cycles.index" :filters="$filters" :statuses="$statuses" placeholder="Name"/>
    <x-register.table :records="$cycles" icon="star" title="No review cycles"
                      description="No appraisal round has been set up yet. A cycle says what period is being reviewed and what people are asked.">
        <x-slot:head><tr><th>Name</th><th>Period</th><th>Scale</th><th>Questions</th><th>Reviews</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($cycles as $cycle)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('review-cycles.show', $cycle) }}">{{ $cycle->name }}</a></td>
                    <td class="whitespace-nowrap">{{ $cycle->period_start?->format('j M Y') }} – {{ $cycle->period_end?->format('j M Y') }}</td>
                    <td class="whitespace-nowrap">{{ $cycle->scaleLabel() }}</td>
                    <td>{{ $cycle->competencies_count }}</td>
                    <td>{{ $cycle->reviews_count }}</td>
                    <td>@if ($cycle->status)<x-badge :tone="$cycle->status->tone()">{{ $cycle->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
