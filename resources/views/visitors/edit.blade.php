<x-layouts.app title="Edit {{ $visitor->number }}">
    <x-page-header :title="'Edit '.$visitor->number"
                   description="A visit can be corrected until the person leaves. Once they have signed out the record is closed for good — it is the answer to who was on site that day."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('visitors.update', $visitor) }}" class="card p-6">
        @csrf @method('PUT')
        @include('visitors.partials.form')

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('visitors.show', $visitor) }}" class="btn btn-ghost">Cancel</a>
            @can ('delete', $visitor)
                <div class="ml-auto">
                    <x-delete-form :action="route('visitors.destroy', $visitor)" label="Delete booking" confirm="Delete this booking?"/>
                </div>
            @endcan
        </div>
    </form>
</x-layouts.app>
