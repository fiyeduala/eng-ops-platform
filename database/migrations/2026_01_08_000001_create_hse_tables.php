<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health, safety and environment (Phase 4).
 *
 * THE SAME SPINE AS THE ENGINEERING REGISTERS, and for the same reason: organization, number,
 * project, discipline, owning unit, status, confidentiality, dates. The access compiler, the
 * workflow engine, the status machine and the numbering engine already know how to read that
 * shape, and a register that invented its own column names would need its own everything (§90).
 *
 * WHAT IS DIFFERENT HERE IS THE DATA ITSELF. Every other module in this application holds records
 * about work. This one holds records about PEOPLE — who was hurt, what part of their body, how many
 * days they lost, what a doctor said. That changes three things in the schema rather than in the
 * policy layer:
 *
 *   1. `incidents.confidentiality` defaults to `restricted`, not `internal`. The default matters
 *      more than the column: the person logging an incident at eleven at night is not thinking
 *      about who can read it, and the safe answer has to be the one they get for free (§6).
 *
 *   2. The medical detail lives on `incident_persons`, one table further in, so that "who can see
 *      the incident" and "who can see what happened to Ada" are two different questions with two
 *      different answers. A safety manager needs the first to run the register; only an HSE lead
 *      with `hse_incident.view_confidential` needs the second.
 *
 *   3. `hse_observations` can be filed ANONYMOUSLY, and when it is, `observed_by` is left null
 *      rather than filled in and hidden. A hidden-but-present column is a promise that lasts until
 *      the first person with database access goes looking, and the whole value of a near-miss
 *      programme rests on people believing the promise.
 *
 * INVESTIGATIONS AND CORRECTIVE ACTIONS ARE THE PHASE'S EXIT CRITERION. Both are opened by the
 * `create_record` workflow auto-action rather than by a listener, which is what makes "an incident
 * spawns an investigation and a corrective action" a sentence an administrator writes on the
 * builder screen instead of a sentence a developer writes in PHP (§32, §73). See
 * App\Engines\Workflow\Contracts\SpawnsFromRecord.
 *
 * CORRECTIVE ACTIONS ARE POLYMORPHIC ON PURPOSE. An action arises from an incident, an
 * investigation, an observation, an inspection finding, an NCR or an audit, and modelling that as
 * six nullable foreign keys would mean six more every time a module is added. One morph, one
 * register, one "what is outstanding against me" query for the whole company.
 *
 * PERMIT TYPES ARE A TENANT TABLE, NOT AN ENUM. A refinery's permit set — hot work, confined space,
 * excavation, working at height, energised electrical, radiography — is not a fabrication shop's,
 * and neither is a hospital estates department's. The types, their default precautions and whether
 * they demand a gas test are configuration a safety manager edits, which is the whole point (§73).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What happened.
         *
         * `occurred_at` and `reported_at` are both kept, and the gap between them is one of the
         * few numbers a regulator actually asks for. Overwriting one with the other — or defaulting
         * the occurrence to "now" — destroys the only evidence that a report was late.
         *
         * SEVERITY IS RECORDED TWICE, actual and potential, because they diverge and the divergence
         * is the entire argument for near-miss reporting: a scaffold plank that fell into an empty
         * walkway has an actual severity of nothing and a potential severity of a fatality. A
         * register that stores only what did happen cannot rank the second one above a paper cut,
         * and so never prompts anybody to fix it.
         */
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            // Nullable: incidents happen in workshops, in offices and on the road, none of which
            // belong to a project. Forcing one would push people to pick the nearest wrong answer.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // All four against configurable option lists (`hse_incident.categories`, `.severities`,
            // `.causes`), never enums: "environmental" means something specific in oil and gas and
            // something else in facilities management, and neither should need a migration (§73).
            $table->string('category', 60)->nullable();
            $table->string('severity', 40)->nullable();
            $table->string('potential_severity', 40)->nullable();

            $table->string('location', 200)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            // What was done in the first ten minutes. Captured on the incident rather than deferred
            // to the investigation because it is the one fact that is only knowable immediately,
            // and the investigation may not start for a week.
            $table->text('immediate_actions')->nullable();

            // The statutory half. `is_reportable` is a judgement somebody makes and must be able to
            // change; the date and reference are what proves they acted on it.
            $table->boolean('is_reportable')->default(false);
            $table->date('reported_to_authority_on')->nullable();
            $table->string('authority_reference', 120)->nullable();

            $table->boolean('is_lost_time')->default(false);
            $table->unsignedSmallInteger('lost_time_days')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // Restricted by default. See the class docblock — this default is a security control,
            // not a preference.
            $table->string('confidentiality', 20)->default('restricted');

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'category', 'severity']);
            // "How many reportable incidents are still open" is the board-level question.
            $table->index(['organization_id', 'is_reportable', 'status_id']);
        });

        /*
         * Who it happened to.
         *
         * A SEPARATE TABLE BECAUSE THE ACCESS QUESTION IS SEPARATE. Everything on `incidents` is
         * operational — where, when, how bad, what was done. Everything here is personal, and some
         * of it is medical. Splitting them means the safety manager who runs the register reads the
         * incident, and only somebody holding `hse_incident.view_confidential` reads the row that
         * names an injury (§6, §68).
         *
         * `user_id` IS NULLABLE AND `person_name` IS NOT, which is the right way round. Most people
         * hurt on a construction site do not have a login: they work for a subcontractor, or they
         * are a member of the public. A schema that could only record employees would quietly drop
         * the incidents that matter most.
         */
        Schema::create('incident_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_name', 200);
            $table->string('company', 200)->nullable();
            $table->string('occupation', 120)->nullable();

            // `hse_incident.involvements` — injured, witness, first aider, driver, supervisor.
            $table->string('involvement', 60)->nullable();

            // The medical detail. Nullable throughout: a witness has no injury, and a row that
            // demanded one would be filled in with nonsense.
            $table->string('injury_type', 120)->nullable();
            $table->string('body_part', 120)->nullable();
            $table->string('treatment', 60)->nullable();
            $table->unsignedSmallInteger('days_lost')->nullable();
            $table->date('returned_to_work_on')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'incident_id']);
            $table->index(['organization_id', 'user_id']);
        });

        /*
         * Why it happened.
         *
         * ONE INVESTIGATION PER INCIDENT, enforced in Investigation::spawnFrom() rather than by a
         * unique index — deliberately, and the reason is soft deletes. A unique index on
         * (organization_id, incident_id) would count a soft-deleted investigation, so an
         * investigation opened against the wrong incident and deleted would permanently block the
         * real one. The idempotence check in `spawnFrom()` ignores trashed rows, which is the
         * behaviour anybody would expect.
         *
         * IMMEDIATE CAUSE AND ROOT CAUSE ARE SEPARATE COLUMNS because separating them is the entire
         * discipline. "The operative did not clip on" is an immediate cause; a register that stops
         * there produces a corrective action that says "remind operatives to clip on", and the same
         * incident recurs. The root cause column is what forces the second question.
         */
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);

            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();

            // `investigation.methods` — five whys, fishbone, TapRooT, ICAM. Which one a company
            // uses is a house standard, and several use different ones by severity.
            $table->string('method', 60)->nullable();

            $table->date('started_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('completed_on')->nullable();

            $table->text('sequence_of_events')->nullable();
            $table->text('immediate_cause')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // Inherits the incident's classification at spawn time, and defaults restricted for the
            // same reason: an investigation quotes the medical detail it was opened over.
            $table->string('confidentiality', 20)->default('restricted');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'incident_id']);
            $table->index(['organization_id', 'lead_id', 'status_id']);
            $table->index(['organization_id', 'due_on']);
        });

        /*
         * What we are going to do about it.
         *
         * THE MOST IMPORTANT TABLE IN THE HSE MODULE, and the one most often reduced to a text field
         * on the incident. It is separate because a corrective action has its own owner, its own due
         * date, its own verification and its own life long after the incident is closed — and
         * because "what is outstanding against me" is a question people need answered across every
         * source at once, which a text field cannot answer at all.
         *
         * POLYMORPHIC SOURCE. Incident, investigation, observation, inspection, NCR, audit — six
         * today and more later. Six nullable foreign keys would be six more with every module.
         *
         * COMPLETION AND VERIFICATION ARE TWO EVENTS BY TWO PEOPLE. The assignee says it is done;
         * somebody else says it worked. Collapsing them into one "closed" flag is how a register
         * fills up with actions that were marked complete and changed nothing, and `effectiveness`
         * is the column that lets a company find out whether its corrective actions correct
         * anything.
         */
        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->nullableMorphs('source');

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // `corrective_action.types` — corrective, preventive, immediate containment.
            $table->string('action_type', 60)->nullable();
            $table->string('priority', 40)->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();

            $table->date('completed_on')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();

            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            // `corrective_action.effectiveness` — effective, partially effective, not effective.
            $table->string('effectiveness', 40)->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // The register's whole reason for existing: my open actions, oldest first.
            $table->index(['organization_id', 'assigned_to', 'status_id']);
            $table->index(['organization_id', 'due_on']);
            $table->index(['organization_id', 'project_id', 'status_id']);
        });

        /*
         * What somebody noticed before it happened.
         *
         * ANONYMITY IS A SCHEMA DECISION HERE, not a display one. When `is_anonymous` is set the
         * service leaves `observed_by` null instead of writing it and hiding it, because a
         * hidden-but-present column is a promise that lasts exactly until somebody with database
         * access goes looking — and the entire value of a near-miss programme rests on people
         * believing the promise. The cost is that an anonymous observation cannot be followed up
         * with its author, which is the trade every safety scheme worth running has already made.
         *
         * POSITIVE OBSERVATIONS BELONG IN THE SAME TABLE. `observation_type` covers safe behaviour
         * and good practice alongside unsafe acts and conditions, because a register that only ever
         * records failures teaches people that reporting is punishment.
         */
        Schema::create('hse_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // `hse_observation.types` and `.categories`.
            $table->string('observation_type', 60)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('risk_level', 40)->nullable();

            $table->string('location', 200)->nullable();
            $table->dateTime('observed_at')->nullable();

            // Null when anonymous, and null MEANS anonymous rather than "not filled in" — see the
            // docblock. `observer_name` carries a name for observers who have no login.
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observer_name', 200)->nullable();
            $table->boolean('is_anonymous')->default(false);

            $table->text('immediate_action')->nullable();
            $table->boolean('action_required')->default(false);

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'observation_type', 'risk_level'], 'hse_observations_org_type_risk_index');
            $table->index(['organization_id', 'observed_at']);
        });

        /*
         * What could go wrong, and what we have done about it.
         *
         * A RISK ASSESSMENT EXPIRES, which makes `valid_to` the most operationally important column
         * in the HSE module: an assessment that lapsed four months ago is worse than none, because
         * the work carried on under it. The register's default view is "expiring", not "all".
         *
         * ASSESSED / REVIEWED / APPROVED ARE THREE COLUMNS AND THREE PEOPLE. This is the one HSE
         * record where separation of duties is a legal expectation rather than a house rule, and
         * collapsing it to a single `signed_by` would make the document unusable as evidence.
         */
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // `risk_assessment.types` — task-based, COSHH, manual handling, environmental aspect.
            $table->string('assessment_type', 60)->nullable();

            $table->string('activity', 300)->nullable();
            $table->string('location', 200)->nullable();

            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assessed_on')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('review_frequency_months')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // The query the register exists for: what lapses this month.
            $table->index(['organization_id', 'valid_to']);
            $table->index(['organization_id', 'project_id', 'status_id']);
        });

        /*
         * The hazards on one assessment.
         *
         * SCORES ARE STORED, NOT COMPUTED IN THE QUERY. `risk_score` is likelihood × severity and
         * could be a generated column, but the register has to sort and filter on it on every page
         * and the matrix dimensions are configurable per tenant — a 5×5 in one company, 4×4 or 3×3
         * in another. Storing the product keeps the index usable and keeps the matrix a setting.
         *
         * RESIDUAL SCORES ARE THE POINT OF THE EXERCISE. An assessment that records only the raw
         * risk says what is dangerous; one that records the risk remaining after the controls says
         * whether the controls are enough, which is the question being asked.
         */
        Schema::create('risk_assessment_hazards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->text('hazard');
            $table->string('who_is_at_risk', 300)->nullable();
            $table->text('existing_controls')->nullable();

            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('severity')->nullable();
            $table->unsignedSmallInteger('risk_score')->nullable();

            $table->text('additional_controls')->nullable();
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_severity')->nullable();
            $table->unsignedSmallInteger('residual_score')->nullable();

            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'risk_assessment_id', 'sort_order'], 'risk_assessment_hazards_org_assessment_sort_index');
            $table->index(['organization_id', 'risk_score']);
        });

        /*
         * Going and looking.
         *
         * `score_achieved` / `score_possible` RATHER THAN A PERCENTAGE. A percentage cannot be
         * re-aggregated: averaging the percentages of a twelve-item inspection and a ninety-item
         * one gives a number that means nothing. Keeping both halves lets a company roll a month of
         * inspections into one honest figure, and lets an item be marked not-applicable without
         * distorting the result.
         */
        Schema::create('hse_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // `hse_inspection.types` — site walk, scaffold, lifting equipment, housekeeping audit.
            $table->string('inspection_type', 60)->nullable();
            $table->string('location', 200)->nullable();

            $table->date('scheduled_for')->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accompanied_by', 200)->nullable();

            $table->decimal('score_achieved', 8, 2)->nullable();
            $table->decimal('score_possible', 8, 2)->nullable();

            $table->text('summary')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'scheduled_for']);
        });

        /*
         * The checklist, one row per line.
         *
         * `result` IS A CONFIGURABLE LIST AND NOT A BOOLEAN, because "not applicable" is a real and
         * frequent answer and a boolean forces it to be recorded as a pass — which is how an
         * inspection scores 100% on items nobody looked at.
         */
        Schema::create('hse_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hse_inspection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('reference', 60)->nullable();
            $table->text('description');
            $table->string('category', 60)->nullable();

            // `hse_inspection.results` — pass, fail, not applicable, observation.
            $table->string('result', 40)->nullable();
            $table->string('severity', 40)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('action_required')->default(false);

            $table->decimal('score_achieved', 8, 2)->nullable();
            $table->decimal('score_possible', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'hse_inspection_id', 'sort_order'], 'hse_inspection_items_org_inspection_sort_index');
        });

        /*
         * Five minutes before the shift starts.
         *
         * THE ATTENDANCE LIST IS THE RECORD. The talk itself is a paragraph; what an inspector or a
         * court asks for is who was standing there, which is why attendees are a table and not a
         * number. `signed_at` is the attendee's own confirmation — the digital version of the sheet
         * everybody signs on the bonnet of a truck.
         */
        Schema::create('toolbox_talks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->string('topic', 200)->nullable();
            $table->text('content')->nullable();
            $table->text('key_points')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->dateTime('held_at')->nullable();
            $table->string('location', 200)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->foreignId('presented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('presenter_name', 200)->nullable();

            // The handout, if there is one. Lives in document control like every other file (§67).
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'held_at']);
        });

        Schema::create('toolbox_talk_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('toolbox_talk_id')->constrained()->cascadeOnDelete();

            // Same shape as `incident_persons`: most attendees on a site are not users of this
            // system, and a schema that could only record employees would record the wrong list.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_name', 200);
            $table->string('company', 200)->nullable();
            $table->string('trade', 120)->nullable();

            $table->dateTime('signed_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'toolbox_talk_id']);
            $table->index(['organization_id', 'user_id']);
        });

        /*
         * The permit catalogue — configuration, per tenant.
         *
         * A refinery's permit set is not a fabrication shop's and neither is a hospital estates
         * department's. Hot work, confined space, excavation, work at height, energised electrical,
         * radiography: which of these exist, what precautions they carry by default and whether
         * they demand a gas test are all decisions a safety manager makes on a settings screen (§73).
         */
        Schema::create('permit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 30);
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();

            $table->unsignedSmallInteger('default_duration_hours')->nullable();
            $table->boolean('requires_gas_test')->default(false);
            $table->boolean('requires_isolation')->default(false);

            // The precautions this type starts with, copied onto each permit at creation. Copied
            // rather than referenced, so editing the template never rewrites history on a permit
            // somebody already signed.
            $table->json('precaution_template')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        /*
         * Permission to do something dangerous, for a stated period.
         *
         * A PERMIT IS THE ONLY RECORD IN THIS APPLICATION THAT EXPIRES BY THE CLOCK. `valid_to` is
         * not a target date; it is the moment the authorisation stops, and work continuing past it
         * is the finding. That is why the register's default view is "live now" and why the model
         * exposes `hasExpired()` separately from `isOverdue()`.
         *
         * FOUR SIGNATURES, FOUR COLUMNS. Requested, issued, accepted, closed — by four people, and
         * frequently a fifth cancels or suspends it. Every one of them is a different act with a
         * different authority behind it, and the reason permits are the most heavily separated
         * record here is that the person who wants the work done must never be the person who
         * authorises it.
         */
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('permit_type_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->string('location', 200)->nullable();
            $table->string('equipment', 200)->nullable();
            $table->unsignedSmallInteger('worker_count')->nullable();

            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            // The performing supervisor takes the permit on. Distinct from issuing: one grants the
            // authority, the other accepts responsibility for working within it.
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_notes')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->boolean('gas_test_required')->default(false);
            $table->string('gas_test_result', 200)->nullable();
            $table->dateTime('gas_tested_at')->nullable();
            $table->foreignId('gas_tested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('isolation_reference', 120)->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // "What is live on site right now" — the query a control room refreshes all day.
            $table->index(['organization_id', 'status_id', 'valid_to']);
            $table->index(['organization_id', 'project_id', 'valid_from']);
            $table->index(['organization_id', 'permit_type_id']);
        });

        /*
         * The precautions on one permit, each individually confirmed.
         *
         * ONE ROW PER PRECAUTION RATHER THAN A TICKED-BOX BLOB, because the question after an
         * incident is never "was the checklist completed" — it is "who confirmed that the space had
         * been purged, and when". `confirmed_by` and `confirmed_at` are the answer, per line.
         */
        Schema::create('permit_precautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->text('description');
            $table->boolean('is_required')->default(true);

            $table->boolean('is_confirmed')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'permit_id', 'sort_order']);
        });

        /*
         * What training exists.
         *
         * `validity_months` NULLABLE, AND NULL MEANS "NEVER EXPIRES" rather than "unknown". An
         * induction does not lapse; a confined-space ticket lapses in three years. Defaulting to a
         * number would put a false expiry on the first kind, and defaulting to null-as-unknown
         * would make the expiry report unable to say anything at all.
         */
        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('code', 40);
            $table->text('description')->nullable();

            // `hse_training.categories` — induction, statutory, competency, refresher.
            $table->string('category', 60)->nullable();

            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->string('provider', 200)->nullable();
            $table->decimal('duration_hours', 6, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'category']);
        });

        /*
         * Who holds what, and until when.
         *
         * `expires_on` IS COMPUTED AT COMPLETION AND THEN STORED, not derived on read from the
         * course's validity. The course's validity changes — a certification body extends a ticket
         * from two years to three — and re-deriving would silently move the expiry date on every
         * certificate ever issued, including ones already lapsed. The date somebody's card actually
         * carries is a fact about that card.
         */
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('training_course_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_name', 200)->nullable();
            $table->string('company', 200)->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->date('completed_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->string('certificate_number', 120)->nullable();
            // The certificate itself, in document control — never a second file store (§67).
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->string('result', 40)->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('provider', 200)->nullable();
            $table->string('trainer_name', 200)->nullable();
            $table->decimal('cost', 12, 2)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // "Whose tickets lapse in the next 60 days" — the only report this register is opened for.
            $table->index(['organization_id', 'expires_on']);
            $table->index(['organization_id', 'user_id', 'training_course_id'], 'training_records_org_user_course_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_records');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('permit_precautions');
        Schema::dropIfExists('permits');
        Schema::dropIfExists('permit_types');
        Schema::dropIfExists('toolbox_talk_attendees');
        Schema::dropIfExists('toolbox_talks');
        Schema::dropIfExists('hse_inspection_items');
        Schema::dropIfExists('hse_inspections');
        Schema::dropIfExists('risk_assessment_hazards');
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('hse_observations');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('investigations');
        Schema::dropIfExists('incident_persons');
        Schema::dropIfExists('incidents');
    }
};
