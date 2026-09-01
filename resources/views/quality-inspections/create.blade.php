{{--
    Request an inspection.

    IT SAVES AND THEN SENDS YOU STRAIGHT TO THE CHECKLIST. That is not a wizard for its own sake:
    the controller redirects to the acceptance criteria because an inspection request with no
    criteria is an invitation to have an opinion, and the moment to write them down is before
    anybody has walked out and formed one.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types, $nextNumber
--}}
<x-layouts.app title="Request an inspection">

    <x-page-header
        title="Request an inspection"
        :description="'Will be raised as '.$nextNumber.'. You will set the acceptance criteria next.'"/>

    <form method="POST" action="{{ route('quality-inspections.store') }}" class="space-y-6">
        @csrf

        @include('quality-inspections.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the request</button>
            <a href="{{ route('quality-inspections.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
