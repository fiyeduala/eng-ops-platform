<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Starting status vocabularies for a new tenant (§63, §72).
 *
 * These are OPINIONS, not structure. A company may rename every label, delete a status it does
 * not use, add three of its own and rewire the transitions — exactly as it may with the role
 * templates. Nothing in the application branches on the keys below; behaviour hangs off
 * `is_initial` and `is_terminal`, which are structural.
 *
 * The transitions are deliberately conservative. It is easy for an administrator to add a move
 * they find missing and impossible for them to notice a move that should never have existed —
 * so where there was a choice, the tighter graph won.
 */
final class StatusCatalogue
{
    /**
     * module => ['statuses' => [key => [label, color, flags...]], 'transitions' => [...]]
     *
     * A transition is [from-key|null, to-key, label, permission|null, requires_comment].
     * A null `from` means "from any status" — the shape a Cancel needs.
     *
     * @return array<string,array{statuses:array<string,array{label:string,color:string,initial?:bool,terminal?:bool}>,transitions:array<int,array{0:?string,1:string,2:string,3:?string,4?:bool}>}>
     */
    public static function all(): array
    {
        return [
            'project' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'planning' => ['label' => 'Planning', 'color' => 'brand'],
                    'active' => ['label' => 'In progress', 'color' => 'brand'],
                    'on_hold' => ['label' => 'On hold', 'color' => 'warning'],
                    'completed' => ['label' => 'Completed', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'planning', 'Move to planning', null],
                    ['planning', 'draft', 'Return to draft', null],
                    ['planning', 'active', 'Start work', 'project.manage'],
                    ['active', 'on_hold', 'Put on hold', 'project.manage', true],
                    ['on_hold', 'active', 'Resume', 'project.manage'],
                    ['active', 'completed', 'Complete', 'project.manage'],
                    // Reopening a completed project rewrites what every report said last month,
                    // so it is deliberately the narrowest permission and demands a reason.
                    ['completed', 'active', 'Reopen', 'project.manage', true],
                    [null, 'cancelled', 'Cancel', 'project.manage', true],
                ],
            ],

            'task' => [
                'statuses' => [
                    'todo' => ['label' => 'To do', 'color' => 'neutral', 'initial' => true],
                    'in_progress' => ['label' => 'In progress', 'color' => 'brand'],
                    'blocked' => ['label' => 'Blocked', 'color' => 'warning'],
                    'review' => ['label' => 'In review', 'color' => 'brand'],
                    'done' => ['label' => 'Done', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['todo', 'in_progress', 'Start', null],
                    ['in_progress', 'blocked', 'Flag as blocked', null, true],
                    ['blocked', 'in_progress', 'Unblock', null],
                    ['in_progress', 'review', 'Send for review', null],
                    ['review', 'in_progress', 'Send back', null, true],
                    ['review', 'done', 'Verify and close', 'task.verify'],
                    ['in_progress', 'done', 'Mark done', 'task.complete'],
                    ['done', 'in_progress', 'Reopen', 'task.manage', true],
                    [null, 'cancelled', 'Cancel', 'task.manage', true],
                ],
            ],

            'meeting' => [
                'statuses' => [
                    'scheduled' => ['label' => 'Scheduled', 'color' => 'brand', 'initial' => true],
                    'held' => ['label' => 'Held', 'color' => 'neutral'],
                    'minuted' => ['label' => 'Minutes circulated', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['scheduled', 'held', 'Mark as held', null],
                    ['held', 'minuted', 'Circulate minutes', 'meeting.circulate'],
                    ['held', 'scheduled', 'Reschedule', 'meeting.edit'],
                    [null, 'cancelled', 'Cancel', 'meeting.edit', true],
                ],
            ],

            'memo' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'issued' => ['label' => 'Issued', 'color' => 'success'],
                    'withdrawn' => ['label' => 'Withdrawn', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'issued', 'Issue', 'memo.issue'],
                    // Not a delete. An issued memo has been read; withdrawing it says so, where
                    // removing it would leave the people who acted on it with no record of why.
                    ['issued', 'withdrawn', 'Withdraw', 'memo.issue', true],
                ],
            ],

