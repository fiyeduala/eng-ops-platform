<x-layouts.app :title="'Edit '.$leave->number">
    <x-page-header :title="'Edit '.$leave->number" description="Only a draft can be changed. Days are recounted when you save."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('leave.update', $leave) }}" class="card p-5">
        @csrf @method('PUT')
        @include('leave.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('leave.show', $leave) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
