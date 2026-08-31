{{--
    The editor — the screen this whole phase exists for.

    Word, roughly, without Word: headings, lists, tables, alignment, and an export that produces a
    .docx people can open. What it deliberately does NOT do is pretend to be Word. Tracked changes,
    exact pagination and embedded objects cannot survive a .docx → HTML → .docx round-trip, and a
    document-control system that quietly loses them is worse than one that never offered them.

    THE LOCK IS THE COLLABORATION MODEL. No real-time co-editing — that needs a persistent connection
    this platform will not require in V1 (§1), and last-writer-wins on a controlled procedure is data
    loss with an audit trail attached. So the editor is honest about the lock: it shows who holds it,
    it will not autosave without it, and taking it is an explicit act.

    AUTOSAVE IS A COURTESY, NOT A GUARANTEE. It debounces, it serialises, and it reports failure
    loudly rather than silently dropping work. The server sanitises every save through the same
    whitelist whether it arrived from the autosave or the button (§67, §84) — nothing here is
    trusted, including the JSON, which is stored but never rendered.

    Expects: $document, $version, $lockHeld, $lockedByOther, $canCheckOut
--}}
@php
    $readOnly = ! $lockHeld || $version->isApproved();
@endphp

<x-layouts.app :title="'Rev '.$version->revision.' · '.$document->displayName()">

    <div x-data="documentEditor({
            content: @js($version->content_html ?? ''),
            saveUrl: @js(route('documents.versions.update', [$document, $version])),
            readOnly: @js($readOnly),
            autosave: @js(! $readOnly),
         })"
         class="pb-16">

        {{-- ------------------------------------------------------------ header --}}
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold tracking-tight text-[var(--text-strong)]">
                    {{ $document->title }}
                </h2>
                <p class="mt-0.5 text-sm text-[var(--text-muted)]">
                    <span class="font-mono">{{ $document->number ?? 'Unnumbered' }}</span>
                    · Revision <span class="font-mono">{{ $version->revision }}</span>
                    @if ($version->isApproved())
                        · <span class="text-emerald-600">approved, read only</span>
                    @endif
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <span class="text-xs text-[var(--text-muted)]" x-text="statusLabel"></span>
                <span class="text-xs text-[var(--text-muted)]">·</span>
                <span class="text-xs text-[var(--text-muted)]"><span x-text="words"></span> words</span>

                <a href="{{ route('documents.show', $document) }}" class="btn btn-sm btn-ghost">Close</a>

                @unless ($readOnly)
                    <button type="button" class="btn btn-sm btn-primary" @click="save()"
                            x-bind:disabled="state === 'saving'">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Save
                    </button>
                @endunless
            </div>
        </div>

        {{-- ------------------------------------------------------------ lock banner --}}
        @if ($version->isApproved())
            <div class="card mb-4 flex items-start gap-3 border-l-4 border-l-emerald-400 p-4">
                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
                <p class="text-sm text-[var(--text-body)]">
                    This revision is approved, so it is read only — for everybody, whatever permissions they
                    hold. An approved revision is a statement of what was signed off; changing it would
                    rewrite that under everyone who acted on it. Raise the next revision instead.
                </p>
            </div>
        @elseif ($lockedByOther)
            <div class="card mb-4 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
                <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
                <p class="text-sm text-[var(--text-body)]">
                    {{ $document->lockedBy?->name ?? 'Somebody else' }} has this document checked out, so you
                    are reading rather than editing. Nothing you type here would be saved.
                </p>
            </div>
        @elseif (! $lockHeld)
            <div class="card mb-4 flex flex-col gap-3 border-l-4 border-l-amber-400 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
                    <p class="text-sm text-[var(--text-body)]">
                        You have not checked this document out, so the editor is read only. Checking out takes
                        it in your name — everyone can see who holds it, and nobody else can save over you.
                    </p>
                </div>

                @if ($canCheckOut)
                    <form method="POST" action="{{ route('documents.check-out', $document) }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">
                            <x-icon name="lock" class="h-3.5 w-3.5"/> Check out and edit
                        </button>
                    </form>
                @endif
            </div>
        @endif

        {{-- ------------------------------------------------------------ save error --}}
        <div class="card mb-4 flex items-start gap-3 border-l-4 border-l-rose-400 p-4"
             x-show="state === 'error'" x-cloak>
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">This revision has not been saved.</p>
                <p class="mt-1 text-[var(--text-muted)]" x-text="message"></p>
            </div>
        </div>

        {{-- ------------------------------------------------------------ toolbar --}}
        @unless ($readOnly)
            <div class="card sticky top-2 z-10 mb-3 flex flex-wrap items-center gap-1 p-2">
                <button type="button" class="toolbar-btn" @click="run('toggleBold')"
                        :class="active.bold && 'toolbar-btn-active'" title="Bold"><b>B</b></button>
                <button type="button" class="toolbar-btn" @click="run('toggleItalic')"
                        :class="active.italic && 'toolbar-btn-active'" title="Italic"><i>I</i></button>
                <button type="button" class="toolbar-btn" @click="run('toggleUnderline')"
                        :class="active.underline && 'toolbar-btn-active'" title="Underline"><u>U</u></button>
                <button type="button" class="toolbar-btn" @click="run('toggleStrike')"
                        :class="active.strike && 'toolbar-btn-active'" title="Strikethrough"><s>S</s></button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 1 })"
                        :class="active.h1 && 'toolbar-btn-active'" title="Heading 1">H1</button>
                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 2 })"
                        :class="active.h2 && 'toolbar-btn-active'" title="Heading 2">H2</button>
                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 3 })"
                        :class="active.h3 && 'toolbar-btn-active'" title="Heading 3">H3</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('toggleBulletList')"
                        :class="active.bullet && 'toolbar-btn-active'" title="Bulleted list">••</button>
                <button type="button" class="toolbar-btn" @click="run('toggleOrderedList')"
                        :class="active.ordered && 'toolbar-btn-active'" title="Numbered list">1.</button>
                <button type="button" class="toolbar-btn" @click="run('toggleBlockquote')"
                        :class="active.quote && 'toolbar-btn-active'" title="Quotation">&ldquo;</button>
                <button type="button" class="toolbar-btn" @click="run('toggleCodeBlock')"
                        :class="active.code && 'toolbar-btn-active'" title="Code block">&lt;/&gt;</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'left')"
                        :class="active.left && 'toolbar-btn-active'" title="Align left">⇤</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'center')"
                        :class="active.center && 'toolbar-btn-active'" title="Centre">⇔</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'right')"
                        :class="active.right && 'toolbar-btn-active'" title="Align right">⇥</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'justify')"
                        :class="active.justify && 'toolbar-btn-active'" title="Justify">≡</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="insertTable()" title="Insert a 3×3 table">Table</button>
                <template x-if="active.table">
                    <span class="flex items-center gap-1">
                        <button type="button" class="toolbar-btn" @click="run('addRowAfter')" title="Add row">+Row</button>
                        <button type="button" class="toolbar-btn" @click="run('addColumnAfter')" title="Add column">+Col</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteRow')" title="Delete row">−Row</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteColumn')" title="Delete column">−Col</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteTable')" title="Delete table">−Table</button>
                    </span>
                </template>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('setHorizontalRule')" title="Horizontal rule">—</button>
                <button type="button" class="toolbar-btn" @click="run('undo')" title="Undo">↶</button>
                <button type="button" class="toolbar-btn" @click="run('redo')" title="Redo">↷</button>
            </div>
        @endunless

        {{-- ------------------------------------------------------------ canvas --}}
        <div class="document-canvas" x-ref="canvas"></div>

        <p class="mt-3 text-xs text-[var(--text-muted)]">
            @unless ($readOnly)
                Saved automatically a couple of seconds after you stop typing, and again when you press
                Save. Every save is sanitised on the server and recorded against this revision.
            @else
                Read only.
            @endunless
        </p>
    </div>

</x-layouts.app>
