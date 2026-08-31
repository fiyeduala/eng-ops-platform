<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ApprovalDecided;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Signature;
use App\Support\Enums\Decision;

/**
 * Turn an approval of a document into a signature on the revision (§28, docs/09 §5).
 *
 * WHY A LISTENER AND NOT THE ENGINE. WorkflowEngine deliberately names no module, and a signature
 * is a document-control concept: a purchase requisition approved by a manager wants an audit trail,
 * not a signature block on a title sheet. Hanging this off the event keeps the engine generic and
 * puts the decision "approvals of THIS kind of record are signatures" where it belongs — in the
 * document module's own wiring, next to the notifications that make the same judgement.
 *
 * ONE SIGNATURE PER APPROVER PER STAGE, which is what an engineering approval block has always
 * shown: prepared by, checked by, approved by, each with a name, a title and a date. The engine
 * already refuses a second decision from the same person on the same stage, so this cannot double
 * up; a resubmission after rejection is a new attempt and genuinely does want a second signature,
 * because the approver is endorsing a revision they had previously refused.
 *
 * ONLY `approve` SIGNS. A rejection is a decision, fully recorded on the Approval row with its
 * comment — but a signature is an affirmative statement of endorsement, and a row in a signature
 * table that means "did not endorse" is a trap for anybody who later counts them. Delegation and
 * abstention sign nothing for the same reason: neither is an endorsement of the content.
 *
 * WHAT IS SNAPSHOTTED AND WHY. `designation_snapshot` freezes the job title as it was, so a
 * promotion does not retroactively rewrite who signed a drawing as what they are now.
 * `content_hash` freezes the bytes, so "which revision did they actually sign" is answerable
 * without argument. Neither can be recovered later if it is not taken now.
 */
class RecordDocumentSignature
{
    public function handle(ApprovalDecided $event): void
    {
        $decision = $event->approval->decision;

        if (! $decision instanceof Decision) {
            $decision = Decision::tryFrom((string) $decision);
        }

        if ($decision !== Decision::Approve) {
            return;
        }

        $subject = $event->stageInstance->instance?->subject;

        if (! $subject instanceof Document) {
            return;
        }

        $version = $this->versionUnderReview($subject);

        if ($version === null) {
            // Nothing to sign. A route can only start on a document with a draft revision, so this
            // means the draft was removed underneath the approval — the Approval row still records
            // the decision, and a signature over nothing would be worse than no signature.
            return;
        }

        $signer = $event->approval->user;

        if ($signer === null) {
            return;
        }

        // Loaded rather than reached for: lazy loading is barred outside production, and the user
        // arrives here from the engine's own query, which has no reason to have preloaded this.
        $employee = $signer->loadMissing('employee.position')->employee;

        Signature::query()->create([
            'organization_id' => $subject->organization_id,
            'approval_id' => $event->approval->getKey(),
            'signable_type' => $subject->getMorphClass(),
            'signable_id' => $subject->getKey(),
            'document_version_id' => $version->getKey(),
            'user_id' => $signer->getKey(),
            'employee_id' => $employee?->getKey(),
            'name_snapshot' => $signer->name,
            // The POSITION's title, not a column on the person: "who signed this, as what" is a
            // question about the job they held that day, and the job is a record of its own.
            'designation_snapshot' => $employee?->position?->title,
            'action' => 'approve',

            // The approval's own timestamp, not `now()`. They are the same second in practice and
            // different in principle: the signature dates the decision, not the bookkeeping.
            'signed_at' => $event->approval->acted_at ?? now(),

            'ip' => $event->approval->ip,
            'user_agent' => $event->approval->user_agent,
            'content_hash' => $version->contentHash(),
            'provider' => 'internal',
            'provider_reference' => [
                'stage' => $event->stageInstance->stage_name,
                'sequence' => $event->stageInstance->sequence,
                'attempt' => $event->stageInstance->attempt,
                'revision' => $version->revision,
            ],
        ]);
    }

    /**
     * The revision this route is about: the newest one nobody has approved.
     *
     * The same rule DocumentVersionService uses to decide which revision a completed route
     * promotes, and it has to be the same rule — a signature attached to a different revision than
     * the one the approval promotes would make the register's own evidence disagree with itself.
     */
    private function versionUnderReview(Document $document): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->whereNull('approved_at')
            ->orderByDesc('id')
            ->first();
    }
}
