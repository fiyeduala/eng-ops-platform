<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "Tell me when this record changes."
 *
 * READ THIS BEFORE USING IT ANYWHERE: a follower row is a notification subscription and NOTHING
 * else. It is not a grant, not an ACL row, and not an input to any access decision. The access
 * engine never reads this table.
 *
 * The reason is §68. If following granted reach, the button that says "follow" would be a
 * self-service authorization escalation for anybody who could guess a record id. So the rule runs
 * the other way: the notification layer takes the follower list and filters it, per recipient,
 * against what that person can actually see — a follower who has lost reach (their project
 * membership expired, the record became confidential) stops hearing about the record and is not
 * told why.
 *
 * `followable` is a free morph because every module gains followers eventually. Controllers must
 * never accept `followable_type` from a request body: each module follows its own records through
 * its own controller, so there is no route on which an attacker can name an arbitrary class.
 */
class Follower extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    // ------------------------------------------------------------------ relationships

    public function followable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOwnerColumns(): array
    {
        return ['user_id', 'created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['user_id'];
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return ($this->user?->name ?? 'Someone').' follows '.class_basename((string) $this->followable_type);
    }
}
