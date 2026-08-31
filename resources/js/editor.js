/*
 * The document editor.
 *
 * This is the "replace Word, or at least stop needing it" half of the document engine. It is a
 * rich-text editor over a structured model, not a textarea with a toolbar bolted on, because
 * everything downstream — .docx export, PDF rendering, revision diffs, the sanitiser's whitelist —
 * depends on the content being structure rather than markup somebody typed.
 *
 * THREE DECISIONS WORTH KNOWING ABOUT:
 *
 * 1. WHAT IS SAVED. Both the HTML and TipTap's own JSON model. The HTML is the source of truth for
 *    everything that renders or exports; the JSON exists so reopening the editor restores exactly
 *    what was saved rather than a re-parse of the HTML, which loses table column widths and empty
 *    paragraphs. The server sanitises the HTML on every write and never trusts or renders the JSON.
 *
 * 2. AUTOSAVE IS DEBOUNCED AND SERIALISED. One save in flight at a time; a change during a save
 *    queues exactly one more. Firing per keystroke would make the audit trail unreadable and would
 *    let a slow response overwrite a newer body with an older one.
 *
 * 3. NO REAL-TIME COLLABORATION, deliberately. That needs a persistent connection this platform
 *    will not require in V1 (§1). The document is exclusively checked out instead — which is how
 *    engineering document control has always worked, so it needs no explaining — and the editor
 *    refuses to save at all when the lock is not held.
 */

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { TableKit } from '@tiptap/extension-table';

export default function documentEditor({ content = '', saveUrl = '', readOnly = false, autosave = true }) {
    return {
        editor: null,

        /** 'idle' | 'dirty' | 'saving' | 'saved' | 'error' */
        state: 'idle',
        message: '',
        savedAt: null,
        words: 0,

        // Autosave bookkeeping. `queued` is a boolean rather than a counter: what matters is
        // "something changed since the save started", not how many times.
        timer: null,
        inFlight: false,
        queued: false,

        init() {
            this.editor = new Editor({
                element: this.$refs.canvas,
                editable: !readOnly,
                content: content || '<p></p>',
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [1, 2, 3, 4] },
                    }),
                    TextAlign.configure({ types: ['heading', 'paragraph'] }),
                    TableKit.configure({ table: { resizable: true } }),
                ],
                editorProps: {
                    attributes: {
                        class: 'prose-document focus:outline-none',
                        spellcheck: 'true',
                    },
                },
                onCreate: () => {
                    this.words = this.countWords();
                    this.$nextTick(() => this.refresh());
                },
                onUpdate: () => {
                    this.words = this.countWords();
                    this.state = 'dirty';
                    this.refresh();

                    if (autosave && !readOnly) {
                        this.schedule();
                    }
                },
                onSelectionUpdate: () => this.refresh(),
            });

            // Leaving with unsaved work is the one thing this editor should be loud about: the
            // lock means nobody else has a copy of what is on screen.
            window.addEventListener('beforeunload', (event) => {
                if (this.state === 'dirty' || this.inFlight) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });


        },

        destroy() {
            this.editor?.destroy();
        },

        // ------------------------------------------------------------------ toolbar

        /**
         * A plain object the toolbar binds to.
         *
         * Rebuilt on every selection change rather than each button asking the editor itself,
         * because Alpine cannot see inside TipTap's state and would never re-render the buttons.
         */
        active: {},

        refresh() {
            if (!this.editor) {
                return;
            }

            this.active = {
                bold: this.editor.isActive('bold'),
                italic: this.editor.isActive('italic'),
                underline: this.editor.isActive('underline'),
                strike: this.editor.isActive('strike'),
                h1: this.editor.isActive('heading', { level: 1 }),
                h2: this.editor.isActive('heading', { level: 2 }),
                h3: this.editor.isActive('heading', { level: 3 }),
                bullet: this.editor.isActive('bulletList'),
                ordered: this.editor.isActive('orderedList'),
                quote: this.editor.isActive('blockquote'),
                code: this.editor.isActive('codeBlock'),
                left: this.editor.isActive({ textAlign: 'left' }),
                center: this.editor.isActive({ textAlign: 'center' }),
                right: this.editor.isActive({ textAlign: 'right' }),
                justify: this.editor.isActive({ textAlign: 'justify' }),
                table: this.editor.isActive('table'),
            };
        },

        run(command, ...args) {
            if (readOnly || !this.editor) {
                return;
            }

            const chain = this.editor.chain().focus();

            if (typeof chain[command] !== 'function') {
                return;
            }

            chain[command](...args).run();
            this.refresh();
        },

        insertTable() {
            this.run('insertTable', { rows: 3, cols: 3, withHeaderRow: true });
        },

        countWords() {
            const text = this.editor?.getText()?.trim() ?? '';

            return text === '' ? 0 : text.split(/\s+/).length;
        },

        // ------------------------------------------------------------------ saving

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.save(), 2500);
        },

        async save() {
            if (readOnly || !this.editor) {
                return;
            }

            if (this.inFlight) {
                this.queued = true;

                return;
            }

            clearTimeout(this.timer);
            this.inFlight = true;
            this.state = 'saving';
            this.message = '';

            try {
                const response = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        _method: 'PUT',
                        content_html: this.editor.getHTML(),
                        content_json: this.editor.getJSON(),
                    }),
                });

                if (!response.ok) {
                    // 422 carries the service's own refusal — an approved revision, a lost lock —
                    // and it is worth showing verbatim rather than as "save failed".
                    const body = await response.json().catch(() => ({}));

                    this.state = 'error';
                    this.message = body.message
                        ?? (response.status === 419
                            ? 'Your session expired. Open this page again in a new tab and copy your work across before reloading.'
                            : 'Could not save. Your work is still on screen — do not close this tab.');

                    return;
                }

                const body = await response.json().catch(() => ({}));

                this.savedAt = body.saved_at ? new Date(body.saved_at) : new Date();
                this.state = 'saved';
                this.message = '';
            } catch (error) {
                this.state = 'error';
                this.message = 'No connection. Your work is still on screen — do not close this tab.';
            } finally {
                this.inFlight = false;

                if (this.queued) {
                    this.queued = false;
                    this.schedule();
                }
            }
        },

        get statusLabel() {
            switch (this.state) {
                case 'saving':
                    return 'Saving…';
                case 'saved':
                    return this.savedAt
                        ? 'Saved at ' + this.savedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                        : 'Saved';
                case 'dirty':
                    return 'Unsaved changes';
                case 'error':
                    return 'Not saved';
                default:
                    return readOnly ? 'Read only' : 'No changes yet';
            }
        },
    };
}

