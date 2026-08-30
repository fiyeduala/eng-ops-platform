<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Support\NotificationCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's channel choices for one kind of notification (docs/10 §4).
 *
 * Absence is meaningful: no row means "the catalogue default", so somebody who has never opened the
 * preferences screen has no rows at all and picks up new defaults as new notification kinds are
 * added. Materialising a row per person per key at account creation would freeze every future
 * default at whatever it was on the day they signed up.
 *
 * A mandatory key can have its channel mix changed but cannot be silenced altogether — the
 * dispatcher forces `in_app` back on for those, because "you may choose how you are told" and "you
 * may choose not to be told you have been given work" are different offers and only the first one
 * is being made.
 */
class NotificationPreference extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
            'push' => 'boolean',
            'digest' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The effective channel mix for a key, given a stored row or none at all.
     *
     * @return array{in_app:bool,email:bool,push:bool,digest:bool}
     */
    public static function resolve(?self $preference, string $key): array
    {
        $defaults = NotificationCatalogue::defaultChannels($key);

        $mix = [
            'in_app' => $preference?->in_app ?? $defaults['in_app'],
            'email' => $preference?->email ?? $defaults['email'],
            'push' => $preference?->push ?? $defaults['push'],
            'digest' => $preference?->digest ?? false,
        ];

        if (NotificationCatalogue::isMandatory($key)) {
            $mix['in_app'] = true;
        }

        return $mix;
    }
}
