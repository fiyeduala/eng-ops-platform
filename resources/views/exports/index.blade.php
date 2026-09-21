{{--
    Files this person asked for, and what became of them.

    Expects: $exports

    THIS PAGE IS ONE PERSON'S, NOT ONE COMPANY'S, and it says so in the banner rather than leaving it
    to be discovered. A managing director who opens it and sees three rows has not been shown a
    company-wide export log with three entries in it — they have been shown their own three. The
    difference matters because the wrong reading is the reassuring one: somebody could conclude from
    an empty page that nobody in the company is exporting anything.

    EVERY DOWNLOAD LINK ON THIS PAGE IS SIGNED AND SHORT-LIVED, generated at render time rather than
    stored, so the markup itself is not a permanent key. It is still not what authorizes the
    download — `auth` and ExportPolicy do that server-side, and a link copied to a colleague is a 403
    (§67, §84). The signature only means a link that leaves this page stops working on its own.
--}}
<x-layouts.app title="Exports">

    <x-page-header
        title="Exports"
        description="Files you asked for. Large reports are prepared in the background and kept for a short while, then deleted.">
        <x-slot:actions>
            <a href="{{ route('configuration.reports.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Reports
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
        <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p>
            These files are <span class="font-medium">yours</span>. Each one holds the rows you could
            see at the moment you asked for it, so nobody else can open them here — not a colleague,
            not an administrator. Once a file is deleted the record of what you exported is kept, but
            the file itself is gone and the link stops working.
        </p>
    </div>

    @if ($exports->isEmpty())
        <div class="card">
            <x-empty-state
                icon="download"
                title="You have not asked for any files"
                description="Most exports download straight away and are never listed here. A report too large to build while you wait is prepared in the background instead, and appears on this page when it is ready — as does the daily executive report, if you are sent one."/>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Format</th>
                        <th>State</th>
                        <th>Asked for</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($exports as $export)
                        <tr>
                            <td>
                                {{-- The name stored on the row, not the report's current one. The
                                     report may since have been renamed or deleted, and what this
                                     file answers is the question as it was asked. --}}
                                <span class="font-medium text-[var(--text-strong)]">{{ $export->name }}</span>

                                @if ($export->isBriefing())
                                    {{-- A different vocabulary, because it is a different thing. This
                                         one arrived unasked, and its `rows` column counts sections of
                                         the board rather than records of a register. --}}
                                    <p class="help mt-0.5">
                                        Sent to you automatically ·
                                        {{ number_format((int) $export->rows) }}
                                        {{ \Illuminate\Support\Str::plural('section', (int) $export->rows) }}
                                    </p>
                                @elseif ($export->rows !== null)
                                    <p class="help mt-0.5">
                                        {{ number_format($export->rows) }}
                                        {{ \Illuminate\Support\Str::plural('row', $export->rows) }}@if ($export->truncated),
                                            and there were more — this file stops at the report’s limit
                                        @endif
                                    </p>
                                @endif

                                @if ($export->status === 'failed' && $export->failure_reason)
                                    <p class="mt-0.5 text-sm text-red-600">{{ $export->failure_reason }}</p>
                                @endif
                            </td>
                            <td class="text-sm text-[var(--text-body)]">
                                {{ $export->formatLabel() }}
                            </td>
                            <td>
                                @if ($export->isCollectable())
                                    <x-badge tone="success">{{ $export->statusLabel() }}</x-badge>
                                    <p class="help mt-0.5">
                                        Until {{ $export->expires_at->format('j M Y, H:i') }}
                                    </p>
                                @elseif ($export->isPending())
                                    <x-badge tone="brand">{{ $export->statusLabel() }}</x-badge>
                                    <p class="help mt-0.5">You will be notified when it is ready.</p>
                                @elseif ($export->status === 'failed')
                                    <x-badge tone="danger">{{ $export->statusLabel() }}</x-badge>
                                @else
                                    {{-- Ready-but-past-retention reads as expired here even if the
                                         row has not been swept yet, because isCollectable() is what
                                         the download route enforces and the page must not offer a
                                         button the server will refuse. --}}
                                    <x-badge tone="neutral">No longer available</x-badge>
                                @endif
                            </td>
                            <td class="text-sm text-[var(--text-muted)]">
                                {{ $export->created_at->format('j M Y, H:i') }}
                                @if ($export->downloads > 0)
                                    <p class="help mt-0.5">
                                        Collected {{ $export->downloads }}
                                        {{ \Illuminate\Support\Str::plural('time', $export->downloads) }}
                                    </p>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-2">
                                    @if ($export->isCollectable())
                                        <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('exports.download', $export->expires_at, ['export' => $export->getKey()]) }}"
                                           class="btn btn-secondary btn-sm">
                                            <x-icon name="download" class="h-3.5 w-3.5"/> Download
                                        </a>
                                    @endif

                                    <x-delete-form
                                        :action="route('exports.destroy', $export)"
                                        label="{{ $export->isCollectable() ? 'Delete' : 'Remove' }}"
                                        confirm="Delete this file?"/>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($exports->hasPages())
                <div class="p-4">{{ $exports->links() }}</div>
            @endif
        </div>
    @endif

</x-layouts.app>
