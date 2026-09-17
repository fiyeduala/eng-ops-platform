<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may see, fill in and send a timesheet.
 *
 * THERE IS NO `approve` HERE — deciding a timesheet is acting on its workflow stage, which is
 * WorkflowStageInstancePolicy's question, the same one it answers for a drawing and for leave.
 */
class TimesheetPolicy extends ScopedPolicy
{
    protected string $module = 'timesheet';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Timesheet && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Timesheet && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function submit(User $user, Timesheet $sheet): bool
    {
        return $sheet->statusKey() === 'draft' && $this->check($user, 'submit', $sheet);
    }
}
