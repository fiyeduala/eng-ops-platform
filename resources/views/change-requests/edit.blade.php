{{--
    Edit a change request.

    Only while it is undecided. The policy refuses `update` on a decided request, so this form is
    unreachable once an answer has been given — a change whose description could be rewritten after
    approval would be a way of approving one thing and delivering another.
--}}
<x-layouts.app :title="'Edit '.$changeRequest->displayName()">

    <x-page-header
        :title="'Edit '.$changeRequest->title"
        :description="$changeRequest->number">
        <x-slot:actions>
            <a href="{{ route('change-requests.show', $changeRequest) }}" class="btn btn-ghost btn-sm">
                Back to the change
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('change-requests.update', $changeRequest) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('change-requests.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('change-requests.show', $changeRequest) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
