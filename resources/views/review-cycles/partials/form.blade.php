@php
    // The rows Alpine starts with: what came back from a failed submit if there is any, otherwise
    // what is on file, otherwise one empty line so a new cycle opens with somewhere to type.
    $rows = old('competencies', $cycle->competencies
        ->map(fn ($c) => [
            'id' => $c->id,
            'label' => $c->label,
            'description' => $c->description,
            'kind' => $c->kind,
            'weight' => (string) $c->weight,
            'org_unit_id' => $c->org_unit_id,
        ])->values()->all());

    if ($rows === []) {
        $rows = [['id' => null, 'label' => '', 'description' => '', 'kind' => 'competency', 'weight' => '1', 'org_unit_id' => null]];
    }
@endphp

<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Name" name="name" class="md:col-span-2" required help="What people will see it called — &ldquo;2026 mid-year review&rdquo;.">
        <x-input name="name" :value="old('name', $cycle->name)" required/>
    </x-field>

    <x-field label="Period start" name="period_start" required help="The work being reviewed, not the dates of the paperwork.">
        <x-input type="date" name="period_start" :value="old('period_start', $cycle->period_start?->toDateString() ?? $cycle->period_start)" required/>
    </x-field>

    <x-field label="Period end" name="period_end" required>
        <x-input type="date" name="period_end" :value="old('period_end', $cycle->period_end?->toDateString() ?? $cycle->period_end)" required/>
    </x-field>

    <x-field label="Opens on" name="opens_on" help="Optional. A note of when the round was meant to start — opening it is still a button somebody presses.">
        <x-input type="date" name="opens_on" :value="old('opens_on', $cycle->opens_on?->toDateString())"/>
    </x-field>

    <x-field label="Closes on" name="closes_on" help="Optional. Shown to reviewers as the deadline.">
        <x-input type="date" name="closes_on" :value="old('closes_on', $cycle->closes_on?->toDateString())"/>
    </x-field>

    {{-- The scale is the company's, not the platform's (§47). A firm that rates 1–4 with no
         comfortable middle enters 1 and 4 here and every form on every review obeys it. --}}
    <x-field label="Lowest rating" name="rating_min" required help="Whatever this company's scale starts at.">
        <x-input type="number" step="0.01" name="rating_min" :value="old('rating_min', $cycle->rating_min)" required/>
    </x-field>

    <x-field label="Highest rating" name="rating_max" required help="Must be above the lowest. 1–5, 0–10, 1–4 — all fine.">
        <x-input type="number" step="0.01" name="rating_max" :value="old('rating_max', $cycle->rating_max)" required/>
    </x-field>

    <x-field label="Description" name="description" class="md:col-span-2">
        <x-input name="description" :value="old('description', $cycle->description)" placeholder="One line for the register."/>
    </x-field>

    <x-field label="Instructions" name="instructions" class="md:col-span-2"
             help="Shown at the top of every review in this round — what the scale means, what evidence to give, when to have the conversation.">
        <x-textarea name="instructions" rows="4">{{ old('instructions', $cycle->instructions) }}</x-textarea>
    </x-field>
</div>

{{--
    The questions. These are a TEMPLATE: opening the round copies each line onto every review it
    raises, so editing a line here never rewrites an appraisal somebody has already scored.

    Weight is a number, not a percentage — they need not add up to anything, and adding a line does
    not mean rebalancing the rest.
--}}
<div class="mt-8" x-data="{
        rows: @js(array_values($rows)),
        add() { this.rows.push({ id: null, label: '', description: '', kind: 'competency', weight: '1', org_unit_id: '' }); },
        remove(i) { this.rows.splice(i, 1); },
     }">
    <div class="mb-3 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-[var(--text-strong)]">What this round asks</h2>
            <p class="text-xs text-[var(--text-muted)]">Leave the department blank to ask everybody. Set one to ask only that department.</p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" @click="add()">Add a line</button>
    </div>

    <div class="space-y-3">
        <template x-for="(row, i) in rows" :key="i">
            <div class="card p-4">
                <input type="hidden" :name="`competencies[${i}][id]`" :value="row.id ?? ''">
                <div class="grid gap-3 md:grid-cols-12">
                    <div class="md:col-span-5">
                        <label class="label">Line</label>
                        <input type="text" class="input" :name="`competencies[${i}][label]`" x-model="row.label" placeholder="Technical judgement" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="label">Kind</label>
                        <select class="input" :name="`competencies[${i}][kind]`" x-model="row.kind">
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="label">Weight</label>
                        <input type="number" step="0.01" min="0" class="input" :name="`competencies[${i}][weight]`" x-model="row.weight">
                    </div>
                    <div class="md:col-span-3">
                        <label class="label">Only for</label>
                        <select class="input" :name="`competencies[${i}][org_unit_id]`" x-model="row.org_unit_id">
                            <option value="">Everybody</option>
                            @foreach ($units as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-11">
                        <label class="label">What good looks like</label>
                        <input type="text" class="input" :name="`competencies[${i}][description]`" x-model="row.description"
                               placeholder="Optional — the sentence that stops two managers scoring the same thing differently.">
                    </div>
                    <div class="flex items-end md:col-span-1">
                        <button type="button" class="btn btn-ghost btn-sm text-danger-600" @click="remove(i)" x-show="rows.length > 1">Remove</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
