<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memos, announcements and meetings — the three ways this platform says something to people
 * (§17, §18, §19).
 *
 * They look similar and are deliberately not the same shape, because they answer three different
 * questions:
 *
 *   A MEMO is addressed. It has named recipients, it carries confidentiality, and being on the
 *   distribution is a reach grant — `memo.view:assigned` is a real seeded permission, so writing a
 *   `memo_recipients` row hands that account sight of the record, exactly as `tasks.assignee_id`
 *   does. That is why MemoPolicy asks about the person as well as the memo.
 *
 *   An ANNOUNCEMENT is broadcast. It has an AUDIENCE, not recipients, and it carries NO
 *   confidentiality column at all. That absence is the design: an announcement is a noticeboard,
 *   and a noticeboard whose contents are secret from some of the people standing in front of it is
 *   a memo wearing the wrong name. Anything that must be withheld from part of the company is a
 *   memo. Narrowing an announcement to a department or a project is therefore ROUTING — it decides
 *   whose board it appears on, not who is permitted to read it.
 *
 *   A MEETING is attended. Its participants are a reach grant like a memo's recipients, its
 *   minutes are a second document living inside the first, and its action items are ordinary Tasks
 *   pointed back at it through `tasks.linked_type`/`linked_id` rather than a private to-do list
 *   nobody else's queries can see (§90). A meeting that produces work produces WORK — the same
 *   record type the assignee already sees on their task list, in their notifications and on the
 *   calendar.
 *
 * `issued_at` and `published_at` are the two publication columns in the system. They are declared
 * to the access engine through Scopeable::accessPublicationColumn(), which adds one predicate to
 * every compiled query: a draft is reachable by its author, by an explicit share, and by whoever
 * holds the ability to issue — and by nobody else, however wide their ordinary reach. See
 * ScopeCompiler::applyPublication().
 *
 * Meetings have no such column on purpose. A scheduled meeting is real the moment it is scheduled;
 * there is no state in which it exists but must not be seen by the people expected to attend it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------------------- memos

        Schema::create('memos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // MEMO/2026/001, from the tenant's own scheme (§64).
            $table->string('number', 64);
            $table->string('subject', 200);
            $table->text('body');

            // The two axes, same column names the access engine already looks for. A memo from the
            // Engineering department about nothing in particular has a unit and no project; a memo
            // about Project Alpha has both.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // Gate 5. A memo is the one communication that can be genuinely sensitive — a
            // disciplinary letter, a commercial instruction — so unlike an announcement it carries
            // the full confidentiality vocabulary.
            $table->string('confidentiality', 20)->default('internal');

            // THE PUBLICATION COLUMN. Null means draft, and a draft is invisible to everyone but
            // its author, an explicit share, and the holders of `memo.issue`. Stamped structurally
            // by MemoService on the first move away from the initial status — never keyed off a
            // status key, because a company that renames "Issued" must not lose the semantics.
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            // Why it was pulled back. Recorded because withdrawing an issued memo is a statement
            // about a document people have already acted on, and "it disappeared" is not one.
            $table->string('withdrawn_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'issued_at']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'org_unit_id']);
        });

        // The distribution list. Every row here is a visibility grant, which is why adding one has
        // its own permission and its own two-question policy rather than folding into memo.edit.
        Schema::create('memo_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('memo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // to | cc — the distinction people expect from a memo header. It carries no
            // authorization difference: both reach the record, because both were told.
            $table->string('kind', 8)->default('to');

            // When this person opened it. The point of a memo over an email is that the issuer can
            // answer "who has seen this", which no mail server will tell them.
            $table->timestamp('read_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['memo_id', 'user_id']);
            $table->index(['organization_id', 'user_id', 'read_at']);
        });

        // ----------------------------------------------------------------------- announcements

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('number', 64);
            $table->string('title', 200);
            $table->text('body');

            // The AUDIENCE, not an access boundary. Both null means the whole company; one set
            // means that unit's subtree or that project's team. See the class docblock, and
            // Announcement::scopeAddressedTo() for how it is read.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // No `confidentiality` column, on purpose. See the class docblock.

            // The publication column. Same rules as a memo's issued_at.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // Comes off the board on its own. Notices about a shutdown that ended last March are
            // how a noticeboard stops being read.
            $table->date('expires_on')->nullable();

            $table->boolean('is_pinned')->default(false);
            $table->string('urgency', 16)->default('normal');   // normal | high | urgent

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'published_at']);
            $table->index(['organization_id', 'org_unit_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'expires_on']);
        });

        // ---------------------------------------------------------------------------- meetings

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('number', 64);
            $table->string('title', 200);
            $table->text('agenda')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            // Where and when. `location` is free text rather than a foreign key to `locations`
            // because half of all meetings happen in "Meeting Room 2" and the other half in a
            // video call, and forcing both through a site record helps neither.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location', 200)->nullable();
            $table->string('meeting_url', 500)->nullable();

            // The organizer is an owner column, so a person always reaches the meetings they
            // called even with no other grant.
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();

            // The minutes: a second document inside the first. Held on the meeting rather than in
            // its own table because minutes without their meeting are not a record of anything,
            // and one-to-one tables are a join nobody thanks you for.
            $table->longText('minutes')->nullable();
            $table->foreignId('minutes_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('minutes_recorded_at')->nullable();

            // Distinct from recording them. Writing minutes is secretarial; circulating them is
            // publishing a record other people will rely on, and it has its own permission.
            $table->timestamp('minutes_circulated_at')->nullable();

            $table->string('cancelled_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'org_unit_id']);
        });

        // Attendance list and reach grant, in one table — the same double duty project_members
        // does for projects.
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // chair | secretary | participant | optional. The secretary is the one expected to
            // write the minutes; the role is recorded so the meeting page can say so, not because
            // it grants anything — permissions do that.
            $table->string('role', 24)->default('participant');

            // Null until somebody records attendance, which is not the same as absent. A meeting
            // held with three people where the register was never taken must not read as a meeting
            // twelve people failed to attend.
            $table->boolean('attended')->nullable();

            $table->string('apologies', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });

        // What the meeting DECIDED, as distinct from what it discussed.
        //
        // A decision is not a task: it has no assignee and nothing to complete. "We will use
        // grade 60 rebar" is a fact the project is now built on, and it has to be findable years
        // later without reading the whole minutes. Work arising is a Task pointed at the meeting.
        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('decision', 500);
            $table->text('rationale')->nullable();

            // Who carries it afterwards. Not an assignee — there is nothing to do — but the person
            // to ask about it.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['meeting_id', 'sequence']);
            $table->index(['organization_id', 'meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_decisions');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('memo_recipients');
        Schema::dropIfExists('memos');
    }
};
