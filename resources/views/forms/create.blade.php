{{--
    Naming a new form.

    FOUR BOXES AND ONLY ONE OF THEM COMPULSORY, on purpose. The questions are the work; this page
    is the thing standing between somebody and the work, so it asks the least it can and gets out
    of the way. The key is derived from the name when left blank (§73) — a safety officer building
    an inspection sheet should not have to invent a machine name first, and the one place a key
    really matters (exports, reports) is a place they will meet later with the form in front of
    them.

    THIS IS THE ONE PAGE IN THE MODULE THAT REPOPULATES ON REFUSAL. It carries a single form, so
    old() cannot bleed into a neighbouring row the way it would on the builder.
--}}
<x-layouts.app title="New form">

    <x-page-header
        title="New form"
        description="Name it now and build the questions next. Nothing is live until you publish it.">
        <x-slot:actions>
            <a href="{{ route('forms.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All forms
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That form was not created
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('forms.store') }}" class="card max-w-2xl">
        @csrf

        <div class="space-y-4 p-5">
            <div>
                <label class="label" for="name">Name</label>
                <input type="text" id="name" name="name" class="input" value="{{ old('name') }}"
                       required maxlength="160" autofocus placeholder="e.g. Scaffold inspection">
                <p class="help">What people will call it in a list — the words on the paper version.</p>
            </div>

            <div>
                <label class="label" for="key">Key</label>
                <input type="text" id="key" name="key" class="input font-mono" value="{{ old('key') }}"
                       maxlength="64" placeholder="scaffold_inspection">
                <p class="help">
                    Leave blank and one will be made from the name. This is what exports and reports
                    call the form, and it freezes the first time somebody fills the form in.
                </p>
            </div>

            <div>
                <label class="label" for="category">Group</label>
                <input type="text" id="category" name="category" class="input" value="{{ old('category') }}"
                       maxlength="60" placeholder="e.g. HSE">
                <p class="help">Optional. Forms are listed under their group, which is worth having once you have more than a handful.</p>
            </div>

            <div>
                <label class="label" for="description">Description</label>
                <textarea id="description" name="description" class="input" rows="3"
                          maxlength="500">{{ old('description') }}</textarea>
                <p class="help">A sentence for whoever has to decide whether this is the right form to fill in.</p>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-[var(--surface-border)] p-4">
            <a href="{{ route('forms.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm">Create and add questions</button>
        </div>
    </form>

</x-layouts.app>
