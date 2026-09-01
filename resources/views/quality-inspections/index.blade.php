{{--
    Quality inspections.

    IT OPENS ON "AWAITING INSPECTION" BECAUSE THAT IS THE ONLY VIEW ANYBODY IS IN A HURRY ABOUT. A
    concrete pour does not proceed until the reinforcement has been signed off, so the question this
    register answers before any other is which requests are holding work up — sorted by `required_by`
    with the undated ones last, exactly as the controller orders them.

    "REJECTED" IS ITS OWN VIEW AND IT IS NOT THE LIST OF NCRs. A rejected inspection means the work
    was looked at and failed; whether that failure was serious enough to raise a formal
    non-conformance is a separate judgement made on the show page. Keeping the two apart here is what
    stops the count of rejections being read as the count of NCRs.

    Expects: $inspections, $statuses, $projects, $types, $results, $views, $filters, $canCreate
--}}
<x-layouts.app title="Quality inspections">

    <x-page-header
        title="Quality inspections"
        description="Requests to look at finished work, and the verdict recorded when somebody did.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('quality-inspections.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Request an inspection
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="quality-inspections.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="quality-inspections.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title, activity or location">
        <x-slot:extra>
            <div>
                <label for="inspection_type" class="label">Inspection type</label>
                <x-select name="inspection_type" :options="$types"
                          :selected="$filters['inspection_type']" placeholder="Any type"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$inspections" icon="check-circle"
                      title="No inspections"
                      description="Either nothing matches these filters, or the inspections that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('quality-inspections.create') }}" class="btn btn-primary btn-sm">Request an inspection</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Work</th>
                <th>Type</th>
                <th>Required by</th>
                <th class="text-right">Checks</th>
                <th>Verdict</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($inspections as $inspection)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('quality-inspections.show', $inspection) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $inspection->number }}
                        </a>
                        @if ($inspection->itp_reference)
                            <span class="block text-xs text-[var(--text-muted)]">
                                ITP {{ $inspection->itp_reference }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <a href="{{ route('quality-inspections.show', $inspection) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $inspection->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($inspection->activity) {{ $inspection->activity }} @endif
                            @if ($inspection->location) · {{ $inspection->location }} @endif
                            @if ($inspection->project) · {{ $inspection->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $types[$inspection->inspection_type] ?? ($inspection->inspection_type ? Str::headline($inspection->inspection_type) : '—') }}
                    </td>

                    {{-- A date that has passed on work nobody has looked at yet is the reason this
                         screen exists, so it is the one thing coloured. Once the verdict is in, a
                         late date is history and stops shouting. --}}
                    <td class="whitespace-nowrap text-sm">
                        @if ($inspection->required_by)
                            <span @class([
                                'font-medium text-rose-600' => ! $inspection->wasInspected()
                                    && $inspection->required_by->isPast(),
                                'text-[var(--text-muted)]' => $inspection->wasInspected()
                                    || ! $inspection->required_by->isPast(),
                            ])>
                                {{ $inspection->required_by->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    {{-- A zero here is not a formatting detail. An inspection with no acceptance
                         criteria is a walk with an opinion at the end of it. --}}
                    <td class="whitespace-nowrap text-right tabular-nums">
                        @if ($inspection->items_count === 0)
                            <span class="font-medium text-amber-600">0</span>
                        @else
                            {{ $inspection->items_count }}
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($inspection->wasInspected())
                            <span @class([
                                'font-medium text-rose-600' => $inspection->result === 'rejected',
                                'text-[var(--text-body)]' => $inspection->result !== 'rejected',
                            ])>{{ $results[$inspection->result] ?? ($inspection->result ? Str::headline($inspection->result) : 'Inspected') }}</span>
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $inspection->inspector?->name ?? 'Inspector not recorded' }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not yet inspected</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($inspection->status)
                            <x-badge :tone="$inspection->status->tone()">{{ $inspection->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Requested</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
