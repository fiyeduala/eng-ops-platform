{{--
    A filled-in form, as a record.

    EVERY QUESTION IS SHOWN, ANSWERED OR NOT, and it is shown as the edition this submission was
    started against asked it — not as the form asks it today. A page that rendered March's
    answers against today's questions is the failure the whole versioning design exists to
    prevent, and it is the kind that is invisible until somebody is being audited.

    Expects: $submission, $version, $values, $compiler, $transitions, $editable, $canDelete,
             $canReview, $canSign, $hash, $actorId, $signable
--}}
<x-layouts.app :title="$submission->displayName()">

    @php
        $sections = $version?->sections ?? collect();
        $fields = $version?->fields ?? collect();
        $questions = $fields->whereNull('parent_field_id')->groupBy('form_section_id');
        $columns = $fields->whereNotNull('parent_field_id')->groupBy('parent_field_id');
    @endphp

    <x-page-header :title="$submission->displayName()"
                   :description="'Filled in against version '.($version?->version ?? '?').' of this form.'">
        <x-slot:actions>
            @if ($editable)
                <a href="{{ route('form-submissions.edit', $submission) }}" class="btn btn-primary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Carry on filling it in
                </a>
            @endif
            @if ($canDelete && ! $submission->isSubmitted())
                <x-delete-form :action="route('form-submissions.destroy', $submission)"
                               label="Delete"
                               confirm="Delete this unsubmitted form?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 border-l-4 border-l-[var(--tone-success)] p-4 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-l-4 border-l-[var(--tone-danger)] p-4">
            <ul class="list-disc space-y-1 pl-5 text-sm text-[var(--text-body)]">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @forelse ($sections as $section)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">{{ $section->title }}</h3>
                    </div>

                    <dl class="grid gap-4 p-5 sm:grid-cols-2">
                        @foreach ($questions->get($section->id, collect()) as $field)
                            @continue(in_array($field->type, ['heading', 'note'], true))

                            @if ($field->isTable())
                                <div class="sm:col-span-2">
                                    @include('form-submissions.partials.table', [
                                        'table' => $field,
                                        'columns' => $columns->get($field->id, collect()),
                                        'rows' => $values[$field->key] ?? [],
                                        'compiler' => $compiler,
                                        'editable' => false,
                                    ])
                                </div>
                            @elseif ($field->isSignature())
                                <div class="sm:col-span-2">
                                    @include('form-submissions.partials.signature', [
                                        'submission' => $submission,
                                        'field' => $field,
                                        'compiler' => $compiler,
                                        'hash' => $hash,
                                        'canSign' => $canSign,
                                        'actorId' => $actorId,
                                        'open' => $signable,
                                    ])
                                </div>
                            @else
                                <x-register.fact :label="$field->label">
                                    <span class="whitespace-pre-line">
                                        {{ $compiler->render($field, $values[$field->key] ?? null) }}
                                    </span>
                                </x-register.fact>
                            @endif
                        @endforeach
                    </dl>
                </div>
            @empty
                <div class="card">
                    <x-empty-state title="This form asks nothing"
                                   description="The edition this was started against has no questions on it."/>
                </div>
            @endforelse
        </div>

        <div class="space-y-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="grid gap-4 p-5">
                    <x-register.fact label="Reference" :value="$submission->number" mono/>
                    <x-register.fact label="Form" :value="$submission->form?->name"/>
                    <x-register.fact label="Status">
                        <x-badge :tone="$submission->status?->color ?? 'neutral'">
                            {{ $submission->status?->label ?? 'No status' }}
                        </x-badge>
                    </x-register.fact>
                    <x-register.fact label="Project" :value="$submission->project?->name"/>
                    <x-register.fact label="Department" :value="$submission->orgUnit?->name"/>
                    <x-register.fact label="Started by" :value="$submission->creator?->name"/>
                    <x-register.fact label="Submitted">
                        @if ($submission->isSubmitted())
                            {{ $submission->submitted_at?->format('j M Y, H:i') }}
                            by {{ $submission->submitter?->name ?? 'somebody' }}
                        @else
                            Not submitted yet
                        @endif
                    </x-register.fact>
                </dl>
            </div>

            @if ($canReview)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Review</h3>
                    </div>

                    @if ($submission->isSubmitted() && ! $submission->isClosed())
                        <form method="POST" action="{{ route('form-submissions.send-back', $submission) }}"
                              class="border-b border-[var(--surface-border)] p-5">
                            @csrf
                            <label class="label" for="reason">Send back for correction</label>
                            <textarea id="reason" name="reason" class="input" rows="3" required
                                      maxlength="1000"
                                      placeholder="What needs correcting?"></textarea>
                            {{-- The reason is required rather than optional: "sent back" with no
                                 explanation is a message that makes somebody guess. --}}
                            <p class="help">Whoever filled it in will be able to edit it again.</p>
                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="btn btn-secondary btn-sm">Send back</button>
                            </div>
                        </form>
                    @endif

                    <x-register.transitions :action="route('form-submissions.status', $submission)"
                                            :transitions="$transitions"
                                            noun="submission"/>
                </div>
            @endif
        </div>
    </div>

    {{-- A signature block is signable from the record screen as well as the fill-in one: the person
         counter-signing a submitted sheet has no reason to be sent to a page full of boxes they are
         not allowed to change. --}}
    @include('form-submissions.partials.signature-forms', [
        'submission' => $submission,
        'blocks' => $fields->filter(fn ($field) => $field->isSignature()),
        'canSign' => $canSign,
        'open' => $signable,
    ])

</x-layouts.app>
