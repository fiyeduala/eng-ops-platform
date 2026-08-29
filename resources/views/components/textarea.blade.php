@props(['name', 'value' => null, 'rows' => 3])

<textarea name="{{ $name }}"
          id="{{ $attributes->get('id', $name) }}"
          rows="{{ $rows }}"
          @error($name) aria-invalid="true" @enderror
          {{ $attributes->except(['id'])->merge(['class' => 'textarea '.($errors->has($name) ? 'input-error' : '')]) }}>{{ old($name, $value) }}</textarea>
