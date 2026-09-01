<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PartyContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Named people at a client. Everything of substance is in PartyContactController.
 */
class ClientContactController extends PartyContactController
{
    public function __construct(private readonly ClientService $clients) {}

    public function store(PartyContactRequest $request, Client $client): RedirectResponse
    {
        return $this->persistNew($request, $client);
    }

    public function update(PartyContactRequest $request, ClientContact $contact): RedirectResponse
    {
        return $this->persistExisting($request, $contact);
    }

    public function destroy(Request $request, ClientContact $contact): RedirectResponse
    {
        return $this->retire($request, $contact);
    }

    protected function service(): object
    {
        return $this->clients;
    }

    protected function contactClass(): string
    {
        return ClientContact::class;
    }

    protected function parentRoute(): string
    {
        return 'clients.show';
    }

    protected function parentRelation(): string
    {
        return 'client';
    }
}
