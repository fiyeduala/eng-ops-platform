<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * A line of a material request. Everything it can do, its parent decides.
 *
 * See LineItemPolicy for why this is four lines rather than forty — including the part that
 * matters, which is that a posted `material_request_item_id` is re-checked against the request it
 * claims to belong to before anything is written (§84).
 */
class MaterialRequestItemPolicy extends LineItemPolicy
{
    protected string $module = 'material_request';

    protected function parentRelation(): string
    {
        return 'materialRequest';
    }
}
