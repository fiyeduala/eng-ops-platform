<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\Client;
use App\Models\OrgUnit;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * Turn a posted `party_type` + `party_id` into a real record of THIS tenant.
 *
 * WHY THIS IS ONE PIECE OF CODE. Four registers carry a counterparty morph — contracts, RFIs,
 * submittals and transmittals — and each of them is a place where a submitted integer decides which
 * row a foreign key points at. Written four times, this is four chances to make the same two
 * mistakes: resolving the class through `Relation::getMorphedModel()`, which happily returns
 * `User::class` for `party_type=user` and hands a form the ability to point a contract at a person;
 * and trusting the id, which is exactly the IDOR §84 and §94 are about.
 *
 * THE WHITELIST IS THE POINT. `partyClasses()` names the classes a given register may be addressed
 * to and nothing else resolves — not because the morph map is wrong, but because a register's
 * counterparty is a business question with a short answer. A contract is held with a client or a
 * vendor. A transmittal may additionally go to another unit of the same company, because a drawing
 * issued from engineering to construction is a transmittal in every company that issues them.
 *
 * THE ID IS NEVER TRUSTED. The lookup is an ordinary Eloquent query, so OrganizationScope applies
 * and another tenant's id simply does not resolve — fail-closed by construction rather than a check
 * somebody has to remember to write (§75). The caller gets a \DomainException, which controllers
 * turn into a field error rather than a 500.
 *
 * THE TYPED NAME SURVIVES THE LINK. Every one of these registers keeps a free-text name beside the
 * morph, and linking a record does not overwrite a name somebody already typed. A transmittal
 * addressed to "Kestrel Energy Ltd" in 2024 must still say so after the company rebrands, because
 * the register is a record of what was sent to whom on the day — not a live view of a directory.
 */
trait ResolvesParties
{
    /**
     * Which classes this register may be addressed to, keyed by morph key.
     *
     * Override to widen it. Deliberately not a constant: the transmittal register accepts an
     * internal unit and the contract register must not.
     *
     * @return array<string,class-string<Model>>
     */
    protected function partyClasses(): array
    {
        return ['client' => Client::class, 'vendor' => Vendor::class];
    }

    /** What to say when the type is not one of the above. Overridden where a register can say more. */
    protected function partyTypeMessage(): string
    {
        return 'That is not something this record can be addressed to.';
    }

    /**
     * Resolve the morph, or clear it when the form left the picker empty.
     *
     * Returns the record so callers can use its name; null means "no party", which is a legitimate
     * state on all four registers — an internal RFI is addressed to nobody outside the company.
     *
     * @param  array<string,mixed>  $attributes
     * @param  string  $typeKey  the attribute holding the morph key
     * @param  string  $idKey  the attribute holding the id
     * @param  string  $morph  the morph name on the model: `party`, `to_party`
     */
    protected function resolveParty(
        Model $record,
        array $attributes,
        string $typeKey = 'party_type',
        string $idKey = 'party_id',
        string $morph = 'party',
    ): ?Model {
        // Absent is not the same as blank. A create that never sent the field must not clear a link
        // an earlier edit established, while a form that sent an empty picker is asking for exactly
        // that — so only a key that ARRIVED is allowed to null the morph.
        if (! array_key_exists($typeKey, $attributes) && ! array_key_exists($idKey, $attributes)) {
            return null;
        }

        $type = $attributes[$typeKey] ?? null;
        $id = $attributes[$idKey] ?? null;

        if (blank($type) || blank($id)) {
            $record->setAttribute($morph.'_type', null);
            $record->setAttribute($morph.'_id', null);

            return null;
        }

        $class = $this->partyClasses()[$type] ?? null;

        if ($class === null) {
            throw new \DomainException($this->partyTypeMessage());
        }

        /** @var Model|null $party */
        $party = $class::query()->find($id);

        if ($party === null) {
            // Deliberately the same message whether the row belongs to another tenant or does not
            // exist at all. Distinguishing them would turn this field into a probe for which ids
            // exist in somebody else's organization (§75).
            throw new \DomainException('That could not be found on the register.');
        }

        $record->{$morph}()->associate($party);

        return $party;
    }

    /**
     * Fill the free-text name from the linked record, but only when nobody typed one.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function nameParty(Model $record, ?Model $party, string $nameColumn = 'party_name', array $attributes = []): void
    {
        if ($party === null) {
            return;
        }

        // A name the user typed on THIS request wins, and so does one already on the record. The
        // link is a convenience; the name is the evidence.
        if (filled($attributes[$nameColumn] ?? null) || filled($record->getAttribute($nameColumn))) {
            return;
        }

        $record->setAttribute($nameColumn, (string) $party->getAttribute('name'));
    }

    /**
     * The registers that may also address an internal unit.
     *
     * @return array<string,class-string<Model>>
     */
    protected function partyClassesWithUnits(): array
    {
        return [...$this->partyClasses(), 'org_unit' => OrgUnit::class];
    }
}
