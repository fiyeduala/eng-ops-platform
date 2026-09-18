{{--
    THE FIRE ROLL CALL. This is the screen the whole register exists for, and it is designed for
    somebody standing outside a building holding a phone, not for somebody at a desk.

    So: the headcount is the largest thing on the page, it counts HEADS rather than rows because a
    delegation of six arrived on one record, there is no pagination, and there are no filters beyond
    the site. Anything that turns reading it into searching it is wrong here.

    It is behind `visitor.view`, not `visitor.manage` — see the route file. An administrative
    permission between somebody and the list of who is still inside would be a security control that
    gets people killed.
--}}
<x-layouts.app title="Who is on site">
    <x-page-header title="Who is on site" description="Everybody signed in and not yet signed out. Refresh for the current position.">
        <x-slot:actions>
            <a href="{{ route('visitors.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @include('finance.errors')

    <div class="card mb-6 flex flex-wrap items-center justify-between gap-4 p-6">
        <div>
            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Visitors on site</p>
            <p class="mt-1 text-5xl font-semibold text-[var(--text-strong)]">{{ $headcount }}</p>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                across {{ $onSite->count() }} {{ \Illuminate\Support\Str::plural('visit', $onSite->count()) }}
                @if ($locationId)at this site @else at every site you can see @endif
                · as at {{ now()->format('j M Y H:i') }}
            </p>
        </div>

        @if ($locations !== [])
            <form method="GET" class="flex items-end gap-2">
                <div class="min-w-56">
                    <label class="label" for="location">Site</label>
                    <x-select name="location" :options="$locations" :selected="$locationId" placeholder="Everywhere I can see"/>
                </div>
                <button class="btn btn-secondary btn-sm">Show</button>
            </form>
        @endif
    </div>

    @if ($onSite->isEmpty())
        <div class="card p-10 text-center">
            <p class="text-sm font-medium text-[var(--text-strong)]">Nobody is signed in.</p>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                If that does not match what you can see in front of you, somebody came in without being signed in —
                which is what this register is for.
            </p>
        </div>
    @else
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--surface-muted)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                    <tr>
                        <th class="px-4 py-3">Visitor</th>
                        <th class="px-4 py-3">Here to see</th>
                        <th class="px-4 py-3">Site</th>
                        <th class="px-4 py-3">Signed in</th>
                        <th class="px-4 py-3">Badge</th>
                        @if ($canCheckOut)<th class="px-4 py-3"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    @foreach ($onSite as $visit)
                        <tr>
                            <td class="px-4 py-3">
                                <a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('visitors.show', $visit) }}">{{ $visit->full_name }}</a>
                                @if ($visit->company_name)<span class="block text-[11px] text-[var(--text-muted)]">{{ $visit->company_name }}</span>@endif
                                @if ($visit->headcount() > 1)
                                    <span class="mt-1 inline-block rounded bg-[var(--surface-muted)] px-1.5 py-0.5 text-[11px] font-medium">party of {{ $visit->headcount() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                {{ $visit->host?->fullName() ?? '—' }}
                                @if ($visit->requires_escort)
                                    <span class="block text-[11px] text-[var(--text-muted)]">
                                        escorted by {{ $visit->escort?->fullName() ?? 'nobody named' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-[var(--text-muted)]">{{ $visit->location?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{ $visit->checked_in_at?->format('H:i') }}
                                <span class="block text-[11px] text-[var(--text-muted)]">{{ $visit->checked_in_at?->diffForHumans(short: true) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $visit->badge_number ?? '—' }}</td>
                            @if ($canCheckOut)
                                <td class="px-4 py-3 text-right">
                                    @can ('checkOut', $visit)
                                        <form method="POST" action="{{ route('visitors.check-out', $visit) }}">@csrf
                                            <button class="btn btn-ghost btn-sm">Sign out</button>
                                        </form>
                                    @endcan
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
