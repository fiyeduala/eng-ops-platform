<x-layouts.app :title="$template->name">
    <x-page-header :title="$template->name" :description="ucfirst($template->kind).' list'"/>
    @include('finance.errors')
    <form method="POST" action="{{ route('checklist-templates.update', $template) }}" class="card max-w-5xl p-5">
        @csrf @method('PUT')
        @include('checklist-templates.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('checklist-templates.index') }}" class="btn btn-ghost">Back</a>
        </div>
    </form>
    <div class="card mt-6 max-w-5xl p-5">
        <x-delete-form :action="route('checklist-templates.destroy', $template)" label="Retire this list" confirm="Retire this list? Checklists already started from it keep working."/>
    </div>
</x-layouts.app>
