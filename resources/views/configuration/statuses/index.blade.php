{{--
    Every register whose vocabulary a company may edit.

    The counts are the useful part. A register showing 0 statuses is one the provisioner has not
    seeded and whose records will have no state at all — a real fault, visible at a glance, which
    would otherwise only surface as a broken create form months later.

    Expects: $registers, $canManage
--}}
<x-layouts.app title="Statuses & transitions">

    <x-page-header
        title="Statuses & transitions"
        description="The states each kind of record can be in, in your own words, and exactly who may move a record from one to the next.">
        <x-slot:actions>
            <a href="{{ route('configuration.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Configuration
            </a>
        </x-slot:actions>
    </x-page-header>

    @unless ($canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                You can read this configuration but not change it. Editing needs the
                <span class="font-medium">status.manage</span> permission.
            </p>
        </div>
    @endunless

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Register</th>
                    <th class="text-right">Statuses</th>
                    <th class="text-right">Permitted moves</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($registers as $register)
                    <tr>
                        <td>
                            <a href="{{ route('configuration.statuses.show', $register['key']) }}"
                               class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                {{ $register['name'] }}
                            </a>
                            <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $register['key'] }}</span>
                        </td>

                        <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                            {{ $register['statuses'] }}
                        </td>

                        <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                            {{ $register['transitions'] }}
                        </td>

                        <td class="whitespace-nowrap text-right">
                            @if ($register['statuses'] === 0)
                                <x-badge tone="danger">No states defined</x-badge>
                            @elseif ($register['transitions'] === 0)
                                <x-badge tone="warning">Nothing can move</x-badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</x-layouts.app>
