<x-layouts.app title="My reviews">
    <x-page-header title="My reviews" description="Your own appraisals, and the ones waiting for you to write."/>

    {{--
        Two lists, not one filtered table: being reviewed and reviewing somebody are different jobs
        with different deadlines, and a manager who is behind on four of their team's reviews should
        not have to find that out among their own.
    --}}
    <div class="space-y-8">
        <div>
            <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Waiting for you</h2>
            @if ($toReview->isEmpty())
                <x-empty-state icon="check" title="Nothing to write"
                               description="No review has been assigned to you. If you expected one, the round may not have been opened yet."/>
            @else
                <div class="card overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr><th class="text-left">Person</th><th class="text-left">Cycle</th><th class="text-left">Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($toReview as $review)
                                <tr>
                                    <td class="font-medium text-[var(--text-strong)]">{{ $review->employee?->fullName() ?? '—' }}</td>
                                    <td>{{ $review->cycle?->name ?? '—' }}</td>
                                    <td>@if ($review->status)<x-badge :tone="$review->status->tone()">{{ $review->status->label }}</x-badge>@endif</td>
                                    <td class="text-right"><a href="{{ route('performance-reviews.show', $review) }}" class="btn btn-ghost btn-sm">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Your own</h2>
            @if ($mine->isEmpty())
                <x-empty-state icon="star" title="No appraisals yet"
                               description="Nothing has been raised about you. Reviews appear here when a round is opened for your department."/>
            @else
                <div class="card overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr><th class="text-left">Cycle</th><th class="text-left">Reviewer</th><th class="text-left">Overall</th><th class="text-left">Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($mine as $review)
                                <tr>
                                    <td class="font-medium text-[var(--text-strong)]">{{ $review->cycle?->name ?? '—' }}</td>
                                    <td>{{ $review->reviewer?->name ?? '—' }}</td>
                                    <td>{{ $review->overall_rating !== null ? rtrim(rtrim((string) $review->overall_rating, '0'), '.') : '—' }}</td>
                                    <td>@if ($review->status)<x-badge :tone="$review->status->tone()">{{ $review->status->label }}</x-badge>@endif</td>
                                    <td class="text-right"><a href="{{ route('performance-reviews.show', $review) }}" class="btn btn-ghost btn-sm">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
