<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Expense;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExpenseDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Expense $expense,
        public readonly ?int $actorId = null,
    ) {}
}
