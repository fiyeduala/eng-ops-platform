<x-layouts.app title="Add a person">

    <x-page-header
        title="Add a person"
        description="Where you place them decides who can see them. Only units and supervisors within your own access are offered."/>

    <form method="POST" action="{{ route('employees.store') }}" class="card max-w-4xl">
        @csrf

        @include('employees.partials.form', [
            'canSeeContact' => auth()->user()->hasPermission('employee.view_contact'),
            'canSeeConfidential' => auth()->user()->hasPermission('employee.view_confidential'),
        ])

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('employees.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Add person</button>
        </div>
    </form>

</x-layouts.app>
