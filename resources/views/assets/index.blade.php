{{--
    What the company owns, and who has it.

    THE CUSTODIAN COLUMN IS THE POINT OF THIS SCREEN. An asset register that lists serial numbers
    and acquisition costs answers an accountant's question; the question people actually walk up to
    this screen with is "where is the 40kVA generator and who has it", and that is one column with a
    person's name in it. Everything else is reference.

    "HELD BY NOBODY" IS A TAB rather than a warning banner, because an unassigned asset is not a
    fault — a spare pump on a shelf is legitimately held by nobody. It is a tab because that shelf is
    also where things quietly go missing, and somebody should be able to look at the list.

    Expects: $assets, $statuses, $projects, $categories, $views, $filters, $canCreate
--}}
<x-layouts.app title="Assets">

    <x-page-header
        title="Assets"
        description="Plant, equipment, tools and vehicles — and the person answerable for each one.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('assets.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add an asset
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="assets.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="assets.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Code, name, serial or tag number">
        <x-slot:extra>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories" :selected="$filters['category']"
                          placeholder="Any category"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$assets" icon="package"
                      title="No assets"
                      description="Nothing your access reaches. An asset follows its custodian, its project and the unit that owns it, so a site's plant may be visible only to that site.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('assets.create') }}" class="btn btn-primary btn-sm">Add an asset</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Asset</th>
                <th>Category</th>
                <th>Held by</th>
                <th>Where</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($assets as $asset)
                <tr>
                    <td>
                        <a href="{{ route('assets.show', $asset) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $asset->name }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $asset->code }}@if ($asset->is_vehicle) · vehicle @endif
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $categories[$asset->category] ?? $asset->category ?: '—' }}
                    </td>

                    <td class="text-sm">
                        @if ($asset->custodian)
                            <span class="text-[var(--text-body)]">{{ $asset->custodian->name }}</span>
                        @else
                            {{-- Stated rather than left blank. A dash here reads as missing data;
                                 "nobody" is the actual fact and is worth somebody noticing. --}}
                            <span class="text-[var(--text-muted)]">Nobody</span>
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $asset->project?->name ?: $asset->orgUnit?->name ?: $asset->location?->name ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($asset->status)
                            <x-badge :tone="$asset->status->tone()">{{ $asset->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Not set</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
