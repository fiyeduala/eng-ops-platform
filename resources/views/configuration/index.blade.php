{{--
    The Admin Configuration Center.

    A map, not a menu. The grouping and the order are the argument the page is making: name the
    company, then say how work moves through it, then say what it records, then check the record.
    Somebody handed this platform on a Monday morning should be able to work down this page and have
    a configured company by the end of it, without ever asking a developer anything (§73).

    Every tile here is a link to a screen that gates itself. What is shown is Gate 2 — a courtesy
    (§96) — and the absence of a tile means the viewer cannot open it anyway.

    Expects: $groups
--}}
<x-layouts.app title="Configuration">

    <x-page-header
        title="Configuration"
        description="Everything about this platform that is yours to decide — the words, the numbers, the approvals and the forms. Nothing here needs a developer."/>

    @if ($groups === [])
        <div class="card overflow-hidden">
            <x-empty-state icon="lock" title="Nothing here is yours to change"
                           description="Configuration is held by administrators. You can still use every screen your role reaches — this page only lists the settings behind them."/>
        </div>
    @endif

    <div class="space-y-8">
        @foreach ($groups as $group)
            <section>
                <div class="mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-[var(--text-strong)]">
                        {{ $group['label'] }}
                    </h2>
                    <p class="mt-0.5 max-w-3xl text-sm text-[var(--text-muted)]">{{ $group['description'] }}</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($group['tiles'] as $tile)
                        <a href="{{ $tile['url'] }}"
                           class="card flex gap-3 p-4 transition hover:border-brand-400 hover:shadow-sm">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--surface-muted)]">
                                <x-icon :name="$tile['icon']" class="h-4 w-4 text-[var(--text-muted)]"/>
                            </span>

                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-[var(--text-strong)]">{{ $tile['label'] }}</span>
                                <span class="mt-1 block text-xs leading-relaxed text-[var(--text-muted)]">{{ $tile['blurb'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

</x-layouts.app>
