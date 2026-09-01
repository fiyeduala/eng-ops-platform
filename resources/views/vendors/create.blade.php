{{--
    Add a supplier to the register.

    SAVING PUTS THEM ON THE REGISTER AS A DRAFT, not as an approved supplier. Prequalification, and
    the approval that follows it, are separate acts on the vendor's own page — because "we have their
    details" and "we have assessed them" are two different claims, and a system that conflates them
    is a system where the assessment quietly stops happening.

    Expects: $vendor, $units, $confidentialities, $types, $categories, $nextNumber
--}}
<x-layouts.app title="Add a vendor">

    <x-page-header
        title="Add a vendor"
        :description="'Will be registered as '.$nextNumber.'. Nobody can order from them until they are prequalified and approved.'"/>

    <form method="POST" action="{{ route('vendors.store') }}" class="space-y-6">
        @csrf

        @include('vendors.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add the vendor</button>
            <a href="{{ route('vendors.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
