<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the global permission catalogue into the database.
 *
 * Safe to re-run on every deployment — that is the point. New permissions appear, changed labels
 * update, and permissions removed from the catalogue are deleted along with their grants, so a
 * retired capability cannot linger as an orphaned checkbox that still resolves.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = PermissionCatalogue::definitions();
        $now = now();
        $sort = 0;

        $rows = array_map(function (array $definition) use ($now, &$sort) {
            return [
                'key' => $definition['key'],
                'module' => $definition['module'],
                'action' => $definition['action'],
                'label' => $definition['label'],
                'group' => $definition['group'],
                'is_sensitive' => $definition['is_sensitive'],
                'sort' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $definitions);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('permissions')->upsert(
                $chunk,
                ['key'],
                ['module', 'action', 'label', 'group', 'is_sensitive', 'sort', 'updated_at'],
            );
        }

        $retired = DB::table('permissions')
            ->whereNotIn('key', PermissionCatalogue::keys())
            ->pluck('id');

        if ($retired->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $retired)->delete();
            DB::table('permissions')->whereIn('id', $retired)->delete();
        }

        $this->command?->info(sprintf(
            'Permission catalogue synced: %d permissions across %d modules%s.',
            count($rows),
            count(PermissionCatalogue::modules()),
            $retired->isNotEmpty() ? ', '.$retired->count().' retired' : '',
        ));
    }
}
