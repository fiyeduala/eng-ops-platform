<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Named people at a counterparty — written once for vendors and clients.
 *
 * The two registers keep contacts for the same reason and with the same rules, and the difference
 * between them is a class name and a foreign key. Copying eighty lines to say that twice is how the
 * second copy ends up missing the primary-contact demotion, or taking `organization_id` from the
 * payload — which is a tenant boundary, not a style point (§75, §84).
 *
 * THREE RULES LIVE HERE:
 *
 *   1. Tenancy, authorship and the parent link are set from the parent record and the actor, never
 *      from the submitted attributes.
 *   2. Exactly one primary contact per party, enforced in a transaction. A partial unique index
 *      would be the database-level answer and MySQL does not have one, so this is the portable
 *      guarantee rather than a preference.
 *   3. Contacts are DEACTIVATED, never deleted. An RFQ or a transmittal issued last year names this
 *      row; removing it would leave live correspondence addressed to nobody.
 *
 * The activity entry is written against the PARENT, because "who did we talk to at this vendor and
 * when did that change" is a question about the vendor. A contact with a timeline of its own would
 * split one story across two screens.
 */
trait ManagesPartyContacts
{
    /** @return class-string<Model> */
    abstract protected function contactClass(): string;

    /** `vendor_id` or `client_id`. */
    abstract protected function contactForeignKey(): string;

    /** The relation on the contact pointing back at its party — for the activity subject. */
    abstract protected function contactParentRelation(): string;

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function addContact(Model $party, array $attributes, User $actor): Model
    {
        return DB::transaction(function () use ($party, $attributes, $actor) {
            $class = $this->contactClass();

            /** @var Model $contact */
            $contact = new $class($this->contactAttributes($attributes));
            $contact->setAttribute('organization_id', $party->getAttribute('organization_id'));
            $contact->setAttribute($this->contactForeignKey(), $party->getKey());
            $contact->setAttribute('created_by', $actor->getKey());
            $contact->save();

            if ($contact->getAttribute('is_primary')) {
                $this->demoteOtherPrimaries($party, $contact);
            }

            $this->activity->record(
                $party,
                'contact_added',
                $actor->name.' added '.$contact->getAttribute('name').' as a contact for '
                    .$party->getAttribute('name').'.',
                ['contact' => $contact->getAttribute('name')],
                $actor,
            );

            return $contact;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function updateContact(Model $contact, array $attributes, User $actor): Model
    {
        return DB::transaction(function () use ($contact, $attributes, $actor) {
            $contact->fill($this->contactAttributes($attributes));

            if (! $contact->isDirty()) {
                return $contact;
            }

            $changed = array_keys($contact->getDirty());
            $contact->save();

            $party = $contact->{$this->contactParentRelation()};

            if ($contact->getAttribute('is_primary')) {
                $this->demoteOtherPrimaries($party, $contact);
            }

            $this->activity->record(
                $party,
                'contact_updated',
                $actor->name.' updated the contact details held for '.$contact->getAttribute('name').'.',
                ['contact' => $contact->getAttribute('name'), 'fields' => $changed],
                $actor,
            );

            return $contact;
        });
    }

    /** Retire a contact. See rule 3 in the trait docblock for why this is not a delete. */
    public function deactivateContact(Model $contact, User $actor): Model
    {
        return DB::transaction(function () use ($contact, $actor) {
            $contact->forceFill(['is_active' => false, 'is_primary' => false]);
            $contact->save();

            $party = $contact->{$this->contactParentRelation()};

            $this->activity->record(
                $party,
                'contact_removed',
                $actor->name.' recorded that '.$contact->getAttribute('name').' is no longer a '
                    .'contact at '.$party->getAttribute('name').'.',
                ['contact' => $contact->getAttribute('name')],
                $actor,
            );

            return $contact;
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function contactAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', $this->contactForeignKey(), 'created_by',
            'created_at', 'updated_at',
        ]));
    }

    private function demoteOtherPrimaries(Model $party, Model $keep): void
    {
        $class = $this->contactClass();

        $class::query()
            ->where($this->contactForeignKey(), $party->getKey())
            ->whereKeyNot($keep->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
