<x-layouts.app title="New role">

    <x-page-header
        title="New role"
        description="Name it after the job, not the person. A role is a definition your organization will keep using long after whoever prompted it has moved on.">
        <x-slot:actions>
            <a href="{{ route('roles.index') }}" class="btn btn-sm btn-ghost">All roles</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('roles.store') }}">
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The role</h3>
            </div>

            @include('roles.partials.form')
        </div>

        <div class="card mt-6 overflow-hidden">
            <div class="card-header">
                <h3 class="card-title">What it can do</h3>
            </div>

            <div class="border-b border-[var(--surface-border)] px-5 py-4">
                <div class="flex items-start gap-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                    <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0"/>
                    <p>
                        Each permission has two independent settings. The dropdown says how far up the
                        organization it reaches — their own records, their team, their whole division. The
                        checkbox adds everything on the projects they belong to, regardless of who owns it.
                        Someone holding both sees the two added together, which is how a discipline lead ends up
                        with their department plus every project they are on.
                    </p>
                </div>
            </div>

            @error('scope')
                <p class="error px-5 pt-4" role="alert">{{ $message }}</p>
            @enderror

            @include('roles.partials.grants')
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('roles.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create role</button>
        </div>
    </form>

</x-layouts.app>
