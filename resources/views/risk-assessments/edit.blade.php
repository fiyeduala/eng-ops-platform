{{--
    Editing the cover sheet.

    The hazard table is edited on its own screen — the button for it is on the assessment's page.

    Expects: $assessment, $projects, $units, $people, $confidentialities, $types
--}}
<x-layouts.app :title="'Edit '.$assessment->displayName()">

    <x-page-header :title="'Edit '.$assessment->number"
                   description="What the sheet covers, who wrote it and how long it stands.">
        <x-slot:actions>
            <a href="{{ route('risk-assessments.hazards.edit', $assessment) }}" class="btn btn-secondary btn-sm">
                Edit the hazards
            </a>
            <a href="{{ route('risk-assessments.show', $assessment) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('risk-assessments.update', $assessment) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('risk-assessments.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the assessment</button>
            <a href="{{ route('risk-assessments.show', $assessment) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
