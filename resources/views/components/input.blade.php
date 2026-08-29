@props(['name', 'type' => 'text', 'value' => null])

<input type="{{ $type }}"
       name="{{ $name }}"
       id="{{ $attributes->get('id', $name) }}"
       @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
       @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
       {{ $attributes->except(['id'])->merge(['class' => 'input '.($errors->has($name) ? 'input-error' : '')]) }}>
