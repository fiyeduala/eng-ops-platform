<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Drawing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The drawing register (§26).
 *
 * THE INTERESTING RULE IS THE ONE ABOUT THE FILE. `drawing.download` exists in the catalogue and is
 * deliberately NOT the permission that opens the file. A drawing register line points at a document,
 * and the document module owns every decision about that document — private storage, MIME checks,
 * per-download authorization (§67). If this policy granted the file on `drawing.download` alone, the
 * register would be a way round document confidentiality: add a line pointing at a restricted
 * document, and anybody with register access reads it.
 *
 * So `openFile()` asks BOTH — reach over the register line, and the document's own policy — and the
 * document's answer is the one that can refuse. A user who can see the register but not the drawing
 * sees the row, the number and the revision, and cannot open the file. That is the correct outcome
 * and it is what a title block is for: identifying a drawing is not reading it.
 *
 * Deleting a line that has a file attached is refused. The line is the register's record that the
 * drawing exists; removing it while leaving the document orphaned is how a register stops matching
 * the drawing store.
 */
class DrawingPolicy extends ScopedPolicy
{
    protected string $module = 'drawing';

    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Drawing && $record->hasFile()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * Open the controlled file behind a register line.
     *
     * Two gates, and the second is the document's own. Never collapse this into one check.
     */
    public function openFile(User $user, Drawing $drawing): bool
    {
        if (! $this->check($user, 'download', $drawing)) {
            return false;
        }

        $document = $drawing->relationLoaded('document')
            ? $drawing->document
            : $drawing->document()->first();

        return $document !== null && Gate::forUser($user)->allows('view', $document);
    }

    /**
     * Attach or replace the controlled file.
     *
     * The document being attached is authorized separately by the picker, which runs through the
     * compiler; this only decides whether the actor may change the register line.
     */
    public function attachDocument(User $user, Drawing $drawing): bool
    {
        return $this->check($user, 'update', $drawing);
    }

    public function submit(User $user, Drawing $drawing): bool
    {
        return $this->check($user, 'submit', $drawing);
    }

    public function review(User $user, Drawing $drawing): bool
    {
        return $this->check($user, 'review', $drawing);
    }
}
