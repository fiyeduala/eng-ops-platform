<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Overtime requests. As with leave and timesheets, deciding one is acting on its workflow stage.
 */
class OvertimeRequestPolicy extends ScopedPolicy
{
    protected string $module = 'overtime';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof OvertimeRequest && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof OvertimeRequest && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function submit(User $user, OvertimeRequest $request): bool
    {
        return $request->statusKey() === 'draft' && $this->check($user, 'submit', $request);
    }

    /** The person who asked withdraws their own; `overtime.manage` withdraws anybody's they can reach. */
    public function cancel(User $user, OvertimeRequest $request): bool
    {
        if (! in_array($request->statusKey(), ['submitted', 'approved'], true) || $request->worked_hours !== null) {
            return false;
        }

        return $this->manage($user, $request) || $this->isRequester($user, $request) && $this->check($user, 'submit', $request);
    }

    /** Recording the hours worked: the person themselves, or somebody who manages overtime. */
    public function recordWorked(User $user, OvertimeRequest $request): bool
    {
        if ($request->statusKey() !== 'approved') {
            return false;
        }

        return $this->manage($user, $request) || $this->isRequester($user, $request) && $this->check($user, 'submit', $request);
    }

    public function manage(User $user, OvertimeRequest $request): bool
    {
        return $this->check($user, 'manage', $request);
    }

    private function isRequester(User $user, OvertimeRequest $request): bool
    {
        return $request->requester_id !== null && (int) $request->requester_id === (int) $user->getKey();
    }
}
