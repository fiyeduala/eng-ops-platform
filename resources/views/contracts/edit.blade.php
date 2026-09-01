{{--
    Correct a contract.

    THE RETENTION BALANCE IS NOT ON THIS FORM. `retention_percent` — what was agreed — is; the money
    actually held back moves on the contract's own page behind `contract.manage`, one movement at a
    time, each with a reason on the timeline. A balance that can be retyped is a balance nobody can
    reconcile (§59).

    Expects: $contract, $projects, $units, $people, $confidentialities, $types, $clients, $vendors,
             $documents, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$contract->number">

    <x-page-header
        :title="'Edit '.$contract->number"
        :description="$contract->title.' — terms and dates. The retention balance and the status move from the contract\'s own page.'"/>

    <form method="POST" action="{{ route('contracts.update', $contract) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('contracts.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('contracts.show', $contract) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
