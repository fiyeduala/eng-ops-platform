{{--
    One question, drawn as the control its type calls for.

    ONE PARTIAL FOR EVERY TYPE, and for both an ordinary question and a cell of a repeating table —
    `$name` decides which, because the only difference between the two is where the answer is
    posted. Writing it twice would mean two places to remember that a date is a date.

    NOTHING HERE IS A CONTROL IN THE SECURITY SENSE. `required`, `min`, `max` and `maxlength` are
    conveniences the browser offers the person typing; every one of them is compiled again from the
    stored definition by FormCompiler and applied server-side, because a browser attribute is a
    suggestion and a request is whatever somebody sends (§84).

    A QUESTION NARROWED BY A CONDITION IS HIDDEN WITH x-show, WHICH IS CSS. The compiler decides
    separately whether it applies, and a hidden question that is answered anyway is simply not
    stored. The hiding is so the page reads correctly, not so the answer is refused.

    Expects: $field, $key (the dotted path, e.g. answers.inspector), $value, $compiler, $editable
    Optional: $bare — drop the label and help text, for a cell whose column heading says it already
--}}
@php
    $bare = $bare ?? false;
    // The dotted path is what the validator names a failure, and the bracketed one is what the
    // browser posts. Derived from each other here so a control and its error message can never
    // drift apart — the bug that makes a page show a red border with the message somewhere else.
    $parts = explode('.', $key);
    $name = array_shift($parts).($parts === [] ? '' : '['.implode('][', $parts).']');

    $id = 'q'.$field->id.'-'.md5($key);
    $choices = $compiler->choicesForDisplay($field);
    $rows = (int) $field->setting('rows', 4);
@endphp

@if ($field->type === 'heading')
    <h4 class="mt-2 text-sm font-semibold uppercase tracking-wide text-[var(--text-muted)]">
        {{ $field->label }}
    </h4>
@elseif ($field->type === 'note')
    <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] p-3 text-sm text-[var(--text-body)]">
        <p class="font-medium text-[var(--text-strong)]">{{ $field->label }}</p>
        @if ($field->setting('body'))
            <p class="mt-1 whitespace-pre-line">{{ $field->setting('body') }}</p>
        @endif
    </div>
@else
    <div>
        @unless ($bare)
            <label class="label" for="{{ $id }}">
                {{ $field->label }}
                @if ($field->is_required)
                    <span class="text-[var(--text-muted)]">*</span>
                @endif
            </label>
        @endunless

        @if (! $editable)
            {{-- A submitted sheet still renders every question, so the person reading it sees what
                 was asked as well as what was answered. --}}
            <p class="input bg-[var(--surface-muted)] whitespace-pre-line">{{ $compiler->render($field, $value) }}</p>
        @elseif ($field->type === 'textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" class="input" rows="{{ max(2, min($rows, 20)) }}"
                      placeholder="{{ $field->placeholder }}"
                      maxlength="{{ min((int) $field->setting('max_length', 5000) ?: 5000, 5000) }}">{{ $value }}</textarea>
        @elseif ($field->type === 'number')
            <input type="number" id="{{ $id }}" name="{{ $name }}" class="input"
                   value="{{ $value }}" placeholder="{{ $field->placeholder }}"
                   step="{{ $field->setting('step', 'any') }}"
                   @if (filled($field->setting('min'))) min="{{ $field->setting('min') }}" @endif
                   @if (filled($field->setting('max'))) max="{{ $field->setting('max') }}" @endif>
            @if ($field->setting('suffix'))
                <p class="help">Measured in {{ $field->setting('suffix') }}.</p>
            @endif
        @elseif ($field->type === 'date')
            <input type="date" id="{{ $id }}" name="{{ $name }}" class="input" value="{{ $value }}"
                   @if (filled($field->setting('min'))) min="{{ $field->setting('min') }}" @endif
                   @if (filled($field->setting('max'))) max="{{ $field->setting('max') }}" @endif>
        @elseif ($field->type === 'boolean')
            {{-- The hidden 0 is what makes "unticked" different from "never seen": a bare checkbox
                 posts nothing when it is off, and the compiler would read that as no answer at all
                 rather than as No. --}}
            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked($value)>
                Yes
            </label>
        @elseif ($field->type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" class="select">
                <option value="">Not answered</option>
                @foreach ($choices as $key => $label)
                    <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($choices === [])
                <p class="help">This question's list is empty — an administrator needs to fill it in
                    on the Option lists screen before it can be answered.</p>
            @endif
        @elseif ($field->type === 'multiselect')
            @php $chosen = is_array($value) ? array_map('strval', $value) : []; @endphp
            <div class="grid gap-1 rounded-lg border border-[var(--surface-border)] p-3 sm:grid-cols-2">
                @foreach ($choices as $key => $label)
                    <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $key }}"
                               @checked(in_array((string) $key, $chosen, true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        @elseif ($field->type === 'signature')
            {{-- A FALLBACK, AND IT SHOULD NOT NORMALLY BE REACHED. Both screens that render a sheet
                 send a signature block to partials/signature.blade.php, which has the submission in
                 hand and can therefore show who has signed. This branch is what is left if some
                 other page ever renders a field without one — a placeholder that collects nothing,
                 rather than a text box pretending to be an approval. --}}
            <div class="rounded-lg border border-dashed border-[var(--surface-border)] p-4 text-sm text-[var(--text-muted)]">
                <x-icon name="pencil" class="mb-1 h-4 w-4"/>
                <p>{{ $field->setting('designation_hint') ?: 'Signed when this is submitted.' }}</p>
            </div>
        @else
            <input type="text" id="{{ $id }}" name="{{ $name }}" class="input"
                   value="{{ $value }}" placeholder="{{ $field->placeholder }}"
                   maxlength="{{ min((int) $field->setting('max_length', 255) ?: 255, 255) }}">
        @endif

        @if ($field->help && ! $bare)
            <p class="help">{{ $field->help }}</p>
        @endif

        @error($key)
            <p class="error">{{ $message }}</p>
        @enderror
    </div>
@endif
