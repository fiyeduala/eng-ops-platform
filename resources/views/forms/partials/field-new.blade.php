{{--
    Adding a question — to a section, or as a column of a repeating table.

    ONE PARTIAL FOR BOTH, with $parent deciding which. The only difference between "a question on
    this section" and "a column of this table" is a parent id, and writing it twice would mean two
    places to remember that a table cannot contain a table.

    THE PARENT ID IS POSTED, AND IT IS NOT TRUSTED. FormDefinitionService resolves it against the
    version being written to and returns null for anything else, so a parent id belonging to
    another form — or to the version this form was on last month — adds a top-level question rather
    than hanging a column off somebody else's table (§84).

    Expects: $form, $section, $types, $optionLists, $parent (FormField|null)
--}}
@php
    $prefix = 'new'.$section->id.($parent?->id ?? '');
@endphp

<form method="POST" action="{{ route('forms.fields.store', [$form, $section]) }}"
      class="grid gap-3 border-t border-[var(--surface-border)] bg-[var(--surface-muted)] p-3 sm:grid-cols-3 sm:items-start"
      x-data="{ type: 'text' }"
      x-show="adding" x-cloak>
    @csrf

    @if ($parent)
        <input type="hidden" name="parent_field_id" value="{{ $parent->id }}">
    @endif

    <div class="sm:col-span-2">
        <label class="label" for="{{ $prefix }}-label">
            {{ $parent ? 'Column heading' : 'Question' }}
        </label>
        <input type="text" id="{{ $prefix }}-label" name="label" class="input" value=""
               required maxlength="255"
               placeholder="{{ $parent ? 'e.g. Tag number' : 'e.g. Is the scaffold tagged?' }}">
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-key">Key</label>
        <input type="text" id="{{ $prefix }}-key" name="key" class="input font-mono" value="" maxlength="64">
        <p class="help">Blank makes one from the label.</p>
    </div>

    <div>
        <label class="label" for="{{ $prefix }}-type">Kind</label>
        <select id="{{ $prefix }}-type" name="type" class="select" x-model="type">
            @foreach ($types as $value => $name)
                {{-- A repeating table cannot contain a table or a signature block. One level deep
                     only: a table inside a table is a spreadsheet, and a form builder that grows
                     one has stopped being a form builder. --}}
                @continue($parent && in_array($value, \App\Models\FormField::NOT_IN_TABLE, true))
                <option value="{{ $value }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <div x-show="type === 'select' || type === 'multiselect'" x-cloak>
        <label class="label" for="{{ $prefix }}-list">List to choose from</label>
        <select id="{{ $prefix }}-list" name="option_list_key" class="select">
            <option value="">Choose a list</option>
            @foreach ($optionLists as $key => $name)
                <option value="{{ $key }}">{{ $name }}</option>
            @endforeach
        </select>
        <p class="help">Defined on the Option lists screen.</p>
    </div>

    <div x-show="type !== 'heading' && type !== 'note'" x-cloak>
        <label class="label" for="{{ $prefix }}-help">Help text</label>
        <input type="text" id="{{ $prefix }}-help" name="help" class="input" value="" maxlength="500">
    </div>

    <div class="pt-6 text-sm text-[var(--text-body)]"
         x-show="type !== 'heading' && type !== 'note' && type !== 'table'" x-cloak>
        <label class="flex items-center gap-2">
            <input type="hidden" name="is_required" value="0">
            <input type="checkbox" name="is_required" value="1">
            Must be answered
        </label>
    </div>

    @include('forms.partials.field-config', ['field' => null, 'prefix' => $prefix, 'disabled' => false])

    <div class="flex justify-end gap-2 sm:col-span-3">
        <button type="button" class="btn btn-secondary btn-sm" @click="adding = false">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">
            {{ $parent ? 'Add column' : 'Add question' }}
        </button>
    </div>
</form>
