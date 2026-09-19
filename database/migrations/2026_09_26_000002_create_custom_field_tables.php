<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields a company adds to a register without a developer (§73).
 *
 * Every engineering company records something nobody else does — a client's cost code, a
 * concession number, the rig a job belongs to. A platform that cannot accept those is a platform
 * that gets used alongside a spreadsheet, and the spreadsheet is where the real data ends up.
 *
 * ENTITY-ATTRIBUTE-VALUE, WITH ITS EYES OPEN. The alternative — a JSON column on every register —
 * is tempting and loses three things this design keeps: a value cannot be indexed or filtered
 * portably inside JSON on MySQL 5.7 (§cPanel-first), a field that is deleted leaves its orphaned
 * data invisible rather than removable, and there is nowhere to hang the per-value audit that §59
 * expects. The cost is a join, paid once per record load and eager-loaded in one query (§89).
 *
 * VALUES ARE STORED AS TEXT, DELIBERATELY. A `value_number`/`value_date`/`value_text` triplet
 * looks tidier and then every query has to know which column to read, every field-type change
 * becomes a data migration, and a value that was a date last month is stranded in a column nobody
 * queries. One text column, cast on the way out by the field's declared type, keeps the type in
 * configuration where it belongs. Sorting and range filters cast in SQL; that is the price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The module key — 'project', 'hse_incident' — the same string the permission
            // catalogue and the status tables use. Never a class name.
            $table->string('entity_type', 64);

            // What code and exports call this field. Immutable once values exist: rekeying would
            // orphan every value ever stored against it.
            $table->string('key', 64);
            $table->string('label', 120);

            // 'text', 'textarea', 'number', 'date', 'boolean', 'select', 'multiselect'. Validated
            // against CustomField::TYPES rather than a database enum, because a company adding a
            // field must never require an ALTER TABLE.
            $table->string('type', 24);

            // Only for select/multiselect. A soft reference by key rather than a foreign key: a
            // field may be defined before the list it names, and a list deleted out from under a
            // field must leave a field that says so rather than a row that vanished.
            $table->string('option_list_key', 64)->nullable();

            $table->string('help', 255)->nullable();
            $table->string('placeholder', 120)->nullable();

            // Stored as text for the same reason values are. Applied only when a record is
            // created, never retrospectively — back-filling a default would invent data on
            // records that predate the field.
            $table->text('default_value')->nullable();

            $table->boolean('is_required')->default(false);

            // WHETHER A FIELD IS REQUIRED IS NOT WHETHER IT MAY BE MADE REQUIRED. Turning this on
            // for a register that already holds a thousand records makes every one of them invalid
            // the next time somebody edits it — see CustomFieldService, which refuses unless the
            // field also carries a default.
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('sort')->default(0);

            // Optional narrowing: a field that only applies to one kind of project. Null means the
            // field applies to every record of its entity type.
            $table->string('applies_when_key', 64)->nullable();
            $table->string('applies_when_value', 120)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'key']);
            $table->index(['organization_id', 'entity_type', 'sort']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();

            // A morph pair rather than a column per register: the whole point is that a field can
            // be attached to any module, including ones built after this migration.
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            $table->text('value')->nullable();

            $table->timestamps();

            // One value per field per record. The unique key is what makes writing a value an
            // upsert rather than a read-then-write race.
            $table->unique(['custom_field_id', 'subject_type', 'subject_id'], 'custom_field_values_unique');

            // The eager-load path: every value for a page of records, one query (§89).
            $table->index(['subject_type', 'subject_id']);

            // The filter path: "every record whose cost-code field says X". `value` itself is
            // deliberately NOT indexed — it is TEXT, so MySQL would demand a prefix length that
            // SQLite ignores, and an index that only exists in production is not protection. The
            // narrowing that matters is the field, which is indexed here; a filter then scans the
            // values of one field rather than of every field in the company.
            $table->index(['organization_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
