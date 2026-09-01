<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PartyContactRequest;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Services\VendorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Named people at a supplier. Everything of substance is in PartyContactController.
 */
class VendorContactController extends PartyContactController
{
    public function __construct(private readonly VendorService $vendors) {}

    public function store(PartyContactRequest $request, Vendor $vendor): RedirectResponse
    {
        return $this->persistNew($request, $vendor);
    }

    public function update(PartyContactRequest $request, VendorContact $contact): RedirectResponse
    {
        return $this->persistExisting($request, $contact);
    }

    public function destroy(Request $request, VendorContact $contact): RedirectResponse
    {
        return $this->retire($request, $contact);
    }

    protected function service(): object
    {
        return $this->vendors;
    }

    protected function contactClass(): string
    {
        return VendorContact::class;
    }

    protected function parentRoute(): string
    {
        return 'vendors.show';
    }

    protected function parentRelation(): string
    {
        return 'vendor';
    }
}
