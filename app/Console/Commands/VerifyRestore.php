<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Document\FileVault;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Is this restored copy actually usable? (§85)
 *
 * THE DECISION THIS COMMAND SETTLES. docs/12 left §85 open: does a backup **record** belong inside
 * the application? It does not, and this is the file that says why by being the thing built
 * instead.
 *
 * A backup engine in here would be a lie with a green tile on it. The dump is written by cPanel's
 * scheduler, the offsite copy is pushed by the host, and the file archive is somebody's rsync —
 * none of which this application starts, observes or can fail. A `backup_runs` table would record
 * only what somebody remembered to tell it, and a dashboard reading from it would say "last backup:
 * 6 hours ago" with exactly as much authority as the last person to type that in. A platform that
 * claims to be watching a backup it is not running is worse than one that says nothing, because the
 * first one stops anybody else from checking.
 *
 * WHAT IT CAN HONESTLY DO is the other half of §85: *an untested backup is not a backup*. Restoring
 * is testable, because a restored database is right here and can be interrogated. So this command
 * answers one question — if this is what you have left, can a company work from it? — and it
 * answers it about the copy in front of it, not about a schedule it cannot see.
 *
 * WHY `DB::table()` AND NOT ELOQUENT. Every model in this system carries the tenant global scope,
 * which needs a resolved organization, which needs a request or an explicit context. There is
 * neither on a scratch database somebody has just loaded a dump into. Worse, a scope silently
 * narrowing these counts is the exact failure mode that would make a bad restore look clean. The
 * queries here are deliberately below the application.
 *
 * WHAT IT CANNOT TELL YOU, stated on the page rather than buried here: whether a backup ran, when,
 * whether it went offsite, or whether the copy is recent. Nothing in this process can see any of
 * that, and pretending otherwise is the failure this whole file is arguing against.
 */
class VerifyRestore extends Command
{
    protected $signature = 'engops:verify-restore
                            {--files=500 : How many stored files to spot-check; 0 checks every one}';

    protected $description = 'Check whether the database this is pointed at is a usable restore';

    /**
     * Tables holding a path to something that exists nowhere else.
     *
     * NOT `exports.path` and not `users.avatar_path`. An export is a rendering of rows that are
     * still in the database and can simply be run again; an avatar is a convenience. These two are
     * the ones with no source: a signed drawing revision and a vehicle's insurance certificate are
     * the file, and if the archive and the dump were taken hours apart, this is where that shows.
     *
     * @var array<string,string>
     */
    private const FILE_TABLES = [
        'document_versions' => 'file_path',
        'vehicle_documents' => 'file_path',
    ];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('  Verifying the database at <options=bold>'.$this->connectionLabel().'</>');
        $this->line('');

        if (! $this->databaseAnswers()) {
            return self::FAILURE;
        }

        $this->checkSchema();
        $this->checkPermissionCatalogue();
        $this->checkEveryCompanyCanBeAdministered();
        $this->checkStoredFiles();

