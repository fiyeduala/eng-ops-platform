{{--
    The vendor register.

    IT OPENS ON "AVAILABLE TO ORDER FROM", not on everything, because that is the question a buyer
    walks up to the screen with. A list of every company we have ever dealt with answers "who exists";
    this answers "who can I raise a purchase order to this morning".

    THE PREQUALIFICATION COLUMN IS THE POINT OF THE PAGE. A supplier whose assessment lapses is not
    barred by anything dramatic — the certificate simply runs out, nobody notices, and the failure
    surfaces on the day an order is urgent. The column colours ahead of the date rather than on it,
    on a window the company sets in Settings.

    NO BANK DETAILS, ANYWHERE ON THIS PAGE. Not masked, not partial, not "last four". A register is
    the easiest thing in the application to screenshot, and `vendor.bank` exists precisely so that
    reading account numbers is a deliberate act on one supplier at a time (§61, §67).

    Expects: $vendors, $statuses, $types, $categories, $warningDays, $views, $filters, $canCreate
--}}
<x-layouts.app title="Vendors">

    <x-page-header
        title="Vendors"
        description="Who we buy from, and whether we are still allowed to.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vendors.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a vendor
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="vendors.index" :views="$views" :current="$filters['view']"/>

    {{-- No project filter: a vendor belongs to the company, not to a job. Passing an empty array
         makes the shared bar drop the control rather than render a select with nothing in it. --}}
    <x-register.filters route="vendors.index" :filters="$filters" :projects="[]"
                        :statuses="$statuses" placeholder="Name, code, number or city">
        <x-slot:extra>
            <div>
                <label for="vendor_type" class="label">Type</label>
                <x-select name="vendor_type" :options="$types"
                          :selected="$filters['vendor_type']" placeholder="Any type"/>
            </div>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories"
                          :selected="$filters['category']" placeholder="Any category"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$vendors" icon="building"
                      title="No vendors"
                      description="Either nothing matches these filters, or the vendors that do sit in units your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vendors.create') }}" class="btn btn-primary btn-sm">Add a vendor</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Vendor</th>
                <th>Type</th>
                <th>Category</th>
                <th>Prequalified until</th>
                <th>Rating</th>
                <th>Can we order?</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($vendors as $vendor)
                <tr>
                    <td>
                        <a href="{{ route('vendors.show', $vendor) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $vendor->name }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $vendor->code ?: $vendor->number }}
                            @if ($vendor->city) · {{ $vendor->city }} @endif
                            @if ($vendor->contacts_count) · {{ $vendor->contacts_count }} {{ Str::plural('contact', $vendor->contacts_count) }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $vendor->vendor_type ? ($types[$vendor->vendor_type] ?? Str::headline($vendor->vendor_type)) : '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $vendor->category ? ($categories[$vendor->category] ?? Str::headline($vendor->category)) : '—' }}
                    </td>

                    {{-- Amber inside the window the company configured, rose once it has gone.
                         Warning before the date is the whole value of holding the date. --}}
                    <td class="whitespace-nowrap text-sm">
                        @if ($vendor->prequalification_expires_on)
                            <span @class([
                                'font-medium text-rose-600' => $vendor->prequalificationHasExpired(),
                                'font-medium text-amber-600' => ! $vendor->prequalificationHasExpired()
                                    && $vendor->prequalificationExpiresWithin($warningDays),
                                'text-[var(--text-muted)]' => ! $vendor->prequalificationHasExpired()
                                    && ! $vendor->prequalificationExpiresWithin($warningDays),
                            ])>
                                {{ $vendor->prequalification_expires_on->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not recorded</span>
                        @endif
                    </td>

                    {{-- A score with no denominator is a number people argue about. --}}
                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        @if ($vendor->rated_count)
                            {{ number_format((float) $vendor->rating, 1) }}
                            <span class="text-xs">from {{ $vendor->rated_count }}</span>
                        @else
                            —
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($vendor->canBeUsed())
                            <span class="text-emerald-600">Yes</span>
                        @else
                            <span class="font-medium text-rose-600">{{ $vendor->unusableReason() }}</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($vendor->status)
                            <x-badge :tone="$vendor->status->tone()">{{ $vendor->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">New</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
