{{--
    Raise a non-conformance.

    RAISING IS NOT ISSUING. Saving this writes the finding down; sending it to the party responsible
    is a separate act behind its own permission, on the record's own page. The gap between the two is
    where somebody re-reads the requirement they typed and decides whether it holds.

    Expects: $ncr, $projects, $units, $people, $confidentialities, $severities, $categories,
             $sources, $nextNumber
--}}
<x-layouts.app title="Raise a non-conformance">

    <x-page-header
        title="Raise a non-conformance"
        :description="'Will be raised as '.$nextNumber.'. It is not issued to anybody until you issue it.'"/>

    <form method="POST" action="{{ route('ncrs.store') }}" class="space-y-6">
        @csrf

        @include('ncrs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the NCR</button>
            <a href="{{ route('ncrs.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
