<x-layouts.app :title="'Edit '.$employee->fullName()">

    <x-page-header
        :title="'Edit '.$employee->fullName()"
        description="Changing someone’s unit or supervisor changes who can see their records. It is an access decision as much as an HR one.">
        <x-slot:actions>
            <a href="{{ route('employees.show', $employee) }}" class="btn btn-sm btn-ghost">Back to record</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('employees.update', $employee) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('employees.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('employees.show', $employee) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

</x-layouts.app>