        return $this->verdict();
    }

    // ------------------------------------------------------------------ checks

    private function databaseAnswers(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable $e) {
            $this->error('  Cannot connect to the database.');
            $this->line('    '.$e->getMessage());
            $this->line('');
            $this->line('  Point `.env` at the restored copy before running this. Running it against');
            $this->line('  the live database tells you nothing about the restore.');

            return false;
        }
    }

    /**
     * A dump is a snapshot of a schema at a version. The code is at whatever version the checkout
     * is. If the dump is older, the application will boot and then fail on the first query that
     * touches a column the dump predates — which is a failure that shows up in use rather than at
     * restore time, and therefore after somebody has started working in it.
     */
    private function checkSchema(): void
    {
        try {
            $ran = DB::table('migrations')->count();
        } catch (QueryException) {
            $this->flag('schema', 'No `migrations` table. This dump is not from this application, or it is truncated.');

            return;
        }

        $onDisk = count(glob(database_path('migrations').'/*.php') ?: []);

        if ($ran < $onDisk) {
            $this->flag('schema', ($onDisk - $ran).' migration(s) in this checkout have not been applied to it.');
            $this->line('        Run <options=bold>php artisan migrate --force</> against the restore before using it.');

            return;
        }

        if ($ran > $onDisk) {
            // The dangerous direction, and the quieter one. The dump is NEWER than the code, which
            // happens when a restore is paired with an older checkout — and `migrate` will not
            // undo anything, so nothing complains. The application simply ignores columns it does
            // not know about, and writes rows that the newer schema's constraints expect to be
            // populated.
            $this->warn('  ~ schema    the dump is ahead of this checkout by '.($ran - $onDisk).' migration(s).');
            $this->warnings[] = 'The code is older than the data. Check out the revision this dump was taken from.';

            return;
        }

        $this->pass('schema', $ran.' migrations, matching this checkout');
    }

    /**
     * `AccessResolver` fails closed: with no catalogue, every grant map is empty and every person
     * in the company — including the owner — can reach nothing. A restore that dropped this table
     * looks completely healthy until somebody logs in.
     */
    private function checkPermissionCatalogue(): void
    {
        $count = DB::table('permissions')->count();

        if ($count === 0) {
            $this->flag('access', 'The permission catalogue is empty. Everyone would sign in and reach nothing.');
            $this->line('        Run <options=bold>php artisan db:seed --class=PermissionSeeder --force</>.');

            return;
        }

        $this->pass('access', $count.' permissions in the catalogue');
    }

    /**
     * The restore that is technically perfect and practically useless.
     *
     * Every table intact, every file present, and nobody who can get in — because the one account
     * with `is_super_admin` was deactivated, soft-deleted or never included in the dump's date
     * range. There is no recovery path from inside the application for that: `engops:install` will
     * not touch an existing company, and it is right not to. So it has to be caught here, while
     * somebody still has the dump and the option of a different one.
     */
    private function checkEveryCompanyCanBeAdministered(): void
    {
        $organizations = DB::table('organizations')->select('id', 'name')->orderBy('id')->get();

        if ($organizations->isEmpty()) {
            $this->flag('companies', 'There are no organizations in this database.');

            return;
        }

        $stranded = [];

        foreach ($organizations as $organization) {
            $administrators = DB::table('users')
                ->where('organization_id', $organization->id)
                ->where('is_super_admin', true)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count();

            if ($administrators === 0) {
                $stranded[] = $organization->name;
            }
        }

        if ($stranded !== []) {
            $this->flag('companies', count($stranded).' company(ies) have no active administrator:');

            foreach ($stranded as $name) {
                $this->line('        '.$name);
            }

            $this->line('        Nobody can configure these. Restore a dump that includes their account,');
            $this->line('        or reactivate it directly before handing this over.');

            return;
        }

        $this->pass('companies', $organizations->count().' company(ies), each with an administrator');
    }

    /**
     * THE CHECK THIS COMMAND IS REALLY FOR.
     *
     * The database and the private files are two different backups taken by two different
     * mechanisms at two different moments, and a restore pairs them. Every hour between those two
     * moments is a window of rows that point at files the archive does not have — a drawing
     * revision that opens to a download error, an insurance certificate that is simply gone. It is
     * the one loss in the list that cannot be reconstructed from anything else, and the one nobody
     * discovers until a specific person goes looking for a specific document, weeks later.
     *
     * Sampled by default because a real tenant has tens of thousands of these and a restore drill
     * that takes an hour does not get run. `--files=0` checks all of them, which is the right thing
     * to do once, during the drill, on the copy you intend to keep.
     */
    private function checkStoredFiles(): void
    {
        $limit = (int) $this->option('files');
        $disk = Storage::disk(FileVault::DISK);

        $checked = 0;
        $missing = [];

        foreach (self::FILE_TABLES as $table => $column) {
            try {
                $query = DB::table($table)->whereNotNull($column)->where($column, '!=', '');
            } catch (QueryException) {
                continue;
            }

            $total = (clone $query)->count();

            if ($total === 0) {
                continue;
            }

            // Newest first. If the two backups are out of step, it is the most recent uploads that
            // are missing, so a sample taken from the other end would come back clean on exactly
            // the database where this check matters.
            $rows = $query->orderByDesc('id')
                ->when($limit > 0, fn ($q) => $q->limit($limit))
                ->pluck($column, 'id');

            foreach ($rows as $id => $path) {
                $checked++;

                if (! $disk->exists((string) $path)) {
                    $missing[] = $table.' #'.$id;
                }
            }
        }

        if ($checked === 0) {
            $this->pass('files', 'no stored files in this database');

            return;
        }

        if ($missing !== []) {
            $this->flag('files', count($missing).' of '.$checked.' checked file(s) are not on the private disk:');

            foreach (array_slice($missing, 0, 10) as $row) {
                $this->line('        '.$row);
            }

            if (count($missing) > 10) {
                $this->line('        … and '.(count($missing) - 10).' more');
            }

            $this->line('');
            $this->line('        The file archive and the database dump are out of step. Restore a');
            $this->line('        `storage/app/private` archive taken at or after the dump. These files');
            $this->line('        exist nowhere else — the rows describing them are not a copy.');

            return;
        }

        $this->pass('files', $checked.' file(s) checked, all present'.($limit > 0 ? ' (sampled — use --files=0 for all)' : ''));
    }

    // ------------------------------------------------------------------ output

    private function pass(string $label, string $detail): void
    {
        $this->line('  <fg=green>✓</> '.str_pad($label, 10).' '.$detail);
    }

    private function flag(string $label, string $detail): void
    {
        $this->line('  <fg=red>✗</> '.str_pad($label, 10).' '.$detail);

        $this->failures[] = $label;
    }

    private function verdict(): int
    {
        $this->line('');

        if ($this->failures !== []) {
            $this->error('  This restore is not usable as it stands.');
            $this->line('');
            $this->line('  Fix the items above, or go back for a different copy, before anybody works in it.');
            $this->line('');

            return self::FAILURE;
        }

        $this->info('  This restore is usable.');

        foreach ($this->warnings as $warning) {
            $this->line('  ! '.$warning);
        }

        // The limits, printed every time and not only on failure. A green result is precisely when
        // somebody is most likely to read more into it than it says.
        $this->line('');
        $this->line('  It does NOT tell you whether a backup ran, when it ran, whether a copy went');
        $this->line('  offsite, or how old this one is. Nothing running inside the application can');
        $this->line('  see any of that, and a green tile claiming otherwise would stop you checking.');
        $this->line('');
        $this->line('  Finish the drill: sign in, open a project, download a document (§85).');
        $this->line('');

        return self::SUCCESS;
    }

    private function connectionLabel(): string
    {
        $name = (string) config('database.default');
        $config = config('database.connections.'.$name);

        return $name.' — '.($config['database'] ?? 'unknown').' @ '.($config['host'] ?? 'local');
    }
}
