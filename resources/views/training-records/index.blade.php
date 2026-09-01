{{--
    Training records.

    THE EXPIRY DATE IS THE PRODUCT AND IT IS SORTED FIRST. Nobody opens this register to admire it;
    they open it because somebody is going into a confined space on Thursday and the question is
    whether their ticket is still good. Everything else on the row is context for that column.

    "MINE" MEANS ABOUT ME, NOT ENTERED BY ME. This is the one register in the application where it
    does, because a person's own certificates are the thing they most legitimately want to check —
    and the policy still refuses to let them edit their own expiry date.

    Expects: $records, $statuses, $projects, $courses, $people, $horizon, $views, $filters, $canCreate
--}}
<x-layouts.app title="Training records">

    <x-page-header
        title="Training records"
        description="Who holds what, and until when.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('training-records.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Record a certificate
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="training-records.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="training-records.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, name or certificate number">
        <x-slot:extra>
            <div>
                <label for="course" class="label">Course</label>
                <x-select name="course" :options="$courses" :selected="$filters['course']"
                          placeholder="Any course"/>
            </div>
            <div>
                <label for="person" class="label">Person</label>
                <x-select name="person" :options="$people" :selected="$filters['person']"
                          placeholder="Anybody"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$records" icon="badge"
                      title="No training records"
                      description="Either nothing matches these filters, or the certificates that do belong to people your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('training-records.create') }}" class="btn btn-primary btn-sm">Record a certificate</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Who</th>
                <th>Course</th>
                <th>Completed</th>
                <th>Expires</th>
                <th>Result</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($records as $record)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('training-records.show', $record) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $record->number }}
                        </a>
                        @if ($record->certificate_number)
                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                {{ $record->certificate_number }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <a href="{{ route('training-records.show', $record) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $record->holderLabel() }}
                        </a>
                        @if ($record->company)
                            <span class="block text-xs text-[var(--text-muted)]">{{ $record->company }}</span>
                        @endif
                    </td>

                    <td class="text-[var(--text-muted)]">
                        {{ $record->course?->name ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $record->completed_on?->format('j M Y') ?: '—' }}
                    </td>

                    {{-- Three states and they are not the same colour: lapsed is a fact about
                         today, expiring is a fact about the next {{ $horizon }} days, and no expiry
                         is neither. --}}
                    <td class="whitespace-nowrap">
                        @if ($record->expires_on === null)
                            <span class="text-[var(--text-muted)]">Never</span>
                        @elseif ($record->hasExpired())
                            <span class="text-sm font-medium text-rose-600">
                                {{ $record->expires_on->format('j M Y') }}
                            </span>
                        @elseif ($record->expiresWithin($horizon))
                            <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                                {{ $record->expires_on->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-sm text-[var(--text-muted)]">
                                {{ $record->expires_on->format('j M Y') }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($record->status)
                            <x-badge :tone="$record->status->tone()">{{ $record->status->label }}</x-badge>
                        @elseif ($record->result)
                            <x-badge tone="neutral">{{ Str::ucfirst($record->result) }}</x-badge>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
