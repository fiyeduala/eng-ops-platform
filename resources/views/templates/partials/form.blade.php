{{--
    A template's content and page setup.

    The body is edited with the same editor a document is edited with, deliberately (§90). A separate
    "template markup" field would mean administrators writing HTML by hand into the thing that
    defines what every document in the company looks like — and the first person to paste a stray
    div would find out at PDF time.

    THE EDITOR MIRRORS INTO HIDDEN INPUTS ON EVERY KEYSTROKE, not on submit. A form can be submitted
    by pressing Enter in a text field, and a mirror that only ran on the Save button would then post
    the previous content over the new one.

    PLACEHOLDERS ARE INSERTED FROM THE SERVER'S OWN WHITELIST. They are typed text either way — the
    renderer resolves only paths it recognises and leaves everything else standing — but a picker
    means an administrator learns the real token name here, rather than from a document that shipped
    with the token still printed on it.

    Expects: $template, $kinds, $categories, $pageSizes, $orientations, $placeholders
--}}
@php
    $margins = $template->marginSet();
@endphp

<div class="space-y-6">

    {{-- ---------------------------------------------------------------- identity --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What this template is</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Name" name="name" required
                     help="What an author picks from a list. Company letterhead, Method statement, RFI response.">
                <x-input name="name" :value="$template->name" required maxlength="150"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Kind" name="kind" required
                         help="A document body seeds a new document. The rest render straight to PDF.">
                    <x-select name="kind" :options="$kinds" :selected="old('kind', $template->kind)"/>
                </x-field>

                <x-field label="Register" name="document_category_id"
                         help="Tie it to one register, or leave it blank and it is offered everywhere.">
                    <x-select name="document_category_id" :options="$categories"
                              :selected="old('document_category_id', $template->document_category_id)"
                              placeholder="Offered in every register"/>
                </x-field>
            </div>

            <x-field label="Description" name="description"
                     help="When to reach for this one rather than another. Read by whoever is choosing.">
                <x-textarea name="description" :value="$template->description" rows="2"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- the body --}}
    <div class="card"
         x-data="richTextField({ content: @js(old('content_html', $template->content_html ?? '')) })">

        <div class="card-header flex items-center justify-between">
            <h3 class="card-title">The body</h3>
            <span class="text-xs text-[var(--text-muted)]">Edited exactly as a document is edited.</span>
        </div>

        <div class="p-5">
            {{-- ------------------------------------------------------ toolbar --}}
            <div class="mb-3 flex flex-wrap items-center gap-1 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] p-2">
                <button type="button" class="toolbar-btn" @click="run('toggleBold')"
                        :class="active.bold && 'toolbar-btn-active'" title="Bold"><b>B</b></button>
                <button type="button" class="toolbar-btn" @click="run('toggleItalic')"
                        :class="active.italic && 'toolbar-btn-active'" title="Italic"><i>I</i></button>
                <button type="button" class="toolbar-btn" @click="run('toggleUnderline')"
                        :class="active.underline && 'toolbar-btn-active'" title="Underline"><u>U</u></button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 1 })"
                        :class="active.h1 && 'toolbar-btn-active'" title="Heading 1">H1</button>
                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 2 })"
                        :class="active.h2 && 'toolbar-btn-active'" title="Heading 2">H2</button>
                <button type="button" class="toolbar-btn" @click="run('toggleHeading', { level: 3 })"
                        :class="active.h3 && 'toolbar-btn-active'" title="Heading 3">H3</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('toggleBulletList')"
                        :class="active.bullet && 'toolbar-btn-active'" title="Bulleted list">&bull;&bull;</button>
                <button type="button" class="toolbar-btn" @click="run('toggleOrderedList')"
                        :class="active.ordered && 'toolbar-btn-active'" title="Numbered list">1.</button>
                <button type="button" class="toolbar-btn" @click="run('toggleBlockquote')"
                        :class="active.quote && 'toolbar-btn-active'" title="Quotation">&ldquo;</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'left')"
                        :class="active.left && 'toolbar-btn-active'" title="Align left">&#8676;</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'center')"
                        :class="active.center && 'toolbar-btn-active'" title="Centre">&#8660;</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'right')"
                        :class="active.right && 'toolbar-btn-active'" title="Align right">&#8677;</button>
                <button type="button" class="toolbar-btn" @click="run('setTextAlign', 'justify')"
                        :class="active.justify && 'toolbar-btn-active'" title="Justify">&equiv;</button>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="insertTable()" title="Insert a three by three table">Table</button>
                <template x-if="active.table">
                    <span class="flex items-center gap-1">
                        <button type="button" class="toolbar-btn" @click="run('addRowAfter')" title="Add row">+Row</button>
                        <button type="button" class="toolbar-btn" @click="run('addColumnAfter')" title="Add column">+Col</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteRow')" title="Delete row">&minus;Row</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteColumn')" title="Delete column">&minus;Col</button>
                        <button type="button" class="toolbar-btn" @click="run('deleteTable')" title="Delete table">&minus;Table</button>
                    </span>
                </template>

                <span class="mx-1 h-5 w-px bg-[var(--surface-border)]"></span>

                <button type="button" class="toolbar-btn" @click="run('setHorizontalRule')" title="Horizontal rule">&mdash;</button>
                <button type="button" class="toolbar-btn" @click="run('undo')" title="Undo">&#8630;</button>
                <button type="button" class="toolbar-btn" @click="run('redo')" title="Redo">&#8631;</button>

                {{-- ------------------------------------------------ placeholders --}}
                <div class="relative ml-auto" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="btn btn-ghost btn-sm" @click="open = ! open">
                        Insert a placeholder
                        <x-icon name="chevron-down" class="h-3.5 w-3.5"/>
                    </button>

                    <div x-show="open" x-cloak
                         class="absolute right-0 z-20 mt-1 max-h-80 w-72 overflow-y-auto rounded-md border border-[var(--surface-border)] bg-[var(--surface-card)] p-2 shadow-lg">
                        @foreach ($placeholders as $namespace => $tokens)
                            <p class="px-2 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                {{ $namespace }}
                            </p>
                            @foreach ($tokens as $token)
                                <button type="button"
                                        class="block w-full rounded px-2 py-1 text-left font-mono text-xs text-[var(--text-body)] hover:bg-[var(--surface-muted)]"
                                        @click="insertPlaceholder(@js($token)); open = false">
                                    {{ $token }}
                                </button>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------ canvas --}}
            <div class="document-canvas" x-ref="canvas"></div>

            {{-- The actual posted value. Mirrored on every change, never on submit. --}}
            <input type="hidden" name="content_html" x-bind:value="html">

            @error('content_html')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror

            <p class="help mt-2">
                Placeholders are filled in once, at the moment a document is created from this
                template &mdash; never afterwards. A procedure approved under one project name keeps
                saying that name even if the project is later renamed.
            </p>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- page setup --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Page setup</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Paper" name="page_size" required>
                    <x-select name="page_size" :options="$pageSizes" :selected="old('page_size', $template->page_size)"/>
                </x-field>

                <x-field label="Orientation" name="orientation" required>
                    <x-select name="orientation" :options="$orientations"
                              :selected="old('orientation', $template->orientation)"/>
                </x-field>
            </div>

            <div>
                <span class="label">Margins (mm)</span>
                <div class="mt-1 grid gap-3 sm:grid-cols-4">
                    @foreach (['top' => 'Top', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'] as $edge => $label)
                        <div>
                            <label for="margins_{{ $edge }}" class="text-xs text-[var(--text-muted)]">{{ $label }}</label>
                            <input type="number" step="0.5" min="0" max="100"
                                   name="margins[{{ $edge }}]" id="margins_{{ $edge }}" class="input"
                                   value="{{ old('margins.'.$edge, $margins[$edge]) }}">
                            @error('margins.'.$edge)
                                <p class="error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
                <p class="help">
                    Binding margins are a real constraint in document control, so these are settings
                    rather than something baked into the stylesheet.
                </p>
            </div>

            <x-field label="Header" name="header_html"
                     help="Appears at the top of every page of the PDF. Plain text or simple markup; placeholders work here too.">
                <x-textarea name="header_html" :value="$template->header_html" rows="3"/>
            </x-field>

            <x-field label="Footer" name="footer_html"
                     help="Appears at the bottom of every page. A controlled document usually carries its number, revision and issue status here.">
                <x-textarea name="footer_html" :value="$template->footer_html" rows="3"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- availability --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Availability</h3>
        </div>

        <div class="space-y-5 p-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $template->exists ? $template->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Offer this when creating documents</span>
                    <span class="block text-[var(--text-muted)]">
                        Retiring a template takes it off the list. Documents already created from it
                        are untouched &mdash; their content is their own from the moment they were made.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1"
                       @checked(old('is_default', $template->is_default))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Use this one unless the author picks another</span>
                    <span class="block text-[var(--text-muted)]">
                        One default per kind, per register. Setting this here clears it from
                        whichever template currently holds it, so the two can never disagree.
                    </span>
                </span>
            </label>
        </div>
    </div>
</div>
