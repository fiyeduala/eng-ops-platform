{{--
    Every register that puts a reference number on paper.

    The EXAMPLE column is the point of this page. A pattern like {PREFIX}-{YYYY}-{SEQ} is a
    specification and "PRJ-2026-0007" is the thing people say out loud; showing only the former
    would make this a screen for developers. The example is rendered by the engine that issues the
    real numbers, using the live counter, so it is what the next record would actually be called.

    Expects: $registers, $canManage
--}}
<x-layouts.app title="Reference numbering">

    <x-page-header
        title="Reference numbering"
        description="How each kind of record is named on paper, in email and on site. Changes affect future records only — numbers already issued are never rewritten.">
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
                <span class="font-medium">numbering.manage</span> permission.
            </p>
        </div>
    @endunless

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Register</th>
                    <th>Pattern</th>
                    <th>Next reference</th>
                    <th class="text-right">Issued</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($registers as $register)
                    <tr>
                        <td>
                            <a href="{{ route('configuration.numbering.show', $register['key']) }}"
                               class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                {{ $register['name'] }}
                            </a>
                            <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $register['key'] }}</span>
                        </td>

                        <td class="whitespace-nowrap font-mono text-xs text-[var(--text-muted)]">
                            {{ $register['pattern'] }}
                        </td>

                        <td class="whitespace-nowrap font-mono text-sm text-[var(--text-strong)]">
                            {{ $register['example'] }}
                        </td>

                        <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                            {{ $register['issued'] }}
                        </td>

                        <td class="whitespace-nowrap text-right">
                            @if ($register['scheme'] === null)
                                {{-- Not a fault. The scheme is created the first time a record of this
                                     kind is numbered, or the first time somebody opens this row. --}}
                                <x-badge tone="neutral">Not yet in use</x-badge>
                            @elseif ($register['scheme']->reset_period !== 'none')
                                <x-badge tone="neutral">
                                    Restarts {{ $register['scheme']->reset_period === 'monthly' ? 'monthly' : 'yearly' }}
                                </x-badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</x-layouts.app>
