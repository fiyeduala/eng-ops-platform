<x-layouts.app title="Open a petty cash tin">
    <x-page-header title="Open a petty cash tin" description="It starts empty. Replenish it to put cash in."/>
    <form method="POST" action="{{ route('petty-cash.store') }}" class="card p-5">
        @csrf
        @include('petty-cash.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Open account</button><a href="{{ route('petty-cash.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
