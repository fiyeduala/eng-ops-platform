<x-layouts.app :title="'Edit '.$type->code">
    <x-page-header :title="'Edit '.$type->displayName()" description="Leave already booked keeps the days it was counted with."/>
    @include('finance.errors')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('leave-types.update', $type) }}" class="card p-5">
                @csrf @method('PUT')
                @include('leave.types.form')
                <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('leave-types.index') }}" class="btn btn-ghost">Cancel</a></div>
            </form>
        </div>
        <div class="space-y-6">
            @can('delete', $type)
                <div class="card p-5"><x-delete-form :action="route('leave-types.destroy', $type)" label="Delete leave type" confirm="Delete this leave type?"/></div>
            @endcan
            <x-timeline :entries="$timeline" title="History" empty="No changes yet."/>
        </div>
    </div>
</x-layouts.app>
