<x-layouts.app :title="'Edit '.$applicant->number">
    <x-page-header :title="'Edit '.$applicant->fullName()" :description="$applicant->number.' — '.($vacancy?->title ?? 'no vacancy')"/>
    @include('finance.errors')
    <form method="POST" action="{{ route('applicants.update', $applicant) }}" enctype="multipart/form-data" class="card max-w-3xl p-5">
        @csrf @method('PUT')
        @include('applicants.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('applicants.show', $applicant) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
