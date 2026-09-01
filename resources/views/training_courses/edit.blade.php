{{--
    Amending a course.

    Expects: $course, $categories
--}}
<x-layouts.app :title="'Edit '.$course->displayName()">

    <x-page-header :title="'Edit '.$course->name" :description="$course->code">
        <x-slot:actions>
            <a href="{{ route('training-courses.show', $course) }}" class="btn btn-ghost btn-sm">Back to the course</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-muted)]">
            Changing the validity applies to certificates recorded from now on. The expiry dates
            people are already carrying were worked out when their certificate was recorded and stay
            where they are.
        </p>
    </div>

    <form method="POST" action="{{ route('training-courses.update', $course) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('training_courses.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('training-courses.show', $course) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
