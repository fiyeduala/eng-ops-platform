{{--
    One drawing.

    THE PAGE IS A TITLE BLOCK plus a link. Everything about the FILE — its revisions, approvals,
    signatures, downloads — is one module over, in document control, and this page deliberately does
    not reproduce any of it. Two places that both claim to know the current revision is how a
    register starts disagreeing with itself.

    OPENING THE FILE GOES THROUGH DOCUMENT CONTROL, not through here. The button is a redirect, and
    the redirect lands on a page governed by the document's own policy. That is why the button is
    only drawn when BOTH `drawing.download` reach and the document's `view` succeed — an affordance
    that leads to a 403 is worse than no affordance (§67).

    Expects: $drawing, $timeline, $transitions, $purposeLabel, $workflowInstance, $documents,
             $canEdit, $canDelete, $canAttach, $canOpenFile
--}}
<x-layouts.app :title="$drawing->number">

    <x-page-header
        :title="$drawing->title"
        :description="$drawing->number.($drawing->revision ? ' · Rev '.$drawing->revision : '').($drawing->sheetLabel() ? ' · '.$drawing->sheetLabel() : '')">
        <x-slot:actions>
            @if ($canOpenFile)
                <a href="{{ route('drawings.file', $drawing) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="file-text" class="h-3.5 w-3.5"/> Open the file
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('drawings.edit', $drawing) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (! $drawing->hasFile())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">No controlled file has been attached to this line.</p>
                <p class="mt-1 text-[var(--text-muted)]">
                    That is normal while a drawing is still being produced. The register line exists
                    from the day the drawing list is agreed.
                </p>
            </div>
        </div>
    @endif

    @error('document_id')
        <div class="card mb-5 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ============================================================ main column --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- ------------------------------------------------------ the file --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Controlled file</h3>
                </div>

                <div class="p-5">
                    @if ($drawing->document)
                        <a href="{{ route('documents.show', $drawing->document) }}"
                           class="flex items-start gap-3 rounded-md border border-[var(--surface-border)] p-3 hover:border-brand-400">
                            <x-icon name="file-text" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
                            <span>
                                <span class="block text-sm font-medium text-[var(--text-strong)]">
                                    {{ $drawing->document->title }}
                                </span>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                    {{ $drawing->document->number }}
                                </span>
                            </span>
                        </a>

                        <p class="mt-3 text-xs text-[var(--text-muted)]">
                            Revisions, approvals and downloads are handled there, under the document's
                            own permissions. Whether you can open it is decided by that policy, not by
                            this register.
                        </p>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            Nothing attached. Attaching a file does not copy it here — the register
                            keeps a pointer, and document control keeps the file.
                        </p>
                    @endif

                    @if ($canAttach)
                        <form method="POST" action="{{ route('drawings.document', $drawing) }}"
                              class="mt-4 space-y-3 border-t border-[var(--surface-border)] pt-4">
                            @csrf

                            <x-field label="Link a document" name="document_id"
                                     help="Only documents you can already open are listed. Choosing nothing removes the link.">
                                <x-select name="document_id" :options="$documents"
                                          :selected="$drawing->document_id"
                                          placeholder="— no document —"/>
                            </x-field>

                            <button type="submit" class="btn btn-secondary btn-sm">
                                {{ $drawing->hasFile() ? 'Change the link' : 'Attach' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------------ revise --}}
            @if ($canEdit)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Revision</h3>
                    </div>

                    <div class="p-5" x-data="{ open: false }">
                        <p class="text-sm text-[var(--text-body)]">
                            Currently at
                            <span class="font-mono font-medium">{{ $drawing->revision ?? 'no revision' }}</span>@if ($drawing->revision_date),
                                dated {{ $drawing->revision_date->format('j M Y') }}@endif.
                        </p>

                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            The register keeps the current title block; the history of how it got here
                            is on the timeline below. A queryable revision-by-revision history lives in
                            document control, where the files and approvals are.
                        </p>

                        <button type="button" class="btn btn-secondary btn-sm mt-3" x-show="! open" @click="open = true">
                            Move to a new revision
                        </button>

                        <form method="POST" action="{{ route('drawings.revise', $drawing) }}"
                              class="mt-4 space-y-4" x-show="open" x-cloak>
                            @csrf

                            <div class="grid gap-4 sm:grid-cols-3">
                                <x-field label="New revision" name="revision" required>
                                    <x-input name="revision" required maxlength="16" placeholder="e.g. B"/>
                                </x-field>

                                <x-field label="Issue purpose" name="purpose">
                                    <x-select name="purpose" :options="$purposes" :selected="$drawing->purpose"
                                              placeholder="Unchanged"/>
                                </x-field>

                                <x-field label="Revision date" name="revision_date">
                                    <x-input type="date" name="revision_date" :value="now()->toDateString()"/>
                                </x-field>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Record the revision</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="open = false">Never mind</button>
                            </div>
                        </form>
                    </div>

                    <x-register.transitions :action="route('drawings.transition', $drawing)"
                                            :transitions="$transitions" noun="drawing"/>
                </div>
            @endif

            <x-timeline :entries="$timeline"
                        empty="Nothing has happened to this drawing since the line was created."/>
        </div>

        {{-- ============================================================ side column --}}
        <div class="space-y-5">

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Title block</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Number" :value="$drawing->number" mono/>
                    <x-register.fact label="Revision" :value="$drawing->revision" mono/>
                    <x-register.fact label="Sheet" :value="$drawing->sheetLabel()"/>
                    <x-register.fact label="Sheet size" :value="$drawing->sheet_size"/>
                    <x-register.fact label="Scale" :value="$drawing->scale"/>
                    <x-register.fact label="Issue purpose" :value="$purposeLabel"/>

                    <x-register.fact label="Status">
                        @if ($drawing->status)
                            <x-badge :tone="$drawing->status->tone()">{{ $drawing->status->label }}</x-badge>
                        @else
                            —
                        @endif
                    </x-register.fact>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who and where</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Drawn by" :value="$drawing->drawnBy?->name"/>
                    <x-register.fact label="Checked by" :value="$drawing->checkedBy?->name ?? 'Not checked'"/>

                    <x-register.fact label="Project">
                        @if ($drawing->project)
                            <a href="{{ route('projects.show', $drawing->project) }}" class="hover:text-brand-600">
                                {{ $drawing->project->displayName() }}
                            </a>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Discipline" :value="$drawing->discipline?->name"/>

                    <x-register.fact label="Deliverable">
                        @if ($drawing->deliverable)
                            <a href="{{ route('deliverables.show', $drawing->deliverable) }}" class="hover:text-brand-600">
                                {{ $drawing->deliverable->displayName() }}
                            </a>
                        @else
                            Not against a deliverable
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Confidentiality" :value="$drawing->confidentiality?->label()"/>

                    <x-register.fact label="Added">
                        {{ $drawing->created_at?->format('j M Y') ?? '—' }}
                        @if ($drawing->creator)
                            <span class="block text-xs text-[var(--text-muted)]">by {{ $drawing->creator->name }}</span>
                        @endif
                    </x-register.fact>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Remove from the register</h3>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            For a line added in error. The controlled file, if one is attached, is not
                            touched — it belongs to document control and stays there.
                        </p>

                        <x-delete-form :action="route('drawings.destroy', $drawing)"
                                       label="Remove this drawing"
                                       confirm="Remove this drawing from the register? Any attached document is left alone."/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$drawing" class="card mt-5"/>

</x-layouts.app>
