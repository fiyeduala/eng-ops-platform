{{--
    The measures this company keeps, each counted for whoever is reading.

    Expects: $rows, $entities, $scopes, $aggregates, $canManage

    EVERY FIGURE ON THIS PAGE WAS COUNTED A MOMENT AGO, through this viewer's own visibility. That
    is stated on the screen and not only in the code, because a colleague seeing a different number
    beside the same heading is otherwise a bug report, and the honest answer — "you can see more
    incidents than they can" — is one somebody has to be told before they ask.
--}}
<x-layouts.app title="Measures">

    <x-page-header
        title="Measures"
        description="The figures this company watches. Each one is a question about a register — how many, how much, what share — answered fresh every time somebody looks.">
        <x-slot:actions>
            @if ($canManage)
                <a href="{{ route('configuration.kpis.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New measure
                </a>
            @endif
            <a href="{{ route('configuration.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Configuration
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That measure was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p>
            These figures are counted over the records <span class="font-medium">you</span> are allowed
            to see. Somebody with a wider view of the company will correctly see larger numbers against
            the same measures — nothing here is a company-wide total unless your own access is.
        </p>
    </div>

    @if ($rows === [])
        <div class="card p-6 text-sm text-[var(--text-muted)]">
            <p class="mb-2 font-medium text-[var(--text-strong)]">No measures yet.</p>
            <p class="max-w-2xl">
                A measure is a question you want answered the same way every time: how many deliverables
                are overdue, what share of inspections passed, how many hours went into a project.
                Define it once and everybody sees the answer for their own part of the company.
            </p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($rows as $row)
                @php
                    $kpi = $row['kpi'];
                    $result = $row['result'];
                    $standing = $result->standing($kpi);
                @endphp

                <div class="card flex flex-col p-4 {{ $kpi->is_active ? '' : 'opacity-60' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-[var(--text-strong)]">{{ $kpi->name }}</p>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ $aggregates[$kpi->aggregate] ?? $kpi->aggregate }}
                                · {{ $entities[$kpi->entity_type] ?? $kpi->entity_type }}
                            </p>
                        </div>

                        @unless ($kpi->is_active)
                            {{-- The same words the renewals list uses for the same state. Two
                                 configuration screens that say "Off" and "Switched off" for one
                                 idea leave somebody wondering which of the two they are reading. --}}
                            <x-badge tone="warning">Switched off</x-badge>
                        @endunless
                    </div>

                    <div class="mt-4 flex-1">
                        @if ($result->available)
                            <p class="text-3xl font-semibold tabular-nums
                                      {{ match ($standing) {
                                            'critical' => 'text-red-600',
                                            'warning' => 'text-amber-600',
                                            'on_target' => 'text-emerald-600',
                                            default => 'text-[var(--text-strong)]',
                                      } }}">
                                {{ $result->display($kpi) }}{{ $kpi->unit ? ' '.$kpi->unit : '' }}
                            </p>

                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                @if ($kpi->target !== null)
                                    Target {{ rtrim(rtrim(number_format((float) $kpi->target, 2), '0'), '.') }}{{ $kpi->unit ? ' '.$kpi->unit : '' }},
                                    {{ $kpi->higherIsBetter() ? 'higher is better' : 'lower is better' }}.
                                @endif
                                Counted over {{ $result->population }}
                                {{ \Illuminate\Support\Str::plural('record', $result->population) }} you can see.
                            </p>
                        @else
                            {{--
                                Not a zero. A figure that could not be counted and a figure that came
                                back as nought are opposite pieces of news, and a dashboard that
                                prints the same "0" for both is one nobody should trust.
                            --}}
                            <p class="text-sm text-[var(--text-muted)]">
                                <x-icon name="alert" class="mr-1 inline h-3.5 w-3.5 text-amber-600"/>
                                No figure. {{ $result->reason }}
                            </p>
                        @endif
                    </div>

                    <div class="mt-4 space-y-1 border-t border-[var(--surface-border)] pt-3 text-xs text-[var(--text-muted)]">
                        @if ($kpi->description)
                            <p>{{ $kpi->description }}</p>
                        @endif

                        <p>
                            {{-- One echo rather than an inline @if, because a directive that touches
                                 the word before it is not a directive at all: Blade needs a non-word
                                 character in front of the @, so `...you can see@endif` compiles to
                                 the literal text "@endif" and leaves the if open to the end of the
                                 file. Written as an expression it cannot fail that way. --}}
                            Measured {{ \Illuminate\Support\Str::lower($scopes[$kpi->scope] ?? $kpi->scope) }}{{ $kpi->scope === 'organization' ? '' : ', shown here across everything you can see' }}.
                            @if ($kpi->isWindowed())
                                Counted over {{ \Illuminate\Support\Str::lower(\App\Models\KpiDefinition::PERIODS[$kpi->period] ?? $kpi->period) }}.
                            @endif
                        </p>
                    </div>

                    @if ($canManage)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <a href="{{ route('configuration.kpis.edit', $kpi) }}" class="btn btn-secondary btn-sm">Edit</a>

                            <form method="POST" action="{{ route('configuration.kpis.toggle', $kpi) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    {{ $kpi->is_active ? 'Switch off' : 'Switch on' }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('configuration.kpis.destroy', $kpi) }}"
                                  onsubmit="return confirm('Delete this measure? Switching it off keeps the definition, which is the only record of what last quarter\'s number counted.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</x-layouts.app>
