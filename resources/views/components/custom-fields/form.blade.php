@props(['entityType', 'record' => null, 'heading' => 'Additional details'])

{{--
    The questions this company added to this register, rendered as inputs.

    DROP THIS INTO ANY CREATE OR EDIT FORM and the company's own fields appear on it. Nothing else
    changes: the inputs post under `custom_fields[<key>]`, RegisterService picks that key up, and
    CustomFieldRegistry compiles the rules from the definitions and applies them server-side. The
    control rendered here is a convenience for the person typing — it is never what decides what is
    accepted (§84). A `required` attribute on a box is a browser hint; the refusal is in the engine.

    IT RENDERS NOTHING WHEN THERE IS NOTHING TO ASK, so a company that has added no fields sees no
    empty panel and no heading for a section that does not exist.

    Pass `record` on an edit form so the stored answers come back; leave it off on a create form
    and the defaults are offered instead. Either way old() wins, so a rejected save does not throw
    away what somebody typed.
--}}

@php
    // A create form hands over a fresh, unsaved model so the rest of the form can read its
    // defaults. That is not a record yet: it has no key to look answers up by, and treating it as
    // one would ask the database about id null. Defaults, not stored answers, is the right
    // behaviour for it — which is exactly what `null` selects below.
    $record = $record instanceof \Illuminate\Database\Eloquent\Model && $record->exists ? $record : null;

    $registry = app(\App\Engines\Configuration\CustomFieldRegistry::class);
    $fields = $registry->applicableTo($entityType, $record);
    $answers = $record !== null ? $registry->valuesFor($record) : [];
    $lists = app(\App\Services\OptionListService::class);
    $key = \App\Engines\Configuration\CustomFieldRegistry::INPUT_KEY;
@endphp

@if ($fields->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'card']) }}>
        <div class="card-header">
            <h2 class="card-title">{{ $heading }}</h2>
        </div>

        <div class="grid gap-4 p-4 sm:grid-cols-2">
            @foreach ($fields as $field)
                @php
                    $name = $key.'['.$field->key.']';
                    $dotted = $key.'.'.$field->key;

                    // On create there is no record, so the default is the starting answer. The
                    // engine applies it again server-side; offering it here is only so the person
                    // can see and change it before saving.
                    $stored = $record !== null
                        ? ($answers[$field->key] ?? null)
                        : ($field->isMultiple()
                            ? array_filter(array_map('trim', explode(',', (string) $field->default_value)))
                            : $field->default_value);

                    $current = old($dotted, $stored);
                @endphp

                <x-field :label="$field->label"
                         :name="$dotted"
                         :help="$field->help"
                         :required="(bool) $field->is_required"
                         class="{{ in_array($field->type, ['textarea', 'multiselect'], true) ? 'sm:col-span-2' : '' }}">

                    @switch ($field->type)
                        @case('textarea')
                            <textarea name="{{ $name }}" id="{{ $dotted }}" rows="3"
                                      placeholder="{{ $field->placeholder }}"
                                      @class(['input', 'input-error' => $errors->has($dotted)])>{{ $current }}</textarea>
                            @break

                        @case('boolean')
                            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                <input type="hidden" name="{{ $name }}" value="0">
                                <input type="checkbox" name="{{ $name }}" id="{{ $dotted }}" value="1"
                                       @checked(filter_var($current, FILTER_VALIDATE_BOOL))>
                                Yes
                            </label>
                            @break

                        @case('select')
                            <select name="{{ $name }}" id="{{ $dotted }}"
                                    @class(['select', 'input-error' => $errors->has($dotted)])>
                                <option value="">{{ $field->placeholder ?: 'Choose…' }}</option>
                                @foreach ($lists->optionsFor((string) $field->option_list_key) as $value => $label)
                                    <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @break

                        @case('multiselect')
                            @php $chosen = is_array($current) ? $current : array_filter([$current]); @endphp
                            <div class="grid gap-1 sm:grid-cols-3">
                                @foreach ($lists->optionsFor((string) $field->option_list_key) as $value => $label)
                                    <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                        <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}"
                                               @checked(in_array((string) $value, array_map('strval', $chosen), true))>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            @break

                        @default
                            <input type="{{ match ($field->type) { 'number' => 'number', 'date' => 'date', default => 'text' } }}"
                                   name="{{ $name }}" id="{{ $dotted }}"
                                   value="{{ $current }}"
                                   placeholder="{{ $field->placeholder }}"
                                   @if ($field->type === 'number') step="any" @endif
                                   @class(['input', 'input-error' => $errors->has($dotted)])>
                    @endswitch
                </x-field>
            @endforeach
        </div>
    </div>
@endif
