{{--
    The named people at a vendor or a client.

    ONE PARTIAL FOR BOTH REGISTERS, because a contact is the shape of a person and that will not
    diverge — unlike the policies, which are separate because they ask their PARENTS different
    questions as the two registers grow apart.

    EDITING HAPPENS IN PLACE. Each row opens into its own form rather than navigating to a page of
    its own, because a contact is not a record anybody goes to; it is a line on the company's page.
    Giving it a URL would create a second route to a personal phone number and email address whose
    access rules would then have to be kept in step with the parent's.

    RETIRING, NOT DELETING. People leave. The transmittal that named them two years ago still has to
    render, so a retired contact drops out of every picker and stays readable in history.

    Nothing here decides who may do anything. `$canEdit` only chooses whether to draw the controls;
    every endpoint re-authorizes against the parent record, which is the check that counts (§6, §84).

    Expects: $contacts, $canEdit, $storeRoute, $updateRoutePrefix, $destroyRoutePrefix, $partyName
--}}
<div class="card" id="contacts">
    <div class="card-header">
        <h3 class="card-title">Contacts</h3>
        <span class="text-xs text-[var(--text-muted)]">
            {{ $contacts->count() }} {{ Str::plural('person', $contacts->count()) }}
        </span>
    </div>

    <div class="divide-y divide-[var(--surface-border)]">
        @forelse ($contacts as $contact)
            <div class="p-5" x-data="{ editing: false }">
                <div class="flex items-start justify-between gap-4">
                    <div @class(['min-w-0', 'opacity-60' => ! $contact->is_active])>
                        <p class="font-medium text-[var(--text-strong)]">
                            {{ $contact->name }}
                            @if ($contact->is_primary)
                                <x-badge tone="brand">Primary</x-badge>
                            @endif
                            @if (! $contact->is_active)
                                <x-badge tone="neutral">No longer here</x-badge>
                            @endif
                        </p>
                        @if ($contact->position)
                            <p class="text-sm text-[var(--text-muted)]">{{ $contact->position }}</p>
                        @endif
                        <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[var(--text-muted)]">
                            @if ($contact->email)
                                <a href="mailto:{{ $contact->email }}" class="hover:text-brand-600">
                                    <x-icon name="mail" class="inline h-3.5 w-3.5"/> {{ $contact->email }}
                                </a>
                            @endif
                            @if ($contact->phone)
                                <span><x-icon name="phone" class="inline h-3.5 w-3.5"/> {{ $contact->phone }}</span>
                            @endif
                        </p>
                        @if ($contact->notes)
                            <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $contact->notes }}</p>
                        @endif
                    </div>

                    @if ($canEdit)
                        <button type="button" class="btn btn-ghost btn-sm shrink-0"
                                x-on:click="editing = ! editing">
                            <span x-text="editing ? 'Close' : 'Edit'">Edit</span>
                        </button>
                    @endif
                </div>

                @if ($canEdit)
                    <div x-show="editing" x-cloak class="mt-4 border-t border-[var(--surface-border)] pt-4">
                        <form method="POST" action="{{ route($updateRoutePrefix.'.update', $contact) }}"
                              class="space-y-4">
                            @csrf
                            @method('PUT')

                            @include('partials.party-contact-fields', ['contact' => $contact])

                            <div class="flex items-center gap-3">
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                @if ($contact->is_active)
                                    <x-delete-form :action="route($destroyRoutePrefix.'.destroy', $contact)"
                                                   label="No longer here"
                                                   :confirm="'Retire '.$contact->name.'?'"/>
                                @endif
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <div class="p-5">
                <p class="text-sm text-[var(--text-muted)]">
                    Nobody named yet. A company with no named contact is a company somebody has to
                    ring the switchboard of.
                </p>
            </div>
        @endforelse
    </div>

    @if ($canEdit)
        <div class="border-t border-[var(--surface-border)] p-5" x-data="{ adding: false }">
            <button type="button" class="btn btn-secondary btn-sm" x-show="! adding"
                    x-on:click="adding = true">
                <x-icon name="plus" class="h-3.5 w-3.5"/> Add a contact
            </button>

            <form method="POST" action="{{ $storeRoute }}" class="space-y-4" x-show="adding" x-cloak>
                @csrf

                @include('partials.party-contact-fields', ['contact' => null])

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn btn-primary btn-sm">Add to {{ $partyName }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="adding = false">Cancel</button>
                </div>
            </form>
        </div>
    @endif
</div>
