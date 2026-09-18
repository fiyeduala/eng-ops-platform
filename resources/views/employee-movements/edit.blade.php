<x-layouts.app title="Edit {{ $movement->number }}">
    <x-page-header :title="'Edit '.$movement->number" description="Only a draft can be edited. Once it has been submitted, the approver decides the proposal as it stands."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('employee-movements.update', $movement) }}" class="card p-6">
        @csrf @method('PUT')
        @include('employee-movements.partials.form')

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('employee-movements.show', $movement) }}" class="btn btn-ghost">Cancel</a>
            @can ('delete', $movement)
                <div class="ml-auto">
                    <x-delete-form :action="route('employee-movements.destroy', $movement)" label="Delete draft" confirm="Delete this proposed movement?"/>
                </div>
            @endcan
        </div>
    </form>
</x-layouts.app>
