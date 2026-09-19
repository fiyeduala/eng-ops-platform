<?php

declare(strict_types=1);

use App\Support\StatusCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which statuses the CODE depends on, as opposed to the ones a company invented.
 *
 * PHASE 7 OPENS THE STATUS VOCABULARY TO ADMINISTRATORS, and this column is what stops that being
 * a way to break payroll from a settings screen.
 *
 * `RegisterService::moveTo()` finds a status by KEY among the ACTIVE ones and silently does nothing
 * when it cannot: 'a company that removed "answered" from its vocabulary has said it does not use
 * it'. That reasoning is right for an optional status and catastrophic for a load-bearing one. An
 * administrator who renames the key of `approved` on payroll_run, or merely unticks Active, gets a
 * platform where the workflow engine approves a run and the record silently stays at Submitted —
 * the money is authorised and the register disagrees, with no error anywhere.
 *
 * The keys in StatusCatalogue are therefore not decoration. Service code says moveTo($run,
 * 'approved') as a string literal, which no amount of runtime introspection can discover, so the
 * dependency has to be recorded at the point where it is still known: here.
 *
 * WHAT A COMPANY MAY STILL DO to a system status is everything §73 actually promises — rename its
 * LABEL ('Approved' becomes 'Authorised'), recolour it, reorder it. What it may not do is change
 * the key, delete it, or deactivate it, because those three are the ones that reach into code.
 * Statuses a company adds itself carry no such dependency and stay fully editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        // Backfill from the same catalogue the provisioner seeds from, so an existing tenant ends
        // up in exactly the state a new one is created in. Anything a company added itself is not
        // in the catalogue and correctly stays false.
        foreach (StatusCatalogue::all() as $entityType => $definition) {
            $keys = array_keys($definition['statuses']);

            if ($keys === []) {
                continue;
            }

            DB::table('statuses')
                ->where('entity_type', $entityType)
                ->whereIn('key', $keys)
                ->update(['is_system' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
