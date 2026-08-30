<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification engine's storage (docs/10).
 *
 * Laravel ships a `notifications` table of its own, and this deliberately is not it. The framework
 * table stores an opaque JSON blob keyed by a class name, which makes three things this system
 * needs impossible without decoding every row: filtering a person's bell by module, counting unread
 * work per project, and — the one that matters — answering the §68 question after the fact,
 * "who was told about this record, and could they see it at the time".
 *
 * So the columns are real columns. `subject_type`/`subject_id` name the record the notification is
 * ABOUT, which is the thing NotificationDispatcher authorized against before writing the row. That
 * link is what makes the in-app list re-checkable at read time as well as at send time: a person
 * who loses access to a project between Tuesday and Thursday stops seeing Tuesday's notification.
 *
 * `title` and `body` are stored already-minimised. A notification about a confidential record
 * carries its number and nothing else (docs/10 §1), because the bell is rendered from these strings
 * and an email is sent from them — by the time a row exists, the decision about how much to say has
 * already been made and must not be re-made by a view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The recipient. One row per person: a notification "to the project team" is expanded
            // by the dispatcher into rows, because each person's authorization was asked
            // separately and each person marks their own as read.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Catalogue key — `task.assigned`, `memo.issued`. Preferences, grouping and the module
            // filter all hang off this, so it is a plain string rather than a class name: renaming
            // a PHP class must not silently orphan everybody's preferences.
            $table->string('key', 80);
            $table->string('urgency', 20)->default('normal'); // normal | high | urgent

            $table->string('title', 200);
            $table->text('body')->nullable();

            // A relative path, or null. Null means "there is nothing you may open" — the dispatcher
            // writes null rather than a link it knows the recipient would be refused, and the route
            // behind it re-authorizes on click anyway (docs/10 §1).
            $table->string('action_url', 300)->nullable();

            // What this is ABOUT. Nullable because account-level notices (password reset) are about
            // nothing but the person.
            //
            // nullableMorphs() indexes (subject_type, subject_id) itself, which is the index the
            // §68 audit question needs — "who was told about this record" — so there is no second
            // one below.
            $table->nullableMorphs('subject');

            // Who caused it. Nullable for system-generated notices — an expiry watch has no actor.
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalised from the subject so the bell can group by project without a morph join.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell's query, exactly: this person, in this tenant, unread first.
            $table->index(['organization_id', 'user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // No foreign key: the catalogue lives in PHP, and a key removed from it must leave the
            // row harmlessly ignored rather than break a delete.
            $table->string('notification_key', 80);

            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('push')->default(false);

            // Roll this key's email into the digest instead of sending it as it happens.
            $table->boolean('digest')->default(false);

            $table->timestamps();

            // One row per person per key. Absence means "the catalogue default", so a person who
            // never opens the preferences screen has no rows at all.
            $table->unique(['user_id', 'notification_key'], 'notification_preferences_unique');
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
