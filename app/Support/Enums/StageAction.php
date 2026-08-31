<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * What a stage ASKS its assignees to do (docs/07 §4).
 *
 * The distinction that matters is between stages that can block and stages that cannot. A `review`
 * or an `approve` stage holds the record until somebody acts; `notify_only` tells people and moves
 * on in the same breath. Conflating the two is how approval systems end up with routes that appear
 * to require sign-off but silently do not, which is worse than having no stage at all.
 *
 * `sign` is `approve` plus a Signature row — the same decision, recorded with a designation
 * snapshot and a content hash because somebody may have to defend it years later (§28).
 */
enum StageAction: string
{
    case Review = 'review';
    case Approve = 'approve';
    case Sign = 'sign';
    case Acknowledge = 'acknowledge';
    case Input = 'input';
    case NotifyOnly = 'notify_only';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Review and comment',
            self::Approve => 'Approve or reject',
            self::Sign => 'Approve and sign',
            self::Acknowledge => 'Acknowledge receipt',
            self::Input => 'Provide information',
            self::NotifyOnly => 'Notify only',
        };
    }

    /** Does the workflow wait here? */
    public function blocks(): bool
    {
        return $this !== self::NotifyOnly;
    }

    /** Does acting here write a Signature? */
    public function producesSignature(): bool
    {
        return $this === self::Sign;
    }

    /**
     * May an actor at this stage reject outright?
     *
     * An acknowledgement cannot be rejected — "I have seen it" is not a decision with two outcomes,
     * and offering a Reject button there would produce a terminated workflow nobody intended.
     */
    public function allowsRejection(): bool
    {
        return in_array($this, [self::Review, self::Approve, self::Sign], true);
    }

    public function verb(): string
    {
        return match ($this) {
            self::Review => 'Review',
            self::Approve, self::Sign => 'Approve',
            self::Acknowledge => 'Acknowledge',
            self::Input => 'Respond',
            self::NotifyOnly => 'Note',
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
