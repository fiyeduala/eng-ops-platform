{{--
    Raise or amend a document.

    Shared by create and edit, and the differences between the two are real rather than cosmetic:

      - KIND is chosen once. A native document has content rows and a file-based one has files;
        switching afterwards would orphan one or the other, so on edit the control is replaced by a
        statement of fact. DocumentRequest drops the field entirely on update — the disabled control
        is the courtesy, the server rule is the guarantee.
      - THE TEMPLATE only appears on create, because it seeds the FIRST revision's body and
        placeholders are resolved once, at that moment. Applying a template later would be a
        different act (a new revision) wearing this form's clothes.
      - THE NUMBER is never an input. It is shown as a preview of what the numbering engine will
        issue, and it is issued on save.

    Expects: $document, $categories, $projects, $units, $disciplines, $owners,
             $confidentialities, $kinds, $templates, $nextNumber
--}}
@php
    $isNew = ! $document->exists;
    $tags = old('tags', $document->tags ?? []);
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ---------------------------------------------------------------- identity --}}
    <div class="lg:col-span-2 space-y-6">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What it is</h3>
            </div>

            <div class="space-y-4 p-5">
                <x-field label="Title" name="title" required
                         help="What somebody searching for this in three years will type.">
                    <x-input name="title" :value="$document->title" required autofocus maxlength="250"/>
                </x-field>

                <x-field label="Description" name="description"
                         help="Optional. Scope, purpose, what it supersedes — anything the title cannot carry.">
                    <x-textarea name="description" :value="$document->description" rows="3"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Register" name="document_category_id"
                             help="Supplies the numbering, the revision scheme and the approval route.">
                        <x-select name="document_category_id"
                                  :options="$categories"
                                  :selected="$document->document_category_id"
                                  placeholder="Not filed yet"/>
                    </x-field>

                    <x-field label="Owner" name="owner_id"
                             help="Who is answerable for it — not necessarily who typed it in.">
                        <x-select name="owner_id"
                                  :options="$owners"
                                  :selected="$document->owner_id ?? auth()->id()"
                                  placeholder="Nobody yet"/>
                    </x-field>
                </div>

                @if ($isNew && $nextNumber)
                    <div class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-sm text-[var(--text-muted)]">
                        This will be issued as
                        <span class="font-mono font-medium text-[var(--text-strong)]">{{ $nextNumber }}</span>
                        when you save. Numbers are per company and never reused.
                    </div>
                @elseif ($document->number)
                    <div class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-sm text-[var(--text-muted)]">
                        Number
                        <span class="font-mono font-medium text-[var(--text-strong)]">{{ $document->number }}</span>
                        — fixed for the life of the document, whatever the revisions do.
                    </div>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------ placement --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it belongs</h3>
                <span class="text-xs text-[var(--text-muted)]">Placement decides who reaches it. You can only file it somewhere you reach yourself.</span>
            </div>

            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects" :selected="$document->project_id"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units" :selected="$document->org_unit_id"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id"
                         help="Civil, Piping, Electrical — disciplines are departments here, not a separate list.">
                    <x-select name="discipline_id" :options="$disciplines" :selected="$document->discipline_id"
                              placeholder="Not discipline-specific"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Raising this can hide the document from people who can see it today.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="$document->confidentiality?->value"/>
                </x-field>
            </div>
        </div>

        {{-- ------------------------------------------------------------ tags --}}
        <div class="card" x-data="{
            tags: @js(array_values(array_filter((array) $tags))),
            draft: '',
            add() {
                const value = this.draft.trim().slice(0, 40);
                if (value !== '' && ! this.tags.includes(value) && this.tags.length < 20) {
                    this.tags.push(value);
                }
                this.draft = '';
            },
        }">
            <div class="card-header">
                <h3 class="card-title">Tags</h3>
                <span class="text-xs text-[var(--text-muted)]">Free labels for filtering. Up to twenty.</span>
            </div>

            <div class="p-5">
                <template x-for="(tag, i) in tags" :key="tag">
                    <input type="hidden" name="tags[]" :value="tag">
                </template>

                <div class="flex flex-wrap gap-1.5" x-show="tags.length">
                    <template x-for="(tag, i) in tags" :key="tag">
                        <span class="badge badge-neutral gap-1">
                            <span x-text="tag"></span>
                            <button type="button" class="opacity-60 hover:opacity-100"
                                    @click="tags.splice(i, 1)" aria-label="Remove tag">×</button>
                        </span>
                    </template>
                </div>

                <div class="mt-3 flex gap-2">
                    <input type="text" x-model="draft" maxlength="40" class="input"
                           placeholder="Add a tag and press Enter"
                           @keydown.enter.prevent="add()">
                    <button type="button" class="btn btn-secondary" @click="add()">Add</button>
                </div>

                @error('tags')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- control --}}
    <div class="space-y-6">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">How it is authored</h3>
            </div>

            <div class="space-y-4 p-5">
                @if ($isNew)
                    <x-field label="Kind" name="kind" required
                             help="Chosen once. Native documents are written here and export to Word or PDF; uploaded ones are versioned and guarded without being read.">
                        <x-select name="kind" :options="$kinds" :selected="$document->kind?->value"/>
                    </x-field>

                    @if ($templates !== [])
                        <x-field label="Start from a template" name="template_id"
                                 help="Only for native documents. The template's placeholders are filled in once, now — so an approved document keeps saying what it said when it was approved.">
                            <x-select name="template_id" :options="$templates" :selected="null"
                                      placeholder="Start from a blank page"/>
                        </x-field>
                    @endif
                @else
                    <div>
                        <span class="label">Kind</span>
                        <p class="text-sm text-[var(--text-body)]">
                            {{ $document->isNative() ? 'Written in the editor' : 'Uploaded files' }}
                        </p>
                        <p class="help">
                            Fixed at creation. A native document has content and a file-based one has files;
                            switching would orphan one or the other.
                        </p>
                    </div>
                @endif

                <label class="flex items-start gap-2">
                    <input type="hidden" name="is_controlled" value="0">
                    <input type="checkbox" name="is_controlled" value="1" class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]"
                           @checked(old('is_controlled', $document->is_controlled ?? true))>
                    <span class="text-sm">
                        <span class="font-medium text-[var(--text-strong)]">Controlled document</span>
                        <span class="mt-0.5 block text-[var(--text-muted)]">
                            Controlled documents require an approved revision before they may be issued,
                            and every copy taken is recorded.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Dates</h3>
            </div>

            <div class="space-y-4 p-5">
                <x-field label="Next review" name="next_review_on"
                         help="Optional. When somebody should look at whether this is still true.">
                    <x-input type="date" name="next_review_on"
                             :value="$document->next_review_on?->format('Y-m-d')"/>
                </x-field>

                <x-field label="Keep until" name="retention_until"
                         help="Optional. Retention is recorded, never enforced by deletion.">
                    <x-input type="date" name="retention_until"
                             :value="$document->retention_until?->format('Y-m-d')"/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="document" :record="$document" class="card mt-5"/>
