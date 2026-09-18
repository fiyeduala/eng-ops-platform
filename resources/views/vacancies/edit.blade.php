<x-layouts.app :title="'Edit '.$vacancy->number">
    <x-page-header :title="'Edit '.$vacancy->number" :description="$vacancy->title"/>
    @include('finance.errors')
    <form method="POST" action="{{ route('vacancies.update', $vacancy) }}" class="card max-w-4xl p-5">
        @csrf @method('PUT')
        @include('vacancies.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('vacancies.show', $vacancy) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
