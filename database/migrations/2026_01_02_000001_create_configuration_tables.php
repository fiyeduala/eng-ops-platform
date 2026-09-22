<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine 5, the parts Phase 2 records cannot be built without (§63, §64).
 *
 * Two things every operational record needs, and neither may be a constant in code:
 *
 *   statuses            One company's project goes Planned → Mobilising → Executing → Closed;
 *                       the next runs Tendering → Awarded → In Progress → Handover → Warranty.
 *                       A hard-coded enum forces the second company to describe its business in
 *                       the first company's words, which is the failure §63 names outright.
 *   numbering_schemes   PRJ-2026-0007 for one, MER/ENG/26/007 for another, and the sequence must
 *                       be gapless and collision-free under concurrency (§64).
 *
 * The BUILDER UI for both lands in Phase 7. The engine lands here, because a record written now
 * against a hard-coded status list would have to be migrated later, and every module built in
 * between would have copied the mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ statuses
        //
        // `entity_type` is the module key ('project', 'task', 'meeting'), not a class name.
        // Class names move when code is refactored; the module key is the same string the
        // permission catalogue uses, so a rename in app/Models never orphans a company's
        // configuration.
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 64);
            $table->string('key', 64);
            $table->string('label', 120);

            // A design-system token name ('slate', 'sky', 'amber'…), never raw CSS. Storing a
            // colour value would let configuration inject styling into every page that renders
            // a badge.
            $table->string('color', 24)->default('neutral');

            $table->unsignedSmallInteger('sort')->default(0);

            // Exactly one initial per entity type is enforced by StatusMachine, not by the
            // schema: MySQL cannot express "unique where is_initial = 1" portably, and a partial
            // index that SQLite ignored would be protection that only exists in production.
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'key']);
            $table->index(['organization_id', 'entity_type', 'sort']);
        });

        // ------------------------------------------------------------------ transitions
        //
        // The absence of a row is a refusal. A company that defines statuses but no transitions
        // gets a machine where nothing may move — which is visibly broken on the first attempt,
        // rather than a machine where everything may move, which is invisibly broken forever.
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 64);

            // Null means "from anywhere" — the escape hatch a Cancel transition needs.
            $table->foreignId('from_status_id')->nullable()->constrained('statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('statuses')->cascadeOnDelete();

            $table->string('label', 120)->nullable();

            // The permission the actor must hold to make this move, e.g. 'task.complete'. Null
            // means the ordinary edit permission for the module suffices.
            $table->string('permission_key', 120)->nullable();

            // Reserved for Phase 7's condition builder ("only if progress = 100"). Read by
            // StatusMachine, which refuses a transition it cannot evaluate rather than allowing
            // one it did not understand.
            $table->json('guard')->nullable();

            $table->boolean('requires_comment')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'from_status_id', 'to_status_id'], 'status_transitions_unique');
            $table->index(['organization_id', 'entity_type', 'from_status_id'], 'status_transitions_org_entity_from_index');
        });

        // ------------------------------------------------------------------ numbering
        Schema::create('numbering_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 64);
            $table->string('label', 120)->nullable();

            // Tokens resolved by NumberGenerator: {ORG} {PREFIX} {YYYY} {YY} {MM} {PROJECT}
            // {TYPE} {UNIT} {SEQ}. Everything else is a literal.
            $table->string('pattern', 191)->default('{PREFIX}-{YYYY}-{SEQ}');
            $table->string('prefix', 24)->nullable();

            // none | yearly | monthly — when the counter returns to 1.
            $table->string('reset_period', 16)->default('yearly');
            $table->unsignedTinyInteger('padding')->default(4);
            $table->unsignedInteger('start_at')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type']);
        });

        // ------------------------------------------------------------------ sequences
        //
        // One row per (scheme, period). The counter is incremented inside a transaction holding
        // a row lock (§64) — see NumberGenerator. The unique key is the second line of defence:
        // if two workers ever raced past the lock, the loser gets an integrity violation and
        // retries, instead of two projects silently sharing a number.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('numbering_scheme_id')->constrained()->cascadeOnDelete();

            // '2026', '2026-03', or 'all' when the scheme never resets.
            $table->string('period_key', 16);
            $table->unsignedBigInteger('next_value')->default(1);

            $table->timestamps();

            $table->unique(['numbering_scheme_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('numbering_schemes');
        Schema::dropIfExists('status_transitions');
        Schema::dropIfExists('statuses');
    }
};
