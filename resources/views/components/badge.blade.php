@props(['tone' => 'neutral'])

@php
    $tones = ['neutral', 'brand', 'success', 'warning', 'danger'];
    $tone = in_array($tone, $tones, true) ? $tone : 'neutral';
@endphp

<span {{ $attributes->merge(['class' => 'badge badge-'.$tone]) }}>{{ $slot }}</span>
