{{--
    Adding a course.

    Expects: $course, $categories
--}}
<x-layouts.app title="Add a training course">

    <x-page-header title="Add a training course"
                   description="What it is called, and how long the ticket lasts."/>

    <form method="POST" action="{{ route('training-courses.store') }}" class="space-y-6">
        @csrf

        @include('training_courses.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add the course</button>
            <a href="{{ route('training-courses.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
