<x-layouts.app title="Edit training requirement">
    <x-page-header :title="'Edit — '.($requirement->course?->name ?? 'requirement')" description="Changing what a job requires changes the matrix immediately. It changes nobody's certificates."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('training-requirements.update', $requirement) }}" class="card max-w-3xl p-5">
        @csrf @method('PUT')
        @include('training-requirements.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('training-requirements.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
    @can ('delete', $requirement)
        <div class="card mt-6 max-w-3xl p-5">
            <x-delete-form :action="route('training-requirements.destroy', $requirement)" label="Remove requirement"
                           confirm="Remove this requirement? The matrix will stop asking for this course, and nobody's certificates change."/>
        </div>
    @endcan
</x-layouts.app>
