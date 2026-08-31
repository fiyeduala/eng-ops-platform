<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\WorkflowCatalogue;
use Illuminate\Validation\Rule;

/**
 * Define an approval route (§5, §33).
 *
 * `entity_type` is restricted to the modules that actually route, from a catalogue rather than from
 * free text. Two reasons, and the second is the one that matters: a typo would produce a definition
 * no record can ever match, which fails silently — and an arbitrary string would let somebody point
 * a workflow at a morph key the engine will happily instantiate.
 *
 * NOT ACCEPTED: `version`, `supersedes_id`, `published_at`, `published_by`. Versioning is the
 * service's, and publishing is a separate permission with its own endpoint. A form that could set
 * `published_at` would make a route live without ever passing WorkflowDefinitionService::validate()
 * — which is to say, without anyone checking that its stages resolve to real people (§84).
 */
class WorkflowRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Immutable after creation: an existing route's stages, conditions and history are all
            // written against one kind of record.
            'entity_type' => [
                $this->route('workflow') === null ? 'required' : 'nullable',
                Rule::in(array_keys(WorkflowCatalogue::routableEntities())),
            ],

            'trigger' => ['nullable', 'string', 'max:32'],

            // "Use this when the record's own category does not name one." Claimed at publish time.
            'is_default' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_default']);
        $this->nullifyBlanks(['description', 'trigger']);
    }

    /** @return array<string,mixed> */
    public function workflowAttributes(): array
    {
        $data = $this->validated();

        if ($this->route('workflow') !== null) {
            unset($data['entity_type']);
        }

        return $data;
    }
}
