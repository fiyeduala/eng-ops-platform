{{--
    Amend a non-conformance.

    A DISPOSITIONED NCR IS FROZEN, AND THE POLICY IS WHAT FREEZES IT. The requirement, the finding
    and the cost impact are what the engineering decision was made on; rewriting them afterwards
    changes what somebody appears to have decided. If the finding itself was wrong, that is a new
    NCR or a withdrawal — both of which leave the original readable, which an edit would not (§59).

    Expects: $ncr, $projects, $units, $people, $confidentialities, $severities, $categories, $sources
--}}
<x-layouts.app :title="'Edit '.$ncr->number">

    <x-page-header :title="'Edit '.$ncr->number" :description="$ncr->title"/>

    @if ($ncr->hasResponse() || $ncr->isDispositioned())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                @if ($ncr->isDispositioned())
                    This NCR has been dispositioned. What you change here is what the engineering
                    decision was made against.
                @else
                    This NCR has been answered. What you change here is what the other side
                    responded to.
                @endif
                The response, the disposition, the verification and the closure cannot be reached
                from this screen at all.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('ncrs.update', $ncr) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('ncrs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('ncrs.show', $ncr) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
