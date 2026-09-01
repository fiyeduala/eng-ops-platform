<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Services\Concerns\ManagesPartyContacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The vendor register: who we may buy from, and on what evidence.
 *
 * FOUR THINGS ARE WRITTEN HERE AND NOWHERE ELSE, each for its own reason.
 *
 * `blacklist()` / `liftBlacklist()` — because blacklisting is a decision about a company rather
 * than an edit to a record. It carries its own permission, demands a reason, stamps who and when,
 * and is reversible only by a second deliberate act. A form that could set `is_blacklisted` through
 * ordinary `fill()` would let anybody with `vendor.edit` quietly un-blacklist a supplier they
 * happen to prefer, and the reason column would go blank without anybody noticing.
 *
 * `updateBankDetails()` — because payment redirection fraud IS this method. The attack is not
 * exotic: somebody who legitimately holds `vendor.edit` changes an account number a week before a
 * payment run. Splitting the write out gives it its own permission (`vendor.bank`), its own
 * activity entry naming the fields that moved, and — through the audit engine's automatic
 * before/after capture — a permanent record of the old number. The activity entry names the FIELDS
 * and never the values, because a timeline is read by everyone who can see the vendor (§59, §68).
 *
 * `recordRating()` — because a supplier score somebody can type is a supplier score that says
 * whatever its author needs it to say. It is a running mean fed by goods receipts and quotation
 * evaluations in part B, and `rating` / `rated_count` are on the protected list so no form reaches
 * them.
 *
 * WHAT IS NOT HERE: any notion of "approved". That is the status graph, configured per tenant, and
 * a company that prequalifies in two stages configures two statuses rather than asking for a
 * schema change (§32). `Vendor::canBeUsed()` is where the conjunction is read.
 */
class VendorService extends RegisterService
{
    use ManagesPartyContacts;

    protected function entityType(): string
    {
        return 'vendor';
    }

    protected function modelClass(): string
    {
        return Vendor::class;
    }

