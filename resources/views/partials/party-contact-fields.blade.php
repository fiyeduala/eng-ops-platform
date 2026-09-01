{{--
    The fields of one contact, used by both the add form and every edit form on the page.

    `old()` IS DELIBERATELY NOT USED HERE. Several of these forms render on one page at once, and
    they all post the same field names — so a failed edit of contact 3 would repopulate contacts 1
    and 2 with contact 3's details, which looks exactly like data loss. Validation failures come back
    with a message and the values the user typed for the form they submitted; the rest of the page
    shows what is stored, which is the truth.

    Expects: $contact (a model, or null for a new one)
--}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-field label="Name" :name="'name'" required>
        <x-input name="name" :value="$contact?->name" required/>
    </x-field>

    <x-field label="Position" :name="'position'">
        <x-input name="position" :value="$contact?->position"
                 placeholder="Contracts manager, site agent, accounts"/>
    </x-field>

    <x-field label="Email" :name="'email'">
        <x-input type="email" name="email" :value="$contact?->email"/>
    </x-field>

    <x-field label="Phone" :name="'phone'">
        <x-input name="phone" :value="$contact?->phone"/>
    </x-field>
</div>

<x-field label="Notes" :name="'notes'">
    <x-textarea name="notes" rows="2"
                placeholder="Best reached mornings; handles all mechanical enquiries.">{{ $contact?->notes }}</x-textarea>
</x-field>

<div class="flex flex-wrap gap-6">
    {{-- Ticking this demotes whoever held it, in the same transaction. Two primary contacts is the
         state that makes a register useless for "who do we call", so it cannot be held. --}}
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_primary" value="1" @checked($contact?->is_primary)>
        <span>Primary contact <span class="text-[var(--text-muted)]">— replaces the current one</span></span>
    </label>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked($contact?->is_active ?? true)>
        <span>Still works here</span>
    </label>
</div>
