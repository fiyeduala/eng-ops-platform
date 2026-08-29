<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Two-tier seeding.
 *
 * PermissionSeeder is REQUIRED in every environment including production — the permission
 * catalogue is application structure, not sample data, and a system without it grants nothing to
 * anyone because the resolver fails closed.
 *
 * DemoSeeder is sample data and never runs in production. It is skipped by default there and
 * requires an explicit --force-demo to override, because seeding fictional employees into a live
 * tenant is not a mistake anyone should be able to make by typing db:seed.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        if (app()->isProduction() && ! $this->command?->option('force') ) {
            $this->command?->warn('Production environment: demo data skipped. Use --force to override.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
