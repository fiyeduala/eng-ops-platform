<x-layouts.app :title="'Edit '.$overtime->number">
    <x-page-header :title="'Edit '.$overtime->number" description="Only a draft can be changed."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('overtime.update', $overtime) }}" class="card max-w-3xl p-5">
        @csrf @method('PUT')
        @include('overtime.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('overtime.show', $overtime) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