/*
 * The same editor, embedded in an ordinary form.
 *
 * Templates are administration: they are written once, saved with a Save button, and validated
 * server-side alongside a dozen other fields. Autosaving them against a REST endpoint would mean a
 * second write path, a second authorization check and a second sanitiser call for content that is
 * already going through a form request — so this variant carries no save logic at all. It mirrors
 * the editor into hidden inputs and lets the form post.
 *
 * The mirror runs on every update rather than only on submit, because a form can be submitted by
 * pressing Enter in a text field, and a hidden input that is only filled by a click handler is a
 * silent data loss waiting for somebody in a hurry.
 */
export function richTextField({ content = '', name = 'content_html', jsonName = null }) {
    return {
        editor: null,
        active: {},
        html: content,
        json: '',

        init() {
            this.editor = new Editor({
                element: this.$refs.canvas,
                content: content || '<p></p>',
                extensions: [
                    StarterKit.configure({ heading: { levels: [1, 2, 3, 4] } }),
                    TextAlign.configure({ types: ['heading', 'paragraph'] }),
                    TableKit.configure({ table: { resizable: true } }),
                ],
                editorProps: {
                    attributes: { class: 'prose-document focus:outline-none', spellcheck: 'true' },
                },
                onCreate: () => { this.mirror(); this.refresh(); },
                onUpdate: () => { this.mirror(); this.refresh(); },
                onSelectionUpdate: () => this.refresh(),
            });
        },

        destroy() {
            this.editor?.destroy();
        },

        mirror() {
            this.html = this.editor.getHTML();
            this.json = JSON.stringify(this.editor.getJSON());
        },

        refresh() {
            if (!this.editor) {
                return;
            }

            this.active = {
                bold: this.editor.isActive('bold'),
                italic: this.editor.isActive('italic'),
                underline: this.editor.isActive('underline'),
                strike: this.editor.isActive('strike'),
                h1: this.editor.isActive('heading', { level: 1 }),
                h2: this.editor.isActive('heading', { level: 2 }),
                h3: this.editor.isActive('heading', { level: 3 }),
                bullet: this.editor.isActive('bulletList'),
                ordered: this.editor.isActive('orderedList'),
                quote: this.editor.isActive('blockquote'),
                left: this.editor.isActive({ textAlign: 'left' }),
                center: this.editor.isActive({ textAlign: 'center' }),
                right: this.editor.isActive({ textAlign: 'right' }),
                justify: this.editor.isActive({ textAlign: 'justify' }),
                table: this.editor.isActive('table'),
            };
        },

        run(command, ...args) {
            if (!this.editor) {
                return;
            }

            const chain = this.editor.chain().focus();

            if (typeof chain[command] !== 'function') {
                return;
            }

            chain[command](...args).run();
            this.refresh();
        },

        insertTable() {
            this.run('insertTable', { rows: 3, cols: 3, withHeaderRow: true });
        },

        /* Drop a placeholder in at the cursor. The names come from the server's whitelist. */
        insertPlaceholder(token) {
            this.editor?.chain().focus().insertContent(token).run();
        },

        fieldName: name,
        jsonFieldName: jsonName,
    };
}
