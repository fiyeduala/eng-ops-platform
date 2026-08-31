{{--
    The transmittal cover sheet — the actual deliverable of the module.

    This is the piece of paper that goes to the other party, and years later it is the evidence. So
    it is laid out as a formal document rather than as a screen: a title block naming both sides, a
    numbered schedule of revisions, and a footer stating who issued it and when.

    Rendered by Dompdf, which is why the markup is tables and inline styles rather than anything
    modern. PdfRenderer supplies the base stylesheet (h1/h2, table borders, .muted, .danger, .ok) and
    stamps a DRAFT watermark over the whole thing while `issued_at` is null — a cover sheet that
    looks official but has not been issued is exactly the document that must not be mistaken for one
    that has.

    EVERYTHING IS ESCAPED. Blade does that by default, and there is no {!! !!} on this page: the
    values here are typed by users and the cover note is stored as plain text, so injecting it raw
    would put user input into a renderer that then draws it.

    Expects: $transmittal (with items.documentVersion.document, project, issuer, organization)
--}}
@php
    $org = $transmittal->organization;
    $items = $transmittal->items;
@endphp

<h1 style="margin-bottom: 1mm;">Transmittal</h1>
<p class="muted" style="margin-bottom: 5mm;">{{ $org?->name }}</p>

{{-- ---------------------------------------------------------------- title block --}}
<table style="margin-bottom: 5mm;">
    <tr>
        <th style="width: 22%;">Transmittal no.</th>
        <td style="width: 28%;">{{ $transmittal->number }}</td>
        <th style="width: 22%;">Date issued</th>
        <td style="width: 28%;">
            @if ($transmittal->isIssued())
                {{ $transmittal->issued_at->format('j F Y') }}
            @else
                <span class="danger">Not issued</span>
            @endif
        </td>
    </tr>
    <tr>
        <th>Project</th>
        <td>{{ $transmittal->project?->displayName() ?? '—' }}</td>
        <th>Purpose</th>
        <td>{{ $transmittal->purposeLabel() }}</td>
    </tr>
    <tr>
        <th>Issued by</th>
        <td>{{ $transmittal->issuer?->name ?? $transmittal->creator?->name ?? '—' }}</td>
        <th>Sent by</th>
        <td>{{ $transmittal->transportLabel() ?? '—' }}</td>
    </tr>
    <tr>
        <th>Confidentiality</th>
        <td>{{ $transmittal->confidentiality?->label() }}</td>
        <th>Response due</th>
        <td>
            @if ($transmittal->due_response_on)
                {{ $transmittal->due_response_on->format('j F Y') }}
            @elseif ($transmittal->requires_acknowledgement)
                On receipt
            @else
                —
            @endif
        </td>
    </tr>
</table>

{{-- ---------------------------------------------------------------- addressee --}}
<table style="margin-bottom: 5mm;">
    <tr>
        <th style="width: 22%;">To</th>
        <td>
            <strong>{{ $transmittal->to_name }}</strong>
            @if ($transmittal->to_attention)
                <br>For the attention of {{ $transmittal->to_attention }}
            @endif
            @if ($transmittal->to_address)
                <br>{{ $transmittal->to_address }}
            @endif
            @if ($transmittal->to_email)
                <br>{{ $transmittal->to_email }}
            @endif
        </td>
    </tr>
</table>

<h2>Subject</h2>
<p>{{ $transmittal->subject }}</p>

@if ($transmittal->cover_note)
    <h2>Covering note</h2>
    @foreach (preg_split('/\R{2,}/', trim($transmittal->cover_note)) as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
@endif

{{-- ---------------------------------------------------------------- schedule --}}
<h2>Documents transmitted</h2>

@if ($items->isEmpty())
    <p class="danger">No documents are listed on this transmittal.</p>
@else
    <table>
        <thead>
            <tr>
                <th style="width: 8%;">Item</th>
                <th style="width: 24%;">Document no.</th>
                <th>Title</th>
                <th style="width: 8%;">Rev</th>
                <th style="width: 14%;">Media</th>
                <th style="width: 8%;">Copies</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                @php
                    $version = $item->documentVersion;
                    $document = $version?->document;
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $document->number ?? '—' }}</td>
                    <td>
                        {{ $document->title ?? 'Revision no longer available' }}
                        @if ($item->remarks)
                            <br><span class="muted">{{ $item->remarks }}</span>
                        @endif
                    </td>
                    <td>{{ $version->revision ?? '—' }}</td>
                    <td>{{ $item->mediaLabel() }}</td>
                    <td>{{ $item->copies }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="muted">
        {{ $items->count() }} {{ Str::plural('document', $items->count()) }} transmitted.
        The revisions listed above are the revisions issued. Later revisions of the same documents
        are not covered by this transmittal.
    </p>
@endif

{{-- ---------------------------------------------------------------- receipt --}}
@if ($transmittal->requires_acknowledgement)
    <h2>Acknowledgement of receipt</h2>

    @if ($transmittal->isAcknowledged())
        <p>
            Receipt confirmed
            @if ($transmittal->acknowledged_by_name)
                by {{ $transmittal->acknowledged_by_name }}
            @endif
            on {{ $transmittal->acknowledged_at->format('j F Y') }}.
            @if ($transmittal->acknowledgement_reference)
                Reference: {{ $transmittal->acknowledgement_reference }}.
            @endif
        </p>
    @else
        <p class="muted">
            Please confirm receipt of the documents listed above by returning a signed copy of this
            sheet, or by reply quoting transmittal {{ $transmittal->number }}.
        </p>

        <table style="margin-top: 4mm;">
            <tr>
                <th style="width: 25%;">Received by</th>
                <td style="height: 12mm;"></td>
                <th style="width: 18%;">Date</th>
                <td style="width: 22%;"></td>
            </tr>
            <tr>
                <th>Signature</th>
                <td colspan="3" style="height: 14mm;"></td>
            </tr>
        </table>
    @endif
@endif

<p class="muted" style="margin-top: 6mm; font-size: 8pt;">
    {{ $transmittal->number }} &nbsp;|&nbsp; Generated {{ now()->format('j M Y, H:i') }}
    @unless ($transmittal->isIssued())
        &nbsp;|&nbsp; <span class="danger">DRAFT — this transmittal has not been issued.</span>
    @endunless
</p>
