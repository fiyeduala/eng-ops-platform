<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PaymentPolicy extends ScopedPolicy
{
    protected string $module = 'payment';

    public function update(User $user, Model $record): bool
    {
        return $record instanceof Payment && $record->statusKey() === 'draft' && parent::update($user, $record);
    }

    /** Only a draft goes; anything further along is cancelled, so its history stays. */
    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Payment && $record->statusKey() === 'draft' && parent::delete($user, $record);
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $this->check($user, 'approve', $payment);
    }

    public function pay(User $user, Payment $payment): bool
    {
        return $this->check($user, 'pay', $payment);
    }

    public function manage(User $user, Payment $payment): bool
    {
        return $this->check($user, 'manage', $payment);
    }
}
