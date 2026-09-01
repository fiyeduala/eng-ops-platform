{{--
    Amend an inspection request.

    ONCE IT HAS BEEN INSPECTED, THIS SCREEN IS EDITING TESTIMONY. The verdict itself cannot be
    reached from here, but the title, the activity and the criteria reference are what the verdict
    was given against, and quietly rewording them afterwards changes what the record appears to say
    somebody signed. The policy is what actually decides; the banner is the warning.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types
--}}
<x-layouts.app :title="'Edit '.$inspection->number">

    <x-page-header
        :title="'Edit '.$inspection->number"
        :description="$inspection->title"/>

    @if ($inspection->wasInspected())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                This inspection was carried out on
                {{ $inspection->inspected_at?->format('j M Y H:i') }}
                @if ($inspection->inspector) by {{ $inspection->inspector->name }} @endif
                and the verdict recorded was <strong>{{ $inspection->result ? Str::headline($inspection->result) : 'not stated' }}</strong>.
                Anything you change here changes what that verdict appears to have been given against.
                The verdict itself cannot be altered from this screen.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('quality-inspections.update', $inspection) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('quality-inspections.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('quality-inspections.show', $inspection) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
