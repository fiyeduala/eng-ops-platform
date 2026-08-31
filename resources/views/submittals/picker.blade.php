{{--
    Choose a controlled revision to put in a package.

    ITS OWN PAGE RATHER THAN A DROPDOWN, because a document register that is worth having has more
    entries than a select can hold, and because picking the wrong REVISION is the expensive mistake
    here — a select showing only document titles hides the one field that matters.

    THE LIST IS COMPILED THROUGH `document.view`, NOT THROUGH `submittal.edit`. That is the whole
    security argument of this screen: whoever can build a package can only ever enumerate the
    documents they could already open in the document register. A picker built on the submittal's
    own permission would let anybody who may edit a package read back the number and title of every
    document in the company, which is a leak whether or not they can attach it (§61, §67).

    Revisions already in the package are shown as taken rather than hidden, because "it is already
    in there" and "you cannot see it" are different answers and the reader deserves the true one.

    Expects: $submittal, $documents, $already, $q
--}}
<x-layouts.app :title="'Add to '.$submittal->number">

    <x-page-header
        title="Add a controlled revision"
        :description="'Into '.$submittal->number.' — '.$submittal->title">
        <x-slot:actions>
            <a href="{{ route('submittals.show', $submittal) }}" class="btn btn-ghost btn-sm">
                Back to the package
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('submittals.picker', $submittal) }}" class="card mb-6">
        <div class="flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[16rem] flex-1">
                <label for="q" class="label">Search</label>
                <x-input name="q" id="q" :value="$q" placeholder="Document number or title"/>
            </div>
            <button type="submit" class="btn btn-secondary">Search</button>
            @if (filled($q))
                <a href="{{ route('submittals.picker', $submittal) }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    @if ($submittal->project)
        <p class="mb-4 text-sm text-[var(--text-muted)]">
            Limited to documents on {{ $submittal->project->displayName() }}, because the package is
            against that project. Move the package if you need something from elsewhere.
        </p>
    @endif

    @error('document_version_id') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('description') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($documents->isEmpty())
        <x-empty-state
            icon="file-text"
            title="No documents to add"
            description="Either nothing matches that search, or the documents that do are ones your access does not reach. A revision has to exist before it can go in a package.">
            <x-slot:actions>
                <a href="{{ route('submittals.show', $submittal) }}" class="btn btn-secondary btn-sm">
                    Back to the package
                </a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="space-y-4">
            @foreach ($documents as $document)
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">{{ $document->title }}</h3>
                            <p class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">
                                {{ $document->number }}
                                @if ($document->category) · {{ $document->category->name }} @endif
                            </p>
                        </div>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($document->versions as $version)
                            @php $taken = in_array((int) $version->id, $already, true); @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                <div>
                                    <span class="text-sm font-medium text-[var(--text-strong)]">
                                        Revision {{ $version->revision }}
                                    </span>
                                    <span class="ml-2 text-xs text-[var(--text-muted)]">
                                        {{ $version->approved_at
                                            ? 'Approved '.$version->approved_at->format('j M Y')
                                            : 'Not approved' }}
                                    </span>
                                </div>

                                @if ($taken)
                                    <x-badge tone="neutral">Already in this package</x-badge>
                                @else
                                    <form method="POST" action="{{ route('submittals.items.store', $submittal) }}"
                                          class="flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="document_version_id" value="{{ $version->id }}">
                                        <input type="hidden" name="description" value="{{ $document->title }}">
                                        <input type="hidden" name="reference" value="{{ $document->number }}">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            Add revision {{ $version->revision }}
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <p class="mt-6 text-sm text-[var(--text-muted)]">
            Showing the first 100 matches. Narrow the search if what you want is not here — the list
            is deliberately capped rather than paginated, because a picker people scroll for five
            minutes is a picker people stop using.
        </p>
    @endif

</x-layouts.app>
