<x-layouts.app :title="'Edit '.$code->code">
    <x-page-header :title="'Edit '.$code->displayName()" description="Records already raised keep the rate they were raised with."/>
    @include('finance.errors')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('tax-codes.update', $code) }}" class="card p-5">
                @csrf @method('PUT')
                @include('tax-codes.form')
                <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('tax-codes.index') }}" class="btn btn-ghost">Cancel</a></div>
            </form>
        </div>
        <div class="space-y-6">
            @can('delete', $code)
                <div class="card p-5"><x-delete-form :action="route('tax-codes.destroy', $code)" label="Delete tax code" confirm="Delete this tax code?"/></div>
            @endcan
            <x-timeline :entries="$timeline" title="History" empty="No changes yet."/>
        </div>
    </div>
</x-layouts.app>
