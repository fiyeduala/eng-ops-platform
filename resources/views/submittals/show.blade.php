{{--
    One package: what is in it, who is holding it, and what they said.

    THE VERDICT SITS ABOVE THE CONTENTS. When a coded package is opened, the first thing anybody
    needs is the code and the comment — not the eleven lines that were sent. When it is still out,
    the first thing anybody needs is how long it has been out. The layout follows that rather than
    the order the record was filled in.

    THE REVIEW FORM ASKS FOR TWO ANSWERS, NOT ONE: the code that goes on the stamp, and the status
    the package moves to. Nothing here derives the second from the first, because "Code B" means
    approved on one contract and revise-and-resubmit on the next, and the mapping lives in a
    document this platform has never read.

    THERE IS NO WAY TO CODE FROM THE SUBMITTING SIDE. The whole review card is behind
    `submittal.review`, the per-line stamp asks the same ability, and the edit form refuses the
    field outright — three separate refusals for one rule, because it is the rule that makes the
    register worth keeping (§84).

    Expects: $submittal, $items, $timeline, $transitions, $reviewCodes, $reviewStatuses, $typeLabel,
             $attemptNumber, $workflowInstance, $canEdit, $canDelete, $canAddItems, $canSubmit,
             $canReview, $canResubmit
--}}
<x-layouts.app :title="$submittal->displayName()">

    <x-page-header :title="$submittal->title" :description="$submittal->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('submittals.edit', $submittal) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canSubmit && ! $submittal->isReviewed())
                <form method="POST" action="{{ route('submittals.submit', $submittal) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm" @disabled($items->isEmpty())>
                        Submit for review
                    </button>
                </form>
            @endif

            @if ($canResubmit && $submittal->isReviewed())
                <form method="POST" action="{{ route('submittals.resubmit', $submittal) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">
                        Raise attempt {{ $attemptNumber + 1 }}
                    </button>
                </form>
            @endif

            <a href="{{ route('submittals.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('submit') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('resubmit') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('items') <p class="error mb-4">{{ $message }}</p> @enderror

    {{-- ---------------------------------------------------------------- where it stands --}}
    @php $left = $submittal->daysRemaining(); @endphp

    <div @class([
        'mb-6 rounded-md border px-4 py-3',
        'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30' => $submittal->isReviewed(),
        'border-rose-300 bg-rose-50 dark:bg-rose-950/30' => ! $submittal->isReviewed() && $submittal->isOverdue(),
        'border-[var(--surface-border)] bg-[var(--surface-muted)]' => ! $submittal->isReviewed() && ! $submittal->isOverdue(),
    ])>
        @if ($submittal->isReviewed())
            <p class="text-sm font-medium text-[var(--text-strong)]">
                Coded <span class="font-mono">{{ $submittal->review_code }}</span>
                by {{ $submittal->reviewer?->name ?? 'the reviewer' }}
                on {{ $submittal->reviewed_at?->format('j M Y') }}.
            </p>
            @if ($submittal->review_comment)
                <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $submittal->review_comment }}</p>
            @endif
            <p class="mt-2 text-xs text-[var(--text-muted)]">
                A coded package is closed. If the code asks for changes, raise the next attempt — it
                carries the contents forward with a number of its own and points back at this one.
            </p>
        @elseif ($submittal->submitted_on)
            <p class="text-sm font-medium text-[var(--text-strong)]">
                Out with {{ $submittal->party_name ?? 'the reviewer' }} since
                {{ $submittal->submitted_on->format('j M Y') }}.
            </p>
            @if ($left !== null)
                <p class="mt-1 text-sm text-[var(--text-body)]">
                    {{ $left < 0
                        ? 'The review was due '.abs($left).' day'.(abs($left) === 1 ? '' : 's').' ago.'
                        : ($left === 0 ? 'The review is due today.' : 'Due in '.$left.' day'.($left === 1 ? '' : 's').'.') }}
                </p>
            @endif
        @else
            <p class="text-sm font-medium text-[var(--text-strong)]">Draft — not yet submitted.</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                @if ($items->isEmpty())
                    Add the contents before submitting. A package cannot be sent with nothing on it.
                @else
                    {{ $items->count() }} item{{ $items->count() === 1 ? '' : 's' }} ready to go.
                @endif
            </p>
        @endif
    </div>

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ main column --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- ------------------------------------------------------- the review --}}
            @if ($canReview && ! $submittal->isReviewed())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Record the verdict</h3>
                    </div>

                    <form method="POST" action="{{ route('submittals.review', $submittal) }}" class="space-y-5 p-5">
                        @csrf

                        <p class="text-sm text-[var(--text-muted)]">
                            Two answers, deliberately. The code is what goes on the stamp and comes from
                            your contract's own vocabulary. The status is where the package lands in this
                            company's process. Nothing here infers one from the other.
                        </p>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-field label="Review code" name="review_code" required>
                                <x-select name="review_code" :options="$reviewCodes" required
                                          placeholder="Choose a code"/>
                            </x-field>

                            <x-field label="Move the package to" name="status_key" required>
                                <x-select name="status_key" :options="$reviewStatuses" required
                                          placeholder="Choose a status"/>
                            </x-field>
                        </div>

                        <x-field label="Comment" name="review_comment"
                                 help="What the code means in this case — the conditions attached, the clauses that failed. This is what the next attempt is written against.">
                            <x-textarea name="review_comment" rows="4">{{ old('review_comment') }}</x-textarea>
                        </x-field>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn btn-primary">Record the review</button>
                            <span class="help">This cannot be undone. A coded package is closed for good.</span>
                        </div>
                    </form>
                </div>
            @endif

            {{-- ------------------------------------------------------- contents --}}
            <div class="card">
                <div class="card-header flex items-center justify-between gap-3">
                    <h3 class="card-title">Package contents</h3>
                    @if ($canAddItems && ! $submittal->isReviewed())
                        <a href="{{ route('submittals.picker', $submittal) }}" class="btn btn-secondary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add a controlled revision
                        </a>
                    @endif
                </div>

                @if ($items->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">Nothing in the package yet.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Revision</th>
                                    <th>Qty</th>
                                    <th>Code</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    <tr>
                                        <td>
                                            <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                            @if ($item->reference)
                                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                    {{ $item->reference }}
                                                </span>
                                            @endif
                                            @if ($item->remarks)
                                                <span class="mt-1 block text-xs text-[var(--text-muted)]">{{ $item->remarks }}</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-xs">
                                            @if ($item->documentVersion?->document)
                                                <a href="{{ route('documents.show', $item->documentVersion->document) }}"
                                                   class="font-mono text-[var(--text-strong)] hover:text-brand-600">
                                                    {{ $item->documentVersion->document->number }}
                                                </a>
                                                <span class="block text-[var(--text-muted)]">
                                                    Rev {{ $item->documentVersion->revision }}
                                                </span>
                                            @else
                                                <span class="text-[var(--text-muted)]">Not registered</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-[var(--text-muted)]">{{ $item->quantity }}</td>

                                        <td class="whitespace-nowrap">
                                            @if ($item->review_code)
                                                <span class="font-mono text-xs text-[var(--text-strong)]">{{ $item->review_code }}</span>
                                            @elseif ($canReview)
                                                <form method="POST"
                                                      action="{{ route('submittals.items.code', [$submittal, $item]) }}"
                                                      class="flex items-center gap-2">
                                                    @csrf
                                                    <select name="review_code" class="select text-xs" required>
                                                        <option value="">Code…</option>
                                                        @foreach ($reviewCodes as $value => $label)
                                                            <option value="{{ $value }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="btn btn-ghost btn-sm">Stamp</button>
                                                </form>
                                            @else
                                                <span class="text-[var(--text-muted)]">—</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-right">
                                            @if ($canAddItems && ! $submittal->isReviewed())
                                                <x-delete-form
                                                    :action="route('submittals.items.destroy', [$submittal, $item])"
                                                    label="Remove"
                                                    confirm="Remove this line from the package?"/>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @error('review_code') <p class="error px-5 pb-4">{{ $message }}</p> @enderror
                @error('description') <p class="error px-5 pb-4">{{ $message }}</p> @enderror
                @error('document_version_id') <p class="error px-5 pb-4">{{ $message }}</p> @enderror

                {{-- Lines that name nothing this platform holds a file for — a physical sample, a
                     mock-up panel, a certificate that arrived as a scan. Refusing them would push
                     half of a real package into an email, which is the failure this register exists
                     to end. --}}
                @if ($canAddItems && ! $submittal->isReviewed())
                    <form method="POST" action="{{ route('submittals.items.store', $submittal) }}"
                          class="space-y-4 border-t border-[var(--surface-border)] p-5">
                        @csrf

                        <p class="text-sm text-[var(--text-muted)]">
                            Add something that is not a controlled document — a sample, a mock-up, a
                            vendor certificate nobody has registered.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-4">
                            <div class="sm:col-span-2">
                                <x-field label="Item" name="description" required>
                                    <x-input name="description" required maxlength="300"
                                             placeholder="e.g. Sample panel, external render"/>
                                </x-field>
                            </div>

                            <x-field label="Reference" name="reference">
                                <x-input name="reference" maxlength="120" placeholder="Spec clause or tag"/>
                            </x-field>

                            <x-field label="Qty" name="quantity">
                                <x-input type="number" name="quantity" value="1" min="1" max="9999"/>
                            </x-field>
                        </div>

                        <button type="submit" class="btn btn-secondary btn-sm">Add to the package</button>
                    </form>
                @endif
            </div>

            {{-- ------------------------------------------------------- description --}}
            @if ($submittal->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Description</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $submittal->description }}</p>
                    </div>
                </div>
            @endif

            <x-register.transitions :action="route('submittals.transition', $submittal)"
                                    :transitions="$transitions" noun="submittal"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this package yet."/>
        </div>

        {{-- ============================================================ side column --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$submittal->number" mono/>
                    <x-register.fact label="Type" :value="$typeLabel"/>
                    <x-register.fact label="Revision" :value="$submittal->revision"/>
                    <x-register.fact label="Attempt" :value="$attemptNumber"/>
                    <x-register.fact label="Reviewing party" :value="$submittal->party_name"/>
                    <x-register.fact label="Project" :value="$submittal->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$submittal->discipline?->name"/>
                    <x-register.fact label="Department" :value="$submittal->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$submittal->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates and people</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Submitted by" :value="$submittal->submitter?->name"/>
                    <x-register.fact label="Submitted on" :value="$submittal->submitted_on?->format('j M Y')"/>
                    <x-register.fact label="Review due" :value="$submittal->required_by?->format('j M Y')"/>
                    <x-register.fact label="Reviewed by" :value="$submittal->reviewer?->name"/>
                    <x-register.fact label="Reviewed on" :value="$submittal->reviewed_at?->format('j M Y')"/>
                    <x-register.fact label="Opened by" :value="$submittal->creator?->name"/>
                </dl>

                @if ($submittal->resubmittalOf)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Replaces</p>
                        <a href="{{ route('submittals.show', $submittal->resubmittal_of_id) }}"
                           class="mt-1 block font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                            {{ $submittal->resubmittalOf->number }}
                        </a>
                    </div>
                @endif
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The package goes off the register. Its history is kept — deleting a
                            submittal does not delete the record that it existed.
                        </p>
                        <x-delete-form :action="route('submittals.destroy', $submittal)"
                                       label="Delete this package"
                                       :confirm="'Delete '.$submittal->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
