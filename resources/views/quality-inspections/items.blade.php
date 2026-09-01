{{--
    The acceptance criteria this inspection will be judged against.

    THE CRITERIA ARE SET BEFORE THE WALK AND FROZEN THE MOMENT THE VERDICT IS IN. The service refuses
    to rewrite the checkpoints of an inspection that has been carried out, because a criterion edited
    after the measurement is not a criterion — it is a description of whatever was found. The refusal
    is shown as a plain notice rather than a disabled form, because a form that silently does nothing
    teaches nobody why (§59, §84).

    "ACCEPTANCE CRITERIA" IS THE COLUMN THIS SCREEN EXISTS FOR. A checkpoint that says "check the
    welds" produces an argument; one that says "undercut not exceeding 1mm, AWS D1.1 Table 6.1"
    produces a measurement. The live count of stated criteria is there to make an empty column
    visible before anybody walks out rather than afterwards.

    SAVING REPLACES THE WHOLE TABLE with what is on the screen — honest for a checklist being
    drafted, and impossible afterwards, which is the same rule stated twice.

    Expects: $inspection
--}}
<x-layouts.app :title="'Criteria for '.$inspection->displayName()">

    <x-page-header :title="'Acceptance criteria for '.$inspection->number"
                   :description="$inspection->title">
        <x-slot:actions>
            <a href="{{ route('quality-inspections.show', $inspection) }}" class="btn btn-ghost btn-sm">
                Back to the inspection
            </a>
        </x-slot:actions>
    </x-page-header>

    @error('items') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($inspection->wasInspected())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                This inspection has been carried out
            </p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                Its criteria are fixed. What the work was measured against cannot be changed once the
                measurements are in — if the wrong criteria were used, request the inspection again
                against the right ones.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('quality-inspections.items.update', $inspection) }}"
              class="space-y-6"
              x-data="criteriaTable(@js($inspection->items->map(fn ($item) => [
                  'reference' => $item->reference ?? '',
                  'description' => $item->description ?? '',
                  'acceptance_criteria' => $item->acceptance_criteria ?? '',
              ])->all()))">
            @csrf
            @method('PUT')

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What will be checked</h3>
                    <span class="text-sm text-[var(--text-muted)]">
                        <span class="font-mono" x-text="statedCount"></span> of
                        <span class="font-mono" x-text="rows.length"></span> state a criterion
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                            <tr>
                                <th class="w-24 px-4 py-3">Ref</th>
                                <th class="px-4 py-3">Checkpoint <span class="text-rose-600">*</span></th>
                                <th class="px-4 py-3">Acceptance criteria</th>
                                <th class="w-12 px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <input type="text" class="input" maxlength="60"
                                               :name="`items[${i}][reference]`" x-model="row.reference">
                                    </td>
                                    <td class="px-4 py-3">
                                        <textarea class="input" rows="2" maxlength="2000"
                                                  :name="`items[${i}][description]`"
                                                  x-model="row.description"
                                                  placeholder="Cover to main reinforcement, pile cap P12"></textarea>
                                    </td>
                                    <td class="px-4 py-3">
                                        <textarea class="input" rows="2" maxlength="5000"
                                                  :name="`items[${i}][acceptance_criteria]`"
                                                  x-model="row.acceptance_criteria"
                                                  placeholder="50mm nominal, −0/+10mm, drawing SD-104 rev C"></textarea>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" class="btn btn-ghost btn-sm"
                                                @click="rows.splice(i, 1)">Remove</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-secondary" @click="add()">Add a checkpoint</button>
                <button type="submit" class="btn btn-primary">Save the criteria</button>
                <a href="{{ route('quality-inspections.show', $inspection) }}" class="btn btn-ghost">Cancel</a>
                <span class="text-sm text-[var(--text-muted)]">
                    A row with no checkpoint is dropped; saving replaces the list with exactly what is
                    on this screen.
                </span>
            </div>
        </form>
    @endif

</x-layouts.app>
