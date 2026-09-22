<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Console\Commands\VerifyRestore;
use App\Engines\Document\FileVault;
use App\Models\Organization;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The restore drill, run as a test (§85).
 *
 * WHAT §85 ACTUALLY ASKS FOR. Not a backup feature — the dump is cPanel's, the offsite push is the
 * host's, and this application starts none of it, so a `backup_runs` table would record only what
 * somebody remembered to type in and a dashboard tile reading from it would carry exactly that much
 * authority. What §85 asks for is the sentence at the end of docs/12: *an untested backup is not a
 * backup*. Restoring is the part that IS testable, because the restored copy is right there.
 *
 * SO THIS FILE TESTS THE TESTER. Each case builds a database that is broken in one of the specific
 * ways a restore is broken in practice, and asserts that `engops:verify-restore` says so before
 * anybody works in it. The four failure modes are not hypothetical; they are the four ways these
 * two backups — a database dump and a file archive, taken separately, by different mechanisms, at
 * different moments — come apart.
 *
 * THE LAST TEST IS THE IMPORTANT ONE and it is the least obvious: a restore can pass every
 * structural check and still be useless, because the only account that could administer the company
 * was deactivated, soft-deleted, or fell outside the dump. Nothing else catches it. `engops:install`
 * will not touch an existing company and is right not to, so the discovery point is somebody
 * staring at a sign-in screen they cannot get past, days after the dump they needed was rotated out.
 *
 * @see VerifyRestore for why there is no backup engine to test instead
 */
final class RestoreDrillTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private const SUCCESS = 0;

    private const FAILURE = 1;

    private string $output = '';

    private Organization $org;

    private User $adaeze;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileVault::DISK);

        $this->org = $this->makeOrganization('Harmattan Engineering');
        $unit = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Okonkwo', $unit, []);

        // The owner. `makePerson` builds ordinary staff, and the flag is not fillable on purpose,
        // so it is set here the way `engops:install` sets it.
        $this->adaeze->is_super_admin = true;
        $this->adaeze->save();
    }

    #[Test]
    public function a_complete_restore_passes_and_says_what_it_did_not_check(): void
    {
        $this->assertSame(self::SUCCESS, $this->verify());

        $this->assertStringContainsString('This restore is usable', $this->output);

        // Printed on success, not only on failure — a green result is exactly when somebody is
        // most likely to read more into it than it says. If this command ever implies it knows
        // that a backup ran, it has become the lie it exists instead of.
        $this->assertStringContainsString('does NOT tell you whether a backup ran', $this->output);
        $this->assertStringContainsString('offsite', $this->output);
    }

    #[Test]
    public function a_dump_that_lost_the_permission_catalogue_is_refused(): void
    {
        // The restore that looks perfect. Every company, every project, every document — and
        // `AccessResolver` fails closed, so the first thing anybody discovers is that their own
        // administrator can reach nothing. There is no error anywhere; the system is simply empty.
        DB::table('permissions')->delete();

        $this->assertSame(self::FAILURE, $this->verify());

        $this->assertStringContainsString('permission catalogue is empty', $this->output);
        $this->assertStringContainsString('sign in and reach nothing', $this->output);

        // And it names the command that fixes it. A refusal that does not is a refusal that ends
        // in somebody restoring a different dump for no reason.
        $this->assertStringContainsString('PermissionSeeder', $this->output);
    }

    #[Test]
    public function a_file_archive_older_than_the_dump_is_refused_by_name(): void
    {
        // A document whose file was uploaded in the window between the archive and the dump. The
        // row is in the database; the bytes are not on the disk. Nothing in the application
        // notices until a specific person opens a specific drawing, weeks later — and the file is
        // the only copy, because the row describing it is not a copy of anything.
        $document = $this->makeDocument($this->org, $this->adaeze, 'Quay wall reinforcement detail');
        $version = $this->makeNativeVersion($this->org, $document, $this->adaeze);

        DB::table('document_versions')
            ->where('id', $version->id)
            ->update(['file_path' => 'documents/'.$this->org->id.'/missing-from-the-archive.pdf']);

        $this->assertSame(self::FAILURE, $this->verify());

        $this->assertStringContainsString('not on the private disk', $this->output);
        $this->assertStringContainsString('document_versions #'.$version->id, $this->output);
        $this->assertStringContainsString('These files', $this->output);
        $this->assertStringContainsString('exist nowhere else', $this->output);
    }

    #[Test]
    public function a_file_that_is_actually_there_is_not_reported(): void
    {
        $document = $this->makeDocument($this->org, $this->adaeze, 'Quay wall reinforcement detail');
        $version = $this->makeNativeVersion($this->org, $document, $this->adaeze);

        $path = 'documents/'.$this->org->id.'/present.pdf';

        Storage::disk(FileVault::DISK)->put($path, 'the bytes');

        DB::table('document_versions')->where('id', $version->id)->update(['file_path' => $path]);

        // The control, and the reason the previous test means anything. A checker that flags every
        // row would have passed it too, and would then be flagging every healthy restore — which
        // is how a check gets switched off.
        $this->assertSame(self::SUCCESS, $this->verify());

        $this->assertStringContainsString('1 file(s) checked, all present', $this->output);
    }

    #[Test]
    public function a_company_nobody_can_administer_is_refused(): void
    {
        // Technically perfect and practically useless. Every table intact, every file present, and
        // the one account that could configure the company is switched off — the dump caught it
        // mid-offboarding, or it simply fell outside the range.
        $this->adaeze->is_active = false;
        $this->adaeze->save();

        $this->assertSame(self::FAILURE, $this->verify());

        $this->assertStringContainsString('no active administrator', $this->output);
        $this->assertStringContainsString('Harmattan Engineering', $this->output);

        // Named because the fix is outside the application. `engops:install` refuses to touch an
        // existing company, correctly, so the operator has to either go back for a dump that
        // includes the account or reactivate it directly — and needs to be told that now, while
        // the other dumps still exist.
        $this->assertStringContainsString('reactivate it', $this->output);
    }

    #[Test]
    public function a_soft_deleted_administrator_does_not_count_as_one(): void
    {
        // The same hole through the other door, and the one a naive check misses: the row is still
        // in `users`, still flagged `is_super_admin`, still `is_active`. It is only `deleted_at`
        // that makes it unable to sign in, and a count that forgot the soft-delete column would
        // report this company as perfectly administrable.
        $this->adaeze->delete();

        $this->assertSame(self::FAILURE, $this->verify());

        $this->assertStringContainsString('no active administrator', $this->output);
    }

    private function verify(array $options = []): int
    {
        $code = Artisan::call('engops:verify-restore', array_merge(['--no-interaction' => true], $options));

        $this->output = Artisan::output();

        return $code;
    }
}
