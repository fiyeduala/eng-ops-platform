<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * Insurance, licences and permits — the paperwork that makes a vehicle legal to move.
 *
 * `renew` IS SEPARATE FROM `edit`, AND THE DISTINCTION IS THE WHOLE MODULE. Renewing is not
 * changing a date: it is filing the NEXT certificate. The old one stays, with its own number, its
 * own issue date and its own scanned file, because "was this vehicle insured on the 14th of March"
 * is a question somebody asks after a collision, and a register that overwrites the expiry date
 * each year cannot answer it. `edit` fixes a typo in a policy number; `renew` adds a row.
 *
 * THERE IS NO STATUS AND NO SCHEDULED JOB THAT MARKS ANYTHING EXPIRED. Expiry is a date query —
 * `expiringWithin()`, `expired()`, a register tab, and a per-organization warning-days setting —
 * exactly as contracts, vendor documents, training records and risk assessments already do it. The
 * reason is worth stating where somebody will read it: a status column is only as true as the last
 * cron run, and a missed cron would leave the system reporting a lapsed insurance certificate as
 * valid. A date comparison cannot be stale.
 *
 * DOWNLOADS ARE GUARDED BY `view` ON THIS CLASS AND NOTHING ELSE (§67). The scan of a certificate
 * carries the plate, the owner's name and often an address, so the file lives in private storage and
 * every download re-runs the five gates against the record it claims to belong to — the URL is not
 * evidence of anything (§84).
 */
class VehicleDocumentPolicy extends ScopedPolicy
{
    protected string $module = 'vehicle_document';

    public function update(User $user, Model $record): bool
    {
        return $record->superseded_on === null && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record->superseded_on === null && $record->renews_id === null && parent::delete($user, $record);
    }

    /** File the next certificate. Adds a row; never overwrites the one it replaces. */
    public function renew(User $user, ?VehicleDocument $document = null): bool
    {
        return ($document === null || $document->superseded_on === null) && $this->check($user, 'renew', $document);
    }

    public function manage(User $user, ?VehicleDocument $document = null): bool
    {
        return $this->check($user, 'manage', $document);
    }
}
