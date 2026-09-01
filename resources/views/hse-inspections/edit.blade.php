{{--
    Editing the plan.

    NOT THE FINDINGS. Everything on this form is the plan — what, where, when, who can read it. The
    results are written on the inspection's own page through `perform`, which has its own permission
    and cannot be reached from here.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types
--}}
<x-layouts.app :title="'Edit '.$inspection->displayName()">

    <x-page-header :title="'Edit '.$inspection->number"
                   description="The plan for the walk. The findings are recorded on the inspection itself.">
        <x-slot:actions>
            <a href="{{ route('hse-inspections.items.edit', $inspection) }}" class="btn btn-secondary btn-sm">
                Edit the checklist
            </a>
            <a href="{{ route('hse-inspections.show', $inspection) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('hse-inspections.update', $inspection) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('hse-inspections.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the inspection</button>
            <a href="{{ route('hse-inspections.show', $inspection) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
