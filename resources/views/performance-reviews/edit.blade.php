<x-layouts.app title="Edit {{ $review->number }}">
    <x-page-header :title="'Edit '.$review->number"
                   description="Who reviews this person, and which department it is filed under. Nothing that has been scored is editable here."/>

    @include('finance.errors')

    {{--
        This form changes the two things HR is entitled to change. The scores belong to the two
        people who wrote them, and a screen that let an administrator adjust somebody's rating would
        make every rating in the system deniable (§84).
    --}}
    <form method="POST" action="{{ route('performance-reviews.update', $review) }}" class="card p-6">
        @csrf @method('PUT')

        <div class="grid gap-4 md:grid-cols-2">
            <x-field label="Reviewer" name="reviewer_id" required
                     help="The manager who writes this review. Changing it grants them access to the record and takes it from the person replaced.">
                <x-select name="reviewer_id" :options="$users" :selected="old('reviewer_id', $review->reviewer_id)" placeholder="Choose a reviewer"/>
            </x-field>

            <x-field label="Department" name="org_unit_id" help="Where the review is filed for reporting.">
                <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $review->org_unit_id)" placeholder="None"/>
            </x-field>
        </div>

        {{-- The fields this company added to this register (§73). Renders nothing until
             somebody defines one on Configuration → Custom fields. --}}
        <x-custom-fields.form entity-type="performance_review" :record="$review" class="card mt-5"/>

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('performance-reviews.show', $review) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
