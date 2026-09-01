{{--
    Correct a client's particulars.

    THE ACCOUNT OWNER IS NOT HERE. It is changed on the client's own page, behind `client.manage`,
    because it decides who can see the account rather than merely describing it (§84).

    Expects: $client, $units, $confidentialities, $types, $industries, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$client->name">

    <x-page-header
        :title="'Edit '.$client->name"
        :description="$client->number.' — particulars and terms. The account owner and the status are changed from the client\'s own page.'"/>

    <form method="POST" action="{{ route('clients.update', $client) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('clients.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('clients.show', $client) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
