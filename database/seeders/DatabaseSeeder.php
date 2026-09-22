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
 * DemoSeeder is sample data and NEVER runs in production. Not "unless you ask", not "unless you
 * pass a flag" — never, from here.
 *
 * THIS GUARD USED TO READ `--force`, AND THAT WAS EXACTLY BACKWARDS. `--force` is Laravel's
 * "yes, I know this is production, skip the confirmation prompt" flag: it is the flag an operator
 * MUST pass to seed anything at all on a live install, and `docs/12` tells them to. Reading it as
 * consent to demo data meant the documented production command would have loaded Meridian
 * Engineering and its fictional staff into a real company's database — the guard inverting under
 * precisely the conditions it existed for.
 *
 * There is deliberately no flag that re-enables it either. A developer who genuinely wants the
 * sample tenant on a production-flagged environment can name the class:
 * `php artisan db:seed --class=DemoSeeder --force`. That is a sentence somebody has to mean, and it
 * cannot be arrived at by typing the deployment instructions.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Required everywhere including production: the permission catalogue is application
        // structure, not sample data, and the resolver fails closed without it.
        $this->call(PermissionSeeder::class);

        if (app()->isProduction()) {
            $this->command?->warn(
                'Production environment: demo data skipped. '
                .'To load it anyway, name it: db:seed --class=DemoSeeder'
            );

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