    /**
     * The bank block is on this list, so `create()` and `update()` cannot write it even if a
     * crafted form posts it (§84). New bank details on a new vendor are a second, permissioned
     * call — which is the correct amount of friction for the fields in question.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'rating', 'rated_count',
            'is_blacklisted', 'blacklist_reason', 'blacklisted_at', 'blacklisted_by',
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Vendor $record */
        if ($record->is_active === null) {
            $record->is_active = true;
        }
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        return $actor->name.' added '.$this->label($record).' to the vendor register.';
    }

    // ================================================================== standing

    /**
     * Stop this vendor being used, and say why.
     *
     * The reason is not optional and not a nicety. "Why can we not use them" is asked every single
     * time somebody's preferred supplier fails to appear in a dropdown, and a blacklisting with no
     * reason gets lifted by whoever is asked the question loudest.
     */
    public function blacklist(Vendor $vendor, string $reason, User $actor): Vendor
    {
        if (blank($reason)) {
            throw new \DomainException('Blacklisting a vendor must say why.');
        }

        if ($vendor->is_blacklisted) {
            throw new \DomainException($vendor->name.' is already blacklisted.');
        }

        return DB::transaction(function () use ($vendor, $reason, $actor) {
            $vendor->forceFill([
                'is_blacklisted' => true,
                'blacklist_reason' => trim($reason),
                'blacklisted_at' => now(),
                'blacklisted_by' => $actor->getKey(),
            ]);

            $this->moveTo($vendor, 'blacklisted');
            $vendor->save();

            // The reason IS carried into the activity entry here, unlike the field-names-only rule
            // elsewhere: everybody who can see the vendor needs to know why they cannot use it, and
            // that is the entire purpose of the record.
            $this->activity->record(
                $vendor,
                'blacklisted',
                $actor->name.' blacklisted '.$vendor->name.'.',
                ['reason' => trim($reason)],
                $actor,
            );

            return $vendor;
        });
    }

    /** Put them back on the register. A second deliberate act, with its own reason. */
    public function liftBlacklist(Vendor $vendor, string $reason, User $actor): Vendor
    {
        if (! $vendor->is_blacklisted) {
            throw new \DomainException($vendor->name.' is not blacklisted.');
        }

        if (blank($reason)) {
            throw new \DomainException('Lifting a blacklisting must say why.');
        }

        return DB::transaction(function () use ($vendor, $reason, $actor) {
            $vendor->forceFill([
                'is_blacklisted' => false,
                'blacklist_reason' => null,
                'blacklisted_at' => null,
                'blacklisted_by' => null,
            ]);

            // Back to "under review" rather than straight to approved. Somebody has to look again.
            $this->moveTo($vendor, 'under_review');
            $vendor->save();

            $this->activity->record(
                $vendor,
                'blacklist_lifted',
                $actor->name.' lifted the blacklisting on '.$vendor->name.'.',
                ['reason' => trim($reason)],
                $actor,
            );

            return $vendor;
        });
    }

    /** Record that the prequalification was renewed, and until when. */
    public function recordPrequalification(Vendor $vendor, ?string $from, ?string $until, User $actor): Vendor
    {
        return DB::transaction(function () use ($vendor, $from, $until, $actor) {
            $vendor->forceFill([
                'prequalified_on' => $from,
                'prequalification_expires_on' => $until,
            ]);

            $vendor->save();

            $this->activity->record(
                $vendor,
                'prequalified',
                $actor->name.' recorded the prequalification for '.$vendor->name.'.',
                array_filter(['from' => $from, 'until' => $until]),
                $actor,
            );

            return $vendor;
        });
    }

    // ================================================================== bank details

    /**
     * Change where the money goes. See the class docblock — this is the fraud surface.
     *
     * @param  array<string,mixed>  $details
     * @param  string|null  $reason  why it changed and how it was confirmed, kept in the timeline
     */
    public function updateBankDetails(Vendor $vendor, array $details, User $actor, ?string $reason = null): Vendor
    {
        return DB::transaction(function () use ($vendor, $details, $actor, $reason) {
            $vendor->fill(array_intersect_key($details, array_flip([
                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch', 'bank_swift',
            ])));

            if (! $vendor->isDirty()) {
                return $vendor;
            }

            $changed = array_keys($vendor->getDirty());

            $vendor->save();

            // FIELD NAMES ONLY. The before/after values are captured by the audit engine, which has
            // its own access rules; an activity entry is read by everybody who can see the vendor,
            // and an account number in a timeline is an account number in a screenshot (§59).
            $this->activity->record(
                $vendor,
                'bank_details_updated',
                $actor->name.' changed the bank details held for '.$vendor->name.'.'.($reason === null ? '' : ' Reason given: '.$reason),
                ['fields' => $changed, 'reason' => $reason],
                $actor,
            );

            return $vendor;
        });
    }

    // ================================================================== performance

    /**
     * Fold one score into the running mean.
     *
     * A mean rather than a latest value, because a single bad delivery should move a long-standing
     * supplier's score and not replace it. `rated_count` is kept so the average can be recomputed
     * and so a screen can say "3.8 from 14 assessments" — a score with no denominator is a number
     * people argue about.
     */
    public function recordRating(Vendor $vendor, float $score, User $actor, ?string $note = null): Vendor
    {
        if ($score < 0 || $score > 5) {
            throw new \DomainException('A vendor score must be between 0 and 5.');
        }

        return DB::transaction(function () use ($vendor, $score, $actor, $note) {
            $count = (int) $vendor->rated_count;
            $current = (float) ($vendor->rating ?? 0);

            $vendor->forceFill([
                'rating' => round((($current * $count) + $score) / ($count + 1), 2),
                'rated_count' => $count + 1,
            ]);

            $vendor->save();

            $this->activity->record(
                $vendor,
                'rated',
                $actor->name.' scored '.$vendor->name.' at '.number_format($score, 1).'.',
                array_filter(['score' => $score, 'note' => $note, 'assessments' => $count + 1]),
                $actor,
            );

            return $vendor;
        });
    }

    // ================================================================== contacts

    /** @return class-string<VendorContact> */
    protected function contactClass(): string
    {
        return VendorContact::class;
    }

    protected function contactForeignKey(): string
    {
        return 'vendor_id';
    }

    protected function contactParentRelation(): string
    {
        return 'vendor';
    }
}
