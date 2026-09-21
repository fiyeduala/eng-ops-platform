<?php

declare(strict_types=1);

namespace App\Engines\Access;

/**
 * Whether somebody's reach contains a whole scope — and, when it does not, what to tell them.
 *
 * The reason is not decoration. A dashboard tile that has to fall back from a shared figure to a
 * private one must say so on the tile, because the alternative is two people standing at one screen
 * reading different numbers under one heading and neither of them knowing why. "Counted for you"
 * beside a figure is the whole difference between a disagreement people resolve in ten seconds and
 * one that ends with somebody deciding the platform is wrong.
 */
final class ReachVerdict
{
    private function __construct(
        public readonly bool $covers,
        public readonly ?string $reason,
    ) {}

    public static function covers(): self
    {
        return new self(true, null);
    }

    /**
     * @param  string  $reason  addressed to the person, in the second person, and describing the
     *                          limit rather than the records — "some records here are confidential"
     *                          never names how many, because the count is the thing being withheld
     */
    public static function partial(string $reason): self
    {
        return new self(false, $reason);
    }
}
