@props(['record', 'heading' => 'Additional details'])

{{--
    What this company recorded in its own fields, on a record's show page.

    Only fields with an answer appear — a show page listing twenty empty labels would bury the
    facts that are there. Choice values print through the option list, so a record shows
    "Mechanical & Piping" even though it stored `mechanical`.

    NO PERMISSION CHECK HERE, deliberately. A custom value has no visibility of its own; it is part
    of the record, and anybody who reached this page has already been granted the record (§6).
--}}

@php
    $entries = app(\App\Engines\Configuration\CustomFieldRegistry::class)->displayFor($record);
@endphp

@if ($entries !== [])
    <div {{ $attributes->merge(['class' => 'card']) }}>
        <div class="card-header">
            <h2 class="card-title">{{ $heading }}</h2>
        </div>

        <dl class="grid gap-4 p-4 sm:grid-cols-2">
            @foreach ($entries as $entry)
                <x-register.fact :label="$entry['field']->label" :value="$entry['display']"/>
            @endforeach
        </dl>
    </div>
@endif
