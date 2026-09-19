<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carry every company that was already being warned onto the watch it is now warned by.
 *
 * WITHOUT THIS THE UPGRADE IS SILENT DATA LOSS OF THE WORST KIND — the kind nothing reports. The
 * previous release swept two dates unconditionally: vehicle document expiry and maintenance due
 * dates, each with a per-company warning window. This release deletes that sweep in favour of watch
 * rows, and a company that upgrades with no rows simply stops being warned. No error, no empty
 * screen, no failed job. The first anybody hears of it is an uninsured truck at a checkpoint.
 *
 * THE COMPANY'S OWN WINDOW IS PRESERVED, not replaced by the seed defaults. A fleet that set sixty
 * days set it for a reason, so the configured number becomes the outermost milestone and the
 * standard inner ones are those that fit inside it. What does change is the CADENCE: the old sweep
 * warned every day for the whole window, so a sixty-day setting meant sixty identical e-mails about
 * one certificate. That was not a feature anybody chose either, and milestones are the fix.
 *
 * RAW QUERIES, NO MODELS. A migration that boots Eloquent gets the tenant scope, the morph map, the
 * audit engine and the policy guard along with it, all of which are the application as it exists
 * TODAY rather than as it existed when the migration was written. This file names its own columns
 * and will still mean the same thing in two years.
 *
 * IRREVERSIBLE BY DESIGN: see down().
 */
return new class extends Migration
{
    /**
     * The two the old sweep covered, and only those.
     *
     * Everything else a new company now gets — permits, training, vendor prequalification, document
     * review — is deliberately NOT backfilled here. Those were never being warned about, so adding
     * them silently on upgrade would mean an existing company's staff arriving one morning to
     * warnings about four registers nobody asked to be warned about, from a screen they have not
     * been told exists. New watches are an invitation, not a surprise; the screen offers them.
     */
    private const CARRIED_OVER = [
        ['setting' => 'vehicle_document.expiry_warning_days', 'default' => 60,
            'entity_type' => 'vehicle_document', 'date_field' => 'expires_on',
            'name' => 'Vehicle document expiry', 'overdue' => true],
        ['setting' => 'asset.maintenance_warning_days', 'default' => 30,
            'entity_type' => 'asset_maintenance', 'date_field' => 'due_on',
            'name' => 'Maintenance due', 'overdue' => false],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $organizationId) {
            foreach (self::CARRIED_OVER as $carried) {
                $exists = DB::table('expiry_watches')
                    ->where('organization_id', $organizationId)
                    ->where('entity_type', $carried['entity_type'])
                    ->where('date_field', $carried['date_field'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $configured = DB::table('settings')
                    ->where('organization_id', $organizationId)
                    ->where('key', $carried['setting'])
                    ->value('value');

                $window = is_numeric($configured) ? (int) $configured : $carried['default'];

                DB::table('expiry_watches')->insert([
                    'organization_id' => $organizationId,
                    'entity_type' => $carried['entity_type'],
                    'date_field' => $carried['date_field'],
                    'name' => $carried['name'],
                    'lead_days' => json_encode($this->milestonesWithin($window)),
                    'warn_when_overdue' => $carried['overdue'],
                    'notify_role_ids' => json_encode([]),
                    'notify_user_ids' => json_encode([]),
                    'notify_owner' => true,
                    'notify_followers' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * The company's window, then the usual staging posts inside it.
     *
     * The configured number always survives as the first warning, so nobody is told later than they
     * were being told before. A company on a seven-day window gets [7, 3, 1] rather than the
     * platform's thirty-day opinion imposed on it.
     *
     * @return array<int,int>
     */
    private function milestonesWithin(int $window): array
    {
        $window = max(1, min(730, $window));

        $days = array_values(array_unique(array_filter(
            [$window, 30, 14, 7, 3, 1],
            fn (int $day) => $day <= $window && $day >= 1,
        )));

        rsort($days);

        // Six is what ExpiryWatch::MAX_LEAD_DAYS allows, and more than anybody reads anyway.
        return array_slice($days, 0, 6);
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would have to decide which watches were created here and which an administrator
     * has since made, edited or come to depend on — and the two are indistinguishable by then. The
     * schema rollback in the migration before this one drops the table outright, which is the honest
     * way to undo this; deleting rows by guesswork is not.
     */
    public function down(): void {}
};
