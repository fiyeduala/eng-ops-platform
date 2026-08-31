@props(['label', 'value' => null, 'mono' => false])

{{--
    One line of a particulars list.

    A `dt`/`dd` pair, extracted because five show pages carrying twelve of these each is sixty
    chances to mark up a definition list slightly differently. Pass `value` for plain text or use the
    slot for anything with a link or a badge in it. An empty value renders an em dash rather than
    nothing, because a blank space where a fact should be reads as a rendering bug.
--}}

<div>
    <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">{{ $label }}</dt>
    <dd @class(['mt-0.5 text-[var(--text-strong)]', 'font-mono text-sm' => $mono])>
        @if (trim($slot->toHtml()) !== '')
            {{ $slot }}
        @else
            {{ filled($value) ? $value : '—' }}
        @endif
    </dd>
</div>
