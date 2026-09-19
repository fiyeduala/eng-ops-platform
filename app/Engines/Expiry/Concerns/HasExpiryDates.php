<?php

declare(strict_types=1);

namespace App\Engines\Expiry\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A register that has dates somebody would want warning about.
 *
 * OPTING IN IS THE POINT. Nearly every table in the platform has a date column, and almost none of
 * them are expiries — `created_at`, a meeting's `held_on`, an incident's `occurred_at`. A generic
 * engine that offered every date column would bury the six that matter in four hundred that do not,
 * and an administrator picking from that list would be guessing. A model therefore names its own
 * watchable dates, in its own words, and a register that names none simply never appears.
 *
 * THE LABELS ARE THE COMPANY'S SCREEN. `valid_to` is a column name; "Permit valid until" is what an
 * administrator recognises when choosing what to be warned about, so the label belongs next to the
 * field rather than being derived from it — deriving it gives "Valid To", which is nobody's words.
 *
 * WHAT A DECLARED FIELD PROMISES. That it is a real date or datetime column on this model's own
 * table, comparable with `<=`, and that a null in it means "no date set" rather than "never
 * expires" — the sweep skips nulls either way. Declaring a field that is not a column would be
 * caught on the first sweep rather than on save, which is why ExpiryCatalogue is the only thing a
 * watch is ever validated against (§84).
 */
trait HasExpiryDates
{
    /**
     * The dates on this register a company may ask to be warned about.
     *
     * A MODEL DECLARES `protected static array $expiryDates`, and this trait deliberately does NOT
     * declare it. Two dead ends led here and both are worth recording, because the obvious fix is
     * the one that does not work:
     *
     *   - `static::$expiryDates ?? []` is a fatal error, not null. Reading an UNDECLARED static
     *     property throws, so the null-coalesce never gets its turn.
     *   - Declaring it in the trait with an empty default is worse: PHP then requires every model's
     *     declaration to be IDENTICAL, default value included, and refuses to compose the class at
     *     all — "define the same property … however the definition differs". Every model that
     *     actually named a date would fail to load.
     *
     * `property_exists` is therefore the guard, and a model that declares nothing is simply a model
     * with no watchable dates, which is most of them.
     *
     * @return array<string,string> column => label, in the company's words
     */
    public static function expiryDateFields(): array
    {
        return property_exists(static::class, 'expiryDates') ? static::$expiryDates : [];
    }

    /**
     * What to call one of these records in a warning.
     *
     * Overridden where `displayName()` is not the recognisable thing — a vehicle document is better
     * described by its type and the plate it belongs to than by its own title.
     */
    public function expiryLabel(): string
    {
        if (method_exists($this, 'displayName')) {
            return (string) $this->displayName();
        }

        return class_basename($this).' #'.$this->getKey();
    }

    /**
     * Whoever the record belongs to, for a watch that was told to tell them.
     *
     * Falls through to the workflow engine's answer where the model has one, so a module that has
     * already decided who owns a record — a purchase request owned by its requester rather than its
     * creator — does not get to disagree with itself depending on which engine is asking.
     */
    public function expiryOwnerId(): ?int
    {
        if (method_exists($this, 'workflowOwnerId')) {
            $owner = $this->workflowOwnerId();

            if ($owner !== null) {
                return (int) $owner;
            }
        }

        foreach (['owner_id', 'custodian_id', 'assigned_to', 'created_by'] as $column) {
            if ($this->getAttribute($column) !== null) {
                return (int) $this->getAttribute($column);
            }
        }

        return null;
    }

    /**
     * People this register says must be told, whatever the watch was configured with.
     *
     * THE HOOK THAT MAKES A GENERIC ENGINE HONEST. A watch can be told to notify the owner, some
     * roles and some named users, and for most registers that is the whole audience. It is not for
     * all of them: the person who will be stopped at a checkpoint holding a lapsed insurance
     * certificate is the DRIVER of that truck, who owns nothing, follows nothing and appears in no
     * column on the certificate. Deriving him needs a hop through the vehicle to the asset, which
     * is knowledge belonging to the fleet module and to nowhere else.
     *
     * So the module contributes the names it alone can work out, and the watch contributes the ones
     * an administrator chose. Neither can silence the other, and NotificationDispatcher still
     * decides which of them may actually see the record (§68) — a hook here is a nomination, never
     * a grant.
     *
     * Nulls are allowed in the returned array and are dropped by the caller, so an implementation
     * can list columns without guarding each one.
     *
     * @return array<int,int|null>
     */
    public function expiryAlsoTell(): array
    {
        return [];
    }

    /**
     * Records whose followers care about this one.
     *
     * Somebody following a vehicle is following its paperwork, and following an asset is following
     * the work done to it; making them follow each certificate and each job separately is a
     * subscription nobody would keep up with. Returning the parent puts the warning where the
     * interest already was.
     *
     * @return array<int,Model|null>
     */
    public function expiryRelatedRecords(): array
    {
        return [];
    }

    /**
     * The department that should hear about it, where that is not this row's own.
     *
     * A maintenance job has no `org_unit_id` on purpose — the workshop does not own the generator,
     * the unit holding it does — so the answer is one hop away and only the module knows the hop.
     */
    public function expiryOrgUnitId(): ?int
    {
        return null;
    }

    /**
     * The job this warning belongs to, for the dispatcher's project scoping.
     *
     * Null is the ordinary answer and means "company-wide", which is correct for a vendor
     * prequalification and wrong for a machine that is currently on somebody's site.
     */
    public function expiryProjectId(): ?int
    {
        return null;
    }

    /**
     * A narrowing the register applies to its own sweep.
     *
     * The escape hatch for a row that has a date but is no longer the row that matters: a vehicle
     * document filed early and already superseded still has next year's expiry on it, and warning
     * about a certificate that has been replaced teaches a fleet officer to ignore the warnings.
     *
     * Overridden as an ordinary scope on the model. A class method beats a trait method in PHP, so
     * the override simply wins — no registration, and a register that needs no narrowing says
     * nothing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiryWatchable(Builder $query): Builder
    {
        return $query;
    }
}
