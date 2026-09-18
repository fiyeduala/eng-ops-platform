<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Model;

/**
 * Vacancies. As with leave, timesheets and overtime, approving one is acting on its workflow stage —
 * there is no `approve` here, and no `vacancies.approve` route for anybody to find.
 */
class VacancyPolicy extends ScopedPolicy
{
    protected string $module = 'recruitment';

    /** Only a draft is editable: an approved head cannot have its openings quietly raised. */
    public function update(User $user, Model $record): bool
    {
        return $record instanceof Vacancy && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Vacancy && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function submit(User $user, Vacancy $vacancy): bool
    {
        return $vacancy->statusKey() === 'draft' && $this->check($user, 'submit', $vacancy);
    }

    /**
     * Withdrawing a head. The person who asked for it may withdraw their own; `recruitment.manage`
     * may withdraw any they can reach.
     */
    public function close(User $user, Vacancy $vacancy): bool
    {
        if (! in_array($vacancy->statusKey(), ['draft', 'submitted', 'open'], true)) {
            return false;
        }

        return $this->manage($user, $vacancy)
            || $this->isRequester($user, $vacancy) && $this->check($user, 'submit', $vacancy);
    }

    public function manage(User $user, Vacancy $vacancy): bool
    {
        return $this->check($user, 'manage', $vacancy);
    }

    private function isRequester(User $user, Vacancy $vacancy): bool
    {
        return $vacancy->requester_id !== null && (int) $vacancy->requester_id === (int) $user->getKey();
    }
}
