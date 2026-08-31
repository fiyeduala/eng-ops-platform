<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * What an approver did when the record reached them (docs/07 §5).
 *
 * An enum rather than a string because these five values drive the routing: the engine asks the
 * decision what to do next, instead of a switch statement in the controller that a sixth caller
 * would eventually get wrong.
 *
 * Note what is NOT here. There is no `skip` — a stage is skipped by its CONDITIONS evaluating false,
 * which happens before anybody is asked, and is recorded on the stage instance rather than as a
 * human decision. And there is no `cancel`: cancelling belongs to the instance, not to one person's
 * turn, so it lives on WorkflowEngine::cancel() where it can be authorized separately.
 */
enum Decision: string
{
    /** This stage is satisfied. Advance to the next stage whose conditions pass. */
    case Approve = 'approve';

    /** Refused. What happens next is the stage's configured `on_reject`, not a fixed rule. */
    case Reject = 'reject';

    /**
     * Not refused, but not acceptable as submitted. The record returns to its owner, who may fix
     * it and resubmit as a NEW ATTEMPT on the same instance (§24) — the prior attempt's comments
     * and timings survive, because "what were we asked to change last time" is the whole value.
     */
    case RequestRevision = 'request_revision';

    /**
     * Counts toward quorum without expressing a view. Exists so that an `all` quorum stage cannot
     * be deadlocked by one assignee who has no opinion, without forcing them to claim one.
     */
    case Abstain = 'abstain';

    /**
     * Handed to somebody else. Not really a decision about the record — it is a decision about who
     * decides — so the engine records it and leaves the stage pending for the delegate.
     */
    case Delegate = 'delegate';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approve',
            self::Reject => 'Reject',
            self::RequestRevision => 'Request revision',
            self::Abstain => 'Abstain',
            self::Delegate => 'Delegate',
        };
    }

    /** Does recording this decision leave the stage waiting for somebody? */
    public function leavesStagePending(): bool
    {
        return $this === self::Delegate;
    }

    /** Does this decision count toward the stage's quorum? */
    public function countsTowardQuorum(): bool
    {
        return match ($this) {
            self::Approve, self::Abstain => true,
            default => false,
        };
    }

    /** Does this decision end the stage the moment one person makes it, whatever the quorum? */
    public function isDecisive(): bool
    {
        return match ($this) {
            self::Reject, self::RequestRevision => true,
            default => false,
        };
    }

    /**
     * The permission a person needs to record it.
     *
     * Delegation is separated from acting because they are different authorities: a reviewer may
     * be trusted to approve while not being trusted to hand the decision to somebody else.
     */
    public function permission(): string
    {
        return $this === self::Delegate ? 'approval.delegate' : 'approval.act';
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Approve => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Reject => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::RequestRevision => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Abstain => 'bg-slate-100 text-slate-600 ring-slate-200',
            self::Delegate => 'bg-sky-50 text-sky-700 ring-sky-200',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
