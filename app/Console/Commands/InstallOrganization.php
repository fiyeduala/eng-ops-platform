<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Engines\Access\OrganizationContext;
use App\Engines\Organization\OrganizationProvisioner;
use App\Models\Organization;
use App\Models\User;
use App\Rules\OrganizationPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The first run: a company, and one account that can log in and configure it (§73, §10, §92).
 *
 * THE STEP THAT WAS MISSING. docs/12 walks an operator from `git clone` to `view:cache` and then
 * says everything else "is created through the screens by the first administrator". There was no
 * first administrator. `OrganizationProvisioner::provision()` builds a complete tenant — roles,
 * statuses, numbering series, option lists, workflows, registers — and deliberately creates no
 * user; its only caller was `DemoSeeder`, whose people are all `is_super_admin = false`. A correct
 * production install following the documented steps therefore ended at a login screen with no
 * account able to reach it, and no supported way to make one. This command is that step, and it is
 * the only thing in the application that sets `is_super_admin`.
 *
 * IT REFUSES ON AN EMPTY PERMISSION CATALOGUE, and that guard is worth more than it looks.
 * `AccessResolver` grants a super administrator every permission by iterating the `permissions`
 * table (see its `buildRaw`), so an owner created before `PermissionSeeder` has run holds the whole
 * of an empty set: they log in successfully, see a sidebar built from permissions (§96) with
 * nothing on it, and every screen they guess the URL of answers 403. That failure looks exactly
 * like a broken install of a working platform, and the fix — reseed, then log out and back in — is
 * not one anybody would guess. Far better to refuse at the console, where the message can say so.
 *
 * IT REFUSES WHEN A COMPANY ALREADY EXISTS. Running this twice is legal — the platform is
 * multi-tenant and a second tenant is a supported thing to want — but it is almost never what
 * somebody typing `engops:install` on a live system means. `--force` is how you say you meant it,
 * and it names the existing companies before it goes ahead, so the confirmation is informed.
 *
 * THE PASSWORD IS GENERATED, PRINTED ONCE AND NEVER WRITTEN DOWN (§92). Nothing in this file has a
 * default password in it, because a default password in an installer is a default password in
 * production: the whole point of the guard is that there is nothing to guess. The generated one is
 * echoed to the operator's terminal and to nowhere else — not the log, not the audit trail, not a
 * file — and the account is created with `must_change_password`, so it is single-use by
 * construction. Printing it is therefore the ONLY delivery, which is why `--quiet` is refused
 * below rather than honoured. An operator who would rather choose
 * one may, through `--password`, which is validated against `OrganizationPassword` — the same
 * rule the change-password screen applies — rather than against a floor invented here. On a
 * shell that keeps history, and in `ps`, that option is readable by anyone else on the box; the
 * generated path is the better one and is the default for that reason.
 *
 * WHAT IT DOES NOT CREATE. No org units, no employees, no documents, no departments. Those are the
 * first administrator's job, through the screens, which is the §73 claim
 * `NewCompanyWalkthroughTest` exercises end to end. An installer that seeded a starting org chart
 * would be seeding an opinion about how companies are shaped, and §5 is explicit that a company
 * whose structure looks nothing like the templates must still be able to model itself.
 */
