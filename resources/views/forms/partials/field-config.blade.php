{{--
    The per-type settings for one question, shown and hidden by the type select beside them.

    ONE PARTIAL FOR BOTH THE ADD FORM AND THE EDIT FORM, because the settings a type has are a
    property of the type and not of which form you are looking at. Two copies would drift, and the
    way they drift is that somebody adds "suffix" to the add form and a year of edited questions
    quietly lose it.

    EVERY BOX HERE POSTS WHETHER OR NOT IT IS ON SCREEN — x-show is CSS, not a gate. That is safe,
    and deliberately so: FormDefinitionService::configFor() keeps only the keys the chosen type
    actually has and drops the rest, so `config[rows]` submitted against a number question is not
    stored rather than being stored and ignored. It is also why nothing that changes a RULE lives
    in config: `is_required`, `type` and `option_list_key` are columns, and config is presentation
    only (§84).

    Expects: $field (FormField|null), $prefix (string, unique id prefix), $disabled (bool)
--}}
@php
    $setting = fn (string $key, $fallback = null) => $field?->setting($key, $fallback) ?? $fallback;
@endphp

<div class="sm:col-span-3" x-show="['number','text','textarea','date','table','note','signature'].includes(type)" x-cloak>
    <div class="rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] p-3">
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Settings for this kind of question</p>

        <div class="grid gap-3 sm:grid-cols-4">

            {{-- number --}}
            <div x-show="type === 'number'" x-cloak>
                <label class="label" for="{{ $prefix }}-min">Smallest allowed</label>
                <input type="text" id="{{ $prefix }}-min" name="config[min]" class="input"
                       value="{{ $setting('min') }}" maxlength="24" @disabled($disabled)>
            </div>
            <div x-show="type === 'number'" x-cloak>
                <label class="label" for="{{ $prefix }}-max">Largest allowed</label>
                <input type="text" id="{{ $prefix }}-max" name="config[max]" class="input"
                       value="{{ $setting('max') }}" maxlength="24" @disabled($disabled)>
            </div>
            <div x-show="type === 'number'" x-cloak>
                <label class="label" for="{{ $prefix }}-step">Steps of</label>
                <input type="text" id="{{ $prefix }}-step" name="config[step]" class="input"
                       value="{{ $setting('step') }}" maxlength="12" placeholder="1" @disabled($disabled)>
            </div>
            <div x-show="type === 'number'" x-cloak>
                <label class="label" for="{{ $prefix }}-suffix">Unit</label>
                <input type="text" id="{{ $prefix }}-suffix" name="config[suffix]" class="input"
                       value="{{ $setting('suffix') }}" maxlength="16" placeholder="mm" @disabled($disabled)>
            </div>

            {{-- text and long text --}}
            <div x-show="type === 'text' || type === 'textarea'" x-cloak>
                <label class="label" for="{{ $prefix }}-maxlen">Longest answer</label>
                <input type="text" id="{{ $prefix }}-maxlen" name="config[max_length]" class="input"
                       value="{{ $setting('max_length') }}" maxlength="6" placeholder="255" @disabled($disabled)>
                <p class="help">Characters.</p>
            </div>
            <div x-show="type === 'textarea'" x-cloak>
                <label class="label" for="{{ $prefix }}-rows">Box height</label>
                <input type="text" id="{{ $prefix }}-rows" name="config[rows]" class="input"
                       value="{{ $setting('rows') }}" maxlength="3" placeholder="4" @disabled($disabled)>
                <p class="help">Lines.</p>
            </div>

            {{-- date. Named the same as the number bounds on purpose: they are the same idea, and
                 configFor() allows `min`/`max` for both, so one pair of names covers both. --}}
            <div x-show="type === 'date'" x-cloak>
                <label class="label" for="{{ $prefix }}-dmin">Earliest date</label>
                <input type="date" id="{{ $prefix }}-dmin" name="config[min]" class="input"
                       value="{{ $setting('min') }}" @disabled($disabled)>
            </div>
            <div x-show="type === 'date'" x-cloak>
                <label class="label" for="{{ $prefix }}-dmax">Latest date</label>
                <input type="date" id="{{ $prefix }}-dmax" name="config[max]" class="input"
                       value="{{ $setting('max') }}" @disabled($disabled)>
            </div>

            {{-- repeating table --}}
            <div x-show="type === 'table'" x-cloak>
                <label class="label" for="{{ $prefix }}-minrows">Fewest rows</label>
                <input type="text" id="{{ $prefix }}-minrows" name="config[min_rows]" class="input"
                       value="{{ $setting('min_rows') }}" maxlength="3" placeholder="1" @disabled($disabled)>
            </div>
            <div x-show="type === 'table'" x-cloak>
                <label class="label" for="{{ $prefix }}-maxrows">Most rows</label>
                <input type="text" id="{{ $prefix }}-maxrows" name="config[max_rows]" class="input"
                       value="{{ $setting('max_rows') }}" maxlength="3" placeholder="20" @disabled($disabled)>
            </div>
            <div class="sm:col-span-2" x-show="type === 'table'" x-cloak>
                <label class="label" for="{{ $prefix }}-addlabel">Words on the "add row" button</label>
                <input type="text" id="{{ $prefix }}-addlabel" name="config[add_label]" class="input"
                       value="{{ $setting('add_label') }}" maxlength="40" placeholder="Add another" @disabled($disabled)>
            </div>

            {{-- standing text --}}
            <div class="sm:col-span-4" x-show="type === 'note'" x-cloak>
                <label class="label" for="{{ $prefix }}-body">The text to show</label>
                <textarea id="{{ $prefix }}-body" name="config[body]" class="input" rows="3"
                          maxlength="2000" @disabled($disabled)>{{ $setting('body') }}</textarea>
                <p class="help">An instruction, a warning, a regulation reference. Asks nothing.</p>
            </div>

            {{-- signature --}}
            <div class="sm:col-span-4" x-show="type === 'signature'" x-cloak>
                <label class="label" for="{{ $prefix }}-hint">Who should sign here</label>
                <input type="text" id="{{ $prefix }}-hint" name="config[designation_hint]" class="input"
                       value="{{ $setting('designation_hint') }}" maxlength="120"
                       placeholder="e.g. Site supervisor" @disabled($disabled)>
                <p class="help">A prompt only. Who may actually sign is decided by the approval route, not here.</p>
            </div>
        </div>
    </div>
</div>
