{{--
    Choose the revisions to put on a transmittal.

    THE LIST IS COMPILED ON `document.download`, NOT `document.view`. Sending a document out is a
    stronger act than reading it, and this screen is the moment somebody decides to send one. A
    picker built on view would let a person who may read a confidential drawing attach it to a cover
    sheet addressed outside the company — an exfiltration route with letterhead on it (§61, §67).

    EACH ROW OFFERS EVERY REVISION, not just the current one. Re-issuing an earlier revision is an
    ordinary act in document control, and a picker that only offered the latest would force somebody
    to record the wrong thing in the one register whose whole purpose is being precise about which
    revision went out.

    Expects: $transmittal, $documents, $already, $media, $q
--}}
<x-layouts.app :title="'Add revisions to '.$transmittal->number">

    <x-page-header
        title="Add revisions"
        :description="'Onto '.$transmittal->number.' — '.$transmittal->subject">
        <x-slot:actions>
            <a href="{{ route('transmittals.show', $transmittal) }}" class="btn btn-ghost btn-sm">
                Back to the transmittal
            </a>
        </x-slot:actions>
    </x-page-header>

    @error('document_version_id')
        <div class="mb-5 card border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    {{-- ---------------------------------------------------------------- search --}}
    <form method="GET" action="{{ route('transmittals.picker', $transmittal) }}" class="card mb-5 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <label for="q" class="label">Search documents</label>
                <input type="search" name="q" id="q" class="input" value="{{ $q }}"
                       placeholder="Number or title">
            </div>

            <button type="submit" class="btn btn-secondary">
                <x-icon name="search" class="h-3.5 w-3.5"/> Search
            </button>

            @if ($q)
                <a href="{{ route('transmittals.picker', $transmittal) }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>

        @if ($transmittal->project)
            <p class="help mt-2">
                Limited to documents on {{ $transmittal->project->displayName() }}, because the
                transmittal is raised against that project.
            </p>
        @endif
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($documents->isEmpty())
            <x-empty-state
                icon="file-text"
                title="No documents to send"
                description="Either nothing matches, or nothing here is something you are allowed to send out. Being able to read a document is not the same as being able to issue it."/>
        @else
            {{--
                The forms live outside the table on purpose. A <form> is not valid inside a <tr>, and
                a browser recovering from that hoists it out of the table anyway — which works by
                accident rather than by design. Declaring them here and pointing the controls at them
                with `form=` is the same behaviour, deliberately.
            --}}
            @foreach ($documents as $document)
                @if ($document->versions->isNotEmpty())
                    <form method="POST" action="{{ route('transmittals.items.store', $transmittal) }}"
                          id="add-{{ $document->id }}">
                        @csrf
                    </form>
                @endif
            @endforeach

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th class="w-40">Revision</th>
                            <th class="w-32">Media</th>
                            <th class="w-24">Copies</th>
                            <th class="w-56">Remarks</th>
                            <th class="w-24"><span class="sr-only">Add</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php
                                $versions = $document->versions
                                    ->mapWithKeys(fn ($v) => [
                                        $v->id => 'Rev '.$v->revision.($v->approved_at ? '' : ' (unapproved)'),
                                    ])
                                    ->all();

                                $onTransmittal = $document->versions
                                    ->filter(fn ($v) => in_array((int) $v->id, $already, true));
                            @endphp

                            <tr>
                                <td>
                                    <a href="{{ route('documents.show', $document) }}"
                                       class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $document->number }}
                                    </a>
                                    <span class="block text-xs text-[var(--text-muted)]">
                                        {{ $document->title }}
                                        @if ($document->category)
                                            · {{ $document->category->name }}
                                        @endif
                                    </span>

                                    @if ($onTransmittal->isNotEmpty())
                                        <span class="mt-1 inline-block text-xs text-[var(--text-muted)]">
                                            Already on this transmittal:
                                            {{ $onTransmittal->map(fn ($v) => 'Rev '.$v->revision)->implode(', ') }}
                                        </span>
                                    @endif
                                </td>

                                @if ($versions === [])
                                    <td colspan="5" class="text-sm text-[var(--text-muted)]">
                                        No revisions yet — there is nothing to send.
                                    </td>
                                @else
                                    <td>
                                        <select name="document_version_id" class="select"
                                                form="add-{{ $document->id }}" required>
                                            @foreach ($versions as $versionId => $label)
                                                <option value="{{ $versionId }}"
                                                    @selected((int) $document->current_version_id === (int) $versionId)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>

                                    <td>
                                        <select name="media" class="select" form="add-{{ $document->id }}">
                                            @foreach ($media as $value => $label)
                                                <option value="{{ $value }}" @selected($value === 'electronic')>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>

                                    <td>
                                        <input type="number" name="copies" class="input" min="1" max="999"
                                               value="1" form="add-{{ $document->id }}">
                                    </td>

                                    <td>
                                        <input type="text" name="remarks" class="input" maxlength="300"
                                               form="add-{{ $document->id }}" placeholder="Optional">
                                    </td>

                                    <td>
                                        <button type="submit" class="btn btn-primary btn-sm"
                                                form="add-{{ $document->id }}">
                                            Add
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs text-[var(--text-muted)]">
        The first hundred matches are shown. Narrow the search rather than scrolling — the list is
        deliberately capped so a picker can never become a way to enumerate the whole register.
    </p>

</x-layouts.app>
