<x-layouts.app title="Record an application">
    <x-page-header title="Record an application" :description="$vacancy->number.' — '.$vacancy->title"/>
    @include('finance.errors')
    <form method="POST" action="{{ route('vacancies.applicants.store', $vacancy) }}" enctype="multipart/form-data" class="card max-w-3xl p-5">
        @csrf
        @include('applicants.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('vacancies.show', $vacancy) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
