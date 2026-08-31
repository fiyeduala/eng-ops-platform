<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Revisions borrow their document's authority, and add one rule of their own.
 *
 * A revision has no independent audience: if you may see the document you may see its revision
 * history, and if you may not, the revision does not exist for you either. So every ability here
 * delegates to DocumentPolicy against the parent rather than re-deriving reach — which also means a
 * change to how documents are scoped cannot leave revisions behind on an older answer (§90).
 *
 * THE RULE OF ITS OWN: AN APPROVED REVISION IS IMMUTABLE. Not editable, not deletable, by anyone,
 * at any scope, in any state. That is the entire value of a controlled document — "Rev C as
 * approved on 14 March" has to still be Rev C as approved on 14 March. Corrections go into Rev D,
 * where the change is visible, attributable and dated. This is the reason the schema has no content
 * column on `documents` at all: the immutable thing and the mutable thing are different rows.
 */
class DocumentVersionPolicy extends ScopedPolicy
{
    protected string $module = 'document';

    public function viewAny(User $user): bool
    {
        return $this->check($user, 'viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof DocumentVersion
            ? $this->throughDocument($user, 'view', $record)
            : parent::view($user, $record);
    }

    /**
     * Editing a revision's content.
     *
     * `createVersion` on the document decides whether a NEW revision may be started; this decides
     * whether THIS one may still be typed into. Both have to pass for the editor to open.
     */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof DocumentVersion) {
            return parent::update($user, $record);
        }

        if ($record->isApproved()) {
            return false;
        }

        return $this->throughDocument($user, 'update', $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if (! $record instanceof DocumentVersion) {
            return parent::delete($user, $record);
        }

        // The current revision is what the number means today. Deleting it would leave the register
        // pointing at nothing, so it goes when the document goes and not before.
        if ($record->isApproved() || $record->is_current) {
            return false;
        }

        return $this->throughDocument($user, 'delete', $record);
    }

    /** §67 again, one level down: the file lives on the revision, so the check lands here. */
    public function download(User $user, DocumentVersion $version): bool
    {
        return $this->throughDocument($user, 'download', $version);
    }

    public function restore(User $user, Model $record): bool
    {
        return $record instanceof DocumentVersion
            ? $this->throughDocument($user, 'restore', $record)
            : parent::restore($user, $record);
    }

    /**
     * Ask DocumentPolicy about the parent.
     *
     * A revision whose document has vanished — a partially deleted row, a bad join — authorizes
     * nothing. Failing closed on a missing parent is the only safe reading of "I cannot tell".
     */
    private function throughDocument(User $user, string $ability, DocumentVersion $version): bool
    {
        $document = $version->relationLoaded('document') ? $version->document : $version->document()->first();

        if ($document === null) {
            return false;
        }

        return Gate::forUser($user)->allows($ability, $document);
    }
}
