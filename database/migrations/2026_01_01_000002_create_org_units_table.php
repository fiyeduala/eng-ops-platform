<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The configurable organization tree (section 3).
 *
 * ONE self-referencing table with a `type` column and a materialized `path` supports every
 * company shape without code changes:
 *   Company A: Engineering > {Civil, Mechanical, Electrical}
 *   Company B: Operations  > {Projects, HSE, Procurement, Logistics}
 *   Company C: Projects    > {Construction, Engineering, Commercial}
 *
 * `path` is a materialized ancestor list ("/1/7/23/") so subtree scope filtering is a single
 * indexed LIKE rather than a recursive query. See docs/04-access-model.md section 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->string('type', 20)->index();           // division|department|section|team
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->text('description')->nullable();

            // Materialized path of ancestor ids, inclusive of self: "/1/7/23/"
            $table->string('path', 512)->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('sort')->default(0);

            // Functional head. Nullable FK added later (employees does not exist yet).
            $table->unsignedBigInteger('head_employee_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'parent_id']);
            $table->index(['organization_id', 'type', 'is_active']);
            $table->index(['organization_id', 'path']);
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
