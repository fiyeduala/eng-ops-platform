<x-layouts.app :title="$cycle->name">
    <x-page-header :title="$cycle->name" :description="$cycle->description ?: 'Appraisal round'">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('review-cycles.edit', $cycle) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">What this round asks</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    Copied onto each review as it is raised. A review already open keeps the wording it was raised with.
                </p>
                @if ($cycle->competencies->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">No lines yet. A round with no questions opens reviews with nothing to score.</p>
                @else
                    <table class="mt-3 min-w-full text-sm">
                        <thead><tr><th class="text-left">Line</th><th class="text-left">Kind</th><th class="text-left">Weight</th><th class="text-left">Asked of</th></tr></thead>
                        <tbody>
                            @foreach ($cycle->competencies as $competency)
                                <tr>
                                    <td class="font-medium text-[var(--text-strong)]">
                                        {{ $competency->label }}
                                        @if ($competency->description)
                                            <span class="block text-xs font-normal text-[var(--text-muted)]">{{ $competency->description }}</span>
                                        @endif
                                    </td>
                                    <td class="capitalize">{{ $competency->kind }}</td>
                                    <td>{{ rtrim(rtrim((string) $competency->weight, '0'), '.') }}</td>
                                    <td>
                                        @if ($competency->position)
                                            {{ $competency->position->title }}
                                        @elseif ($competency->orgUnit)
                                            {{ $competency->orgUnit->name }}
                                        @else
                                            <span class="text-[var(--text-muted)]">Everybody</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($cycle->instructions)
                <div class="card p-5">
                    <h2 class="card-title">Instructions to reviewers</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $cycle->instructions }}</p>
                </div>
            @endif

            {{--
                Everybody's appraisals are NOT on this page. The controller runs the compiler over
                this cycle's reviews, so a manager sees their team's and a general employee sees
                their own — opening a round must not become a way to read the company's (§61).
            --}}
            <div class="card p-5">
                <h2 class="card-title">Reviews in this round</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">Only the ones you are entitled to see. An empty list here does not mean the round is empty.</p>
                @if ($reviews->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">Nothing to show you.</p>
                @else
                    <table class="mt-3 min-w-full text-sm">
                        <thead><tr><th class="text-left">Number</th><th class="text-left">Person</th><th class="text-left">Reviewer</th><th class="text-left">Status</th></tr></thead>
                        <tbody>
                            @foreach ($reviews as $review)
                                <tr>
                                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('performance-reviews.show', $review) }}">{{ $review->number }}</a></td>
                                    <td>{{ $review->employee?->fullName() ?? '—' }}</td>
                                    <td>{{ $review->reviewer?->name ?? '—' }}</td>
                                    <td>@if ($review->status)<x-badge :tone="$review->status->tone()">{{ $review->status->label }}</x-badge>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="mt-4">{{ $reviews->links() }}</div>
                @endif
            </div>

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this cycle."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$cycle->status?->label"/>
                    <x-register.fact label="Period" :value="$cycle->period_start?->format('j M Y').' – '.$cycle->period_end?->format('j M Y')"/>
                    <x-register.fact label="Scale" :value="$cycle->scaleLabel()"/>
                    <x-register.fact label="Opens on" :value="$cycle->opens_on?->format('j M Y')"/>
                    <x-register.fact label="Closes on" :value="$cycle->closes_on?->format('j M Y')"/>
                    <x-register.fact label="Questions" :value="(string) $cycle->competencies->count()"/>
                </dl>
            </div>

            {{--
                THE OPEN BUTTON RAISES REAL WORK FOR REAL PEOPLE. Leaving the departments blank
                opens a review for everybody in scope; picking some opens them for those only, which
                is how engineering is appraised in June and everybody else in September. Pressing it
                twice is safe — the service skips anybody who already has a review in this round.
            --}}
            @if ($canOpen && $cycle->isOpen() === false && ! $cycle->isClosed())
                <form method="POST" action="{{ route('review-cycles.open', $cycle) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Open the round</h2>
                    <p class="text-xs text-[var(--text-muted)]">Raises a review for each active person covered, and tells their manager.</p>
                    <div>
                        <label class="label" for="org_units">Departments (leave empty for all)</label>
                        <select name="org_units[]" id="org_units" multiple size="6" class="input">
                            @foreach ($units as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm w-full" onclick="return confirm('This raises a review for everybody covered and notifies their managers. Continue?')">Open reviews</button>
                </form>
            @elseif ($canOpen && $cycle->isOpen())
                {{-- Still offered while the round is open: people join the company mid-round, and a
                     second press only raises the reviews that are missing. --}}
                <form method="POST" action="{{ route('review-cycles.open', $cycle) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Raise any missing reviews</h2>
                    <p class="text-xs text-[var(--text-muted)]">For people who joined, or departments left out the first time. Nobody gets a second review.</p>
                    <div>
                        <label class="label" for="org_units">Departments (leave empty for all)</label>
                        <select name="org_units[]" id="org_units" multiple size="6" class="input">
                            @foreach ($units as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-secondary btn-sm w-full">Raise missing reviews</button>
                </form>
            @endif

            @if (($canClose || $canCancel) && ! $cycle->isClosed())
                <div class="card space-y-4 p-5">
                    @if ($canClose)
                        <form method="POST" action="{{ route('review-cycles.close', $cycle) }}">@csrf
                            <button class="btn btn-secondary btn-sm w-full" onclick="return confirm('Close the round? Reviews still unfinished stay as they are.')">Close the round</button>
                        </form>
                    @endif
                    @if ($canCancel)
                        <form method="POST" action="{{ route('review-cycles.cancel', $cycle) }}" class="space-y-2 border-t border-[var(--border)] pt-4">@csrf
                            <x-textarea name="reason" rows="2" placeholder="Why is this round not happening?"></x-textarea>
                            <button class="btn btn-ghost btn-sm w-full">Cancel the round</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($canDelete)
                <div class="card p-5">
                    <x-delete-form :action="route('review-cycles.destroy', $cycle)" label="Delete cycle"
                                   confirm="Delete this cycle? Reviews raised from it are not deleted."/>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
