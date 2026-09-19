{{--
    The builder — one form, one version, every question on it.

    EVERYTHING IS ON ONE PAGE ON PURPOSE. "Is this already asked, and is it in the right section?"
    is the only question anybody building a form has, and a screen that makes you click into each
    section to answer it answers it badly. What changes between visits is which VERSION you are
    looking at, which is the strip under the heading.

    THE PAGE TELLS YOU WHAT IT WILL REFUSE, BEFORE YOU TRY. A published edition shows its
    questions with the key, the kind and the required box frozen, and says why in a sentence rather
    than by greying a control and leaving you to guess. The refusals themselves live in
    FormDefinitionService — the grey is CSS and a form post does not read CSS (§6, §84).

    Expects: $form, $version, $versions, $sections, $fieldsBySection, $columnsByParent, $tables,
             $types, $optionLists, $submissions, $canEdit, $canPublish, $canDelete
--}}
@php
    $editable = $version?->isEditable() ?? false;
    $sectionIds = $sections->pluck('id')->all();
@endphp

<x-layouts.app :title="$form->name">

    <x-page-header :title="$form->name" :description="$form->description">
        <x-slot:actions>
            @if ($canEdit && ! $editable)
                <form method="POST" action="{{ route('forms.draft', $form) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="pencil" class="h-3.5 w-3.5"/>
                        {{ $versions->contains(fn ($v) => $v->isDraft()) ? 'Carry on with the draft' : 'Edit the questions' }}
                    </button>
                </form>
            @endif
            <a href="{{ route('forms.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All forms
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That change was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ----------------------------------------------------------- which edition --}}
    @if ($versions->count() > 1)
        <div class="card mb-5 flex flex-wrap items-center gap-2 p-3">
            <span class="mr-1 text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Edition</span>
            @foreach ($versions as $row)
                <a href="{{ route('forms.show', ['form' => $form, 'version' => $row->id]) }}"
                   class="btn btn-sm {{ $version && $row->is($version) ? 'btn-primary' : 'btn-secondary' }}">
                    v{{ $row->version }}
                    <span class="ml-1 opacity-70">{{ $row->stateLabel() }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($version === null)
        <div class="card p-6 text-sm text-[var(--text-body)]">
            This form has no versions, which should not be possible. Nothing has been lost — tell
            whoever administers this system, because it means a draft was removed by something
            other than the builder.
        </div>
    @else

    {{-- ----------------------------------------------------------- what state this is in --}}
    <div class="card mb-5 flex items-start gap-3 p-4 text-sm text-[var(--text-body)]">
        @if ($version->isDraft())
            <x-icon name="pencil" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600"/>
            <p>
                <span class="font-medium text-[var(--text-strong)]">Version {{ $version->version }} is a draft.</span>
                Nobody can fill it in yet and everything on it can still be changed.
                @if ($versions->contains(fn ($v) => $v->isPublished()))
                    The live edition keeps serving people until you publish this one.
                @endif
            </p>
        @elseif ($version->isPublished())
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>
                <span class="font-medium text-[var(--text-strong)]">Version {{ $version->version }} is live</span>
                @if ($version->published_at)
                    — published {{ $version->published_at->format('j M Y') }}
                    @if ($version->publisher) by {{ $version->publisher->name }}@endif.
                @else
                    .
                @endif
                The wording can still be corrected. The questions themselves cannot: people have
                been answering these, and changing one now would change what their answers were
                answers to. Use <span class="font-medium">Edit the questions</span> to start the
                next version instead.
            </p>
        @else
            <x-icon name="archive" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                <span class="font-medium text-[var(--text-strong)]">Version {{ $version->version }} has been superseded.</span>
                It is kept, unchanged, because the submissions made against it are answers to these
                questions and to no others.
            </p>
        @endif
    </div>

    @if ($version->change_note)
        <div class="card mb-5 p-4 text-sm text-[var(--text-body)]">
            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">What changed in this version</p>
            <p>{{ $version->change_note }}</p>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------- the questions --}}
        <div class="space-y-5 lg:col-span-2">

            @foreach ($sections as $index => $section)
                @php
                    $up = $sectionIds;
                    if ($index > 0) { [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]]; }
                    $down = $sectionIds;
                    if ($index < count($sectionIds) - 1) { [$down[$index + 1], $down[$index]] = [$down[$index], $down[$index + 1]]; }

                    $questions = $fieldsBySection[$section->id] ?? collect();
                    $questionIds = $questions->pluck('id')->all();
                @endphp

                <div class="card" x-data="{ editing: false, adding: false }">
                    <div class="card-header flex items-start justify-between gap-3">
                        <div x-show="! editing" class="min-w-0">
                            <h2 class="card-title">{{ $section->title }}</h2>
                            @if ($section->description)
                                <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $section->description }}</p>
                            @endif
                        </div>

                        @if ($canEdit)
                            <div class="flex shrink-0 items-center gap-1" x-show="! editing">
                                @if ($editable && count($sectionIds) > 1)
                                    @foreach (['up' => $up, 'down' => $down] as $direction => $order)
                                        @continue(($direction === 'up' && $index === 0) || ($direction === 'down' && $index === count($sectionIds) - 1))
                                        <form method="POST" action="{{ route('forms.sections.reorder', [$form, $version]) }}">
                                            @csrf
                                            @foreach ($order as $id)
                                                <input type="hidden" name="order[]" value="{{ $id }}">
                                            @endforeach
                                            <button type="submit" class="text-[var(--text-muted)] hover:text-brand-600"
                                                    aria-label="Move {{ $section->title }} {{ $direction }}">
                                                <x-icon name="chevron-down" class="h-3.5 w-3.5 {{ $direction === 'up' ? 'rotate-180' : '' }}"/>
                                            </button>
                                        </form>
                                    @endforeach
                                @endif

                                {{-- Retitling is wording, so it is offered on a published edition
                                     too. A section carries nothing but words. --}}
                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = true"
                                        aria-label="Rename {{ $section->title }}">
                                    <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                </button>

                                @if ($editable && count($sectionIds) > 1)
                                    <form method="POST" action="{{ route('forms.sections.destroy', [$form, $section]) }}"
                                          onsubmit="return confirm('Remove &quot;{{ $section->title }}&quot; and the {{ $questions->count() }} question(s) in it from this draft?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                aria-label="Remove {{ $section->title }}">
                                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Renaming the section, in place. --}}
                    @if ($canEdit)
                        <form method="POST" action="{{ route('forms.sections.update', [$form, $section]) }}"
                              class="space-y-3 border-b border-[var(--surface-border)] p-4" x-show="editing" x-cloak>
                            @csrf
                            @method('PUT')

                            <div>
                                <label class="label" for="s{{ $section->id }}-title">Heading</label>
                                <input type="text" id="s{{ $section->id }}-title" name="title" class="input"
                                       value="{{ $section->title }}" required maxlength="160">
                            </div>

                            <div>
                                <label class="label" for="s{{ $section->id }}-desc">Description</label>
                                <input type="text" id="s{{ $section->id }}-desc" name="description" class="input"
                                       value="{{ $section->description }}" maxlength="500">
                                <p class="help">The line under the heading, telling people what this part is about.</p>
                            </div>

                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = false">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </div>
                        </form>
                    @endif

                    {{-- The questions. --}}
                    @if ($questions->isEmpty())
                        <p class="p-6 text-sm text-[var(--text-muted)]">
                            Nothing is asked here yet.
                        </p>
                    @else
                        <ul class="divide-y divide-[var(--surface-border)]">
                            @foreach ($questions as $position => $field)
                                @php
                                    $fUp = $questionIds;
                                    if ($position > 0) { [$fUp[$position - 1], $fUp[$position]] = [$fUp[$position], $fUp[$position - 1]]; }
                                    $fDown = $questionIds;
                                    if ($position < count($questionIds) - 1) { [$fDown[$position + 1], $fDown[$position]] = [$fDown[$position], $fDown[$position + 1]]; }

                                    $columns = $columnsByParent[$field->id] ?? collect();
                                @endphp

                                <li x-data="{ editing: false, adding: false }">
                                    <div class="flex items-start justify-between gap-3 p-3" x-show="! editing">
                                        <div class="min-w-0">
                                            <span class="font-medium text-[var(--text-strong)]">{{ $field->label }}</span>

                                            @if ($field->is_required)
                                                <x-badge tone="danger" class="ml-2">Must be answered</x-badge>
                                            @endif
                                            @unless ($field->answersSomething())
                                                <x-badge tone="neutral" class="ml-2">Asks nothing</x-badge>
                                            @endunless

                                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                {{ $field->key }} &middot; {{ $field->typeLabel() }}
                                                @if ($field->option_list_key)
                                                    &middot; {{ $optionLists[$field->option_list_key] ?? $field->option_list_key }}
                                                @endif
                                            </span>

                                            @if ($field->help)
                                                <span class="mt-1 block text-xs text-[var(--text-muted)]">{{ $field->help }}</span>
                                            @endif

                                            @if ($field->applies_when_key)
                                                <span class="mt-1 block text-xs text-[var(--text-muted)]">
                                                    Only asked when <span class="font-mono">{{ $field->applies_when_key }}</span>
                                                    is {{ $field->applies_when_value }}
                                                </span>
                                            @endif
                                        </div>

                                        @if ($canEdit)
                                            <div class="flex shrink-0 items-center gap-1">
                                                @if ($editable && count($questionIds) > 1)
                                                    @foreach (['up' => $fUp, 'down' => $fDown] as $direction => $order)
                                                        @continue(($direction === 'up' && $position === 0) || ($direction === 'down' && $position === count($questionIds) - 1))
                                                        <form method="POST" action="{{ route('forms.fields.reorder', [$form, $section]) }}">
                                                            @csrf
                                                            @foreach ($order as $id)
                                                                <input type="hidden" name="order[]" value="{{ $id }}">
                                                            @endforeach
                                                            <button type="submit" class="text-[var(--text-muted)] hover:text-brand-600"
                                                                    aria-label="Move {{ $field->label }} {{ $direction }}">
                                                                <x-icon name="chevron-down" class="h-3.5 w-3.5 {{ $direction === 'up' ? 'rotate-180' : '' }}"/>
                                                            </button>
                                                        </form>
                                                    @endforeach
                                                @endif

                                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = true"
                                                        aria-label="Edit {{ $field->label }}">
                                                    <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                                </button>

                                                @if ($editable)
                                                    <form method="POST" action="{{ route('forms.fields.destroy', [$form, $field]) }}"
                                                          onsubmit="return confirm('Remove &quot;{{ $field->label }}&quot; from this draft?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger btn-sm"
                                                                aria-label="Remove {{ $field->label }}">
                                                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    @if ($canEdit)
                                        <div x-show="editing" x-cloak>
                                            @include('forms.partials.field-edit', ['field' => $field, 'editable' => $editable])
                                        </div>
                                    @endif

                                    {{-- The columns of a repeating table, one level deep and no further. --}}
                                    @if ($field->isTable())
                                        <div class="ml-4 border-l-2 border-[var(--surface-border)] pl-3 pb-3">
                                            @if ($columns->isEmpty())
                                                <p class="py-2 text-xs text-[var(--text-muted)]">
                                                    This table has no columns yet, so there is nothing to fill in.
                                                    A version with an empty table cannot be published.
                                                </p>
                                            @else
                                                <ul class="divide-y divide-[var(--surface-border)]">
                                                    @foreach ($columns as $column)
                                                        <li class="flex items-start justify-between gap-3 py-2"
                                                            x-data="{ editing: false }">
                                                            <div class="min-w-0" x-show="! editing">
                                                                <span class="text-sm text-[var(--text-strong)]">{{ $column->label }}</span>
                                                                @if ($column->is_required)
                                                                    <x-badge tone="danger" class="ml-2">Required</x-badge>
                                                                @endif
                                                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                                    {{ $column->key }} &middot; {{ $column->typeLabel() }}
                                                                </span>
                                                            </div>

                                                            @if ($canEdit)
                                                                <div class="flex shrink-0 items-center gap-1" x-show="! editing">
                                                                    <button type="button" class="btn btn-secondary btn-sm"
                                                                            @click="editing = true"
                                                                            aria-label="Edit {{ $column->label }}">
                                                                        <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                                                    </button>
                                                                    @if ($editable)
                                                                        <form method="POST" action="{{ route('forms.fields.destroy', [$form, $column]) }}"
                                                                              onsubmit="return confirm('Remove the &quot;{{ $column->label }}&quot; column from this draft?')">
                                                                            @csrf
                                                                            @method('DELETE')
                                                                            <button type="submit" class="btn btn-danger btn-sm"
                                                                                    aria-label="Remove {{ $column->label }}">
                                                                                <x-icon name="trash" class="h-3.5 w-3.5"/>
                                                                            </button>
                                                                        </form>
                                                                    @endif
                                                                </div>

                                                                <div class="w-full" x-show="editing" x-cloak>
                                                                    @include('forms.partials.field-edit', ['field' => $column, 'editable' => $editable])
                                                                </div>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            @if ($canEdit && $editable)
                                                <button type="button" class="btn btn-ghost btn-sm mt-2"
                                                        x-show="! adding" @click="adding = true">
                                                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a column
                                                </button>

                                                @include('forms.partials.field-new', ['parent' => $field])
                                            @endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Adding a question to this section. --}}
                    @if ($canEdit && $editable)
                        <div class="border-t border-[var(--surface-border)] p-3" x-show="! adding">
                            <button type="button" class="btn btn-secondary btn-sm" @click="adding = true">
                                <x-icon name="plus" class="h-3.5 w-3.5"/> Add a question
                            </button>
                        </div>

                        @include('forms.partials.field-new', ['parent' => null])
                    @endif
                </div>
            @endforeach

            {{-- Adding a section. --}}
            @if ($canEdit && $editable)
                <form method="POST" action="{{ route('forms.sections.store', [$form, $version]) }}"
                      class="card space-y-3 p-4">
                    @csrf

                    <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">New section</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label" for="new-section-title">Heading</label>
                            <input type="text" id="new-section-title" name="title" class="input" value=""
                                   required maxlength="160" placeholder="e.g. Scaffold condition">
                        </div>
                        <div>
                            <label class="label" for="new-section-desc">Description</label>
                            <input type="text" id="new-section-desc" name="description" class="input"
                                   value="" maxlength="500">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add section
                        </button>
                    </div>
                </form>
            @endif
        </div>

        {{-- ------------------------------------------------------- the side panel --}}
        <div class="space-y-5">

            {{-- Publishing. --}}
            @if ($canPublish && $editable)
                <form method="POST" action="{{ route('forms.versions.publish', [$form, $version]) }}" class="card">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">Publish version {{ $version->version }}</h2>
                    </div>

                    <div class="space-y-3 p-4 text-sm text-[var(--text-body)]">
                        <p>
                            This makes these questions the ones people fill in, and freezes them.
                            @if ($versions->contains(fn ($v) => $v->isPublished()))
                                Version {{ $versions->first(fn ($v) => $v->isPublished())->version }}
                                stops being offered; everything submitted against it stays readable
                                for good.
                            @endif
                        </p>

                        @if ($version->version > 1)
                            <div>
                                <label class="label" for="change-note">What changed</label>
                                <textarea id="change-note" name="change_note" class="input" rows="3"
                                          maxlength="500" required></textarea>
                                <p class="help">
                                    Required from version 2 onwards. Somebody comparing two editions
                                    a year from now has no other way of finding out why they differ.
                                </p>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="check" class="h-3.5 w-3.5"/> Publish
                        </button>
                    </div>
                </form>
            @endif

            {{-- Settings. --}}
            <div class="card" x-data="{ open: false }">
                <div class="card-header flex items-center justify-between gap-2">
                    <h2 class="card-title">Details</h2>
                    @if ($canEdit)
                        <button type="button" class="btn btn-ghost btn-sm" @click="open = ! open">
                            <x-icon name="pencil" class="h-3.5 w-3.5"/>
                        </button>
                    @endif
                </div>

                <dl class="space-y-2 p-4 text-sm" x-show="! open">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[var(--text-muted)]">Key</dt>
                        <dd class="truncate font-mono text-[var(--text-body)]">{{ $form->key }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[var(--text-muted)]">Group</dt>
                        <dd class="text-[var(--text-body)]">{{ $form->category ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[var(--text-muted)]">In service</dt>
                        <dd><x-badge :tone="$form->is_active ? 'success' : 'neutral'">{{ $form->is_active ? 'Yes' : 'No' }}</x-badge></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[var(--text-muted)]">Filled in</dt>
                        <dd class="tabular-nums text-[var(--text-body)]">{{ $submissions }}</dd>
                    </div>
                </dl>

                @if ($canEdit)
                    <form method="POST" action="{{ route('forms.update', $form) }}"
                          class="space-y-3 p-4" x-show="open" x-cloak>
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="label" for="form-name">Name</label>
                            <input type="text" id="form-name" name="name" class="input"
                                   value="{{ $form->name }}" required maxlength="160">
                        </div>

                        <div>
                            <label class="label" for="form-key">Key</label>
                            @if ($submissions > 0)
                                {{-- Read-only rather than disabled, so the value still posts and
                                     the service's own refusal stays the thing that decides. --}}
                                <input type="text" id="form-key" name="key" class="input font-mono"
                                       value="{{ $form->key }}" maxlength="64" readonly>
                                <p class="help">Frozen — {{ $submissions }} {{ Str::plural('submission', $submissions) }} name this form by it.</p>
                            @else
                                <input type="text" id="form-key" name="key" class="input font-mono"
                                       value="{{ $form->key }}" maxlength="64">
                                <p class="help">What exports and reports call this form.</p>
                            @endif
                        </div>

                        <div>
                            <label class="label" for="form-category">Group</label>
                            <input type="text" id="form-category" name="category" class="input"
                                   value="{{ $form->category }}" maxlength="60">
                        </div>

                        <div>
                            <label class="label" for="form-description">Description</label>
                            <textarea id="form-description" name="description" class="input" rows="3"
                                      maxlength="500">{{ $form->description }}</textarea>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($form->is_active)>
                            In service
                        </label>
                        <p class="help">
                            Switching a form off stops it being offered and keeps every submission
                            ever made against it.
                        </p>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" @click="open = false">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- Winding things down. Kept apart from Details because these two buttons do not
                 undo, and a destructive action beside a text box gets pressed by accident. --}}
            @if (($canPublish && $form->is_active) || ($canEdit && $editable) || $canDelete)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Winding down</h2>
                    </div>

                    <div class="space-y-3 p-4 text-sm text-[var(--text-body)]">
                        @if ($canEdit && $editable)
                            <form method="POST" action="{{ route('forms.versions.discard', [$form, $version]) }}"
                                  onsubmit="return confirm('Throw away draft version {{ $version->version }} and everything on it?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <x-icon name="x" class="h-3.5 w-3.5"/> Discard this draft
                                </button>
                                <p class="help mt-1">Nothing already submitted is affected.</p>
                            </form>
                        @endif

                        @if ($canPublish && $form->is_active)
                            <form method="POST" action="{{ route('forms.retire', $form) }}"
                                  onsubmit="return confirm('Take &quot;{{ $form->name }}&quot; out of service? Nobody will be able to start a new one. Everything already submitted stays.')">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <x-icon name="archive" class="h-3.5 w-3.5"/> Take out of service
                                </button>
                                <p class="help mt-1">Reversible — switch it back on under Details.</p>
                            </form>
                        @endif

                        @if ($canDelete)
                            <form method="POST" action="{{ route('forms.destroy', $form) }}"
                                  onsubmit="return confirm('Delete &quot;{{ $form->name }}&quot; entirely? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm" @disabled($submissions > 0)>
                                    <x-icon name="trash" class="h-3.5 w-3.5"/> Delete this form
                                </button>
                                <p class="help mt-1">
                                    @if ($submissions > 0)
                                        Not possible — {{ $submissions }} {{ Str::plural('submission', $submissions) }}
                                        would go with it. Take it out of service instead.
                                    @else
                                        Only while nobody has filled it in.
                                    @endif
                                </p>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @endif

</x-layouts.app>
