{{-- Expects: $document, $vehicles, $types, $confidentialities --}}
<x-layouts.app title="File a certificate">

    <x-page-header
        title="File a certificate"
        description="Insurance, roadworthiness, a permit or a licence — and the date it stops making the vehicle legal."/>

    <form method="POST" action="{{ route('vehicle-documents.store') }}"
          enctype="multipart/form-data" class="space-y-6">
        @csrf

        @include('vehicle-documents.partials.form', ['vehicleLocked' => false])

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">File the certificate</button>
            <a href="{{ route('vehicle-documents.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
