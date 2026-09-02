{{--
    Every quotation on one enquiry, side by side — and the screen where the award is decided.

    THIS TABLE IS SHORTER FOR SOME PEOPLE THAN OTHERS. The quotations are read through the reader's
    own scoped query, so an evaluator with narrow reach sees fewer columns rather than somebody
    else's fuller table, and is not told that anything was left out (§61).

    THE CONVERSION IS FROZEN. Foreign totals are converted at the rate stored on each quotation when
    it was recorded, never at today's rate — so this page reads the same next year as it did at the
    meeting where the decision was taken.

    CHEAPEST IS MARKED, NOT RECOMMENDED. The lowest total is highlighted because somebody will look
    for it anyway; the evaluation score sits beside it precisely so that the cheapest and the best
    can be seen to be different things.

    AWARDING IS ONE ACT OVER THE WHOLE ENQUIRY. Picking a winner marks every other quotation
    unsuccessful in the same transaction, which is why the award lives here rather than as a button
    on each quotation — half an award is worse than none.

    Expects: $rfq, $quotations, $criteria, $marks, $lowest, $baseCurrency, $canAward,
             $minimumQuotations
--}}
<x-layouts.app :title="'Comparison — '.$rfq->number">

    <x-page-header
        :title="'Comparison of '.$rfq->number"
        :description="$rfq->title">
        <x-slot:actions>
            <a href="{{ route('rfqs.show', $rfq) }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Back to the enquiry
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($quotations->isEmpty())
        <x-empty-state icon="coins" title="Nothing to compare"
                       description="No quotations have been recorded against this enquiry that your access reaches."/>
    @else
        @if ($quotations->count() < $minimumQuotations)
            {{-- Said out loud rather than enforced. Companies award single-source contracts for
                 good reasons every week, and the record should carry the fact that fewer than the
                 usual number of prices were obtained rather than the buyer working round a block. --}}
            <div class="card mb-5 border-amber-300 p-4">
                <p class="text-sm text-[var(--text-body)]">
                    <span class="font-medium text-amber-600">{{ $quotations->count() }}
                        {{ \Illuminate\Support\Str::plural('quotation', $quotations->count()) }}</span>
                    against a house rule of {{ $minimumQuotations }}. Awarding is still allowed —
                    say why in the reason, because that is the sentence somebody will read in two
                    years' time.
                </p>
            </div>
        @endif

        <div class="card mb-6 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                    <tr>
                        <th class="px-5 py-3 font-medium">Supplier</th>
                        <th class="px-3 py-3 font-medium">Quotation</th>
                        <th class="px-3 py-3 text-right font-medium">As quoted</th>
                        <th class="px-3 py-3 text-right font-medium">
                            In {{ $baseCurrency ?: 'base currency' }}
                        </th>
                        <th class="px-3 py-3 text-right font-medium">Lead</th>
                        <th class="px-3 py-3 text-right font-medium">Score</th>
                        <th class="px-3 py-3 font-medium">Holds until</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    @foreach ($quotations as $quotation)
                        <tr @class(['bg-[var(--surface-muted)]' => $quotation->id === $lowest])>
                            <td class="px-5 py-3">
                                <span class="font-medium text-[var(--text-strong)]">{{ $quotation->vendor?->name ?: '—' }}</span>
                                @if ($quotation->id === $lowest)
                                    <span class="ml-2 text-xs font-medium text-emerald-600">Lowest</span>
                                @endif
                            </td>

                            <td class="px-3 py-3">
                                <a href="{{ route('quotations.show', $quotation) }}"
                                   class="font-mono text-xs hover:text-brand-600">{{ $quotation->number }}</a>
                            </td>

                            <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-body)]">
                                {{ number_format((float) $quotation->total, 2) }}
                                <span class="text-[var(--text-muted)]">{{ $quotation->currency }}</span>
                            </td>

                            <td class="px-3 py-3 text-right font-mono text-sm text-[var(--text-strong)]">
                                {{ number_format((float) $quotation->totalInBaseCurrency(), 2) }}
                            </td>

                            <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-muted)]">
                                {{ $quotation->lead_time_days !== null ? $quotation->lead_time_days.'d' : '—' }}
                            </td>

                            <td class="px-3 py-3 text-right font-mono text-sm">
                                {{ $marks[$quotation->id] !== null ? $marks[$quotation->id] : '—' }}
                            </td>

                            <td class="px-3 py-3 text-xs">
                                @if ($quotation->valid_until)
                                    <span @class([
                                        'font-medium text-rose-600' => $quotation->hasExpired(),
                                        'text-[var(--text-muted)]' => ! $quotation->hasExpired(),
                                    ])>{{ $quotation->valid_until->format('j M Y') }}</span>
                                @else
                                    <span class="text-[var(--text-muted)]">—</span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                @if ($quotation->status)
                                    <x-badge :tone="$quotation->status->tone()">{{ $quotation->status->label }}</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (count($criteria))
            <div class="card mb-6">
                <div class="card-header">
                    <h3 class="card-title">How the panel marked them</h3>
                    <span class="text-xs text-[var(--text-muted)]">Average of every evaluator, out of ten</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                            <tr>
                                <th class="px-5 py-3 font-medium">Criterion</th>
                                @foreach ($quotations as $quotation)
                                    <th class="px-3 py-3 text-right font-medium">{{ $quotation->vendor?->name ?: $quotation->number }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            @foreach ($criteria as $key => $label)
                                <tr>
                                    <td class="px-5 py-3 text-[var(--text-body)]">{{ $label }}</td>
                                    @foreach ($quotations as $quotation)
                                        @php
                                            // Averaged over the evaluators who marked this criterion, and
                                            // normalised out of ten against the scale each of them was
                                            // given — a panel whose scale was changed mid-evaluation still
                                            // adds up, because every row carries its own max_score.
                                            $rows = $quotation->scores->where('criterion_key', $key)
                                                ->filter(fn ($s) => (float) $s->max_score > 0);
                                            $mark = $rows->isEmpty()
                                                ? null
                                                : round($rows->avg(fn ($s) => ((float) $s->score / (float) $s->max_score) * 10), 1);
                                        @endphp
                                        <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-body)]">
                                            {{ $mark !== null ? $mark : '—' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-[var(--surface-border)]">
                            <tr>
                                <td class="px-5 py-3 font-medium text-[var(--text-strong)]">Weighted</td>
                                @foreach ($quotations as $quotation)
                                    <td class="px-3 py-3 text-right font-mono text-sm font-medium text-[var(--text-strong)]">
                                        {{ $marks[$quotation->id] !== null ? $marks[$quotation->id] : '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        @if ($rfq->awarded_quotation_id)
            <div class="card p-5">
                <p class="text-sm text-[var(--text-body)]">
                    This enquiry was awarded
                    @if ($rfq->awarded_at)
                        on {{ $rfq->awarded_at->format('j M Y') }}
                    @endif
                    to
                    <span class="font-medium text-[var(--text-strong)]">{{ $quotations->firstWhere('id', $rfq->awarded_quotation_id)?->vendor?->name ?: 'the successful supplier' }}</span>.
                </p>
                @if ($rfq->award_reason)
                    <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $rfq->award_reason }}</p>
                @endif
            </div>
        @elseif ($canAward)
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Award the enquiry</h3>
                </div>

                <form method="POST" action="{{ route('rfqs.award', $rfq) }}">
                    @csrf

                    <div class="space-y-5 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            One act over the whole enquiry: the quotation you pick is accepted, every
                            other one is marked unsuccessful, and the enquiry is closed. It cannot be
                            done in halves.
                        </p>

                        <x-field label="The successful quotation" name="quotation_id" required>
                            <x-select name="quotation_id" required
                                      :options="$quotations->mapWithKeys(fn ($q) => [
                                          $q->id => ($q->vendor?->name ?: $q->number).' — '
                                              .number_format((float) $q->totalInBaseCurrency(), 2)
                                              .' '.($baseCurrency ?: ''),
                                      ])->all()"
                                      :selected="old('quotation_id')"
                                      placeholder="Choose the winner"/>
                        </x-field>

                        <x-field label="Why" name="reason"
                                 help="The sentence somebody reads in two years' time when they ask why the cheapest quotation did not win. Write it for them.">
                            <x-textarea name="reason" :value="old('reason')" rows="3"
                                        placeholder="Highest technical score and the only supplier able to deliver inside the shutdown window, at 4% above the lowest price."/>
                        </x-field>
                    </div>

                    <div class="border-t border-[var(--surface-border)] px-5 py-4">
                        <button type="submit" class="btn btn-primary">Award this enquiry</button>
                    </div>
                </form>
            </div>
        @endif
    @endif

</x-layouts.app>
