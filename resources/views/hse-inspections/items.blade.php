{{--
    The checklist an inspection will be walked against.

    IT IS FROZEN THE MOMENT THE WALK HAPPENS. The service refuses to rewrite the items of a performed
    inspection, because changing the questions after the answers are in is how a 62% becomes a 95%.
    This page shows the refusal as a plain notice rather than a disabled form, since a form that
    silently does nothing teaches nobody why (§59, §84).

    SAVING REPLACES THE WHOLE TABLE with what is on the screen. That is honest for a checklist being
    drafted and impossible afterwards, which is the same rule stated twice.

    Expects: $inspection
--}}
<x-layouts.app :title="'Checklist for '.$inspection->displayName()">

    <x-page-header :title="'Checklist for '.$inspection->number"
                   :description="$inspection->title">
        <x-slot:actions>
            <a href="{{ route('hse-inspections.show', $inspection) }}" class="btn btn-ghost btn-sm">
                Back to the inspection
            </a>
        </x-slot:actions>
    </x-page-header>

    @error('items') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($inspection->wasPerformed())
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                This inspection has been carried out
            </p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                Its checklist is fixed. The questions cannot be changed once the answers are in — if
                the wrong list was used, schedule the inspection again with the right one.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('hse-inspections.items.update', $inspection) }}"
              class="space-y-6"
              x-data="checklistTable(@js($inspection->items->map(fn ($item) => [
                  'reference' => $item->reference ?? '',
                  'description' => $item->description ?? '',
                  'category' => $item->category ?? '',
                  'score_possible' => $item->score_possible ?? '',
              ])->all()))">
            @csrf
            @method('PUT')

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What is being checked</h3>
                    <span class="text-sm text-[var(--text-muted)]">
                        Out of <span class="font-mono" x-text="totalPossible"></span> points
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                            <tr>
                                <th class="w-24 px-4 py-3">Ref</th>
                                <th class="px-4 py-3">Question <span class="text-rose-600">*</span></th>
                                <th class="w-40 px-4 py-3">Category</th>
                                <th class="w-28 px-4 py-3">Points</th>
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
                                                  placeholder="Is edge protection in place and complete?"></textarea>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="text" class="input" maxlength="60"
                                               :name="`items[${i}][category]`" x-model="row.category">
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="number" class="input" min="0" max="999999" step="0.5"
                                               :name="`items[${i}][score_possible]`"
                                               x-model="row.score_possible">
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
                <button type="button" class="btn btn-secondary" @click="add()">Add a question</button>
                <button type="submit" class="btn btn-primary">Save the checklist</button>
                <a href="{{ route('hse-inspections.show', $inspection) }}" class="btn btn-ghost">Cancel</a>
                <span class="text-sm text-[var(--text-muted)]">
                    <span x-text="rows.length"></span> questions. A row with no question is dropped;
                    saving replaces the list with exactly what is on this screen.
                </span>
            </div>
        </form>
    @endif

</x-layouts.app>