            'announcement' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'published' => ['label' => 'Published', 'color' => 'success'],
                    'archived' => ['label' => 'Archived', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'published', 'Publish', 'announcement.publish'],
                    ['published', 'archived', 'Archive', 'announcement.publish'],
                    ['published', 'draft', 'Unpublish', 'announcement.publish', true],
                ],
            ],

            /*
             * A controlled document's life (§26).
             *
             * `superseded` is the status that makes this a document control system rather than a
             * file store. When revision C is approved, revision B does not vanish and does not stay
             * current — it becomes the thing you can still read, still audit and still prove was
             * what the client held in March. The move is made by DocumentVersionService inside the
             * same transaction that promotes the new revision, so the two can never disagree.
             *
             * `in_review` is deliberately NOT where the workflow lives. The workflow engine drives
             * the record through whatever stages the company configured; this status just says the
             * document is out for approval. A company with a four-stage review and a company with
             * one both use this vocabulary — the difference is configuration in `workflows`, not a
             * different set of words here.
             */
            'document' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'in_review' => ['label' => 'In review', 'color' => 'brand'],
                    'approved' => ['label' => 'Approved', 'color' => 'success'],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger'],
                    'superseded' => ['label' => 'Superseded', 'color' => 'neutral'],
                    'archived' => ['label' => 'Archived', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'in_review', 'Submit for review', 'document.submit'],
                    ['in_review', 'approved', 'Approve', 'document.approve'],
                    ['in_review', 'rejected', 'Reject', 'document.reject', true],
                    ['in_review', 'draft', 'Return for revision', 'document.review', true],
                    ['rejected', 'draft', 'Revise and resubmit', 'document.edit'],
                    // Not offered in the UI as a button a person presses in the ordinary course:
                    // DocumentVersionService makes this move when a newer revision is approved.
                    // It stays a configured transition so the move is auditable and so an
                    // administrator can supersede by hand when a document is retired outright.
                    ['approved', 'superseded', 'Supersede', 'document.manage'],
                    [null, 'archived', 'Archive', 'document.archive', true],
                    ['archived', 'draft', 'Restore', 'document.restore', true],
                ],
            ],

            /*
             * A transmittal is a covering note for documents leaving the building, so its states
             * are about the OUTSIDE party, not about internal progress. `acknowledged` is terminal
             * because the question a transmittal exists to answer — "did they confirm receipt" —
             * has been answered; anything further is a new transmittal.
             */
            'transmittal' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'issued' => ['label' => 'Issued', 'color' => 'brand'],
                    'acknowledged' => ['label' => 'Acknowledged', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'issued', 'Issue', 'transmittal.issue'],
                    ['issued', 'acknowledged', 'Record acknowledgement', 'transmittal.acknowledge'],
                    [null, 'cancelled', 'Cancel', 'transmittal.manage', true],
                ],
            ],

            /*
             * A deliverable's life is the life of a PROMISE, not of a file (§26).
             *
             * `not_started` is the initial state and is the one most registers get wrong by not
             * having: a deliverable exists from the day the schedule is signed, and a register
             * whose first status is "in progress" cannot distinguish work nobody has begun from
             * work nobody has reported. `issued` is not terminal — a deliverable is re-issued at
             * the next revision, which is the ordinary case, not an exception.
             */
            'deliverable' => [
                'statuses' => [
                    'not_started' => ['label' => 'Not started', 'color' => 'neutral', 'initial' => true],
                    'in_progress' => ['label' => 'In progress', 'color' => 'brand'],
                    'in_review' => ['label' => 'In review', 'color' => 'brand'],
                    'issued' => ['label' => 'Issued', 'color' => 'success'],
                    'on_hold' => ['label' => 'On hold', 'color' => 'warning'],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['not_started', 'in_progress', 'Start', null],
                    ['in_progress', 'in_review', 'Submit for review', 'deliverable.submit'],
                    ['in_review', 'in_progress', 'Return for revision', 'deliverable.review', true],
                    ['in_review', 'issued', 'Issue', 'deliverable.approve'],
                    // Re-issue at the next revision. Deliberately available, because a register
                    // that cannot reopen an issued deliverable forces a duplicate row for Rev B.
                    ['issued', 'in_progress', 'Revise', 'deliverable.edit', true],
                    ['in_progress', 'on_hold', 'Put on hold', 'deliverable.edit', true],
                    ['on_hold', 'in_progress', 'Resume', 'deliverable.edit'],
                    [null, 'cancelled', 'Cancel', 'deliverable.delete', true],
                ],
            ],

            /*
             * The drawing register's vocabulary is the ISSUE PURPOSE, because that is what an
             * engineer means when they ask what state a drawing is in. IFR/IFA/IFC/AB are the
             * near-universal set; a company that uses different codes renames them, and nothing in
             * the application branches on the keys.
             *
             * `as_built` is terminal: a drawing marked as-built records what was constructed, and
             * changing it afterwards is a new revision, not an edit of the record of what was.
             */
            'drawing' => [
                'statuses' => [
                    'in_preparation' => ['label' => 'In preparation', 'color' => 'neutral', 'initial' => true],
                    'ifr' => ['label' => 'Issued for review', 'color' => 'brand'],
                    'ifa' => ['label' => 'Issued for approval', 'color' => 'brand'],
                    'ifc' => ['label' => 'Issued for construction', 'color' => 'success'],
                    'as_built' => ['label' => 'As-built', 'color' => 'success', 'terminal' => true],
                    'superseded' => ['label' => 'Superseded', 'color' => 'neutral'],
                    'void' => ['label' => 'Void', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['in_preparation', 'ifr', 'Issue for review', 'drawing.submit'],
                    ['ifr', 'in_preparation', 'Return for revision', 'drawing.review', true],
                    ['ifr', 'ifa', 'Issue for approval', 'drawing.submit'],
                    ['ifa', 'in_preparation', 'Return for revision', 'drawing.review', true],
                    ['ifa', 'ifc', 'Issue for construction', 'drawing.approve'],
                    ['ifc', 'as_built', 'Mark as-built', 'drawing.approve'],
                    ['ifc', 'superseded', 'Supersede', 'drawing.approve'],
                    ['superseded', 'in_preparation', 'Revise', 'drawing.edit'],
                    // "This drawing should never have existed." Distinct from superseded, which
                    // says it was right until something replaced it.
                    [null, 'void', 'Void', 'drawing.delete', true],
                ],
            ],

            /*
             * A question's life. The three-way ending is the point: `closed` means the asker got
             * what they needed, `answered` means somebody replied and nobody has agreed that
             * settles it, and `withdrawn` means the question went away unanswered. A register that
             * collapses those cannot tell an outstanding query from a forgotten one.
             */
            'rfi' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'open' => ['label' => 'Open', 'color' => 'brand'],
                    'answered' => ['label' => 'Answered', 'color' => 'brand'],
                    'closed' => ['label' => 'Closed', 'color' => 'success', 'terminal' => true],
                    'withdrawn' => ['label' => 'Withdrawn', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'open', 'Send', 'rfi.submit'],
                    ['open', 'answered', 'Record answer', 'rfi.respond'],
                    ['answered', 'closed', 'Accept and close', 'rfi.close'],
                    // The asker did not accept it. Back to open, with a reason, rather than a
                    // second answer overwriting the first.
                    ['answered', 'open', 'Answer not accepted', 'rfi.close', true],
                    [null, 'withdrawn', 'Withdraw', 'rfi.edit', true],
                ],
            ],

            /*
             * Submittals turn on the review code. `revise_resubmit` is a status of its own rather
             * than a rejection because it is not one: the reviewer is asking for a change, the
             * package is still live, and the next event is a resubmittal that points back at this
             * record. Marking it "rejected" would lose the distinction the contract cares about.
             */
            'submittal' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'submitted' => ['label' => 'Submitted', 'color' => 'brand'],
                    'under_review' => ['label' => 'Under review', 'color' => 'brand'],
                    'approved' => ['label' => 'Approved', 'color' => 'success', 'terminal' => true],
                    'approved_as_noted' => ['label' => 'Approved as noted', 'color' => 'success', 'terminal' => true],
                    'revise_resubmit' => ['label' => 'Revise and resubmit', 'color' => 'warning'],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'submitted', 'Submit', 'submittal.submit'],
                    ['submitted', 'under_review', 'Start review', 'submittal.review'],
                    ['under_review', 'approved', 'Code as approved', 'submittal.review'],
                    ['under_review', 'approved_as_noted', 'Code as approved as noted', 'submittal.review'],
                    ['under_review', 'revise_resubmit', 'Code as revise and resubmit', 'submittal.review', true],
                    ['under_review', 'rejected', 'Code as rejected', 'submittal.review', true],
                    // The resubmittal is a NEW record; this move just parks the old one.
                    ['revise_resubmit', 'draft', 'Prepare resubmittal', 'submittal.create'],
                ],
            ],

            /*
             * A change request's status is where it has got to; its DISPOSITION is the answer, and
             * they are separate columns for a reason spelled out on the model. The statuses below
             * therefore never say "approved" — that is the disposition's word — they say where the
             * paperwork is.
             */
            'change_request' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'submitted' => ['label' => 'Submitted', 'color' => 'brand'],
                    'under_review' => ['label' => 'Under review', 'color' => 'brand'],
                    'awaiting_decision' => ['label' => 'Awaiting decision', 'color' => 'warning'],
                    'decided' => ['label' => 'Decided', 'color' => 'success'],
                    'closed' => ['label' => 'Closed', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'submitted', 'Submit', 'change_request.submit'],
                    ['submitted', 'under_review', 'Start assessment', 'change_request.review'],
                    ['under_review', 'awaiting_decision', 'Send for decision', 'change_request.review'],
                    ['under_review', 'draft', 'Return to originator', 'change_request.review', true],
                    ['awaiting_decision', 'decided', 'Record decision', 'change_request.approve', true],
                    ['decided', 'closed', 'Close', 'change_request.edit'],
                ],
            ],
        ];
    }

    /** @return array<int,string> */
    public static function entityTypes(): array
    {
        return array_keys(self::all());
    }
}
