<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training requirements and performance reviews — Phase 6, part 4.
 *
 * THERE IS NO SECOND TRAINING REGISTER HERE, AND THAT IS THE POINT. `training_courses` and
 * `training_records` already exist from the HSE phase, and a company does not hold two ideas of
 * whether Amaka's confined-space ticket is in date. What was missing was the QUESTION, not the
 * answer: the HSE register says what people HOLD, and nothing said what they are SUPPOSED to hold.
 * `training_requirements` is that sentence — this position, or this department, or everybody, needs
 * this course — and the matrix is the two read against each other. Adding a third column to a
 * training record would have been the other design, and it would have made "who is missing their
 * first-aid certificate" unanswerable, because a missing certificate has no row.
 *
 * A REVIEW'S COMPETENCIES ARE COPIED ONTO IT, exactly as a joining checklist's items are copied in
 * part 3, and for the same reason: a cycle edited in December must not rewrite what somebody was
 * assessed against in June. `review_cycle_competencies` is the template; `performance_review_items`
 * is the copy that was actually scored, and it is the copy an appeal is heard against.
 *
 * THE TWO SCORES ARE SEPARATE COLUMNS. `self_score` and `manager_score` sit side by side because the
 * gap between them is the conversation the whole exercise exists to have, and a single column with a
 * "who wrote this" flag loses it the moment the manager saves. The service lets the employee write
 * one and the reviewer write the other, and neither can write the other's.
 *
 * A REVIEW IS RESTRICTED BY DEFAULT. It contains somebody's manager's written opinion of them. The
 * employee reads their own, their reviewer reads it because they wrote it, and everybody else needs
 * `performance.view_confidential` — a department head is NOT automatically entitled to read the
 * reviews of people two rungs below them (§68).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a job requires, as opposed to what a person holds.
         *
         * The target is EITHER a position, OR a department, OR the whole company, and `scope` says
         * which rather than the reader inferring it from which foreign key happens to be null — a
         * row with both set and neither meant is the sort of thing that survives for years.
         *
         * `grace_days` exists because "expired" and "overdue" are different states in every real
         * company. A ticket that lapsed yesterday is a renewal booking; one that lapsed in March is
         * a person who should not be on site, and a matrix that cannot tell them apart gets ignored.
         */
        Schema::create('training_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();

            $table->string('scope', 20);                          // organization | org_unit | position
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();

            // Mandatory requirements are the ones the matrix counts as a gap; the rest are
            // development the company would like people to have.
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active', 'scope']);
            $table->index(['organization_id', 'training_course_id']);
            $table->index(['organization_id', 'position_id']);
            $table->index(['organization_id', 'org_unit_id']);
        });

        /*
         * A review round: the period being assessed, and the window in which it is done.
         *
         * `rating_min` and `rating_max` are company data (§47). A five-point scale is an opinion
         * this platform has no business holding, and the moment it is hard-coded, the company that
         * runs a 1–4 scale without a comfortable middle cannot use the module at all.
         */
        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();

            $table->date('period_start');
            $table->date('period_end');
            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();

            $table->decimal('rating_min', 4, 2)->default(1);
            $table->decimal('rating_max', 4, 2)->default(5);
            $table->text('instructions')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'period_end']);
        });

        /* The template rows. Copied onto every review opened in the cycle — never referenced. */
        Schema::create('review_cycle_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();

            $table->string('label', 200);
            $table->string('description', 500)->nullable();
            $table->string('kind', 20)->default('competency');    // competency | objective | value
            $table->decimal('weight', 5, 2)->default(1);
            $table->unsignedSmallInteger('sequence')->default(0);

            // Narrowing: a competency that only applies to people in one department or one job.
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'review_cycle_id', 'sequence']);
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // The subject's own sign-in, copied from their personnel record when the review is
            // opened. Denormalised deliberately: "my review" has to be answerable in SQL at `own`
            // scope, and own-ness does not travel up a parent relation (see ScopeCompiler). Without
            // this column the person being reviewed would be the one person in the company unable
            // to open it. Null for somebody who has no account yet — they are reviewed on paper.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The manager doing the review, as a USER: reviewing is something an account does, and
            // the reviewer is not always the reporting-line supervisor — people move, and somebody
            // has to review the person whose manager left in October.
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->text('self_comments')->nullable();
            $table->timestamp('self_submitted_at')->nullable();

            $table->text('manager_comments')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();

            // Stored, not derived: the weighted average of the items AS THEY WERE SCORED. An item
            // edited afterwards under an appeal must not silently restate last year's rating.
            $table->decimal('overall_rating', 5, 2)->nullable();
            $table->text('development_plan')->nullable();

            // The employee's own acknowledgement — "I have seen this", not "I agree with it".
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_comments')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // One review per person per cycle. Two half-finished reviews of the same person in the
            // same round is the failure this prevents, and it is a database question, not a UI one.
            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'reviewer_id', 'status_id']);
        });

        Schema::create('performance_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_review_id')->constrained()->cascadeOnDelete();

            $table->string('label', 200);
            $table->string('description', 500)->nullable();
            $table->string('kind', 20)->default('competency');
            $table->decimal('weight', 5, 2)->default(1);
            $table->unsignedSmallInteger('sequence')->default(0);

            // Two scores, two authors. See the class docblock.
            $table->decimal('self_score', 5, 2)->nullable();
            $table->string('self_comment', 1000)->nullable();
            $table->decimal('manager_score', 5, 2)->nullable();
            $table->string('manager_comment', 1000)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'performance_review_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_review_items');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('review_cycle_competencies');
        Schema::dropIfExists('review_cycles');
        Schema::dropIfExists('training_requirements');
    }
};
