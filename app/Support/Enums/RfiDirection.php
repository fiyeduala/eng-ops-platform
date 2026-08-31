<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Who owes the answer.
 *
 * The one field that decides whether an overdue RFI is somebody else's problem or ours. A register
 * that mixes the two — as a spreadsheet always does — produces the report where a company chases a
 * client for forty answers while quietly sitting on thirty of its own.
 */
enum RfiDirection: string
{
    /** We asked. Somebody outside this register owes us an answer. */
    case Outgoing = 'outgoing';

    /** We were asked. The clock is running against us. */
    case Incoming = 'incoming';

    public function label(): string
    {
        return match ($this) {
            self::Outgoing => 'Outgoing — we asked',
            self::Incoming => 'Incoming — we were asked',
        };
    }

    /** Whose answer is late when `required_by` passes. */
    public function answerIsOwedByUs(): bool
    {
        return $this === self::Incoming;
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
