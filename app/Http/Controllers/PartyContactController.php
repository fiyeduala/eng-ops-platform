<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PartyContactRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The named people at a vendor or a client, managed from the parent's own page.
 *
 * NO INDEX, NO SHOW, NO STANDALONE FORM. A contact is not a record anybody navigates to; it is a
 * row on the company's page. Giving it its own screens would create a second route to a phone
 * number and an email address whose access rules would then have to be kept in step with the
 * parent's — which is exactly the drift VendorContactPolicy exists to prevent.
 *
 * AUTHORIZATION IS ALWAYS THE PARENT'S. Adding asks `createFor` (which resolves to the parent's
 * `update`); editing and deactivating ask the contact's policy, which delegates upward. There is no
 * `vendor_contact` permission module, because it would be a checkbox no administrator could explain
 * — "may edit vendors but not vendor contacts" is not a policy anybody has.
 *
 * THE PARENT COMES FROM THE BOUND ROUTE, NEVER FROM THE PAYLOAD. `vendor_id` / `client_id` are
 * stripped again by ManagesPartyContacts, so a posted foreign key cannot move a contact onto a
 * company the actor cannot open (§84, §94).
 *
 * DEACTIVATE RATHER THAN DELETE. People leave; the transmittal that named them still has to render
 * two years later. A deactivated contact drops out of every picker and stays readable in history.
 *
 * The return type on `service()` is deliberately loose: VendorService and ClientService share the
 * contact methods through the ManagesPartyContacts trait, and a trait is not a type. An interface
 * would be a third file describing three methods that already have one home.
 */
abstract class PartyContactController extends Controller
{
    /** VendorService or ClientService — both carry ManagesPartyContacts. */
    abstract protected function service(): object;

    /** The contact class, so `createFor` reaches the right policy. */
    abstract protected function contactClass(): string;

    /** `vendors.show` / `clients.show`. */
    abstract protected function parentRoute(): string;

    /** The relation name on the contact, used to reach the parent after a save. */
    abstract protected function parentRelation(): string;

    protected function persistNew(PartyContactRequest $request, Model $party): RedirectResponse
    {
        $this->authorize('createFor', [$this->contactClass(), $party]);

        $contact = $this->service()->addContact($party, $request->contactAttributes(), $this->actor($request));

        return redirect($this->redirectTo($party))
            ->with('status', $contact->name.' has been added to '.$party->name.'.');
    }

    protected function persistExisting(PartyContactRequest $request, Model $contact): RedirectResponse
    {
        $this->authorize('update', $contact);

        $this->service()->updateContact($contact, $request->contactAttributes(), $this->actor($request));

        return redirect($this->redirectTo($this->parentOf($contact)))
            ->with('status', $contact->name.' has been updated.');
    }

    protected function retire(Request $request, Model $contact): RedirectResponse
    {
        $this->authorize('update', $contact);

        $this->service()->deactivateContact($contact, $this->actor($request));

        return redirect($this->redirectTo($this->parentOf($contact)))
            ->with('status', $contact->name.' is no longer a current contact.');
    }

    private function parentOf(Model $contact): Model
    {
        return $contact->{$this->parentRelation()};
    }

    private function redirectTo(Model $party): string
    {
        return route($this->parentRoute(), $party).'#contacts';
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
