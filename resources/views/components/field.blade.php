@props(['label' => null, 'name', 'help' => null, 'required' => false])

{{--
    One field, one place. Wrapping label + control + help + error together is what stops the
    "this input has no error message" bug from being possible one screen at a time.
--}}

<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $name }}" class="label">
            {{ $label }}
            @if ($required)
                <span class="text-red-500" aria-hidden="true">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($help && ! $errors->has($name))
        <p class="help">{{ $help }}</p>
    @endif

    @error($name)
        <p class="error" role="alert">{{ $message }}</p>
    @enderror
</div>
