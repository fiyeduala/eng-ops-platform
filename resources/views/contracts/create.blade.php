{{--
    Register a contract.

    SAVING RECORDS IT; IT DOES NOT MAKE IT LIVE. Drafted, out for signature, live, suspended,
    completed, terminated — that sequence belongs to the status graph and to whoever holds
    `contract.approve`. Registering a contract is bookkeeping; declaring it in force is not.

    Expects: $contract, $projects, $units, $people, $confidentialities, $types, $clients, $vendors,
             $documents, $nextNumber
--}}
<x-layouts.app title="Register a contract">

    <x-page-header
        title="Register a contract"
        :description="'Will be registered as '.$nextNumber.'. Contracts start confidential — only people with reach into the owning unit will see it.'"/>

    <form method="POST" action="{{ route('contracts.store') }}" class="space-y-6">
        @csrf

        @include('contracts.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Register the contract</button>
            <a href="{{ route('contracts.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
