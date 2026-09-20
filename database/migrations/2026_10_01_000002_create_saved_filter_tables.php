<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved filters — a question somebody asks often enough to name (§54, docs/11 §2).
 *
 * A SAVED FILTER HOLDS CONDITIONS, NEVER ROWS. That is the entire reason sharing one is safe. What
 * travels to a colleague is "status is open and the unit is Civil"; what comes back when they run
 * it is whatever THEY are allowed to see, re-derived through their own `visibleTo()` at that
 * moment. A stored result set could only ever have been one person's, and handing it to a second
 * reader is exactly what §61 exists to prevent — so there is deliberately no column here in which
 * one could be kept.
 *
 * `visibility` IS THE "WITHIN A SCOPE" HALF OF §54, and it describes who may see the QUESTION:
 *
 *   private       only the person who saved it
 *   org_unit      that person's department, as a subtree — `org_unit_id` records which
 *   organization  everybody in the company
 *
 * `org_unit_id` is a plain unsigned column rather than a constrained foreign key, and it is the one
 * deliberate departure from the rest of the schema. A department that is dissolved must not take a
 * shared filter down with it on cascade: the filter becomes unreachable through its scope and its
 * owner can re-share it, which is a smaller loss than a silent deletion nobody ordered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The owner. A filter belongs to a person, not to a report: the same "open in Civil"
            // is worth having on the register, the report and eventually the dashboard, and
            // hanging it off one report definition would make it three filters to maintain.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 64);
            $table->string('name', 120);
            $table->json('filters');

            $table->string('visibility', 16)->default('private');
            $table->unsignedBigInteger('org_unit_id')->nullable();

            $table->timestamps();

            // What the run screen actually asks: "which saved filters for this register may this
            // person see". Ordered so the visibility check rides the same index (§89).
            $table->index(['organization_id', 'entity_type', 'visibility'], 'saved_filters_lookup_idx');
            $table->index(['organization_id', 'user_id', 'entity_type'], 'saved_filters_owner_idx');

            // One person may not have two "Open in Civil" for the same register. Scoped to the
            // owner rather than the company, because two people naming their own views the same
            // thing is not a collision — it is two people, each with one list.
            $table->unique(['organization_id', 'user_id', 'entity_type', 'name'], 'saved_filters_unique_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