class InstallOrganization extends Command
{
    protected $signature = 'engops:install
                            {--name= : The company name}
                            {--slug= : URL-safe identifier; defaults to a slug of the name}
                            {--admin-name= : The first administrator\'s full name}
                            {--admin-email= : The first administrator\'s email address}
                            {--password= : Set their password instead of generating one}
                            {--country= : Two-letter country code}
                            {--timezone= : IANA timezone, e.g. Africa/Lagos}
                            {--currency= : Three-letter currency code}
                            {--force : Proceed even though this database already holds a company}';

    protected $description = 'Create a company and its first administrator';

    public function handle(
        OrganizationProvisioner $provisioner,
        OrganizationContext $context,
    ): int {
        if (! $this->canDeliverThePassword()) {
            return self::FAILURE;
        }

        if (! $this->catalogueIsSeeded()) {
            $this->error('The permission catalogue is empty.');
            $this->line('');
            $this->line('  Run this first:');
            $this->line('      php artisan db:seed --class=PermissionSeeder --force');
            $this->line('');
            $this->line('  An administrator created before the catalogue exists holds every');
            $this->line('  permission in an empty set — they can log in and reach nothing.');

            return self::FAILURE;
        }

        if (! $this->existingIsAcceptable()) {
            return self::FAILURE;
        }

        $details = $this->gatherDetails();

        if ($details === null) {
            return self::FAILURE;
        }

        [$organization, $user, $password, $generated] = $this->install($provisioner, $context, $details);

        $this->report($organization, $user, $password, $generated);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ guards

    /**
     * A generated password is printed and stored nowhere, so silencing the output loses it.
     *
     * `--quiet` is a reasonable thing to reach for in a deploy script and every other command in
     * this directory honours it. Here it would produce a company whose only account has a
     * twenty-character password that now exists solely as a bcrypt hash — an install that
     * reports success and cannot be logged into, with no way back in except a second company or
     * a hand-written UPDATE. So it is refused, and the two ways to have both a quiet run and a
     * usable account are named.
     */
    private function canDeliverThePassword(): bool
    {
        if (! $this->output->isQuiet() || $this->stringOption('password') !== null) {
            return true;
        }

        $this->getOutput()->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        $this->error('Refusing to run quietly while generating a password.');
        $this->line('');
        $this->line('  The generated password is printed once and stored nowhere, so with the');
        $this->line('  output silenced it would be lost the moment it was created — leaving a');
        $this->line('  company whose only account nobody can sign in to.');
        $this->line('');
        $this->line('  Either drop --quiet, or pass --password= and keep it quiet.');

        return false;
    }

    /**
     * `AccessResolver` reads the catalogue to build an owner's grant map, so an empty one is not a
     * partial install — it is an owner with no permissions at all.
     */
    private function catalogueIsSeeded(): bool
    {
        return DB::table('permissions')->exists();
    }

    private function existingIsAcceptable(): bool
    {
        $existing = Organization::query()->withoutGlobalScopes()->orderBy('id')->get(['id', 'name']);

        if ($existing->isEmpty()) {
            return true;
        }

        if (! $this->option('force')) {
            $this->error('This database already holds '.$existing->count().' company(ies):');

            foreach ($existing as $organization) {
                $this->line('    #'.$organization->id.'  '.$organization->name);
            }

            $this->line('');
            $this->line('  Installing again adds ANOTHER company; it does not repair or replace an');
            $this->line('  existing one. If that is what you want, pass --force.');
            $this->line('');
            $this->line('  If you are locked out of a company that already exists, this is the wrong');
            $this->line('  tool: reset the administrator\'s password rather than creating a second one.');

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ input

    /**
     * Options first, prompts second, and a hard failure third.
     *
     * A deploy script runs this with `--no-interaction`, where an unanswerable prompt is a hung
     * build rather than an error, so anything required must be an option in that mode. The two
     * that are not required — timezone and currency — fall through to the provisioner's own
     * defaults and are named in the output, because both are editable in Settings and neither is a
     * regulatory assumption this command should be making on a company's behalf (§47).
     *
     * @return array<string,string>|null
     */
    private function gatherDetails(): ?array
    {
        $details = [
            'name' => $this->stringOption('name'),
            'slug' => $this->stringOption('slug'),
            'admin-name' => $this->stringOption('admin-name'),
            'admin-email' => $this->stringOption('admin-email'),
            'password' => $this->stringOption('password'),
            'country' => $this->stringOption('country'),
            'timezone' => $this->stringOption('timezone'),
            'currency' => $this->stringOption('currency'),
        ];

        if ($this->input->isInteractive()) {
            $details['name'] ??= $this->ask('Company name');
            $details['admin-name'] ??= $this->ask('The first administrator\'s full name');
            $details['admin-email'] ??= $this->ask('Their email address');
            $details['timezone'] ??= $this->ask('Timezone', (string) config('app.timezone'));
            $details['currency'] ??= $this->ask('Currency code');
        }

        $details['slug'] ??= Str::slug((string) $details['name']);

        return $this->validated($details);
    }

    /**
     * @param  array<string,string|null>  $details
     * @return array<string,string>|null
     */
    private function validated(array $details): ?array
    {
        $validator = Validator::make($details, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'admin-name' => ['required', 'string', 'max:255'],
            'admin-email' => ['required', 'string', 'email', 'max:255'],
            // Against the policy the company is about to be provisioned with, which at this
            // instant is the seeded default — there is no organization yet to hold a stricter one.
            'password' => ['nullable', 'string', OrganizationPassword::rules()],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        // The collision an operator actually hits, and the one worth spelling out. A run that
        // went wrong halfway looks repairable, so the natural second command is the same line
        // with `--force` bolted on — force reading as "do it properly this time". It is not; it
        // means "add another", and another company under the same name is worse than a refusal.
        // Both would be real, one would hold the configuration, and nothing on a sign-in screen
        // would say which you were looking at. So the message names the option that fixes it.
        $validator->after(function ($validator) use ($details): void {
            $slug = (string) ($details['slug'] ?? '');

            if ($slug !== '' && Organization::query()->withoutGlobalScopes()->where('slug', $slug)->exists()) {
                $validator->errors()->add('slug', 'A company already uses the slug "'.$slug.'". '
                    .'Choose a different --name, or pass an explicit --slug.');
            }
        });

        if ($validator->fails()) {
            $this->error('These details are not usable:');

            foreach ($validator->errors()->all() as $message) {
                $this->line('    '.$message);
            }

            // Only when something required is genuinely absent. Printing it unconditionally
            // tells an operator who supplied every option that they supplied none of them,
            // which sends them to re-check their command line instead of reading the one line
            // above that says what is actually wrong.
            $missing = array_filter(
                ['--name' => 'name', '--admin-name' => 'admin-name', '--admin-email' => 'admin-email'],
                static fn (string $key): bool => ($details[$key] ?? null) === null,
            );

            if ($missing !== [] && ! $this->input->isInteractive()) {
                $this->line('');
                $this->line('  Running non-interactively, so every required detail must be an option:');
                $this->line('      '.implode('= ', array_keys($missing)).'=');
            }

            return null;
        }

        /** @var array<string,string> $validated */
        $validated = array_filter(
            $validator->validated(),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        return $validated;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    // ------------------------------------------------------------------ the install

    /**
     * ONE TRANSACTION, COMPANY AND OWNER TOGETHER. A provisioned tenant nobody can log into is the
     * exact state this command exists to eliminate, so it must not be a state this command can
     * leave behind when the second half fails.
     *
     * @param  array<string,string>  $details
     * @return array{0:Organization,1:User,2:string,3:bool}
     */
    private function install(
        OrganizationProvisioner $provisioner,
        OrganizationContext $context,
        array $details,
    ): array {
        $chosen = $this->stringOption('password');
        $password = $chosen ?? Str::password(20);

        return DB::transaction(function () use ($provisioner, $context, $details, $password, $chosen) {
            $organization = $provisioner->provision(array_filter([
                'name' => $details['name'],
                'slug' => $details['slug'],
                'country' => $details['country'] ?? null,
                'timezone' => $details['timezone'] ?? null,
                'currency' => $details['currency'] ?? null,
            ], static fn ($value): bool => $value !== null));

            $user = $context->within($organization, function () use ($organization, $details, $password): User {
                // Built field by field rather than mass-assigned. `is_super_admin`,
                // `must_change_password` and the verification stamp are not fillable on purpose: a
                // privilege flag that can arrive in an array is a privilege-escalation bug waiting
                // for somebody to pass the wrong array.
                $user = new User;
                $user->organization_id = $organization->getKey();
                $user->name = $details['admin-name'];
                $user->email = $details['admin-email'];
                $user->password = Hash::make($password);
                $user->is_active = true;
                $user->timezone = $organization->timezone;

                $user->is_super_admin = true;

                // Single-use by construction (§92). `EnsurePasswordIsCurrent` holds them on the
                // change-password screen until they pick their own, so the string printed at the
                // console below stops working the moment it has been used once.
                $user->must_change_password = true;

                // Verified because the operator typed this address at a console they already
                // control, and because mail may not be configured yet on a fresh install — an
                // unverifiable verification mail is how a first administrator gets locked out of
                // the system they just installed.
                $user->email_verified_at = now();

                $user->save();

                return $user;
            });

            return [$organization->refresh(), $user, $password, $chosen === null];
        });
    }

    // ------------------------------------------------------------------ output

    /**
     * The password goes to the terminal and nowhere else.
     *
     * Not `Log::info`, not the audit trail, not a file the installer leaves behind. This is the one
     * moment it exists in readable form, which is why the line below says so — an operator who
     * closes the terminal without copying it needs to know that the recovery is a password reset
     * rather than a scroll-back.
     */
    private function report(Organization $organization, User $user, string $password, bool $generated): void
    {
        $this->line('');
        $this->info($organization->name.' is installed.');
        $this->line('');
        $this->line('    Company     #'.$organization->id.'  ('.$organization->slug.')');
        $this->line('    Timezone    '.$organization->timezone);
        $this->line('    Currency    '.$organization->currency);
        $this->line('    Sign in as  '.$user->email);

        if ($generated) {
            $this->line('    Password    '.$password);
            $this->line('');
            $this->warn('    That password is shown once and is stored nowhere. Copy it now.');
        }

        $this->line('');
        $this->line('  It must be changed at first sign-in, and it is the only account that exists.');
        $this->line('  Everything else — departments, people, roles, registers, numbering, routes —');
        $this->line('  is built from the Configuration Center by this administrator.');
        $this->line('');
        $this->line('  Timezone and currency are both editable in Settings if the defaults above are');
        $this->line('  not this company\'s.');
        $this->line('');
    }
}
