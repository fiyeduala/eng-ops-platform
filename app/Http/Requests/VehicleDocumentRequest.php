<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VehicleDocument;
use App\Services\VehicleDocumentService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * File an insurance certificate, a licence or a permit — or next year's replacement for one.
 *
 * THE SAME FORM SERVES CREATE, EDIT AND RENEW, because a renewal is the same paperwork from the same
 * authority with two things changed. What differs is the ROUTE, not the fields: `renew` creates a new
 * row inheriting type and issuer from the old one, and never inherits the number or the file, because
 * copying either would be a forgery in a register somebody may one day rely on.
 *
 * THE UPLOAD IS CHECKED ON SIZE HERE AND ON MIME, EXTENSION AND SIZE IN THE SERVICE (§67). Not
 * duplication for its own sake: Laravel needs a size before the file is moved, and a request that
 * gets as far as the service with a 900MB body has already cost the server the upload. The real
 * content check belongs in the service, where it also protects a seeder and an import — and where
 * the extension is checked separately from the MIME, because the extension is what a web server
 * would dispatch on and the MIME is only what the file claims to be.
 *
 * `expires_on` IS NOT REQUIRED. A proof of ownership does not lapse, and a null expiry means exactly
 * that rather than "unknown" — reporting every one of them as expired on the day it is filed is how
 * a fleet officer learns to ignore the register.
 *
 * THERE IS NO STATUS FIELD, and there is no status vocabulary for this register at all. Expiry is a
 * date compared to today, every time it is asked. A cron that stops running costs the company its
 * warnings and can never make a lapsed certificate look valid.
 */
class VehicleDocumentRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            // Required when filing against a vehicle for the first time; the renew and edit routes
            // already know which vehicle, and moving a certificate to a different lorry is not an
            // edit anybody should be able to make.
            'vehicle_id' => [
                $this->isMethod('POST') && $this->routeIs('vehicle-documents.store') ? 'required' : 'prohibited',
                'integer', $this->existsInTenant('vehicles'),
            ],

            'document_type' => [
                'required',
                Rule::in($lists->keys('vehicle_document.types', VehicleDocument::DEFAULT_TYPES)),
            ],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issuer' => ['nullable', 'string', 'max:200'],

            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],

            'file' => ['nullable', 'file', 'max:'.(int) (VehicleDocumentService::MAX_FILE_BYTES / 1024)],

            'notes' => ['nullable', 'string', 'max:20000'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'document_number', 'issuer', 'issued_on', 'expires_on', 'cost', 'notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'expires_on.after_or_equal' => 'A certificate cannot expire before the day it was issued.',
            'file.max' => 'A scan may be at most 10MB. Photograph the certificate rather than scanning it at full resolution.',
            'vehicle_id.prohibited' => 'A certificate cannot be moved to a different vehicle.',
        ];
    }

    /** @return array<string,mixed> */
    public function documentAttributes(): array
    {
        $data = $this->validated();

        unset($data['file'], $data['vehicle_id']);

        return $data;
    }
}
