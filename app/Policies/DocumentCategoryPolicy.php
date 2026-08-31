<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers: the drawings register, the procedures register, the vendor data register (§26).
 *
 * A category is the document module's CONFIGURATION rather than work within it. It decides the
 * numbering scheme, the revision scheme, which file types may be uploaded, and — the important one
 * — which approval route a new document enters. So there is one write permission, `manage`, and no
 * separate create/edit/delete: an organization that lets somebody rename a register has already
 * decided they may create one.
 *
 * DELETING A REGISTER THAT HOLDS DOCUMENTS IS REFUSED. The documents would be left with a dangling
 * register, and every rule they inherited from it — numbering, retention, route — would silently
 * stop applying. Deactivating is the answer: it takes the register off the "new document" menu and
 * leaves everything already in it exactly as it was.
 */
class DocumentCategoryPolicy extends ScopedPolicy
{
    protected string $module = 'document_category';

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

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof DocumentCategory) {
            if ($record->documents()->exists() || $record->children()->exists()) {
                return false;
            }
        }

        return parent::delete($user, $record);
    }
}
