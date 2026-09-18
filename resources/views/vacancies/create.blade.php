<x-layouts.app title="New vacancy">
    <x-page-header title="New vacancy" description="A requisition is a spending decision. Save it, then send it for approval — it only opens for applications once it has been approved."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('vacancies.store') }}" class="card max-w-4xl p-5">
        @csrf
        @include('vacancies.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save draft</button>
            <a href="{{ route('vacancies.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
