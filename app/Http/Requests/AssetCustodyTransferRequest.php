<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AssetCustodyTransfer;
use Illuminate\Validation\Rule;

/**
 * Hand a thing over, and record who is now answerable for it.
 *
 * ONLY THE `to_` SIDE IS ACCEPTED. The `from_` side is read off the asset's own custody cache inside
 * AssetService::transferCustody(), because whoever is filling in the form does not get to say who
 * they are taking it from — a handover claiming the machine came from a person who never had it is
 * the exact document a dispute turns on (§84).
 *
 * ALL FOUR TARGETS ARE OPTIONAL AND MAY ALL BE BLANK. A handover to nobody is how an asset comes
 * back to the store, and it is a real, common movement rather than an empty form: the previous
 * custodian stops being answerable, which is the whole point of recording it. The service refuses
 * only the handover that changes nothing.
 *
 * `authorised_by` IS VALIDATED BUT NOT REQUIRED HERE. Whether a second signature is needed is a
 * per-organization setting, and the service enforces it — so a seeder, an import and an API call are
 * held to the company's rule as well as this form is. Duplicating the condition here would give two
 * places that can disagree about a control.
 *
 * `condition` IS A FIXED VOCABULARY, unlike an asset category. Good, fair, poor and damaged mean the
 * same thing in every yard, and the value is read by whoever decides whether the last custodian
 * broke it — so it is not a word each company gets to redefine.
 */
class AssetCustodyTransferRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'custodian_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],

            'reason' => ['nullable', 'string', 'max:300'],
            'condition' => ['nullable', Rule::in(AssetCustodyTransfer::CONDITIONS)],
            'remarks' => ['nullable', 'string', 'max:20000'],
            'authorised_by' => ['nullable', 'integer', $this->existsInTenant('users')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'custodian_id', 'org_unit_id', 'project_id', 'location_id',
            'reason', 'condition', 'remarks', 'authorised_by',
        ]);
    }

    /**
     * The four target columns, in the shape transferCustody() expects.
     *
     * @return array{custodian_id:int|null,org_unit_id:int|null,project_id:int|null,location_id:int|null}
     */
    public function destination(): array
    {
        $validated = $this->validated();

        return [
            'custodian_id' => $validated['custodian_id'] ?? null,
            'org_unit_id' => $validated['org_unit_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'location_id' => $validated['location_id'] ?? null,
        ];
    }
}
