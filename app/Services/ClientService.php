<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Concerns\ManagesPartyContacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The client register.
 *
 * SHORTER THAN THE VENDOR SERVICE, AND THAT IS THE POINT. A vendor carries three things the
 * application has to police — a prequalification that expires, a blacklisting that must not be
 * lifted quietly, and bank details somebody will eventually try to redirect. A client carries none
 * of them: we are not paying them, we are invoicing them, and nothing here can lose money by being
 * edited. Giving clients the same ceremony "for symmetry" would add three permissions nobody can
 * explain to an administrator.
 *
 * `relationship_owner_id` IS THE ONE COLUMN WITH A METHOD OF ITS OWN. It is what `assigned` scope
 * reads, so changing it changes who can see the record — which makes it an act rather than an edit,
 * and an act deserves its own entry in the timeline saying who handed the account to whom.
 *
 * @see Vendor for the asymmetry, spelled out on the other side.
 */
class ClientService extends RegisterService
{
    use ManagesPartyContacts;

    protected function entityType(): string
    {
        return 'client';
    }

    protected function modelClass(): string
    {
        return Client::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'relationship_owner_id'];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Client $record */
        if ($record->is_active === null) {
            $record->is_active = true;
        }

        // Whoever opened the account holds it until somebody hands it on. A null owner would mean
        // the register's `assigned` scope grants nothing to anybody, which reads as a permissions
        // fault rather than as the deliberate blank it is.
        $record->relationship_owner_id = $attributes['relationship_owner_id'] ?? $actor->getKey();
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        return $actor->name.' added '.$this->label($record).' to the client register.';
    }

    /**
     * Hand the account to somebody else.
     *
     * Its own method because it changes REACH: `relationship_owner_id` is what the `assigned`
     * access scope reads, so this quietly grants and revokes visibility. That belongs in the
     * timeline as an act with two names in it, not as "updated: fields relationship_owner_id".
     */
    public function assignOwner(Client $client, ?User $owner, User $actor): Client
    {
        return DB::transaction(function () use ($client, $owner, $actor) {
            $previous = $client->relationshipOwner;

            $client->forceFill(['relationship_owner_id' => $owner?->getKey()]);

            if (! $client->isDirty()) {
                return $client;
            }

            $client->save();

            $this->activity->record(
                $client,
                'owner_assigned',
                $owner === null
                    ? $actor->name.' removed the relationship owner from '.$client->name.'.'
                    : $actor->name.' made '.$owner->name.' the relationship owner for '.$client->name.'.',
                array_filter(['from' => $previous?->name, 'to' => $owner?->name]),
                $actor,
            );

            return $client;
        });
    }

    // ================================================================== contacts

    /** @return class-string<ClientContact> */
    protected function contactClass(): string
    {
        return ClientContact::class;
    }

    protected function contactForeignKey(): string
    {
        return 'client_id';
    }

    protected function contactParentRelation(): string
    {
        return 'client';
    }
}
