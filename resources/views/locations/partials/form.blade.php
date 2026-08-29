{{-- Expects: $location, $types --}}
<div class="grid gap-5 p-5 sm:grid-cols-2">

    <x-field label="Name" name="name" required class="sm:col-span-2">
        <x-input name="name" :value="$location->name" required autofocus placeholder="Lagos Fabrication Yard"/>
    </x-field>

    <x-field label="Type" name="type" required>
        <x-select name="type" :options="$types" :selected="$location->type" required/>
    </x-field>

    <x-field label="Code" name="code" help="Short identifier used in document and asset numbering.">
        <x-input name="code" :value="$location->code"/>
    </x-field>

    <x-field label="Address" name="address" class="sm:col-span-2">
        <x-textarea name="address" :value="$location->address" rows="3"/>
    </x-field>

    <x-field label="City" name="city">
        <x-input name="city" :value="$location->city"/>
    </x-field>

    <x-field label="State or province" name="state">
        <x-input name="state" :value="$location->state"/>
    </x-field>

    <x-field label="Country" name="country" help="Two-letter ISO code, e.g. NG.">
        <x-input name="country" :value="$location->country" maxlength="2" class="uppercase"/>
    </x-field>

    <x-field label="Time zone" name="timezone" help="Leave empty to use the company default.">
        <x-select name="timezone"
                  :selected="$location->timezone"
                  placeholder="Company default"
                  :options="collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz])->all()"/>
    </x-field>

    <div class="sm:col-span-2">
        <h4 class="mb-1 text-sm font-medium text-[var(--text-strong)]">Geography</h4>
        <p class="mb-4 text-xs text-[var(--text-muted)]">
            Optional. Recorded now so that site attendance and field reporting have a reference point later.
            Nothing is enforced against these values yet.
        </p>

        <div class="grid gap-5 sm:grid-cols-3">
            <x-field label="Latitude" name="latitude">
                <x-input name="latitude" type="number" step="0.0000001" :value="$location->latitude" placeholder="6.4531"/>
            </x-field>

            <x-field label="Longitude" name="longitude">
                <x-input name="longitude" type="number" step="0.0000001" :value="$location->longitude" placeholder="3.3958"/>
            </x-field>

            <x-field label="Geofence radius" name="geofence_radius" help="Metres.">
                <x-input name="geofence_radius" type="number" :value="$location->geofence_radius" min="0" placeholder="250"/>
            </x-field>
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $location->is_active ?? true))
               class="h-4 w-4 rounded border-[var(--surface-border)]">
        Active
    </label>
</div>
