<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending a filled-in form out for approval (Phase 7, Part 4).
 *
 * THE BINDING IS ON THE FORM, NOT THE SUBMISSION, and that is the whole design. "Scaffold permits
 * are approved by the HSE manager" is a statement about the permit FORM — it is policy the company
 * sets once, in the builder, and every permit raised afterwards inherits it. Putting the route on
 * the submission instead would let whoever fills a sheet in choose who approves it, which is not an
 * approval process; it is a suggestion.
 *
 * BOTH COLUMNS ARE NEEDED, and the difference between them is the one documents learned the hard
 * way. `requires_approval` says WHETHER a submission is routed at all; `workflow_id` says WHICH
 * route it takes. A null workflow with approval required means "use whatever the tenant's default
 * route for form submissions is", which is how one company runs every form through one review
 * without configuring it per form. `requires_approval = false` means DO NOT ROUTE AND DO NOT FALL
 * BACK — see Document::requiresWorkflow(), which exists because a checkbox that said "no approval
 * needed" while the engine quietly fell through to a default was a screen telling the truth about a
 * system that was doing something else.
 *
 * FALSE IS THE DEFAULT, deliberately, and this is a migration running against forms that already
 * exist. Most forms are not approval workflows — a sign-in sheet, a toolbox talk register, a
 * delivery note. Defaulting to true would take every form built in Part 3 and, on deploy, start
 * posting its submissions into somebody's inbox for a decision nobody asked them to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // nullOnDelete rather than cascade: deleting a workflow definition must never delete
            // the forms that pointed at it. The form falls back to "no particular route", which is
            // a configuration gap somebody can see and fix, rather than a form that vanished.
            $table->foreignId('workflow_id')->nullable()->after('category')
                ->constrained('workflows')->nullOnDelete();

            $table->boolean('requires_approval')->default(false)->after('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
            $table->dropColumn('requires_approval');
        });
    }
};
