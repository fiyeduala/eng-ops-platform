<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-defined vocabulary that is not a status (§63, §73).
 *
 * A status is the STATE of a record — it decides what may happen next, it is gated by transitions
 * and it drives workflow. A great deal of what a company needs to write down is not that: the
 * disciplines it works in, the reasons a change request gets raised, the grades of concrete it
 * pours, the client's own document classes. Those are plain lists of words. Hard-coding them puts
 * one company's business in another's mouth, and modelling each as a table means a developer for
 * every new one.
 *
 * So: a list of lists. An administrator creates "Disciplines", fills it with the seven their
 * company actually uses, and every dropdown custom field that names that list offers exactly those
 * seven — on any register, without a migration.
 *
 * TWO COLUMNS WHERE ONE WOULD SEEM TO DO, and it is the most important decision here. `value` is
 * what records STORE and `label` is what people READ. They are separate because the day a company
 * renames "Mechanical" to "Mechanical & Piping" must not be the day every record that stored it
 * becomes unreadable. `value` is immutable once anything references it; `label` is editable
 * forever. Collapsing them would make every rename a silent data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The stable handle. Custom fields reference a list BY KEY rather than by id for the
            // same reason statuses do: a company exporting its configuration and importing it into
            // a second tenant carries meaning, not primary keys.
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // A list the platform itself provisions and whose key code may name out loud. Same
            // contract as `statuses.is_system`: renameable, not rekeyable, not deletable.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('option_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_list_id')->constrained()->cascadeOnDelete();

            $table->string('value', 64);
            $table->string('label', 160);

            // A design-system token name, never raw CSS — the same rule as statuses.color, and for
            // the same reason: configuration must not be able to inject styling into a page.
            $table->string('color', 24)->nullable();

            $table->unsignedSmallInteger('sort')->default(0);

            // RETIRING, NOT DELETING, is the normal end of an option's life. An option that records
            // already carry cannot be removed without making those records unreadable, but a
            // company that has stopped using "Asbestos survey" still needs it to stop appearing in
            // new dropdowns. `is_active` is that distinction, and it is why deletion can afford to
            // be strict.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['option_list_id', 'value']);
            $table->index(['organization_id', 'option_list_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_list_items');
        Schema::dropIfExists('option_lists');
    }
};
