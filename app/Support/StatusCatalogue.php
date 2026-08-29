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
        ];
    }

    /** @return array<int,string> */
    public static function entityTypes(): array
    {
        return array_keys(self::all());
    }
}
