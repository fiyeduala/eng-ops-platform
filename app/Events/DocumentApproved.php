<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A revision finished its approval route and became the controlled copy.
 *
 * Carries the VERSION as well as the document, because "Drawing A-101 was approved" is ambiguous in
 * a system that keeps revisions and "Rev C of A-101 was approved" is not. On a controlled document
 * that distinction is the difference between building the right thing and the wrong one.
 */
class DocumentApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentVersion $version,
        public readonly ?int $actorId = null,
    ) {}
}
