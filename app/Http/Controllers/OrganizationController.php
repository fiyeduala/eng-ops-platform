<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The company profile (§72).
 *
 * There is no {organization} parameter anywhere in these routes, and that is the whole security
 * design: the record is taken from the bound tenant context, which EnsureOrganizationContext
 * derives from the authenticated user and nothing else. An /organizations/2 to walk simply does
 * not exist, so the §94 IDOR probe has no surface here at all.
 *
 * `slug` and `is_active` are absent from the editable set. The slug identifies the tenant in
 * storage paths and generated document numbers; is_active is a platform-operator decision, and a
 * tenant that could reactivate itself would make suspension meaningless.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function edit(Request $request): View
    {
        $organization = $this->current();

        $this->authorize('view', $organization);

        return view('organization.edit', [
            'organization' => $organization,
            'canEdit' => $request->user()->can('configure', $organization),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->current();

        $this->authorize('configure', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:120'],
            'registration_no' => ['nullable', 'string', 'max:64'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:200'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],

            // No hard-coded country list and no tax rules attached to it (§47) — the country is
            // recorded, never interpreted.
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $organization->fill($validated)->save();

        return redirect()->route('organization.edit')
            ->with('status', 'Company profile updated.');
    }

    /**
     * The tenant, from context. Never from the request.
     */
    private function current(): Organization
    {
        return $this->context->get() ?? abort(403, 'No organization context.');
    }
}
