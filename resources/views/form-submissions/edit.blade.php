{{--
    Filling in a form.

    THE QUESTIONS COME FROM THE SUBMISSION'S OWN VERSION, never from whichever edition is
    published today. Somebody who started this on Tuesday answers Tuesday's questions even if
    the form was republished on Wednesday — see FormSubmissionService.

    TWO BUTTONS, TWO ENDPOINTS, TWO PERMISSIONS. Save records progress and asks nothing of the
    answers beyond their types. Submit asserts the sheet is true, and the service refuses an
    incomplete one. Neither the button nor its absence is the control (§6, §84).

    SIGNING IS NOT SAVING AND NOT SUBMITTING. A signature block carries its own buttons, posting to
    their own endpoints, and covers the answers AS RECORDED — which is why it asks the person to
    save first rather than sweeping the unsaved boxes into the hash behind them.

    Expects: $submission, $version, $values, $compiler, $editable, $missing, $canSubmit,
             $canSign, $hash, $actorId, $signable
--}}
<x-layouts.app :title="'Fill in — '.$submission->displayName()">

    @php
        $sections = $version?->sections ?? collect();
        $fields = $version?->fields ?? collect();
        $questions = $fields->whereNull('parent_field_id')->groupBy('form_section_id');
        $columns = $fields->whereNotNull('parent_field_id')->groupBy('parent_field_id');
    @endphp

    <x-page-header :title="$submission->displayName()"
                   :description="$submission->form?->name.' · version '.($version?->version ?? '?')">
        <x-slot:actions>
            <a href="{{ route('form-submissions.show', $submission) }}" class="btn btn-secondary btn-sm">
                <x-icon name="file-text" class="h-3.5 w-3.5"/> View record
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 border-l-4 border-l-[var(--tone-success)] p-4 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-l-4 border-l-[var(--tone-danger)] p-4">
            <p class="text-sm font-medium text-[var(--text-strong)]">This could not be saved.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-[var(--text-body)]">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @unless ($editable)
        <div class="card mb-5 border-l-4 border-l-[var(--tone-brand)] p-4 text-sm text-[var(--text-body)]">
            @if ($submission->isClosed())
                This has been closed, so its answers can no longer be changed. What it says is the
                record of what was decided.
            @else
                This was submitted on {{ $submission->submitted_at?->format('j M Y') }}. Ask whoever
                is reviewing it to send it back if something needs correcting.
            @endif
        </div>
    @endunless

    @if ($editable && $missing !== [])
        <div class="card mb-5 border-l-4 border-l-[var(--tone-warning)] p-4">
            <p class="text-sm font-medium text-[var(--text-strong)]">
                Still needed before this can be submitted
            </p>
            {{-- Said BEFORE the button is pressed, because on a fifty-question sheet the
                 alternative is an argument with a form. --}}
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-[var(--text-body)]">
                @foreach ($missing as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($version === null || $sections->isEmpty())
        <div class="card">
            <x-empty-state title="This form asks nothing yet"
                           description="The edition this was started against has no questions on it.
                                        Whoever built the form will need to add some."/>
        </div>
    @else
        <form method="POST" action="{{ route('form-submissions.update', $submission) }}"
              id="answers" class="space-y-5">
            @csrf

            {{-- Spelled out rather than @method('PUT') because the Submit button below re-points
                 this same form at a different endpoint with a different verb. One set of boxes
                 serving both buttons is what keeps anything typed since the last Save from being
                 lost on submit. --}}
            <input type="hidden" name="_method" value="PUT">

            @foreach ($sections as $section)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">{{ $section->title }}</h3>
                        @if ($section->description)
                            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $section->description }}</p>
                        @endif
                    </div>

                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        @forelse ($questions->get($section->id, collect()) as $field)
                            @php
                                // A question narrowed to another answer is hidden rather than
                                // removed, and hiding is all this is — the compiler decides
                                // separately whether it applies.
                                $shown = $field->appliesTo($values);
                                $wide = in_array($field->type, ['textarea', 'table', 'note', 'heading', 'multiselect', 'signature'], true);
                            @endphp

                            <div @class(['sm:col-span-2' => $wide]) @unless ($shown) hidden @endunless>
                                @if ($field->isTable())
                                    @include('form-submissions.partials.table', [
                                        'table' => $field,
                                        'columns' => $columns->get($field->id, collect()),
                                        'rows' => $values[$field->key] ?? [],
                                        'compiler' => $compiler,
                                        'editable' => $editable,
                                    ])
                                @elseif ($field->isSignature())
                                    @include('form-submissions.partials.signature', [
                                        'submission' => $submission,
                                        'field' => $field,
                                        'compiler' => $compiler,
                                        'hash' => $hash,
                                        'canSign' => $canSign,
                                        'actorId' => $actorId,
                                        'open' => $signable,
                                    ])
                                @else
                                    @include('form-submissions.partials.control', [
                                        'field' => $field,
                                        'key' => 'answers.'.$field->key,
                                        'value' => $values[$field->key] ?? $field->default_value,
                                        'compiler' => $compiler,
                                        'editable' => $editable,
                                    ])
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-[var(--text-muted)] sm:col-span-2">
                                Nothing is asked in this section.
                            </p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </form>

        {{-- Outside the answers form, and that is not a tidiness decision — see the partial. --}}
        @include('form-submissions.partials.signature-forms', [
            'submission' => $submission,
            'blocks' => $fields->filter(fn ($field) => $field->isSignature()),
            'canSign' => $canSign,
            'open' => $signable,
        ])

        @if ($editable)
            <div class="card sticky bottom-4 mt-5 flex flex-wrap items-center justify-between gap-3 p-4"
                 x-data="{
                     armed: false,
                     point(verb, action) {
                         const form = document.getElementById('answers');
                         form.querySelector('[name=_method]').value = verb;
                         form.action = action;
                     },
                 }">
                <p class="text-sm text-[var(--text-muted)]">
                    Saving keeps your answers without submitting anything.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" form="answers" class="btn btn-secondary"
                            @click="point('PUT', @js(route('form-submissions.update', $submission)))">
                        Save
                    </button>

                    @if ($canSubmit)
                        {{-- The same boxes, a different endpoint and a different permission,
                             because submitting is a different act from saving. The two-step click
                             is a courtesy; the service is what refuses an incomplete sheet. --}}
                        <button type="submit" form="answers" class="btn btn-primary"
                                x-text="armed ? 'Yes — submit it' : 'Submit'"
                                @click="
                                    if (! armed) { $event.preventDefault(); armed = true; return; }
                                    point('POST', @js(route('form-submissions.submit', $submission)));
                                ">Submit</button>
                    @endif
                </div>
            </div>
        @endif
    @endif

</x-layouts.app>
