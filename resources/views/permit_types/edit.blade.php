{{--
    Amending a permit type.

    THE WARNING SAYS WHAT IS AND IS NOT AFFECTED, because the obvious fear — that editing this
    changes permits already signed — is exactly what does not happen, and an administrator who
    believes it does will leave a bad template in place for years.

    Expects: $type, $precautions
--}}
<x-layouts.app :title="'Edit '.$type->displayName()">

    <x-page-header :title="'Edit '.$type->name" :description="$type->code">
        <x-slot:actions>
            <a href="{{ route('permit-types.show', $type) }}" class="btn btn-ghost btn-sm">Back to the type</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-muted)]">
            Changes here apply to permits raised from now on. Permits already issued keep the
            precautions they were issued against — they were copied, not linked.
        </p>
    </div>

    <form method="POST" action="{{ route('permit-types.update', $type) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('permit_types.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('permit-types.show', $type) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
