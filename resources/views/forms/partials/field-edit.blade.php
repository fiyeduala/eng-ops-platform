{{--
    Editing one question, in place.

    THIS PARTIAL IS WHERE THE WORDING/MEANING LINE BECOMES A SCREEN. On a draft everything below is
    a box. On a published edition the label, the help text and the placeholder stay boxes and
    everything else becomes text with a hidden input carrying its current value — so the payload
    still validates, still matches what is stored, and is still refused by
    FormDefinitionService::assertOnlyWordingChanges() if anybody edits it in a browser console.

    WHY HIDDEN INPUTS RATHER THAN @disabled: a disabled control posts NOTHING, and `type` is
    required by FormFieldRequest. Disabling the select would make every rewording on a published
    version fail validation with "type is required", which reads as a broken page rather than as a
    boundary. A read-only display plus a hidden input says the same thing to the person and the
    right thing to the server.

    Expects: $form, $field, $editable (bool — is this version still a draft), $types, $optionLists
--}}
@php
    $prefix = 'f'.$field->id;
@endphp

<form method="POST" action="{{ route('forms.fields.update', [$form, $field]) }}"
      class="grid gap-3 p-3 sm:grid-cols-3 sm:items-start"
      x-data="{ type: '{{ $field->type }}' }">
    @csrf
    @method('PUT')

    <div class="sm:col-span-2">
        <label class="label" for="{{ $prefix }}-label">Question</label>
        <input type="text" id="{{ $prefix }}-label" name="label" class="input"
               value="{{ $field->label }}" required maxlength="255">
        <p class="help">The words above the box. Yours to reword at any time, on any version.</p>
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-key">Key</label>
        @if ($editable)
            <input type="text" id="{{ $prefix }}-key" name="key" class="input font-mono"
                   value="{{ $field->key }}" maxlength="64">
            <p class="help">What exports call this column.</p>
        @else
            <input type="hidden" name="key" value="{{ $field->key }}">
            <p class="input truncate bg-[var(--surface-muted)] font-mono">{{ $field->key }}</p>
            <p class="help">Frozen on a published version.</p>
        @endif
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-type">Kind</label>
        @if ($editable)
            <select id="{{ $prefix }}-type" name="type" class="select" x-model="type">
                @foreach ($types as $value => $name)
                    {{-- A question that is already a column of a repeating table cannot become a
                         table or a signature block itself. The service refuses it either way; this
                         keeps the list honest about what it will accept. --}}
                    @continue($field->isColumn() && in_array($value, \App\Models\FormField::NOT_IN_TABLE, true))
                    <option value="{{ $value }}" @selected($field->type === $value)>{{ $name }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="type" value="{{ $field->type }}">
            <p class="input bg-[var(--surface-muted)]">{{ $field->typeLabel() }}</p>
        @endif
    </div>

    <div x-show="type === 'select' || type === 'multiselect'" x-cloak>
        <label class="label" for="{{ $prefix }}-list">List to choose from</label>
        @if ($editable)
            <select id="{{ $prefix }}-list" name="option_list_key" class="select">
                <option value="">Choose a list</option>
                @foreach ($optionLists as $key => $name)
                    <option value="{{ $key }}" @selected($field->option_list_key === $key)>{{ $name }}</option>
                @endforeach
            </select>
            <p class="help">Defined on the Option lists screen.</p>
        @else
            <input type="hidden" name="option_list_key" value="{{ $field->option_list_key }}">
            <p class="input bg-[var(--surface-muted)]">
                {{ $optionLists[$field->option_list_key] ?? $field->option_list_key ?? '—' }}
            </p>
        @endif
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-placeholder">Placeholder</label>
        <input type="text" id="{{ $prefix }}-placeholder" name="placeholder" class="input"
               value="{{ $field->placeholder }}" maxlength="120">
    </div>

    <div class="sm:col-span-3">
        <label class="label" for="{{ $prefix }}-help">Help text</label>
        <input type="text" id="{{ $prefix }}-help" name="help" class="input"
               value="{{ $field->help }}" maxlength="500">
        <p class="help">The sentence under the box, for whoever is filling this in on site.</p>
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-default">Default answer</label>
        @if ($editable)
            <input type="text" id="{{ $prefix }}-default" name="default_value" class="input"
                   value="{{ $field->default_value }}" maxlength="500">
        @else
            <input type="hidden" name="default_value" value="{{ $field->default_value }}">
            <p class="input truncate bg-[var(--surface-muted)]">{{ $field->default_value ?? '—' }}</p>
        @endif
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-when">Only ask when</label>
        @if ($editable)
            <input type="text" id="{{ $prefix }}-when" name="applies_when_key" class="input font-mono"
                   value="{{ $field->applies_when_key }}" maxlength="64" placeholder="another key">
        @else
            <input type="hidden" name="applies_when_key" value="{{ $field->applies_when_key }}">
            <p class="input truncate bg-[var(--surface-muted)] font-mono">{{ $field->applies_when_key ?? 'Always' }}</p>
        @endif
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-whenis">…is</label>
        @if ($editable)
            <input type="text" id="{{ $prefix }}-whenis" name="applies_when_value" class="input"
                   value="{{ $field->applies_when_value }}" maxlength="120">
            <p class="help">Leave both blank to ask it every time.</p>
        @else
            <input type="hidden" name="applies_when_value" value="{{ $field->applies_when_value }}">
            <p class="input truncate bg-[var(--surface-muted)]">{{ $field->applies_when_value ?? '—' }}</p>
        @endif
    </div>

    <div class="pt-6 text-sm text-[var(--text-body)]" x-show="type !== 'heading' && type !== 'note' && type !== 'table'" x-cloak>
        @if ($editable)
            <label class="flex items-center gap-2">
                <input type="hidden" name="is_required" value="0">
                <input type="checkbox" name="is_required" value="1" @checked($field->is_required)>
                Must be answered
            </label>
        @else
            <input type="hidden" name="is_required" value="{{ $field->is_required ? 1 : 0 }}">
            <p class="text-[var(--text-muted)]">
                {{ $field->is_required ? 'Must be answered' : 'Optional' }} — frozen on a published version.
            </p>
        @endif
    </div>

    @include('forms.partials.field-config', ['field' => $field, 'prefix' => $prefix, 'disabled' => ! $editable])

    <div class="flex justify-end gap-2 sm:col-span-3">
        <button type="button" class="btn btn-secondary btn-sm" @click="editing = false">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </div>
</form>
