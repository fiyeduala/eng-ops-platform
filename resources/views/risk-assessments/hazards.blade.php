{{--
    The hazard table.

    IT IS A STACK OF CARDS, NOT A TWENTY-COLUMN TABLE. This screen gets filled in on a tablet on a
    site, and a horizontally scrolling grid is the reason paper risk assessments outlive the systems
    meant to replace them.

    THE SCORE IS MULTIPLIED IN FRONT OF THE WRITER — twice, before controls and after — because the
    entire point of the exercise is watching the second number come down. The service recalculates
    both server-side on save; nothing here is trusted (§84).

    SAVING REPLACES THE WHOLE TABLE. `setHazards()` takes the rows as posted and makes the record
    match them, so a row deleted here is deleted on save. That is what makes an empty row harmless
    and a half-finished draft safe to leave.

    Expects: $assessment, $people
--}}
<x-layouts.app :title="'Hazards on '.$assessment->displayName()">

    <x-page-header :title="'Hazards on '.$assessment->number"
                   :description="$assessment->title">
        <x-slot:actions>
            <a href="{{ route('risk-assessments.show', $assessment) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    @error('hazards') <p class="error mb-4">{{ $message }}</p> @enderror

    <form method="POST" action="{{ route('risk-assessments.hazards.update', $assessment) }}"
          x-data="hazardTable(@js($assessment->hazards->map(fn ($h) => [
              'hazard' => $h->hazard,
              'who_is_at_risk' => $h->who_is_at_risk,
              'existing_controls' => $h->existing_controls,
              'additional_controls' => $h->additional_controls,
              'likelihood' => $h->likelihood,
              'severity' => $h->severity,
              'residual_likelihood' => $h->residual_likelihood,
              'residual_severity' => $h->residual_severity,
              'responsible_id' => $h->responsible_id,
              'target_date' => $h->target_date?->format('Y-m-d'),
          ])->all()))"
          class="space-y-6">
        @csrf
        @method('PUT')

        <template x-for="(row, i) in rows" :key="i">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Hazard <span x-text="i + 1"></span></h3>
                    <button type="button" class="btn btn-ghost btn-sm" @click="rows.splice(i, 1)">
                        Remove
                    </button>
                </div>

                <div class="space-y-5 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">The hazard</label>
                            <textarea rows="2" class="input" x-model="row.hazard"
                                      :name="`hazards[${i}][hazard]`"
                                      placeholder="Falling object from the edge of the deck"></textarea>
                        </div>
                        <div>
                            <label class="label">Who is at risk</label>
                            <textarea rows="2" class="input" x-model="row.who_is_at_risk"
                                      :name="`hazards[${i}][who_is_at_risk]`"
                                      placeholder="Anyone below, including the public"></textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Controls already in place</label>
                            <textarea rows="3" class="input" x-model="row.existing_controls"
                                      :name="`hazards[${i}][existing_controls]`"></textarea>
                        </div>
                        <div>
                            <label class="label">Controls still needed</label>
                            <textarea rows="3" class="input" x-model="row.additional_controls"
                                      :name="`hazards[${i}][additional_controls]`"></textarea>
                        </div>
                    </div>

                    <div class="grid gap-4 border-t border-[var(--surface-border)] pt-5 sm:grid-cols-2">
                        <div class="rounded-md bg-[var(--surface-muted)] p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                Before controls
                            </p>
                            <div class="mt-3 flex items-end gap-3">
                                <div class="w-24">
                                    <label class="label">Likelihood</label>
                                    <input type="number" min="1" max="5" class="input"
                                           x-model.number="row.likelihood"
                                           :name="`hazards[${i}][likelihood]`">
                                </div>
                                <div class="w-24">
                                    <label class="label">Severity</label>
                                    <input type="number" min="1" max="5" class="input"
                                           x-model.number="row.severity"
                                           :name="`hazards[${i}][severity]`">
                                </div>
                                <p class="pb-2 text-sm">
                                    <span class="text-[var(--text-muted)]">=</span>
                                    <span class="font-mono font-semibold" x-text="score(row.likelihood, row.severity)"></span>
                                </p>
                            </div>
                        </div>

                        <div class="rounded-md bg-[var(--surface-muted)] p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                After the controls above
                            </p>
                            <div class="mt-3 flex items-end gap-3">
                                <div class="w-24">
                                    <label class="label">Likelihood</label>
                                    <input type="number" min="1" max="5" class="input"
                                           x-model.number="row.residual_likelihood"
                                           :name="`hazards[${i}][residual_likelihood]`">
                                </div>
                                <div class="w-24">
                                    <label class="label">Severity</label>
                                    <input type="number" min="1" max="5" class="input"
                                           x-model.number="row.residual_severity"
                                           :name="`hazards[${i}][residual_severity]`">
                                </div>
                                <p class="pb-2 text-sm">
                                    <span class="text-[var(--text-muted)]">=</span>
                                    <span class="font-mono font-semibold"
                                          x-text="score(row.residual_likelihood, row.residual_severity)"></span>
                                </p>
                            </div>
                            <p class="mt-2 text-xs text-amber-600"
                               x-show="notReduced(row)" x-cloak>
                                The controls have not moved the number.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Who sees the extra controls done</label>
                            <select class="input" x-model="row.responsible_id"
                                    :name="`hazards[${i}][responsible_id]`">
                                <option value="">Nobody named</option>
                                @foreach ($people as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">By when</label>
                            <input type="date" class="input" x-model="row.target_date"
                                   :name="`hazards[${i}][target_date]`">
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-secondary" @click="add()">Add a hazard</button>
            <button type="submit" class="btn btn-primary">Save the hazards</button>
            <a href="{{ route('risk-assessments.show', $assessment) }}" class="btn btn-ghost">Cancel</a>
            <span class="text-sm text-[var(--text-muted)]">
                <span x-text="rows.length"></span> on the sheet. Saving replaces the table with
                exactly what is on this screen.
            </span>
        </div>
    </form>


</x-layouts.app>
