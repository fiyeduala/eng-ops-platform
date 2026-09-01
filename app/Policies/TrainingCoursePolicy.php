<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The course catalogue.
 *
 * Governed by `hse_training`, and every write folds onto `manage`. `validity_months` is why: it
 * decides when every certificate of this kind expires, and although TrainingCourse::expiryFrom() is
 * applied once at creation so existing records never move, changing it silently redefines the
 * competence horizon for everybody trained from that day on. That is administration of the training
 * programme, not data entry.
 */
class TrainingCoursePolicy extends ScopedPolicy
{
    protected string $module = 'hse_training';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];

    /** A course somebody holds a certificate in cannot be removed; the certificate would name nothing. */
    public function delete(User $user, Model $record): bool
    {
        if (! parent::delete($user, $record)) {
            return false;
        }

        return ! $record->records()->exists();
    }
}
