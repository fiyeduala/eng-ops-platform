<div class="grid gap-4 md:grid-cols-2">
    <x-field label="First name" name="first_name" required><x-input name="first_name" :value="old('first_name', $applicant->first_name)" maxlength="100"/></x-field>
    <x-field label="Last name" name="last_name" required><x-input name="last_name" :value="old('last_name', $applicant->last_name)" maxlength="100"/></x-field>
    <x-field label="Email" name="email"><x-input type="email" name="email" :value="old('email', $applicant->email)" maxlength="255"/></x-field>
    <x-field label="Phone" name="phone"><x-input name="phone" :value="old('phone', $applicant->phone)" maxlength="60"/></x-field>
    <x-field label="Where they came from" name="source" class="md:col-span-2">
        <x-select name="source" :options="$sources" :selected="old('source', $applicant->source)" placeholder="Not stated"/>
    </x-field>
</div>
<x-field label="Notes" name="notes" class="mt-4" help="Read by recruitment and the hiring manager. Never sent in a notification.">
    <x-textarea name="notes" rows="4">{{ old('notes', $applicant->notes) }}</x-textarea>
</x-field>
<x-field label="CV" name="cv" class="mt-4" help="PDF, Word or a scan, up to 8MB. Stored privately — it is never a public link.">
    <input type="file" name="cv" class="input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
</x-field>
