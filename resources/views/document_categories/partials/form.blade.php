{{--
    A register's configuration.

    Grouped by the question each group answers, because this form decides the behaviour of every
    document that will ever be raised into the register, and a flat list of nineteen fields is how
    somebody sets the retention period to 12 months by accident.

    THE UPLOAD SECTION IS THE ONE WITH TEETH. Whatever is typed there, FileVault refuses executables
    and scripts unconditionally — that list is a constant in the class, not configuration (§67). This
    field narrows an already-safe set; it can never widen it, and the form says so rather than
    letting an administrator believe otherwise.

    Expects: $category, $parents, $workflows, $schemes, $confidentialities, $kinds,
             $forbiddenExtensions, $defaultExtensions
--}}
@php
    // The column is an array; the field is a comma-separated line, because asking somebody to
    // manage a repeater of one-word rows is worse than asking them to type "pdf, dwg, docx".
    // DocumentCategoryRequest normalises the string back into an array before validating it.
    $extensionValue = old(
        'allowed_extensions',
        is_array($category->allowed_extensions) ? implode(', ', $category->allowed_extensions) : '',
    );

    if (is_array($extensionValue)) {
        $extensionValue = implode(', ', $extensionValue);
    }
@endphp

<div class="space-y-6">

    {{-- ---------------------------------------------------------------- identity --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What this register is</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Name" name="name" required
                     help="What people call it in conversation. Drawings, Method statements, Correspondence received.">
                <x-input name="name" :value="$category->name" required maxlength="120"/>
            </x-field>

            <x-field label="Code" name="code"
                     help="Short, and used in document numbers. Letters, digits and hyphens only.">
                <x-input name="code" :value="$category->code" maxlength="16" placeholder="e.g. DWG"/>
            </x-field>

            <x-field label="Sits inside" name="parent_id"
                     help="Optional, and for reading only. Access is decided by the access engine, never by where a register sits in the tree.">
                <x-select name="parent_id" :options="$parents" :selected="old('parent_id', $category->parent_id)"
                          placeholder="Top level"/>
            </x-field>

            <x-field label="Description" name="description"
                     help="What belongs in here and what does not. Read by whoever files the next document.">
                <x-textarea name="description" :value="$category->description" rows="3"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- numbering and revisions --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Numbering and revisions</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Document numbers" name="numbering_pattern"
                     help="Leave blank to use the organisation's default. Placeholders are filled from the document itself.">
                <x-input name="numbering_pattern" :value="$category->numbering_pattern"
                         maxlength="200" placeholder="e.g. {PROJECT}-{CODE}-{SEQ:4}"/>
            </x-field>

            <div class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-xs text-[var(--text-muted)]">
                <p class="font-medium text-[var(--text-body)]">Placeholders</p>
                <p class="mt-1">
                    <span class="font-mono">{PROJECT}</span> the project code &middot;
                    <span class="font-mono">{CODE}</span> this register's code &middot;
                    <span class="font-mono">{ORG}</span> the organisation code &middot;
                    <span class="font-mono">{YYYY}</span> and <span class="font-mono">{MM}</span> the date &middot;
                    <span class="font-mono">{SEQ:4}</span> a running number padded to four digits.
                </p>
                <p class="mt-1">
                    The sequence counts within this register, per organisation. Two companies on the
                    platform both start at 1, and neither can infer the other exists from a number.
                </p>
            </div>

            <x-field label="How revisions are labelled" name="revision_scheme" required
                     help="Worth settling before the first document. Changing it later leaves an existing revision history written in two different languages.">
                <x-select name="revision_scheme" :options="$schemes"
                          :selected="old('revision_scheme', $category->revision_scheme)"/>
            </x-field>

            <x-field label="Draft series prefix" name="draft_revision_prefix"
                     help="Set this and drafts number P01, P02 before the first issued revision. Leave it blank and revisions start at the issued series straight away.">
                <x-input name="draft_revision_prefix" :value="$category->draft_revision_prefix"
                         maxlength="8" placeholder="e.g. P"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- approval --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Approval</h3>
        </div>

        <div class="space-y-5 p-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="requires_approval" value="0">
                <input type="checkbox" name="requires_approval" value="1"
                       @checked(old('requires_approval', $category->requires_approval))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Documents here need approving before they are issued</span>
                    <span class="block text-[var(--text-muted)]">
                        Off for registers that only hold what other people sent you — vendor data,
                        correspondence received. Nobody approves an incoming letter.
                    </span>
                </span>
            </label>

            <x-field label="Approval route" name="workflow_id"
                     help="Only published routes are offered. A register pointed at a draft would send its first document somewhere that does not exist yet.">
                <x-select name="workflow_id" :options="$workflows" :selected="old('workflow_id', $category->workflow_id)"
                          placeholder="Use the module default"/>
            </x-field>

            @if ($workflows === [])
                <p class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-xs text-[var(--text-muted)]">
                    No published route exists for documents yet. Leave this blank — the module default
                    applies, and this register can be pointed at a specific route once one is published.
                </p>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- control and confidentiality --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Control and confidentiality</h3>
        </div>

        <div class="space-y-5 p-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="is_controlled" value="0">
                <input type="checkbox" name="is_controlled" value="1"
                       @checked(old('is_controlled', $category->is_controlled))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">These are controlled documents</span>
                    <span class="block text-[var(--text-muted)]">
                        A controlled document carries its revision and issue status on every printed
                        copy, and superseding one is recorded. Turn it on for anything an auditor may
                        one day ask to see the history of.
                    </span>
                </span>
            </label>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Default confidentiality" name="default_confidentiality" required
                         help="What a new document starts as. An author can raise it; nothing lowers it silently.">
                    <x-select name="default_confidentiality" :options="$confidentialities"
                              :selected="old('default_confidentiality', $category->default_confidentiality?->value)"/>
                </x-field>

                <x-field label="Default kind" name="default_kind" required
                         help="Written here, or uploaded. Both are allowed in any register — this only decides which one the New Document screen offers first.">
                    <x-select name="default_kind" :options="$kinds"
                              :selected="old('default_kind', $category->default_kind?->value)"/>
                </x-field>
            </div>

            <x-field label="Keep for (months)" name="retention_months"
                     help="Recorded so a retention report can be run against it. Nothing is deleted automatically — a platform that quietly destroys records on a schedule is not one to trust with records.">
                <x-input name="retention_months" type="number" min="1" max="1200"
                         :value="$category->retention_months" placeholder="e.g. 84"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- uploads --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What may be uploaded</h3>
        </div>

        <div class="space-y-5 p-5">
            <div>
                <label for="allowed_extensions" class="label">Permitted file types</label>
                <input type="text" name="allowed_extensions" id="allowed_extensions" class="input"
                       value="{{ $extensionValue }}" placeholder="pdf, dwg, docx">
                <p class="help">
                    Comma separated, no dots. Leave blank to use the organisation-wide list:
                    <span class="font-mono">{{ implode(', ', array_slice($defaultExtensions, 0, 12)) }}</span>
                    and {{ max(count($defaultExtensions) - 12, 0) }} more.
                </p>
                @error('allowed_extensions')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror
                @error('allowed_extensions.*')
                    <p class="error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                <p class="font-medium">This setting narrows. It never widens.</p>
                <p class="mt-1">
                    Executable and script files are refused by the platform whatever a register says —
                    <span class="font-mono">{{ implode(', ', array_slice($forbiddenExtensions, 0, 10)) }}</span>
                    and {{ max(count($forbiddenExtensions) - 10, 0) }} others. That list is code, not
                    configuration, precisely so nobody with access to this screen can turn a document
                    library into a way to run something on the server.
                </p>
            </div>

            <x-field label="Largest file (MB)" name="max_upload_mb"
                     help="Leave blank for the organisation default. The hosting has its own limit too, and the smaller of the two wins.">
                <x-input name="max_upload_mb" type="number" min="1" max="512" :value="$category->max_upload_mb"
                         placeholder="e.g. 50"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- housekeeping --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Housekeeping</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Sort order" name="sort" help="Lower numbers come first. Ties are broken by name.">
                <x-input name="sort" type="number" min="0" max="9999" :value="$category->sort ?? 0"/>
            </x-field>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $category->exists ? $category->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Open for new documents</span>
                    <span class="block text-[var(--text-muted)]">
                        Closing a register stops new documents being raised into it. Everything already
                        filed there stays readable, and stays where it is.
                    </span>
                </span>
            </label>
        </div>
    </div>
</div>
