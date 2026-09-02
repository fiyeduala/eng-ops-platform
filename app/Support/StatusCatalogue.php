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

            /*
             * ------------------------------------------------------------------ HSE
             *
             * THE INCIDENT GRAPH IS THE ONE THAT CARRIES PHASE 4's EXIT CRITERION. `under_review`
             * exists as a status in its own right — rather than folding straight from reported to
             * closed — precisely so that an administrator has somewhere to hang "when it gets here,
             * open an investigation and a corrective action". That is configuration doing the work
             * a listener would otherwise do in PHP (§32, §73).
             *
             * `reported` is initial rather than `draft`. Incidents are not drafted: something
             * happened, and the register's first duty is to have a row for it within the hour. A
             * company that wants a drafting step adds one.
             */
            'hse_incident' => [
                'statuses' => [
                    'reported' => ['label' => 'Reported', 'color' => 'warning', 'initial' => true],
                    'under_review' => ['label' => 'Under review', 'color' => 'brand'],
                    'investigating' => ['label' => 'Investigating', 'color' => 'brand'],
                    'actions_pending' => ['label' => 'Actions pending', 'color' => 'warning'],
                    'closed' => ['label' => 'Closed', 'color' => 'success', 'terminal' => true],
                    'void' => ['label' => 'Logged in error', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['reported', 'under_review', 'Start review', 'hse_incident.edit'],
                    ['under_review', 'investigating', 'Open investigation', 'hse_incident.investigate'],
                    ['investigating', 'actions_pending', 'Findings issued', 'hse_incident.investigate'],
                    ['under_review', 'actions_pending', 'Raise actions', 'hse_incident.edit'],
                    ['actions_pending', 'closed', 'Close out', 'hse_incident.close'],
                    ['closed', 'under_review', 'Reopen', 'hse_incident.close', true],
                    ['reported', 'void', 'Logged in error', 'hse_incident.close', true],
                ],
            ],

            'investigation' => [
                'statuses' => [
                    'open' => ['label' => 'Open', 'color' => 'brand', 'initial' => true],
                    'in_progress' => ['label' => 'In progress', 'color' => 'brand'],
                    'draft_findings' => ['label' => 'Draft findings', 'color' => 'warning'],
                    'completed' => ['label' => 'Completed', 'color' => 'success', 'terminal' => true],
                ],
                'transitions' => [
                    ['open', 'in_progress', 'Start', 'hse_incident.investigate'],
                    ['in_progress', 'draft_findings', 'Draft findings', 'hse_incident.investigate'],
                    ['draft_findings', 'completed', 'Sign off', 'hse_incident.investigate'],
                    ['completed', 'in_progress', 'Reopen', 'hse_incident.investigate', true],
                ],
            ],

            /*
             * Two statuses no other register has: `verified` and `ineffective`. They are the whole
             * argument of the corrective-action module. "Completed" is the assignee's word;
             * "verified" is somebody else's, and "ineffective" is the outcome a register that only
             * had "done" could never record — the action was carried out and the problem came back.
             */
            'corrective_action' => [
                'statuses' => [
                    'open' => ['label' => 'Open', 'color' => 'warning', 'initial' => true],
                    'in_progress' => ['label' => 'In progress', 'color' => 'brand'],
                    'completed' => ['label' => 'Completed', 'color' => 'brand'],
                    'verified' => ['label' => 'Verified', 'color' => 'success', 'terminal' => true],
                    'ineffective' => ['label' => 'Ineffective', 'color' => 'danger'],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['open', 'in_progress', 'Start', 'corrective_action.edit'],
                    ['in_progress', 'completed', 'Mark complete', 'corrective_action.edit', true],
                    ['open', 'completed', 'Mark complete', 'corrective_action.edit', true],
                    ['completed', 'verified', 'Verify', 'corrective_action.verify'],
                    ['completed', 'ineffective', 'Not effective', 'corrective_action.verify', true],
                    ['ineffective', 'in_progress', 'Reopen', 'corrective_action.assign'],
                    [null, 'cancelled', 'Cancel', 'corrective_action.close', true],
                ],
            ],

            'hse_observation' => [
                'statuses' => [
                    'open' => ['label' => 'Open', 'color' => 'brand', 'initial' => true],
                    'action_raised' => ['label' => 'Action raised', 'color' => 'warning'],
                    'closed' => ['label' => 'Closed', 'color' => 'success', 'terminal' => true],
                ],
                'transitions' => [
                    ['open', 'action_raised', 'Raise action', 'hse_observation.edit'],
                    ['open', 'closed', 'Close', 'hse_observation.close'],
                    ['action_raised', 'closed', 'Close', 'hse_observation.close'],
                    ['closed', 'open', 'Reopen', 'hse_observation.close', true],
                ],
            ],

            /*
             * `expired` is deliberately NOT in this list. Expiry is a date — see
             * RiskAssessment::hasExpired() — and a status that has to be swept into place by a
             * nightly job is a status that is wrong every night until the job runs. The register
             * derives it from `valid_to`, which is right the moment the clock passes.
             */
            'hse_risk' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'in_review' => ['label' => 'In review', 'color' => 'brand'],
                    'approved' => ['label' => 'Approved', 'color' => 'success'],
                    'superseded' => ['label' => 'Superseded', 'color' => 'neutral', 'terminal' => true],
                    'withdrawn' => ['label' => 'Withdrawn', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'in_review', 'Send for review', 'hse_risk.edit'],
                    ['in_review', 'draft', 'Return for amendment', 'hse_risk.edit', true],
                    ['in_review', 'approved', 'Approve', 'hse_risk.approve'],
                    ['approved', 'superseded', 'Supersede', 'hse_risk.approve'],
                    [null, 'withdrawn', 'Withdraw', 'hse_risk.approve', true],
                ],
            ],

            'hse_inspection' => [
                'statuses' => [
                    'scheduled' => ['label' => 'Scheduled', 'color' => 'neutral', 'initial' => true],
                    'performed' => ['label' => 'Performed', 'color' => 'brand'],
                    'actions_pending' => ['label' => 'Actions pending', 'color' => 'warning'],
                    'closed' => ['label' => 'Closed', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['scheduled', 'performed', 'Record results', 'hse_inspection.edit'],
                    ['performed', 'actions_pending', 'Raise actions', 'hse_inspection.edit'],
                    ['performed', 'closed', 'Close', 'hse_inspection.close'],
                    ['actions_pending', 'closed', 'Close', 'hse_inspection.close'],
                    ['scheduled', 'cancelled', 'Cancel', 'hse_inspection.edit', true],
                ],
            ],

            'toolbox_talk' => [
                'statuses' => [
                    'planned' => ['label' => 'Planned', 'color' => 'neutral', 'initial' => true],
                    'held' => ['label' => 'Held', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['planned', 'held', 'Record delivery', 'toolbox_talk.edit'],
                    ['planned', 'cancelled', 'Cancel', 'toolbox_talk.edit'],
                ],
            ],

            /*
             * THE ONLY GRAPH IN THE APPLICATION WHERE A TERMINAL STATE IS THE NORMAL DAILY OUTCOME.
             * Permits are opened and closed within a shift, in their hundreds, and `suspended` is a
             * round trip rather than an exception — a gas alarm stops the work and the same permit
             * resumes an hour later. Modelling suspension as cancel-and-reissue would lose the fact
             * that it was one job.
             */
            'permit' => [
                'statuses' => [
                    'requested' => ['label' => 'Requested', 'color' => 'neutral', 'initial' => true],
                    'issued' => ['label' => 'Issued', 'color' => 'brand'],
                    'active' => ['label' => 'Active', 'color' => 'success'],
                    'suspended' => ['label' => 'Suspended', 'color' => 'danger'],
                    'closed' => ['label' => 'Closed', 'color' => 'neutral', 'terminal' => true],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['requested', 'issued', 'Issue', 'permit.approve'],
                    ['requested', 'rejected', 'Reject', 'permit.reject', true],
                    ['issued', 'active', 'Accept', 'permit.review'],
                    ['active', 'suspended', 'Suspend', 'permit.edit', true],
                    ['suspended', 'active', 'Resume', 'permit.approve'],
                    ['active', 'closed', 'Close', 'permit.close'],
                    ['suspended', 'closed', 'Close', 'permit.close'],
                    ['issued', 'cancelled', 'Cancel', 'permit.close', true],
                    ['requested', 'cancelled', 'Cancel', 'permit.close', true],
                ],
            ],

            /*
             * A training record has no workflow worth the name — the certificate either exists or
             * it does not — so the vocabulary records the ONE thing the register is opened for:
             * whether the ticket is still good. `expiring` is a courtesy status a company can drive
             * from the expiry report; nothing branches on it.
             */
            'hse_training' => [
                'statuses' => [
                    'valid' => ['label' => 'Valid', 'color' => 'success', 'initial' => true],
                    'expiring' => ['label' => 'Expiring soon', 'color' => 'warning'],
                    'expired' => ['label' => 'Expired', 'color' => 'danger'],
                    'revoked' => ['label' => 'Revoked', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['valid', 'expiring', 'Flag as expiring', 'hse_training.edit'],
                    ['expiring', 'expired', 'Mark expired', 'hse_training.edit'],
                    ['valid', 'expired', 'Mark expired', 'hse_training.edit'],
                    [null, 'revoked', 'Revoke', 'hse_training.manage', true],
                ],
            ],

            // ------------------------------------------------------------------ quality

            'quality_inspection' => [
                'statuses' => [
                    'requested' => ['label' => 'Requested', 'color' => 'neutral', 'initial' => true],
                    'scheduled' => ['label' => 'Scheduled', 'color' => 'brand'],
                    'passed' => ['label' => 'Passed', 'color' => 'success', 'terminal' => true],
                    'passed_with_comment' => ['label' => 'Passed with comment', 'color' => 'success', 'terminal' => true],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger'],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['requested', 'scheduled', 'Schedule', 'quality_inspection.edit'],
                    ['scheduled', 'passed', 'Pass', 'quality_inspection.approve'],
                    ['scheduled', 'passed_with_comment', 'Pass with comment', 'quality_inspection.approve', true],
                    ['scheduled', 'rejected', 'Reject', 'quality_inspection.approve', true],
                    ['rejected', 'requested', 'Re-request after rework', 'quality_inspection.edit'],
                    ['requested', 'cancelled', 'Cancel', 'quality_inspection.edit', true],
                ],
            ],

            /*
             * NOTE WHAT THE NCR STATUSES DO NOT SAY: none of them is "use as is" or "rework". Those
             * are dispositions, they live in their own column, and keeping them out of the status
             * vocabulary is what lets "closed" and "closed as use-as-is" both be answerable — the
             * first from the status, the second only from the pair. See Ncr's docblock.
             */
            'ncr' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'issued' => ['label' => 'Issued', 'color' => 'warning'],
                    'responded' => ['label' => 'Responded', 'color' => 'brand'],
                    'dispositioned' => ['label' => 'Dispositioned', 'color' => 'brand'],
                    'verified' => ['label' => 'Verified', 'color' => 'success'],
                    'closed' => ['label' => 'Closed', 'color' => 'success', 'terminal' => true],
                    'withdrawn' => ['label' => 'Withdrawn', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'issued', 'Issue', 'ncr.submit'],
                    ['issued', 'responded', 'Record response', 'ncr.review'],
                    ['responded', 'dispositioned', 'Record disposition', 'ncr.approve', true],
                    ['issued', 'dispositioned', 'Record disposition', 'ncr.approve', true],
                    ['dispositioned', 'verified', 'Verify', 'ncr.review'],
                    ['verified', 'closed', 'Close', 'ncr.close'],
                    ['draft', 'withdrawn', 'Withdraw', 'ncr.edit', true],
                ],
            ],

            /*
             * --- Phase 5: the commercial foundation ----------------------------------
             *
             * A VENDOR'S STATUS IS ITS PREQUALIFICATION POSITION, and nothing else. "Blacklisted"
             * appears here as well as in its own column because a buyer scanning the register reads
             * the status chip, not a boolean three columns to the right — but the column is what
             * procurement actually checks, and the two are kept in step by VendorService.
             *
             * `provisional` exists because most companies have a category of supplier they will use
             * once, under supervision, before committing to a full prequalification. Without it
             * they would either approve people they have not assessed or refuse work they have
             * already promised — which is how a prequalification process gets quietly abandoned.
             */
            'vendor' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'under_review' => ['label' => 'Under review', 'color' => 'brand'],
                    'provisional' => ['label' => 'Provisionally approved', 'color' => 'warning'],
                    'approved' => ['label' => 'Approved', 'color' => 'success'],
                    'suspended' => ['label' => 'Suspended', 'color' => 'warning'],
                    'blacklisted' => ['label' => 'Blacklisted', 'color' => 'danger'],
                    'archived' => ['label' => 'Archived', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'under_review', 'Submit for prequalification', 'vendor.edit'],
                    ['under_review', 'provisional', 'Approve provisionally', 'vendor.manage'],
                    ['under_review', 'approved', 'Approve', 'vendor.manage'],
                    ['provisional', 'approved', 'Approve fully', 'vendor.manage'],
                    ['under_review', 'draft', 'Return for more information', 'vendor.manage', true],
                    ['approved', 'suspended', 'Suspend', 'vendor.manage', true],
                    ['provisional', 'suspended', 'Suspend', 'vendor.manage', true],
                    ['suspended', 'approved', 'Reinstate', 'vendor.manage', true],
                    // Blacklisting is reached through VendorService, which demands a reason and
                    // stamps the column procurement reads. It is listed here so the chip has
                    // somewhere to be, not as a route somebody clicks past.
                    ['archived', 'under_review', 'Bring back onto the register', 'vendor.manage', true],
                    [null, 'archived', 'Archive', 'vendor.manage', true],
                ],
            ],

            /*
             * A CLIENT HAS ALMOST NO PROCESS, and pretending otherwise would be theatre. Most
             * companies distinguish a prospect from a live account and an account they have stopped
             * trading with; anything finer than that belongs in a CRM.
             */
            'client' => [
                'statuses' => [
                    'prospect' => ['label' => 'Prospect', 'color' => 'neutral', 'initial' => true],
                    'active' => ['label' => 'Active', 'color' => 'success'],
                    'dormant' => ['label' => 'Dormant', 'color' => 'warning'],
                    'closed' => ['label' => 'Closed', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['prospect', 'active', 'Mark as active', 'client.edit'],
                    ['active', 'dormant', 'Mark as dormant', 'client.edit'],
                    ['dormant', 'active', 'Reactivate', 'client.edit'],
                    [null, 'closed', 'Close the account', 'client.manage', true],
                    ['closed', 'active', 'Reopen the account', 'client.manage', true],
                ],
            ],

            /*
             * `expired` IS NOT A STATUS ANYBODY SETS. A contract passes its end date whether or not
             * somebody remembers to click something, so expiry is read from `ends_on` — see
             * Contract::hasExpired(). What the graph records instead is the position in the
             * lifecycle a person is responsible for: drafted, out for signature, live, suspended,
             * completed, terminated.
             *
             * TERMINATION IS SEPARATE FROM COMPLETION and both are terminal. "The job finished" and
             * "we stopped it" are the same row in a badly designed register and completely
             * different conversations in a real one.
             */
            'contract' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'in_review' => ['label' => 'In review', 'color' => 'brand'],
                    'awaiting_signature' => ['label' => 'Awaiting signature', 'color' => 'warning'],
                    'active' => ['label' => 'Active', 'color' => 'success'],
                    'suspended' => ['label' => 'Suspended', 'color' => 'warning'],
                    'completed' => ['label' => 'Completed', 'color' => 'success', 'terminal' => true],
                    'terminated' => ['label' => 'Terminated', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'in_review', 'Send for review', 'contract.edit'],
                    ['in_review', 'draft', 'Return for amendment', 'contract.approve', true],
                    ['in_review', 'awaiting_signature', 'Approve for signature', 'contract.approve'],
                    ['awaiting_signature', 'active', 'Record as executed', 'contract.manage'],
                    ['active', 'suspended', 'Suspend', 'contract.manage', true],
                    ['suspended', 'active', 'Resume', 'contract.manage', true],
                    ['active', 'completed', 'Complete', 'contract.manage'],
                    // Reopening a completed contract rewrites what every commercial report said
                    // last month, so it takes the narrowest permission and demands a reason.
                    ['completed', 'active', 'Reopen', 'contract.manage', true],
                    ['active', 'terminated', 'Terminate', 'contract.manage', true],
                    ['suspended', 'terminated', 'Terminate', 'contract.manage', true],
                ],
            ],

            /*
             * --- Phase 5, part 2: the procurement chain ------------------------------
             *
             * FIVE GRAPHS, ONE CHAIN. Each register below has its own vocabulary, and the joins
             * between them are made by the services rather than by a shared status: a purchase
             * request does not become a purchase order, it CAUSES one, and the request's own
             * status records what happened to the request. Collapsing the five into one lifecycle
             * would mean a rejected receipt somehow set the state of the order it arrived against.
             *
             * WHERE THE PERMISSION SITS IS THE DESIGN. `submit` is held by the person who wrote the
             * thing; `approve` and `reject` are held by somebody else entirely. That separation is
             * the whole of segregation of duties as far as the status graph is concerned — the
             * services add the second half, which is that the two must not be the same person.
             */

            /*
             * A REQUEST THAT NOBODY ACTED ON IS THE COMMONEST STATE IN ANY PROCUREMENT SYSTEM, so
             * `submitted` is a resting place with a name rather than an instant between two others.
             *
             * `ordered` IS SET BY THE SERVICE, NOT BY A PERSON. It means "a purchase order now
             * exists for this", which is a fact about another record and not a decision anybody
             * takes — so it has no transition anybody can click, and PurchaseOrderService moves it
             * when an order is raised from the request.
             */
            'purchase_request' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'submitted' => ['label' => 'Submitted', 'color' => 'brand'],
                    'approved' => ['label' => 'Approved', 'color' => 'success'],
                    'ordered' => ['label' => 'Ordered', 'color' => 'success'],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'submitted', 'Submit for approval', 'purchase_request.submit'],
                    // Returning it is not rejecting it. "We will not buy this" ends the request;
                    // "tell me the size of the flange" sends it back to somebody who can answer.
                    ['submitted', 'draft', 'Return to the requester', 'purchase_request.review', true],
                    ['submitted', 'approved', 'Approve', 'purchase_request.approve'],
                    ['submitted', 'rejected', 'Reject', 'purchase_request.reject', true],
                    // Approval is reversible while nothing has been ordered against it — after
                    // that the order is the commitment and withdrawing the request changes nothing.
                    ['approved', 'submitted', 'Withdraw the approval', 'purchase_request.approve', true],
                    [null, 'cancelled', 'Cancel', 'purchase_request.manage', true],
                ],
            ],

            /*
             * `closed` AND `awarded` ARE NOT THE SAME EVENT. An enquiry closes when the deadline
             * passes and quotations stop being accepted; it is awarded when somebody decides. Weeks
             * separate the two, and the gap is where the evaluation lives.
             *
             * An enquiry that closes with nothing worth buying is `cancelled` WITH A REASON, not
             * left open forever — the suppliers who quoted are entitled to know it ended.
             */
            'rfq' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'issued' => ['label' => 'Issued', 'color' => 'brand'],
                    'closed' => ['label' => 'Closed', 'color' => 'warning'],
                    'under_evaluation' => ['label' => 'Under evaluation', 'color' => 'brand'],
                    'awarded' => ['label' => 'Awarded', 'color' => 'success', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'neutral', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'issued', 'Issue to the invited suppliers', 'rfq.issue'],
                    ['issued', 'closed', 'Close for responses', 'rfq.manage'],
                    // Reopening happens: a supplier asks for two more days and the buyer agrees.
                    // It is recorded rather than hidden, because the other suppliers had a deadline.
                    ['closed', 'issued', 'Reopen for responses', 'rfq.manage', true],
                    ['closed', 'under_evaluation', 'Begin evaluation', 'quotation.compare'],
                    ['issued', 'under_evaluation', 'Begin evaluation', 'quotation.compare'],
                    [null, 'cancelled', 'Cancel the enquiry', 'rfq.manage', true],
                ],
            ],

            /*
             * A QUOTATION'S STATUS IS ABOUT OUR HANDLING OF IT, not the supplier's intent. It
             * arrives, it is evaluated, and it either wins or does not. `withdrawn` is the one
             * state the supplier controls, and it is theirs to declare rather than ours to infer.
             *
             * `expired` IS ABSENT DELIBERATELY, for the same reason the contract graph has no
             * expiry: `valid_until` is a date, and a quotation is out of date whether or not
             * anybody clicked anything. See Quotation::hasExpired().
             */
            'quotation' => [
                'statuses' => [
                    'received' => ['label' => 'Received', 'color' => 'neutral', 'initial' => true],
                    'under_evaluation' => ['label' => 'Under evaluation', 'color' => 'brand'],
                    'accepted' => ['label' => 'Accepted', 'color' => 'success', 'terminal' => true],
                    'unsuccessful' => ['label' => 'Unsuccessful', 'color' => 'neutral', 'terminal' => true],
                    'withdrawn' => ['label' => 'Withdrawn by the supplier', 'color' => 'warning', 'terminal' => true],
                ],
                'transitions' => [
                    ['received', 'under_evaluation', 'Take into evaluation', 'quotation.compare'],
                    // Accepting and marking unsuccessful are both done BY THE AWARD, through
                    // RfqService::award(), which sets every quotation on the enquiry in one act so
                    // the winner and the losers can never disagree. Listed here so the chips exist.
                    ['received', 'withdrawn', 'Record as withdrawn', 'quotation.edit', true],
                    ['under_evaluation', 'withdrawn', 'Record as withdrawn', 'quotation.edit', true],
                ],
            ],

            /*
             * THE LONGEST GRAPH IN THE PLATFORM, because a purchase order genuinely has that many
             * distinct positions and each one is somebody's answer to "where is my order".
             *
             * `approved` AND `issued` ARE SEPARATE, as the migration says: approval is internal
             * authority, issue is the moment the supplier has it. `part_received` and `received`
             * are set by GoodsReceiptService from the arithmetic of the receipts, never clicked —
             * a person deciding an order is fully received is a person overriding the count.
             *
             * `closed` IS NOT `received`. Orders close short: five of the six items arrived, the
             * sixth was cancelled by agreement, and somebody has to say the order is finished with.
             */
            'purchase_order' => [
                'statuses' => [
                    'draft' => ['label' => 'Draft', 'color' => 'neutral', 'initial' => true],
                    'submitted' => ['label' => 'Awaiting approval', 'color' => 'brand'],
                    'approved' => ['label' => 'Approved', 'color' => 'success'],
                    'issued' => ['label' => 'Issued to the supplier', 'color' => 'brand'],
                    'part_received' => ['label' => 'Partly received', 'color' => 'warning'],
                    'received' => ['label' => 'Received in full', 'color' => 'success'],
                    'closed' => ['label' => 'Closed', 'color' => 'neutral', 'terminal' => true],
                    'rejected' => ['label' => 'Rejected', 'color' => 'danger', 'terminal' => true],
                    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'terminal' => true],
                ],
                'transitions' => [
                    ['draft', 'submitted', 'Submit for approval', 'purchase_order.submit'],
                    ['submitted', 'draft', 'Return for amendment', 'purchase_order.approve', true],
                    ['submitted', 'approved', 'Approve', 'purchase_order.approve'],
                    ['submitted', 'rejected', 'Reject', 'purchase_order.reject', true],
                    ['approved', 'issued', 'Issue to the supplier', 'purchase_order.issue'],
                    // An issued order is a commitment somebody else is acting on, so cancelling it
                    // is a narrower permission than raising it and always carries a reason.
                    ['issued', 'cancelled', 'Cancel the order', 'purchase_order.manage', true],
                    ['approved', 'cancelled', 'Cancel the order', 'purchase_order.manage', true],
                    ['part_received', 'closed', 'Close short', 'purchase_order.manage', true],
                    ['received', 'closed', 'Close the order', 'purchase_order.manage'],
                ],
            ],

            /*
             * A RECEIPT IS AN OBSERVATION, so its graph is short and mostly about who has checked
             * it. `disputed` is the state that earns its place: goods arrived, they are not what
             * was ordered, and the order must not read as satisfied while that is unresolved.
             */
            'goods_receipt' => [
                'statuses' => [
                    'draft' => ['label' => 'Being entered', 'color' => 'neutral', 'initial' => true],
                    'received' => ['label' => 'Received', 'color' => 'brand'],
                    'inspected' => ['label' => 'Inspected', 'color' => 'brand'],
                    'accepted' => ['label' => 'Accepted', 'color' => 'success', 'terminal' => true],
                    'disputed' => ['label' => 'Disputed with the supplier', 'color' => 'danger'],
                ],
                'transitions' => [
                    ['draft', 'received', 'Confirm the delivery', 'goods_receipt.edit'],
                    ['received', 'inspected', 'Record the inspection', 'goods_receipt.edit'],
                    ['received', 'accepted', 'Accept', 'goods_receipt.approve'],
                    ['inspected', 'accepted', 'Accept', 'goods_receipt.approve'],
                    ['received', 'disputed', 'Raise a dispute', 'goods_receipt.edit', true],
                    ['inspected', 'disputed', 'Raise a dispute', 'goods_receipt.edit', true],
                    ['disputed', 'accepted', 'Resolve and accept', 'goods_receipt.approve', true],
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
