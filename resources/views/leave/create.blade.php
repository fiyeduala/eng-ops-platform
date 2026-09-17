<x-layouts.app title="Request leave">
    <x-page-header title="Request leave" description="Save it as a draft, or send it straight to your line manager."/>
    @include('finance.errors')
    @include('leave.partials.balances')
    <form method="POST" action="{{ route('leave.store') }}" class="card p-5">
        @csrf
        @include('leave.partials.form')
        <label class="mt-5 flex items-center gap-2 text-sm"><input type="checkbox" name="submit_now" value="1" @checked(old('submit_now', true))> Submit for approval now</label>
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save</button><a href="{{ route('leave.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
