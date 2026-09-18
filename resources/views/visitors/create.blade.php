<x-layouts.app title="Book a visitor">
    <x-page-header title="Book a visitor"
                   description="For somebody coming later, or for somebody standing at the desk right now — book them in, then sign them in from their record."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('visitors.store') }}" class="card p-6">
        @csrf
        @include('visitors.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Book the visit</button>
            <a href="{{ route('visitors.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
