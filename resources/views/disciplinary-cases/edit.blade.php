<x-layouts.app title="Edit {{ $case->number }}">
    <x-page-header :title="'Edit '.$case->number"
                   description="Corrects what was recorded when the case was opened. The investigation, the hearing and the outcome are each changed where they were written."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('disciplinary-cases.update', $case) }}" class="card p-6">
        @csrf @method('PUT')
        @include('disciplinary-cases.partials.form')

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('disciplinary-cases.show', $case) }}" class="btn btn-ghost">Cancel</a>
            @can ('delete', $case)
                <div class="ml-auto">
                    <x-delete-form :action="route('disciplinary-cases.destroy', $case)" label="Delete case"
                                   confirm="Delete this case? Withdrawing it keeps the record and is usually the right act."/>
                </div>
            @endcan
        </div>
    </form>
</x-layouts.app>
