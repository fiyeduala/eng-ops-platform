<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow engine (§19, §20, §21, §78, docs/07).
 *
 * There is exactly ONE of these in the platform. Documents use it; so will purchase requests,
 * expenses, leave, HSE incidents, permits and NCRs, by adding a trait and a row in `workflows` —
 * not by growing their own approval tables. §78 is explicit that ten divergent, separately-buggy
 * approval systems is the failure mode to design against, and the shape below is what prevents it:
 * nothing in these tables names documents.
 *
 * TWO HALVES, and keeping them apart is the whole design:
 *
 *   DEFINITION — `workflows`, `workflow_stages`, `workflow_stage_conditions`. Configuration an
 *   administrator edits through the UI. Versioned, never mutated in place.
 *
 *   RUNTIME — `workflow_instances`, `workflow_stage_instances`, `approvals`, `signatures`. One set
 *   per record in flight. Each instance remembers the workflow VERSION it started on, so an
 *   administrator who reorganises the approval chain this afternoon does not corrupt the fourteen
 *   approvals currently sitting in people's inboxes (docs/07 §2).
 *
 * THE ASSIGNEE RULE (§19) is the most important thing here and it is worth stating plainly: a stage
 * NEVER names a person. It names a RELATIONSHIP — "the head of the department that owns this
 * record", "the manager of this record's project", "whoever holds the QA Manager role". Resolution
 * happens when the stage is ENTERED, against live organisation data, and the resolved set is frozen
 * onto the stage instance for audit. When John leaves, the workflow does not break and nobody has
 * to remember to edit it; the next document routes to whoever now holds the position.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------------- definition

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();

            // The MODULE this routes, as a permission-catalogue module key: document,
            // purchase_request, leave. Deliberately a string rather than a morph class name —
            // configuration rows must not contain PHP class names an administrator can neither
            // read nor be expected to keep valid across a refactor.
            $table->string('entity_type', 50);

            // What starts it. `submitted` is the common case; a workflow may also be started
            // explicitly, or by an automatic action from another workflow.
            $table->string('trigger', 40)->default('submitted');

            /*
             * VERSIONING. Editing a published workflow creates a new row with version + 1 and
             * points `supersedes_id` back at the old one, which is deactivated but never deleted:
             * running instances still reference it and the audit trail still has to explain what
             * the rules were on the day a decision was taken.
             */
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable();

            // Only one workflow per entity type may be the default that new records enter.
            // Enforced in WorkflowDefinitionService rather than by a partial unique index, which
            // MySQL does not support.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // A draft workflow is configuration somebody is still writing and must never pick up
            // live records. Publishing is a separate act with its own permission (workflow.publish).
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'entity_type', 'name', 'version']);
            $table->index(['organization_id', 'entity_type', 'is_active']);
            $table->index(['organization_id', 'entity_type', 'is_default', 'is_active']);
        });

        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('name', 150);
            $table->text('instructions')->nullable();

            /*
             * WHO. See docs/07 §3 for the full table of types. `assignee_ref` is interpreted
             * according to `assignee_type` — a role id, an org unit id, a column name, a depth, or
             * a resolver class — and is null for the types that need no argument because they
             * derive everything from the record (`org_unit_head`, `project_manager`, `supervisor`).
             */
            $table->string('assignee_type', 40)->default('role');
            $table->string('assignee_ref', 150)->nullable();

            // review | approve | sign | acknowledge | input | notify_only
            $table->string('action_type', 24)->default('approve');

            /*
             * QUORUM. `any` — the first responder decides. `all` — every resolved assignee must
             * act. `n_of_m` — quorum_count of them must.
             *
             * This exists because "send it to the three discipline leads" means different things in
             * different companies, and guessing wrong either blocks a document on somebody's
             * holiday or lets one person wave through what three were meant to check.
             */
            $table->string('quorum', 12)->default('any');
            $table->unsignedSmallInteger('quorum_count')->nullable();

            // Business hours, resolved against the organization's working calendar rather than
            // wall-clock — a Friday afternoon submission with a 24-hour SLA is due Monday.
            $table->unsignedSmallInteger('sla_hours')->nullable();

            // terminate | return_to_previous | return_to_owner
            $table->string('on_reject', 24)->default('return_to_owner');

            $table->boolean('allow_delegate')->default(true);

            // Whether the person who created the record may act on this stage. Off by default:
            // self-approval is the single most common control failure in approval systems, and
            // making it opt-in per stage means enabling it is a visible decision somebody made.
            $table->boolean('allow_self_approval')->default(false);

            // Extra audiences told when the stage opens or closes, beyond the assignees. Filtered
            // through the same authorization as any notification (§68) — naming somebody here does
            // NOT grant them sight of the record.
            $table->json('notify')->nullable();

            // Side effects (§32): create_task, set_status, notify, create_record. This is how
            // "NCR raised → corrective action task created → assigned → deadline" is configuration
            // rather than code.
            $table->json('auto_actions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workflow_id', 'sequence']);
            $table->index(['organization_id', 'workflow_id']);
        });

        /*
         * Conditions decide whether a stage RUNS at all (§20). A stage whose conditions evaluate
         * false is skipped — recorded as skipped, never silently omitted, because "why did this
         * not go to the MD" is a question the audit trail must answer.
         *
         * Values are stored as text and compared through ConditionEvaluator, which whitelists the
         * fields readable per entity type. Arbitrary expressions are never evaluated: a condition
         * is data, and a configuration screen that can execute code is a remote shell with a nice
         * form around it.
         */
        Schema::create('workflow_stage_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();

            // Dotted paths into whitelisted relations are allowed: `project.category`.
            $table->string('field', 150);
            // = != > >= < <= in not_in contains between is_null changed
            $table->string('operator', 16)->default('=');
            // May hold a literal, a JSON array for `in`, or a {{setting:key}} token so a threshold
            // lives in settings where finance can change it without editing the workflow.
            $table->text('value')->nullable();

            // How this rule joins the previous one within the stage.
            $table->string('logic', 4)->default('and');
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['workflow_stage_id', 'sort']);
            $table->index(['organization_id', 'workflow_stage_id'], 'workflow_stage_conditions_org_stage_index');
        });

        // ---------------------------------------------------------------------------- runtime

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The version in force when this started. Never updated.
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();

            // What is being routed. Morph rather than a document_id, because that is the entire
            // point of having one engine.
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            // running | completed | rejected | revision_requested | cancelled | stalled
            //
            // `stalled` is not an error state to be swept under the carpet: it is what happens when
            // a stage resolves to NOBODY, and the engine stops and shouts rather than skipping the
            // stage. A workflow that silently skips the approval nobody could be found for is worse
            // than one that halts (docs/07 §3).
            $table->string('status', 24)->default('running');

            // Denormalised pointer at the open stage instance. No foreign key: circular, and the
            // real invariant ("exactly one pending stage instance for the current attempt") is not
            // expressible as one anyway. WorkflowEngine maintains it transactionally.
            $table->unsignedBigInteger('current_stage_instance_id')->nullable();

            /*
             * RESUBMISSION (§24). A rejected record that is fixed and resubmitted does not get a
             * fresh instance — it gets attempt + 1 on this one. The previous attempt's stage
             * instances, decisions, comments and timings all survive, so "what were we asked to
             * change last time, and by whom" is answerable. A new instance per attempt would scatter
             * one conversation across several rows nothing joins.
             */
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // approved | rejected | cancelled — set once, when the instance closes.
            $table->string('outcome', 24)->nullable();
            $table->string('outcome_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'workflow_id', 'status']);
        });

        Schema::create('workflow_stage_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
            // Nullified rather than cascaded: deleting a stage DEFINITION must not erase the record
            // that somebody approved something at that stage last year.
            $table->foreignId('workflow_stage_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('sequence')->default(1);

            // The stage NAME as configured at the moment this instance was created. A snapshot, for
            // the same reason signatures snapshot a designation: an administrator renaming
            // "Technical Review" to "Discipline Check" next year must not rewrite what the approval
            // history says happened.
            $table->string('stage_name', 150)->nullable();

            // pending | approved | rejected | revision_requested | skipped | cancelled | expired
            $table->string('status', 24)->default('pending');

            /*
             * The resolved assignee ids, frozen at stage entry. THIS is what the policy checks —
             * not a re-resolution at action time. If somebody is moved out of a department while a
             * document sits in their inbox, they keep the decision they were legitimately given;
             * re-resolving on each request would let an org chart edit silently retract an
             * assignment mid-flight, which is both surprising and a denial-of-service on approvals.
             */
            $table->json('resolved_assignees')->nullable();
            // Why it resolved to those people — the rule that was applied, in words. Recorded
            // because "why is this in my inbox" must be answerable without reading the config.
            $table->string('resolution_note', 300)->nullable();

            $table->timestamp('entered_at')->nullable();
            $table->timestamp('due_at')->nullable();

            // Filled when the stage CLOSES. For a quorum stage this is the last person to act, and
            // the individual decisions live in `approvals`.
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->string('decision', 24)->nullable();
            $table->text('comment')->nullable();

            // Why a skipped stage was skipped, in words, for the audit trail.
            $table->string('skip_reason', 300)->nullable();

            // Escalation is idempotent (docs/07 §7): the scheduler records the highest level it has
            // already sent so a re-run does not notify the same supervisor four times.
            $table->unsignedSmallInteger('last_escalated_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();

            $table->timestamps();

            // THE APPROVALS INBOX INDEX (docs/07 §8). The inbox is a query over this table, and it
            // runs on every page load for every user, so the shape of this index is the shape of
            // the feature's performance.
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['workflow_instance_id', 'attempt', 'sequence'], 'workflow_stage_instances_instance_attempt_seq_index');
        });

        /*
         * ONE PERSON'S DECISION. Separate from the stage instance because a quorum stage collects
         * several, and because "who actually pressed approve" must survive any later recalculation
         * of what the stage concluded.
         */
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Set when this person is acting because somebody delegated to them. The original
            // assignee is named so the chain of authority is legible afterwards.
            $table->foreignId('delegated_from')->nullable()->constrained('users')->nullOnDelete();

            $table->string('decision', 24);
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();

            // Recorded on the decision itself rather than left to the audit log, because an
            // approval is the record most likely to be disputed later.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            // No double voting. A database-level guarantee rather than a check in the service,
            // because the realistic failure is a double-submitted form, which two concurrent
            // requests will both pass a check and both write.
            $table->unique(['workflow_stage_instance_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });

        /*
         * SIGNATURES (§28, docs/09 §5).
         *
         * An internal electronic approval signature — explicitly NOT a qualified/advanced signature
         * under eIDAS or ESIGN, and docs/09 §5 carries the legal scope statement the UI repeats.
         *
         * Two columns carry the weight:
         *
         *   `designation_snapshot` — the signatory's job title AT THE MOMENT OF SIGNING. A promotion
         *   next year must not silently rewrite who approved a drawing as what they are now.
         *
         *   `content_hash` — the sha256 of the exact bytes signed (a file's hash, or the hash of the
         *   rendered content for a native document). If somebody later claims a different revision
         *   was the one approved, this settles it.
         */
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The approval this signature evidences, where there is one. Nullable because a
            // document may also be signed outside a workflow — a wet-signed scan being registered,
            // or an acknowledgement.
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete();

            // What was signed. Morph, so this table serves documents, transmittals and later
            // purchase orders without alteration.
            $table->string('signable_type', 100);
            $table->unsignedBigInteger('signable_id');

            // The exact revision signed, where the signable has revisions.
            $table->unsignedBigInteger('document_version_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->string('name_snapshot', 200)->nullable();
            $table->string('designation_snapshot', 200)->nullable();

            // approve | review | sign | reject | acknowledge
            $table->string('action', 24)->default('sign');

            $table->timestamp('signed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // A drawn or uploaded signature image, on the private disk like everything else.
            // Optional: the signature is the RECORD, not the picture.
            $table->string('signature_image_path', 500)->nullable();

            $table->string('content_hash', 64)->nullable();

            // Which SignatureProvider produced this. `internal` today; the column exists so that
            // integrating DocuSign or a national CA later (docs/09 §5) does not need a migration
            // that touches existing signature rows.
            $table->string('provider', 40)->default('internal');
            $table->json('provider_reference')->nullable();

            $table->timestamps();

            $table->index(['signable_type', 'signable_id']);
            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'document_version_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('workflow_stage_instances');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_stage_conditions');
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflows');
    }
};
