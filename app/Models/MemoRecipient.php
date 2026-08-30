<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One name on one memo's distribution list.
 *
 * This row is a VISIBILITY GRANT, which is the whole reason it is a model with a policy rather
 * than a column on `memos` holding a comma-separated list. Writing it hands the named account
 * sight of the memo through `memo.view:assigned` — the same double duty ProjectMember does for
 * projects, and the same reason MemoPolicy::address() asks two questions rather than one.
 *
 * Contrast with Follower, which grants nothing: a person follows a record themselves, whereas
 * somebody else puts a person on a distribution list. Self-service and grant are different acts.
 */
class MemoRecipient extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
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

    /** The row hangs off the memo, not off a unit or a project. Reach comes from the memo. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ helpers

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function displayName(): string
    {
        return ($this->user?->name ?? 'Recipient').' ('.$this->kind.')';
    }
}
