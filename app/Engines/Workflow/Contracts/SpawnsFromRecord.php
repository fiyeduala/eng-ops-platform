<?php

declare(strict_types=1);

namespace App\Engines\Workflow\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "When this happens, open one of those."
 *
 * An HSE incident that reaches its review stage should open an investigation; a non-conformance
 * that is accepted should open a corrective action. Both are things an administrator says in a
 * sentence, and both must therefore be CONFIGURATION — the `create_record` auto-action on a
 * workflow stage — rather than a listener somebody has to write per module (§32, §73).
 *
 * WHY AN INTERFACE AND NOT A MATCH STATEMENT IN THE ENGINE. WorkflowEngine names no module, and
 * AutoActionRunner is its vocabulary: the moment the runner contains the word "investigation" it
 * has become the HSE module's code living in the engine, and the next module needs a second edit
 * in the same file. Instead the runner resolves the configured morph key through the morph map and
 * asks the CLASS whether it can be opened from another record. Adding a spawnable record type in a
 * later phase is one interface on one model — the same discovery shape WorkflowCatalogue already
 * uses for routable entities.
 *
 * THE IMPLEMENTATION OWNS THE "SHOULD I?" QUESTION, not just the "how". Two cases matter and only
 * the module can answer them:
 *
 *   - IDEMPOTENCE. A stage that is re-entered — a rejected incident report resubmitted, an
 *     escalation that reopens a stage — runs its auto-actions again. An incident must not collect
 *     four investigations because it went round twice, so `spawnFrom()` returns null when one
 *     already exists. The engine cannot know that; "one per incident" is a fact about
 *     investigations.
 *
 *   - APPLICABILITY. A near-miss with no injury may not warrant an investigation at all under the
 *     company's own rules. Returning null is a legitimate, silent outcome, not a failure.
 *
 * RETURNING NULL IS NORMAL. Throwing is for genuine misconfiguration — a required parameter that
 * is missing, a source record of a kind this type cannot arise from — and AutoActionRunner catches
 * it, logs it and writes it to the source record's timeline without taking the approval down.
 */
interface SpawnsFromRecord
{
    /**
     * Open a record of this type arising from `$source`, or null if one should not be opened.
     *
     * @param  Model  $source  the record whose workflow stage fired the action
     * @param  array<string,mixed>  $params  the auto-action's own configuration, already validated
     *                                       to be an array; implementations must treat every key as
     *                                       untrusted administrator input (§84)
     * @param  int|null  $assigneeId  the user the action's assignee rule resolved to, if any
     * @param  User  $actor  whoever's decision opened the stage — the created record's author
     */
    public static function spawnFrom(Model $source, array $params, ?int $assigneeId, User $actor): ?Model;

    /**
     * The label the builder offers for this record type, e.g. "Investigation".
     *
     * Kept on the model rather than derived from the class name so a module can say "Corrective
     * action" where the class is `CorrectiveAction` and "Investigation" where the register calls it
     * something else — the words an administrator picks from should be the words on the screens.
     */
    public static function spawnLabel(): string;
}
