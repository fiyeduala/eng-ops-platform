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
 * One person who was there.
 *
 * `user_id` NULLABLE, `person_name` NOT — the same shape as IncidentPerson and for the same reason:
 * most people on a site are not users of this system, and a schema that could only record employees
 * would produce an attendance list missing the half of the workforce the talk was actually for.
 *
 * `signed_at` IS THE WHOLE POINT OF THE ROW. An attendance list with no confirmation is a list
 * somebody typed; a signed one is evidence. It stays nullable because the register is frequently
 * filled in afterwards from a paper sheet, and a record that refused to exist without a signature
 * would be a record nobody keeps.
 */
class ToolboxTalkAttendee extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function toolboxTalk(): BelongsTo
    {
        return $this->belongsTo(ToolboxTalk::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function displayName(): string
    {
        return $this->relationLoaded('user') && $this->user !== null
            ? (string) $this->user->name
            : (string) $this->person_name;
    }
}
