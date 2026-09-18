<x-layouts.app title="Appraisals">
    <x-page-header title="Appraisals" description="Every review you are entitled to see. Most people want My reviews instead.">
        <x-slot:actions>
            <a href="{{ route('performance-reviews.mine') }}" class="btn btn-secondary btn-sm">My reviews</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'In progress', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('performance-reviews.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <x-register.filters route="performance-reviews.index" :filters="$filters" :statuses="$statuses" placeholder="Number or name"/>

    <x-register.table :records="$reviews" icon="star" title="No reviews"
                      description="Either no round has been opened, or none of the reviews raised is yours to read. Both look the same from here, and that is deliberate.">
        <x-slot:head><tr><th>Number</th><th>Person</th><th>Cycle</th><th>Reviewer</th><th>Overall</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($reviews as $review)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('performance-reviews.show', $review) }}">{{ $review->number }}</a></td>
                    <td>{{ $review->employee?->fullName() ?? '—' }}</td>
                    <td>{{ $review->cycle?->name ?? '—' }}</td>
                    <td>{{ $review->reviewer?->name ?? '—' }}</td>
                    <td>{{ $review->overall_rating !== null ? rtrim(rtrim((string) $review->overall_rating, '0'), '.') : '—' }}</td>
                    <td>@if ($review->status)<x-badge :tone="$review->status->tone()">{{ $review->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
