{{--
    One quality inspection.

    ONE VERDICT, AND THEN THE RECORD IS TESTIMONY. The inspection form appears exactly once — while
    the inspection is outstanding and this viewer may carry it out — and never again. It is not
    hidden after the fact to keep the page tidy; the service refuses a second verdict outright, and
    a form that would be rejected is worse than no form at all.

    REQUESTING IS BROAD, INSPECTING IS NARROW. Almost anybody on a site can call for an inspection;
    far fewer can pass one, and the person who asked for it can never be the person who passes it.
    That last rule lives in the service and is stated here in words rather than enforced by hiding a
    button, because somebody who cannot see why the form is missing will go and ask for a permission
    they should not have.

    A REJECTION IS NOT AN NCR. Failing an inspection means the work was looked at and did not pass;
    raising a non-conformance is a formal, costed, dispositioned act against a named party. The
    button to raise one sits below the verdict rather than inside it, because somebody has to decide.

    Expects: $inspection, $timeline, $transitions, $workflowInstance, $results, $itemResults,
             $types, $canEdit, $canDelete, $canInspect, $canRaiseNcr
--}}
<x-layouts.app :title="$inspection->displayName()">

    <x-page-header :title="$inspection->number" :description="$inspection->title">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('quality-inspections.items.edit', $inspection) }}" class="btn btn-secondary btn-sm">
                    Acceptance criteria
                </a>
                <a href="{{ route('quality-inspections.edit', $inspection) }}" class="btn btn-ghost btn-sm">
                    Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('result') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('ncr') <p class="error mb-4">{{ $message }}</p> @enderror

    {{-- One banner, chosen by which fact overrides which. A rejected inspection outranks everything;
         after that, whether it has been looked at at all; after that, whether it is late. --}}
    @if ($inspection->wasInspected() && $inspection->result === 'rejected')
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-200">Rejected</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">
                Inspected on {{ $inspection->inspected_at?->format('j M Y H:i') }}
                @if ($inspection->inspector) by {{ $inspection->inspector->name }} @endif
                and not accepted. The work behind this inspection has not been signed off.
            </p>
        </div>
    @elseif ($inspection->wasInspected())
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                {{ $results[$inspection->result] ?? ($inspection->result ? Str::headline($inspection->result) : 'Inspected') }}
            </p>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                Inspected on {{ $inspection->inspected_at?->format('j M Y H:i') }}
                @if ($inspection->inspector) by {{ $inspection->inspector->name }} @endif
                @if ($inspection->responseDays() !== null)
                    — {{ $inspection->responseDays() }}
                    {{ Str::plural('day', $inspection->responseDays()) }} after it was requested
                @endif.
            </p>
        </div>
    @elseif ($inspection->required_by && $inspection->required_by->isPast())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-200">Overdue</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">
                This was required by {{ $inspection->required_by->format('j M Y') }} and nobody has
                recorded a verdict. Whatever is waiting on this sign-off is waiting still.
            </p>
        </div>
    @else
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm text-[var(--text-body)]">
                @if ($inspection->scheduled_for)
                    Awaiting inspection — scheduled for
                    {{ $inspection->scheduled_for->format('j M Y H:i') }}.
                @else
                    Awaiting inspection. No date has been offered.
                @endif
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The work</h3>
                </div>
                <div class="space-y-4 p-5">
                    @if ($inspection->description)
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $inspection->description }}</p>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">No description was given.</p>
                    @endif

                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Activity" :value="$inspection->activity"/>
                        <x-register.fact label="Where" :value="$inspection->location"/>
                        <x-register.fact label="ITP reference" :value="$inspection->itp_reference" mono/>
                        <x-register.fact label="To be witnessed by" :value="$inspection->witnessed_by"/>
                    </dl>
                </div>
            </div>

            {{-- The criteria and, once there is a verdict, what was measured against each of them.
                 The two are shown in the same table on purpose: a measurement without the criterion
                 beside it is a number, and the argument this record exists to settle is always
                 about whether that number was inside the tolerance. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Acceptance criteria</h3>
                    @if ($canEdit && ! $inspection->wasInspected())
                        <a href="{{ route('quality-inspections.items.edit', $inspection) }}" class="btn btn-ghost btn-sm">
                            Edit the criteria
                        </a>
                    @endif
                </div>

                @if ($inspection->items->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-amber-600">
                            No acceptance criteria have been set.
                            @if ($canEdit && ! $inspection->wasInspected())
                                An inspection with nothing to measure against produces an opinion —
                                <a href="{{ route('quality-inspections.items.edit', $inspection) }}" class="link">set them first</a>.
                            @else
                                Whatever verdict is recorded will rest on the inspector's judgement alone.
                            @endif
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                <tr>
                                    <th class="w-20 px-4 py-3">Ref</th>
                                    <th class="px-4 py-3">Checkpoint</th>
                                    <th class="px-4 py-3">Criteria</th>
                                    <th class="w-48 px-4 py-3">Found</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($inspection->items as $item)
                                    <tr class="align-top">
                                        <td class="px-4 py-3 font-mono text-xs text-[var(--text-muted)]">
                                            {{ $item->reference ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-[var(--text-body)]">{{ $item->description }}</td>
                                        <td class="px-4 py-3 text-[var(--text-muted)]">
                                            {{ $item->acceptance_criteria ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($item->result)
                                                <span @class([
                                                    'font-medium text-rose-600' => $item->hasFailed(),
                                                    'text-[var(--text-body)]' => ! $item->hasFailed(),
                                                ])>{{ $itemResults[$item->result] ?? Str::headline($item->result) }}</span>
                                                @if ($item->measured_value)
                                                    <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                        {{ $item->measured_value }}
                                                    </span>
                                                @endif
                                                @if ($item->comment)
                                                    <span class="block text-xs text-[var(--text-muted)]">{{ $item->comment }}</span>
                                                @endif
                                            @else
                                                <span class="text-[var(--text-muted)]">Not recorded</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- THE ONE FORM ON THIS PAGE THAT WRITES A VERDICT, AND IT APPEARS ONCE. Not disabled
                 after the fact, not re-shown with the values filled in: the service refuses a second
                 verdict, and offering a form that will be refused is a lie about what the page does. --}}
            @if ($canInspect && ! $inspection->wasInspected())
                <form method="POST" action="{{ route('quality-inspections.inspect', $inspection) }}"
                      class="card" x-data="{ armed: false }"
                      @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                    @csrf

                    <div class="card-header">
                        <h3 class="card-title">Record the inspection</h3>
                    </div>

                    <div class="space-y-5 p-5">
                        @if ($inspection->items->isNotEmpty())
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                        <tr>
                                            <th class="px-3 py-2">Checkpoint</th>
                                            <th class="w-40 px-3 py-2">Result</th>
                                            <th class="w-40 px-3 py-2">Measured</th>
                                            <th class="w-64 px-3 py-2">Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[var(--surface-border)]">
                                        @foreach ($inspection->items as $item)
                                            <tr class="align-top">
                                                <td class="px-3 py-3">
                                                    <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                                    @if ($item->acceptance_criteria)
                                                        <span class="block text-xs text-[var(--text-muted)]">
                                                            {{ $item->acceptance_criteria }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3">
                                                    {{-- Left blank on purpose. A default of "accepted"
                                                         would score every line nobody looked at as a
                                                         pass, which is precisely the failure the
                                                         separate result column exists to prevent. --}}
                                                    <x-select :name="'results['.$item->id.'][result]'"
                                                              :options="$itemResults"
                                                              placeholder="Not recorded"/>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <input type="text" class="input" maxlength="120"
                                                           name="results[{{ $item->id }}][measured_value]"
                                                           placeholder="e.g. 47mm">
                                                </td>
                                                <td class="px-3 py-3">
                                                    <input type="text" class="input" maxlength="5000"
                                                           name="results[{{ $item->id }}][comment]">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Overall verdict" name="result" required>
                                <x-select name="result" :options="$results" placeholder="Choose" required/>
                            </x-field>
                        </div>

                        <x-field label="What was found" name="comments"
                                 help="Anything other than a clean pass has to say what was found — the service refuses without it.">
                            <x-textarea name="comments" rows="4">{{ old('comments') }}</x-textarea>
                        </x-field>
                    </div>

                    <div class="flex items-center gap-3 border-t border-[var(--surface-border)] p-5">
                        <button type="submit" class="btn btn-primary"
                                x-text="armed ? 'Confirm the verdict' : 'Record the inspection'">
                            Record the inspection
                        </button>
                        <span class="text-sm text-[var(--text-muted)]">
                            This is recorded once and cannot be re-entered.
                        </span>
                    </div>
                </form>
            @elseif (! $inspection->wasInspected())
                {{-- Said in words rather than left as an absence. Somebody who cannot see why the
                     form is missing goes and asks for a permission they should not be given. --}}
                <div class="card p-5">
                    <p class="text-sm text-[var(--text-muted)]">
                        You cannot record the verdict on this inspection. Either you do not hold the
                        permission, or you are the person who requested it — an inspection is never
                        carried out by the person who asked for it.
                    </p>
                </div>
            @endif

            @if ($inspection->wasInspected() && $inspection->comments)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What the inspector found</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $inspection->comments }}</p>
                    </div>
                </div>
            @endif

            {{-- A REJECTION IS NOT AN NCR AND THIS CARD IS WHERE THAT DECISION GETS MADE. Failing an
                 inspection means the work was looked at and did not pass; raising a non-conformance
                 is a formal act against a named party that carries a cost and needs a disposition.
                 Plenty of rejections are fixed the same afternoon and should never become one. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Non-conformances from this inspection</h3>
                </div>

                <div class="space-y-4 p-5">
                    @if ($inspection->ncrs->isEmpty())
                        <p class="text-sm text-[var(--text-muted)]">None raised.</p>
                    @else
                        <ul class="divide-y divide-[var(--surface-border)]">
                            @foreach ($inspection->ncrs as $ncr)
                                <li class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                    <div>
                                        <a href="{{ route('ncrs.show', $ncr) }}"
                                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                            {{ $ncr->number }}
                                        </a>
                                        <span class="block text-sm text-[var(--text-body)]">{{ $ncr->title }}</span>
                                    </div>
                                    @if ($ncr->status)
                                        <x-badge :tone="$ncr->status->tone()">{{ $ncr->status->label }}</x-badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canRaiseNcr)
                        <form method="POST" action="{{ route('quality-inspections.ncrs.store', $inspection) }}"
                              class="space-y-4 border-t border-[var(--surface-border)] pt-4">
                            @csrf

                            <x-field label="Title" name="title" required>
                                <x-input name="title" :value="old('title')" required
                                         :placeholder="'Non-conformance found at '.$inspection->number"/>
                            </x-field>

                            {{-- Required, and the description is not. "The welds are bad" is an
                                 opinion; the clause it breaches is the finding, and only the second
                                 can be dispositioned or defended two years later. --}}
                            <x-field label="Requirement breached" name="requirement" required
                                     help="The specification, drawing or standard clause, and what was measured against it.">
                                <x-textarea name="requirement" rows="3" required>{{ old('requirement') }}</x-textarea>
                            </x-field>

                            <x-field label="What was found" name="description">
                                <x-textarea name="description" rows="3">{{ old('description') }}</x-textarea>
                            </x-field>

                            {{-- Defaults to this inspection's own classification. An NCR quotes the
                                 inspection's number and title back, so opening it up wider than the
                                 record it came from would be a disclosure by the back door (§68). --}}
                            <x-field label="Confidentiality" name="confidentiality" required>
                                <x-select name="confidentiality"
                                          :options="\App\Support\Enums\Confidentiality::options()"
                                          :selected="old('confidentiality', $inspection->confidentiality?->value)"
                                          required/>
                            </x-field>

                            <div class="flex items-center gap-3">
                                <button type="submit" class="btn btn-secondary">Raise a non-conformance</button>
                                <span class="text-sm text-[var(--text-muted)]">
                                    The project, discipline and unit are taken from this inspection.
                                </span>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Number" :value="$inspection->number" mono/>
                        <x-register.fact label="Type"
                                         :value="$types[$inspection->inspection_type] ?? ($inspection->inspection_type ? Str::headline($inspection->inspection_type) : null)"/>
                        <x-register.fact label="Status">
                            @if ($inspection->status)
                                <x-badge :tone="$inspection->status->tone()">{{ $inspection->status->label }}</x-badge>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Project" :value="$inspection->project?->displayName()"/>
                        <x-register.fact label="Discipline" :value="$inspection->discipline?->name"/>
                        <x-register.fact label="Owning unit" :value="$inspection->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality">
                            <x-badge :tone="'neutral'">{{ $inspection->confidentiality?->label() ?? '—' }}</x-badge>
                        </x-register.fact>
                        <x-register.fact label="Requested by" :value="$inspection->creator?->name"/>
                        <x-register.fact label="Scheduled for"
                                         :value="$inspection->scheduled_for?->format('j M Y H:i')"/>
                        <x-register.fact label="Required by"
                                         :value="$inspection->required_by?->format('j M Y')"/>
                        <x-register.fact label="Inspected"
                                         :value="$inspection->inspected_at?->format('j M Y H:i')"/>
                        <x-register.fact label="Inspected by" :value="$inspection->inspector?->name"/>
                    </dl>
                </div>
            </div>

            <x-register.transitions :action="route('quality-inspections.transition', $inspection)"
                                    :transitions="$transitions"
                                    noun="inspection"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this inspection yet."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            @if ($inspection->wasInspected())
                                This inspection has a recorded verdict. Deleting it removes the
                                evidence that the work was ever looked at — and any non-conformance
                                raised from it will lose the record it came from.
                            @else
                                Deleting removes the request and its acceptance criteria. Whatever is
                                waiting on this sign-off will still be waiting.
                            @endif
                        </p>
                        <x-delete-form :action="route('quality-inspections.destroy', $inspection)"
                                       label="Delete this inspection"
                                       :confirm="'Delete '.$inspection->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$inspection" class="card mt-5"/>

</x-layouts.app>
