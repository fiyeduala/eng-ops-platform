@props(['name', 'options' => [], 'selected' => null, 'placeholder' => null])

{{--
    `options` is a value => label map. Passing the map (rather than rendering <option> tags at the
    call site) is what lets every select in the application agree on how old() and the selected
    value interact — the single most common source of "the edit form silently reset a field".
--}}

<select name="{{ $name }}"
        id="{{ $attributes->get('id', $name) }}"
        @error($name) aria-invalid="true" @enderror
        {{ $attributes->except(['id'])->merge(['class' => 'select '.($errors->has($name) ? 'input-error' : '')]) }}>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif

    @php
        $current = old($name, $selected);

        // A backed enum is unwrapped here rather than at the call site, because the call site is
        // where it gets forgotten. Five of the six confidentiality selects wrote `?->value` and one
        // did not, and the one that did not was a 500 on an edit screen — an uncaught
        // "Object of class … could not be converted to string" from the cast below, not a wrong
        // selection. There are 465 of these tags; the rule belongs in the one place, not in 465.
        if ($current instanceof BackedEnum) {
            $current = $current->value;
        }
    @endphp

    @foreach ($options as $value => $label)
        <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
    @endforeach
</select>
