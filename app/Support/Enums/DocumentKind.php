<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * What a document version actually IS.
 *
 * This is the single most consequential column in Phase 3, so it is an enum rather than a boolean
 * called `is_native`. A document register in an engineering business holds two genuinely different
 * kinds of thing, and pretending otherwise is how document systems end up bad at both:
 *
 *   NATIVE — authored in this application. The version's content lives in the database as
 *   sanitised HTML plus the editor's own JSON. Because the content is structured and inside the
 *   system, it can be diffed revision to revision, searched without an extraction step, commented
 *   on clause by clause during review, and rendered to PDF or .docx on demand. A procedure, a
 *   method statement, a letter, a report, a minute of meeting.
 *
 *   FILE — produced somewhere else and deposited here. The version is bytes on the private disk
 *   with a sha256 beside them. A drawing, a vendor data pack, a signed scan, a client letter that
 *   arrived as a PDF. The system cannot read inside it and does not pretend to: it versions it,
 *   controls who may download it, and records every time somebody did.
 *
 * A single document is one kind for its whole life. Mixing them per revision would mean Revision B
 * could be diffed against Revision A only sometimes, which is worse than never — a register whose
 * guarantees vary per row is a register nobody trusts.
 *
 * Conversion between the two is an explicit act with its own audit entry, not a silent property of
 * uploading: importing a .docx into a native document REPLACES its content and says so.
 */
enum DocumentKind: string
{
    /** Authored here. Content in the database, diffable, exportable to .docx and PDF. */
    case Native = 'native';

    /** Authored elsewhere. Bytes on the private disk, hashed and access-controlled. */
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Native => 'Written in the app',
            self::File => 'Uploaded file',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Native => 'Draft and revise here, with revision comparison, review comments and '
                .'export to Word or PDF. Best for procedures, reports, letters and specifications.',
            self::File => 'Upload a file produced elsewhere. Versioned, hashed and access '
                .'controlled, but its contents cannot be compared or searched. Best for drawings, '
                .'scans and documents received from others.',
        };
    }

    /** Whether a version of this kind carries editable content rather than a stored file. */
    public function isAuthored(): bool
    {
        return $this === self::Native;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Native => 'pencil-square',
            self::File => 'paper-clip',
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
