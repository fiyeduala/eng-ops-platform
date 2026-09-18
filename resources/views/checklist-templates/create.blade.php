<x-layouts.app title="New checklist">
    <x-page-header title="New checklist" description="A list of steps. Starting one for somebody copies these steps onto them, so editing this later never rewrites what somebody was asked to do."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('checklist-templates.store') }}" class="card max-w-5xl p-5">
        @csrf
        @include('checklist-templates.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Create</button>
            <a href="{{ route('checklist-templates.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
