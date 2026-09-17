{{-- Expects: $document, $vehicles, $types, $confidentialities --}}
<x-layouts.app :title="'Edit '.$document->typeLabel()">

    <x-page-header
        :title="'Edit '.$document->typeLabel()"
        :description="$document->vehicle?->registration_number"/>

    <form method="POST" action="{{ route('vehicle-documents.update', $document) }}"
          enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        @include('vehicle-documents.partials.form', ['vehicleLocked' => true])

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('vehicle-documents.show', $document) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
