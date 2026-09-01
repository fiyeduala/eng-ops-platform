{{--
    Amending a certificate record.

    THE NOTICE IS NOT DECORATION. Editing exists for typos; a replacement ticket is a RENEWAL, which
    creates a new record and leaves this one exactly as it was. The certificate somebody held in
    2024 was valid in 2024, and overwriting it destroys the only evidence of that (§59).

    Expects: $record, $courses, $results, $people, $projects, $units, $confidentialities,
             $certificates
--}}
<x-layouts.app :title="'Edit '.$record->number">

    <x-page-header :title="'Edit '.$record->number" :description="$record->displayName()">
        <x-slot:actions>
            <a href="{{ route('training-records.show', $record) }}" class="btn btn-ghost btn-sm">Back to the record</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
        <p class="text-sm text-amber-800 dark:text-amber-200">
            Correct a mistake here. If this person has sat the course again, record a renewal from
            the record instead — that keeps this certificate as evidence of what was true at the time.
        </p>
    </div>

    <form method="POST" action="{{ route('training-records.update', $record) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('training-records.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('training-records.show', $record) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
