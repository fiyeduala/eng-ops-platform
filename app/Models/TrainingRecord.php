<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who holds what, and until when.
 *
 * THE ONLY QUESTION THIS REGISTER IS EVER OPENED FOR is "whose tickets lapse in the next sixty
 * days", which is why `expires_on` carries its own index and why the default view is the expiry
 * report rather than the full list.
 *
 * `expires_on` IS COMPUTED AT COMPLETION AND THEN STORED. See TrainingCourse::expiryFrom() — a
 * re-derived expiry would move every historical certificate the day a course's validity changed.
 *
 * A TRAINING RECORD IS ABOUT A PERSON, so it is one of the few registers where `own` scope means
 * something an employee should actually get: everybody can see their own certificates, and only
 * HSE and their line management can see everybody's. `accessOwnerColumns()` is overridden to say
 * so — the default "mine means I created it" would give a training administrator ownership of the
 * entire register and give the certificate holder nothing.
 *
 * THE CERTIFICATE FILE LIVES IN DOCUMENT CONTROL, behind `document_id`, under the private-storage
 * and authorization rules every other file in the application obeys (§67).
 */
class TrainingRecord extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    /** `hse_training.results`. */
    public const DEFAULT_RESULTS = ['Passed', 'Failed', 'Attended', 'Not completed'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'completed_on' => 'date',
            'expires_on' => 'date',
            'score' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * "Mine" is a certificate I HOLD, not one I entered.
     *
     * The deliberate exception to the shared spine. Everywhere else in Phase 4 ownership means
     * authorship, which is right for a record about work; here the record is about a person, and
     * the person it is about is the one with the strongest claim to read it.
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['user_id', 'created_by'];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return ['user_id'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['training_course_id', 'result'];
    }

    /** `hse_training`, not the `training_record` the class name would produce — see Incident. */
    public function workflowEntityType(): string
    {
        return 'hse_training';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Training records';
    }

    /*
     * Deliberately NOT opting out of workflow with requiresWorkflow(): false.
     *
     * Certificates are filed rather than approved, and no route ships for them — but hard-refusing
     * one here would let an administrator configure a training workflow on the builder screen and
     * then watch it never run, which is a worse failure than the empty inbox it was meant to
     * prevent. Nothing routes until a company defines a route, which is the correct default on its
     * own.
     */

    // ------------------------------------------------------------------ state

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isBefore(now()->startOfDay());
    }

    public function expiresWithin(int $days): bool
    {
        return $this->expires_on !== null
            && ! $this->hasExpired()
            && $this->expires_on->isBefore(now()->startOfDay()->addDays($days));
    }

    public function holderLabel(): string
    {
        return (string) ($this->user?->name ?? $this->person_name ?? 'Not recorded');
    }

    /** @param  Builder<static>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->startOfDay())
            ->whereDate('expires_on', '<=', now()->startOfDay()->addDays($days));
    }

    /** @param  Builder<static>  $query */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->startOfDay());
    }

    // ------------------------------------------------------------------ presentation

    /**
     * The certificate holder's name IS the title of this record.
     *
     * Overridden rather than left to `title`, because the table has no title column: a training
     * record identifies itself as "Ada Okonkwo — Confined Space Entry", and a register whose rows
     * all read "TRN-2026-0041" would be unusable.
     */
    public function registerTitleColumn(): string
    {
        return 'person_name';
    }

    public function displayName(): string
    {
        $course = $this->course?->name;

        return trim(($this->number ? $this->number.' — ' : '')
            .$this->holderLabel()
            .($course !== null ? ' · '.$course : ''));
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'person_name', 'certificate_number'];
    }

    public function registerDueColumn(): ?string
    {
        return 'expires_on';
    }
}
