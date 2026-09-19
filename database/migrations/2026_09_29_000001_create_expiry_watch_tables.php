<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform watches for a company, and how far ahead it speaks up (§73).
 *
 * ELEVEN DATES, TWO WARNINGS. Before this table the platform warned about maintenance falling due
 * and vehicle paperwork lapsing, both hard-coded in a service. Meanwhile an HSE permit has a
 * `valid_to`, a training record an `expires_on`, a vendor a `prequalification_expires_on`, a
 * document a `next_review_on`, a contract an `end_date` — none of which told anybody anything. The
 * asymmetry was not a decision; it was two modules whose authors happened to write a sweep. A
 * company should be able to say "warn me about these" without a developer, including about a
 * register built after this migration.
 *
 * A WATCH IS CONFIGURATION, NOT STATE. Nothing here records that a warning was sent, and that is
 * deliberate — see ExpirySweeper, which writes nothing at all. No `is_overdue` column, no expiry
 * status, nothing anywhere that depends on the sweep having run. A dead cron costs a company its
 * REMINDERS and can never make a lapsed insurance certificate look valid. Storing "last warned at"
 * here would quietly break that: the next run would consult a row that a failed run never wrote.
 *
 * MILESTONES RATHER THAN A WINDOW, and this is the one place the generic engine deliberately
 * improves on the two sweeps it replaces. Those warned every single day for the whole window — a
 * sixty-day horizon meant sixty identical e-mails about one certificate, which is how a company
 * learns to filter the warnings it most needs. A watch instead names the days it speaks on: thirty
 * out, seven out, the day before. Silence in between is the feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expiry_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The module key — 'permit', 'hse_training' — the same string the status tables, the
            // custom fields and the workflow definitions use. Never a class name.
            $table->string('entity_type', 64);

            // The date column being watched, e.g. 'valid_to'. Checked on save against the fields
            // the model itself declares watchable, never against whatever was posted: a column
            // name arriving from a select box is a column name from the browser, and one that
            // reached a query builder unchecked would be a way to ask about any column at all.
            $table->string('date_field', 64);

            // What the company calls this watch. Shown on the settings screen and in the warning
            // itself, because "Permit expiring" said by a company that calls them work clearances
            // is the platform talking over them.
            $table->string('name', 120);

            // The days before the date on which this watch speaks. A JSON list rather than a single
            // window: see the class docblock. [30, 7, 1] means three e-mails, not thirty-eight.
            $table->json('lead_days');

            // Past the date, every day, until somebody deals with it. Off by default because most
            // expiries are administrative and a daily e-mail about a lapsed quotation is noise —
            // on for the ones where the record going stale is the actual hazard: an uninsured
            // truck, an expired permit to work, a training certificate a site will be audited on.
            $table->boolean('warn_when_overdue')->default(false);

            // Who hears about it, beyond the people already following the record. Soft references
            // by id in a JSON list rather than pivot tables: a watch names an audience, it does not
            // own one, and a role deleted out from under a watch must leave a watch that reaches
            // fewer people rather than a foreign key violation on an unrelated screen.
            //
            // NAMING SOMEBODY HERE DOES NOT MEAN THEY WILL BE TOLD. Every recipient is still put
            // through the notification engine's gates, which drop anybody who could not open the
            // record itself (§68). A watch on a confidential document therefore reaches fewer
            // people than this list suggests, silently and correctly.
            $table->json('notify_role_ids')->nullable();
            $table->json('notify_user_ids')->nullable();

            // The two audiences that cannot be named by id because they differ per record: whoever
            // the record belongs to, and whoever asked to follow it.
            $table->boolean('notify_owner')->default(true);
            $table->boolean('notify_followers')->default(true);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One watch per date per module per company. A second watch on the same column would
            // be two e-mails about one certificate, which is the problem this table exists to fix.
            $table->unique(['organization_id', 'entity_type', 'date_field'], 'expiry_watches_unique');

            // The sweep's path: every active watch for one company, in one query.
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_watches');
    }
};
