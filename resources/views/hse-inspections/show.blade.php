{{--
    One inspection.

    THE PAGE IS DIFFERENT BEFORE AND AFTER THE WALK, and deliberately so. Before: the checklist as a
    set of questions with a form to answer them. After: the answers, read-only, with the failures
    pulled to the top. Recording is a one-way act — the service refuses a second `perform` — because
    an inspection whose findings can be re-entered is worth nothing as evidence (§59).

    RESULTS POST KEYED BY ITEM ID and the service writes only to rows belonging to this inspection.
    A posted id from somebody else's inspection writes nothing at all, rather than erroring in a way
    that would confirm the record exists (§84, §94).

    Expects: $inspection, $timeline, $transitions, $workflowInstance, $itemResults, $canEdit,
             $canDelete, $canPerform, $canClose, $canRaiseAction, $actionTypes, $actionPriorities,
             $people
--}}
<x-layouts.app :title="$inspection->displayName()">

    <x-page-header :title="$inspection->title" :description="$inspection->number">
        <x-slot:actions>
            @if ($canEdit)
                @unless ($inspection->wasPerformed())
                    <a href="{{ route('hse-inspections.items.edit', $inspection) }}" class="btn btn-secondary btn-sm">
                        <x-icon name="check-square" class="h-3.5 w-3.5"/> Checklist
                    </a>
                @endunless
                <a href="{{ route('hse-inspections.edit', $inspection) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('hse-inspections.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('perform') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('close') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            @if ($inspection->wasPerformed())
                @php
                    $percent = $inspection->compliancePercent();
                    $failed = $inspection->failedItemCount();
                @endphp
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What the walk found</h3>
                        @if ($percent !== null)
                            <x-badge :tone="match (true) {
                                $percent >= 95 => 'success',
                                $percent >= 80 => 'warning',
                                default => 'danger',
                            }">{{ $percent }}% compliant</x-badge>
                        @endif
                    </div>
                    <div class="space-y-5 p-5">
                        {{-- The count of failures sits beside the percentage rather than behind it.
                             94% reads as excellent right up until the 6% is the missing edge
                             protection. --}}
                        @if ($failed > 0)
                            <p class="text-sm font-medium text-rose-700 dark:text-rose-300">
                                {{ $failed }} {{ Str::plural('item', $failed) }} needing action.
                            </p>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">Nothing was marked as needing action.</p>
                        @endif

                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                            {{ $inspection->summary ?: 'No summary was written.' }}
                        </p>
                    </div>
                </div>
            @endif

            {{-- The checklist. Before the walk these are questions with a form around them; after
                 it they are answers with no form at all, because `perform` is refused a second
                 time and a form that cannot submit is a lie about what the page can do. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        {{ $inspection->wasPerformed() ? 'The checklist, as answered' : 'The checklist' }}
                    </h3>
                    <span class="text-sm text-[var(--text-muted)]">
                        {{ $inspection->items->count() }} {{ Str::plural('item', $inspection->items->count()) }}
                    </span>
                </div>

                @if ($inspection->items->isEmpty())
                    <div class="p-5">
                        <x-empty-state icon="check-square" title="No checklist yet"
                                       description="An inspection with no questions cannot be walked. Set the checklist before the walk happens — afterwards it is frozen."/>
                        @if ($canEdit)
                            <div class="mt-4">
                                <a href="{{ route('hse-inspections.items.edit', $inspection) }}"
                                   class="btn btn-primary btn-sm">Set the checklist</a>
                            </div>
                        @endif
                    </div>
                @elseif ($inspection->wasPerformed())
                    <div class="divide-y divide-[var(--surface-border)]">
                        @foreach ($inspection->items as $item)
                            <div class="px-5 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--text-strong)]">
                                            @if ($item->reference)
                                                <span class="font-mono text-xs text-[var(--text-muted)]">{{ $item->reference }}</span>
                                            @endif
                                            {{ $item->description }}
                                        </p>
                                        @if ($item->category)
                                            <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $item->category }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($item->action_required)
                                            <x-badge tone="danger">Action required</x-badge>
                                        @endif
                                        @if ($item->result)
                                            <x-badge tone="neutral">{{ $item->result }}</x-badge>
                                        @endif
                                        @if ($item->isScored())
                                            <span class="font-mono text-sm text-[var(--text-muted)]">
                                                {{ rtrim(rtrim(number_format((float) $item->score_achieved, 1), '0'), '.') }}/{{ rtrim(rtrim(number_format((float) $item->score_possible, 1), '0'), '.') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                @if ($item->comment)
                                    <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">
                                        {{ $item->comment }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif ($canPerform)
                    <form method="POST" action="{{ route('hse-inspections.perform', $inspection) }}">
                        @csrf
                        <div class="divide-y divide-[var(--surface-border)]">
                            @foreach ($inspection->items as $item)
                                <div class="space-y-3 px-5 py-4">
                                    <p class="text-sm font-medium text-[var(--text-strong)]">
                                        @if ($item->reference)
                                            <span class="font-mono text-xs text-[var(--text-muted)]">{{ $item->reference }}</span>
                                        @endif
                                        {{ $item->description }}
                                        @if ($item->isScored())
                                            <span class="text-xs font-normal text-[var(--text-muted)]">
                                                (out of {{ rtrim(rtrim(number_format((float) $item->score_possible, 1), '0'), '.') }})
                                            </span>
                                        @endif
                                    </p>

                                    <div class="grid gap-3 sm:grid-cols-4">
                                        <x-select name="results[{{ $item->id }}][result]"
                                                  :options="$itemResults" placeholder="No answer"/>

                                        <input type="text" class="input" maxlength="40"
                                               name="results[{{ $item->id }}][severity]"
                                               placeholder="Severity">

                                        @if ($item->isScored())
                                            <input type="number" class="input" min="0" step="0.5"
                                                   max="{{ (float) $item->score_possible }}"
                                                   name="results[{{ $item->id }}][score_achieved]"
                                                   placeholder="Score">
                                        @else
                                            <span></span>
                                        @endif

                                        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                            <input type="hidden" name="results[{{ $item->id }}][action_required]" value="0">
                                            <input type="checkbox" class="checkbox"
                                                   name="results[{{ $item->id }}][action_required]" value="1">
                                            Needs action
                                        </label>
                                    </div>

                                    <textarea class="input" rows="2" maxlength="5000"
                                              name="results[{{ $item->id }}][comment]"
                                              placeholder="What was seen"></textarea>
                                </div>
                            @endforeach
                        </div>

                        <div class="space-y-4 border-t border-[var(--surface-border)] p-5">
                            <x-field label="Summary" name="summary"
                                     help="The overall picture, in the words you would use to the site manager.">
                                <x-textarea name="summary" rows="3">{{ old('summary') }}</x-textarea>
                            </x-field>

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit" class="btn btn-primary">Record what was found</button>
                                <span class="text-sm text-[var(--text-muted)]">
                                    This can only be done once. The checklist locks with it.
                                </span>
                            </div>
                        </div>
                    </form>
                @else
                    <div class="divide-y divide-[var(--surface-border)]">
                        @foreach ($inspection->items as $item)
                            <div class="px-5 py-4 text-sm text-[var(--text-body)]">
                                @if ($item->reference)
                                    <span class="font-mono text-xs text-[var(--text-muted)]">{{ $item->reference }}</span>
                                @endif
                                {{ $item->description }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @include('corrective-actions.partials.attached', [
                'record' => $inspection,
                'route' => route('hse-inspections.actions.store', $inspection),
            ])

            @if ($canClose && $inspection->wasPerformed() && $inspection->closed_at === null)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Close it out</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Closing says the findings have been dealt with. Any corrective action
                            raised above outlives it — closing the inspection does not close them.
                        </p>
                        <form method="POST" action="{{ route('hse-inspections.close', $inspection) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Close this inspection</button>
                        </form>
                    </div>
                </div>
            @endif

            <x-register.transitions :action="route('hse-inspections.transition', $inspection)"
                                    :transitions="$transitions" noun="inspection"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this inspection yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$inspection->number" mono/>
                    <x-register.fact label="Status">
                        @if ($inspection->status)
                            <x-badge :tone="$inspection->status->tone()">{{ $inspection->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Type" :value="$inspection->inspection_type"/>
                    <x-register.fact label="Where" :value="$inspection->location"/>
                    <x-register.fact label="Scheduled for"
                                     :value="$inspection->scheduled_for?->format('j M Y')"/>
                    <x-register.fact label="Carried out"
                                     :value="$inspection->performed_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Inspector" :value="$inspection->inspector?->name"/>
                    <x-register.fact label="Accompanied by" :value="$inspection->accompanied_by"/>
                    <x-register.fact label="Project" :value="$inspection->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$inspection->discipline?->name"/>
                    <x-register.fact label="Department" :value="$inspection->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality"
                                     :value="$inspection->confidentiality?->label()"/>
                    <x-register.fact label="Closed" :value="$inspection->closed_at?->format('j M Y')"/>
                    <x-register.fact label="Closed by" :value="$inspection->closer?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The checklist and its answers go with it. An inspection that was actually
                            walked is evidence and should be kept even when the score is bad.
                        </p>
                        <x-delete-form :action="route('hse-inspections.destroy', $inspection)"
                                       label="Delete this inspection"
                                       confirm="Delete it and its findings?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$inspection" class="card mt-5"/>

</x-layouts.app>
