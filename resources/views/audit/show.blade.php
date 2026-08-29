{{--
    One audit entry.

    The entry is shown whether or not the record it describes still exists, and whether or not this
    viewer can reach it — that is what a trail is for. Only the LINK to the live record is
    conditional, so the page never becomes a side door into something the access model refuses (§61).
--}}
@php
    /**
     * Audit values are stored as JSON, so a field can come back as a scalar, a list, or a nested
     * map — a role's grants, for instance. Rendered here rather than in the controller because it
     * is presentation, and because "null" and "empty" have to stay visibly different: one means the
     * field was not set, the other means it was set to nothing.
     */
    $render = function ($value) use (&$render) {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'nothing';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = is_int($key) ? $render($item) : $key.': '.$render($item);
            }

            return implode(', ', $parts);
        }

        return (string) $value;
    };
@endphp

<x-layouts.app title="Audit entry">

    <x-page-header
        :title="$entry->description ?: Str::of($entry->action)->replace('_', ' ')->ucfirst()"
        :description="$entry->created_at->format('l j F Y').' at '.$entry->created_at->format('H:i')">
        <x-slot:actions>
            <a href="{{ route('audit.index') }}" class="btn btn-sm btn-ghost">Back to the trail</a>
            @if ($subjectUrl)
                <a href="{{ $subjectUrl }}" class="btn btn-sm btn-secondary">Open the record</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ changes --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">What changed</h3>
                    @if ($entry->is_privileged)
                        <x-badge tone="warning">Privileged action</x-badge>
                    @endif
                </div>

                @if ($changes === [])
                    <x-empty-state
                        icon="scroll"
                        title="No field values recorded"
                        description="This entry records that something happened rather than a change to particular fields — a sign-in, a download, or a refused attempt."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Before</th>
                                    <th>After</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($changes as $change)
                                    <tr>
                                        <td class="align-top font-mono text-xs text-[var(--text-muted)]">{{ $change['field'] }}</td>
                                        <td class="max-w-xs align-top break-words text-[var(--text-muted)] line-through decoration-1">
                                            {{ $render($change['old']) }}
                                        </td>
                                        <td class="max-w-xs align-top break-words text-[var(--text-strong)]">
                                            {{ $render($change['new']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------ context --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who and what</h3>
                </div>
                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Done by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $entry->actorName() }}
                            @unless ($entry->user_id)
                                <span class="text-[var(--text-muted)]">— an automated process, not a person</span>
                            @endunless
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Action</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-[var(--text-strong)]">{{ $entry->action }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Record</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $subjectLabel }}
                            @if ($subject === null)
                                <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                    No longer exists. The entry outlives the record on purpose.
                                </span>
                            @elseif (! $subjectVisible)
                                <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                    Outside your access, so it is named but not linked.
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Where it came from</h3>
                </div>
                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Request</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-[var(--text-strong)]">
                            {{ $entry->method ?: '—' }} {{ $entry->url ?: '' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Address</dt>
                        <dd class="mt-1 font-mono text-xs text-[var(--text-strong)]">{{ $entry->ip_address ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Browser</dt>
                        <dd class="mt-1 break-words text-xs text-[var(--text-muted)]">{{ $entry->user_agent ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="flex items-start gap-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                <x-icon name="lock" class="mt-0.5 h-3.5 w-3.5 shrink-0"/>
                <p>
                    This entry cannot be edited or removed. The model refuses both operations, and there is no
                    route that would carry the attempt.
                </p>
            </div>
        </div>
    </div>

</x-layouts.app>
