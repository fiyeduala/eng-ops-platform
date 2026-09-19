{{--
    Choosing which form to fill in.

    ONLY FORMS THAT ARE SWITCHED ON AND PUBLISHED ARE OFFERED, and the second half is the one
    that catches people out: a form somebody built this morning and did not publish is not a
    form anybody can fill in. Leaving it off this list is how they find that out — and the
    empty state says so in as many words rather than leaving them looking.

    Expects: $forms, $chosen, $projects, $orgUnits
--}}
<x-layouts.app title="Fill in a form">

    <x-page-header title="Fill in a form"
                   description="Pick the form, and say what it is about. The questions come next."/>

    @if ($errors->any())
        <div class="card mb-5 border-l-4 border-l-[var(--tone-danger)] p-4">
            <ul class="list-disc space-y-1 pl-5 text-sm text-[var(--text-body)]">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($forms->isEmpty())
        <div class="card">
            <x-empty-state title="No form is ready to be filled in"
                           icon="file-text"
                           description="A form has to be published before anybody can fill it in, and
                                        one that has been taken out of service stops being offered.
                                        Whoever looks after the form builder can publish a draft."/>
        </div>
    @else
        <form method="POST" action="{{ route('form-submissions.store') }}" class="card max-w-2xl p-5">
            @csrf

            <div class="space-y-4">
                <div>
                    <label class="label" for="form_id">Form</label>
                    <select id="form_id" name="form_id" class="select" required>
                        <option value="">Choose a form…</option>
                        @foreach ($forms->groupBy(fn ($form) => $form->category ?: 'Other') as $category => $group)
                            <optgroup label="{{ $category }}">
                                @foreach ($group as $form)
                                    <option value="{{ $form->id }}" @selected($chosen === $form->id)>
                                        {{ $form->name }} (v{{ $form->publishedVersion?->version }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="help">
                        You will be filling in the edition that is live right now. It stays that
                        edition even if the form is republished while you are working.
                    </p>
                </div>

                <div>
                    <label class="label" for="project_id">Project</label>
                    <select id="project_id" name="project_id" class="select">
                        <option value="">Not about a project</option>
                        @foreach ($projects as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    {{-- This is not a label. The project and the department are the access axes
                         the scope engine reads, so they decide who else will be able to open this
                         once it is filled in (§6, §75). --}}
                    <p class="help">This decides who else can see it once it is filled in.</p>
                </div>

                <div>
                    <label class="label" for="org_unit_id">Department</label>
                    <select id="org_unit_id" name="org_unit_id" class="select">
                        <option value="">Not about a department</option>
                        @foreach ($orgUnits as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-5 flex items-center justify-end gap-2">
                <a href="{{ route('form-submissions.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Start</button>
            </div>
        </form>
    @endif

</x-layouts.app>
