<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The counters behind the numbering schemes (§64).
 *
 * Every write here is denied to everyone, including the system administrator, and there is no
 * permission that lifts it. A counter is not configuration — it is the record of which numbers
 * have already been handed out. Winding one back does not "fix" anything; it issues a reference
 * that a contract, an email thread and a printed drawing already use for something else.
 *
 * Only NumberGenerator moves these values, inside a locked transaction. A company that wants
 * different numbers changes the SCHEME, which affects what comes next and leaves what has already
 * been issued alone.
 *
 * Reading is allowed under `numbering.view` so the numbering screen can show the current position
 * of each counter, which is the legitimate question an administrator has about them.
 */
class NumberSequencePolicy extends ScopedPolicy
{
    protected string $module = 'numbering';

    public function create(User $user): bool
    {
        return $this->deny();
    }

    public function update(User $user, Model $record): bool
    {
        return $this->deny();
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->deny();
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->deny();
    }
}
