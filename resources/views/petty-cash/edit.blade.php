<x-layouts.app :title="'Edit '.$account->code">
    <x-page-header :title="'Edit '.$account->name" :description="$account->code.' · the balance only moves through vouchers.'"/>
    <form method="POST" action="{{ route('petty-cash.update', $account) }}" class="card p-5">
        @csrf @method('PUT')
        @include('petty-cash.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('petty-cash.show', $account) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
