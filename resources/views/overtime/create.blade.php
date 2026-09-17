<x-layouts.app title="Request overtime">
    <x-page-header title="Request overtime" description="Ask before the hours are worked. Your line manager decides it in My Approvals."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('overtime.store') }}" class="card max-w-3xl p-5">
        @csrf
        @include('overtime.partials.form')
        <label class="mt-5 flex items-center gap-2 text-sm"><input type="checkbox" name="submit_now" value="1" @checked(old('submit_now', true))> Submit for approval now</label>
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save</button><a href="{{ route('overtime.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
