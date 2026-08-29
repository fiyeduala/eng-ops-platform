<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a project (§13, §14).
 *
 * Three fields are conspicuously absent, and their absence is the point:
 *
 *   - `number` is issued by the numbering engine, never typed (§64). A field here would let two
 *     people give two projects the same reference, which no amount of later tidying fixes.
 *   - `status_id` moves through StatusMachine's whitelist, not through a form select, so "who may
 *     close a project" stays configuration rather than a hidden input (§63).
 *   - `organization_id` comes from the session. A hidden field of that name is inert (§74).
 *
 * Commercial figures are split off the same way EmployeeRequest splits confidential identity data:
 * a coordinator who may not SEE the contract value must not be able to overwrite it either, and a
 * form that merely omitted the input would still accept it if it were re-added by hand (§84).
 */
class ProjectRequest extends TenantRequest
{
    /**
     * Fields only writable with project.view_financials.
     *
     * @var array<int,string>
     */
    public const FINANCIAL_FIELDS = ['contract_value', 'currency'];

    public function rules(): array
    {
        $project = $this->project();

        return [
            // --- identity -----------------------------------------------------------------
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'nullable', 'string', 'max:64',
                $this->uniqueInTenant('projects', 'code', $project?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_type_id' => ['nullable', 'integer', $this->existsInTenant('project_types', 'id', true)],

            // --- client -------------------------------------------------------------------
            // Free text until Phase 5 introduces a client register. A nullable client_id pointing
            // at a table that does not exist yet would be a foreign key nothing enforces.
            'client_name' => ['nullable', 'string', 'max:200'],
            'contract_reference' => ['nullable', 'string', 'max:120'],

            // --- placement ----------------------------------------------------------------
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],
            'manager_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'sponsor_id' => ['nullable', 'integer', $this->existsInTenant('users')],

            // --- sensitivity --------------------------------------------------------------
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],

            // --- schedule -----------------------------------------------------------------
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date', 'after_or_equal:actual_start'],

            // --- commercial ---------------------------------------------------------------
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.99'],
            'currency' => ['nullable', 'string', 'max:8'],

            // --- progress -----------------------------------------------------------------
            'progress_percent' => ['nullable', 'integer', 'between:0,100'],
            'health' => ['nullable', Rule::in(self::healths())],
            'priority' => ['required', Rule::in(self::priorities())],

            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // A completion date with no start date is a data-entry slip that quietly poisons every
            // duration report built on top of it. `after_or_equal:actual_start` cannot catch it,
            // because a null start satisfies that rule vacuously.
            if ($this->filled('actual_end') && ! $this->filled('actual_start')) {
                $validator->errors()->add(
                    'actual_end',
                    'Record when the project actually started before recording when it finished.'
                );
            }

            if ($this->filled('sponsor_id')
                && $this->filled('manager_id')
                && (int) $this->input('sponsor_id') === (int) $this->input('manager_id')) {
                $validator->errors()->add(
                    'sponsor_id',
                    'The sponsor and the manager should be different people — the sponsor is who the manager escalates to.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'code', 'description', 'project_type_id', 'client_name', 'contract_reference',
            'org_unit_id', 'location_id', 'manager_id', 'sponsor_id',
            'planned_start', 'planned_end', 'actual_start', 'actual_end',
            'contract_value', 'currency', 'progress_percent', 'health',
        ]);

        $this->merge([
            'confidentiality' => $this->input('confidentiality') ?: Confidentiality::Internal->value,
            'priority' => $this->input('priority') ?: 'normal',
        ]);

        $this->normaliseBooleans(['is_active']);
    }

    /**
     * The validated payload, reduced to what this actor may actually write.
     *
     * Dropped rather than rejected, for the reason EmployeeRequest gives: a coordinator editing a
     * project's schedule should not be handed an error about a commercial field their form never
     * showed them. It is simply not theirs to change, so it keeps the value it had.
     *
     * @return array<string,mixed>
     */
    public function attributesFor(Project $record): array
    {
        $data = $this->validated();
        $user = $this->user();

        // On create there is no row for the record-level gates to test, so the class-level
        // permission is the right question; once the row exists, reach over THIS project is.
        $mayWriteFinancials = $record->exists
            ? $user->can('viewFinancials', $record)
            : $user->hasPermission('project.view_financials');

        if (! $mayWriteFinancials) {
            $data = array_diff_key($data, array_flip(self::FINANCIAL_FIELDS));
        }

        return $data;
    }

    /** @return array<int,string> */
    public static function priorities(): array
    {
        return ['low', 'normal', 'high', 'critical'];
    }

    /**
     * Delivery health, as a project manager reports it — deliberately separate from status.
     * A project can be "In progress" and "At risk" at the same time, and collapsing the two
     * loses the warning.
     *
     * @return array<int,string>
     */
    public static function healths(): array
    {
        return ['on_track', 'at_risk', 'off_track', 'on_hold'];
    }

    public function attributes(): array
    {
        return [
            'project_type_id' => 'project type',
            'org_unit_id' => 'owning unit',
            'location_id' => 'location',
            'manager_id' => 'project manager',
            'sponsor_id' => 'sponsor',
            'client_name' => 'client',
            'contract_value' => 'contract value',
            'progress_percent' => 'progress',
        ];
    }

    private function project(): ?Project
    {
        $bound = $this->route('project');

        return $bound instanceof Project ? $bound : null;
    }
}
