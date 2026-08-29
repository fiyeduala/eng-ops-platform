<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A physical place: office, site, yard, warehouse (§3).
 *
 * Deliberately not part of the reporting tree — where someone works is orthogonal to which
 * department they report into, and companies that conflate the two end up unable to express a
 * secondee.
 *
 * `country` is stored, never interpreted. No tax rule, holiday calendar or statutory behaviour
 * hangs off it (§47); those belong in configuration a company can set for itself.
 */
class LocationRequest extends TenantRequest
{
    public function rules(): array
    {
        $location = $this->location();

        return [
            'name' => ['required', 'string', 'max:150'],

            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/',
                $this->uniqueInTenant('locations', 'code', $location?->id),
            ],

            'type' => ['required', Rule::in(self::types())],

            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // Metres. Used to validate mobile clock-in (§48), so a wildly small radius would
            // lock a whole site out of attendance; the floor is generous on purpose.
            'geofence_radius' => ['nullable', 'integer', 'min:25', 'max:50000'],

            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],

            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lat = $this->input('latitude');
            $lng = $this->input('longitude');

            // Half a coordinate is not a location. Left unchecked it produces a site that looks
            // geofenced in the UI and silently admits every clock-in.
            if (($lat === null) !== ($lng === null)) {
                $validator->errors()->add(
                    $lat === null ? 'latitude' : 'longitude',
                    'Latitude and longitude must be given together.'
                );
            }

            if ($this->input('geofence_radius') !== null && $lat === null) {
                $validator->errors()->add(
                    'geofence_radius',
                    'A geofence needs coordinates to centre on.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'code', 'address', 'city', 'state', 'country',
            'latitude', 'longitude', 'geofence_radius', 'timezone',
        ]);

        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        $this->normaliseBooleans(['is_active']);
    }

    /** @return array<int,string> */
    public static function types(): array
    {
        return ['office', 'site', 'yard', 'warehouse', 'plant', 'remote'];
    }

    public function attributes(): array
    {
        return [
            'geofence_radius' => 'geofence radius',
            'country' => 'country code',
        ];
    }

    private function location(): ?Location
    {
        $bound = $this->route('location');

        return $bound instanceof Location ? $bound : null;
    }
}
