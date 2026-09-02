<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\User;

/**
 * "The person who asks for it is not the person who approves it."
 *
 * WHY THIS IS NOT A POLICY. A policy answers "may this user do this at all", and the answer here is
 * yes — the approver holds `purchase_request.approve` and is entitled to use it on every request in
 * the company except one: the one they wrote themselves. Putting that in the policy would tell
 * somebody holding exactly the right permission that they lack permission, which is both untrue and
 * the single most confusing message an approval screen can show. So it lives here, where the
 * refusal can say what it actually is.
 *
 * WHY IT IS A SETTING AND NOT A RULE. Segregation of duties is a control a company chooses. A
 * hundred-person contractor with a procurement department wants it on and would consider a system
 * without it unauditable. A six-person consultancy where the managing director raises the
 * requisition and also signs it would be locked out of its own purchasing on day one. Both are real
 * and neither is wrong, so the platform ships the control ON and lets an administrator turn it off
 * — which is §4 configurability applied to a control rather than to a dropdown.
 *
 * WHY THE WAIVER IS RECORDED. When the setting is off and the two people ARE the same, this returns
 * true rather than throwing, and every caller writes that into the activity entry. A company that
 * has switched the control off still has to be able to answer "who approved their own purchases,
 * and which ones" during an audit — an unrecorded waiver is the same as no control at all, and
 * worse than an honest one because it looks like a control was in force.
 *
 * The using class must have a SettingsRepository on `$this->settings`.
 */
trait SeparatesDuties
{
    /**
     * Refuse when the actor is an originator and the company requires the two to differ.
     *
     * MORE THAN ONE PERSON CAN BE THE ORIGINATOR, which is why an array is accepted. A purchase
     * order is raised by one person, submitted by perhaps another and issued by a third, and every
     * one of them committed the company's money on it — so a storekeeper who was any of the three
     * is the wrong person to certify that the goods arrived. Passing only the most recent of them
     * would leave the commonest arrangement uncovered: the buyer who raises the order and hands it
     * to a manager to issue.
     *
     * @param  int|array<int,int|null>|null  $originatorId  who raised the thing — null when nobody
     *                                  is recorded, which is not a violation: a record with no
     *                                  author cannot conflict with anybody, and treating it as one
     *                                  would block the approval of everything imported before this
     *                                  column existed
     * @param  string  $message  what the person actually needs to be told, in a sentence
     * @return bool true when the rule WOULD have refused but the company has switched it off —
     *              the caller records that waiver on the activity entry
     *
     * @throws \DomainException when the rule is in force and broken
     */
    protected function assertSeparateDuties(
        int|array|null $originatorId,
        User $actor,
        string $settingKey,
        string $message,
    ): bool {
        $originators = array_values(array_unique(array_map(
            static fn ($id) => (int) $id,
            array_filter(is_array($originatorId) ? $originatorId : [$originatorId], static fn ($id) => $id !== null),
        )));

        if (! in_array((int) $actor->getKey(), $originators, true)) {
            return false;
        }

        if ($this->settings->bool($settingKey, true)) {
            throw new \DomainException($message);
        }

        return true;
    }
}
