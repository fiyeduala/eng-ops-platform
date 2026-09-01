{{--
    Editing an action.

    Changing what an action says is not the same act as completing it, verifying it or withdrawing
    it. Those three live on the show page behind three different permissions, and none of them can
    be reached from this form.

    Expects: $action, $projects, $units, $people, $confidentialities, $types, $priorities
--}}
<x-layouts.app :title="'Edit '.$action->displayName()">

    <x-page-header :title="'Edit '.$action->number"
                   description="Reword it, re-price the date, or hand it to somebody else.">
        <x-slot:actions>
            <a href="{{ route('corrective-actions.show', $action) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('corrective-actions.update', $action) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('corrective-actions.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the action</button>
            <a href="{{ route('corrective-actions.show', $action) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
