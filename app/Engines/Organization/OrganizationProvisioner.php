<?php

declare(strict_types=1);

namespace App\Engines\Organization;

use App\Engines\Access\OrganizationContext;
use App\Models\Organization;
use App\Models\Role;
use App\Support\RoleTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything a new tenant needs before its first user logs in (sections 5, 74).
 *
 * A company that signs up must arrive at a working system, not an empty database — but every
 * single thing created here is editable. The role templates, the setting defaults and the unit
 * labels are opinions, not structure.
 */
class OrganizationProvisioner
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function provision(array $attributes): Organization
    {
        return DB::transaction(function () use ($attributes) {
            $organization = Organization::create([
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? Str::slug($attributes['name']),
                'legal_name' => $attributes['legal_name'] ?? $attributes['name'],
                'industry' => $attributes['industry'] ?? null,
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address' => $attributes['address'] ?? null,
                'country' => $attributes['country'] ?? null,
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'currency' => $attributes['currency'] ?? 'USD',
                'is_active' => true,
                'activated_at' => now(),
            ]);

            // Provisioning runs outside a request, so nothing has set the tenant yet. Everything
            // below creates tenant-scoped rows, which the global scope will attribute to whatever
            // context is current — so it has to be this organization, explicitly.
            $this->context->within($organization, function () use ($organization) {
                $this->seedRoles($organization);
                $this->seedSettings($organization);
            });

            return $organization->refresh();
        });
    }

    /**
     * Create the starter roles. Idempotent: re-running adds newly published templates without
     * overwriting grants an administrator has since edited.
     *
     * @return array<string,Role> slug => role
     */
    public function seedRoles(Organization $organization, bool $overwriteExisting = false): array
    {
        $roles = [];

        foreach (RoleTemplates::all() as $slug => $template) {
            $existing = Role::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('slug', $slug)
                ->first();

            if ($existing !== null && ! $overwriteExisting) {
                $roles[$slug] = $existing;

                continue;
            }

            $role = $existing ?? new Role;
            $role->organization_id = $organization->id;
            $role->slug = $slug;
            $role->name = $template['name'];
            $role->description = $template['description'];
            $role->level = $template['level'];
            // is_system stays false on every template: §5 requires that an administrator can
            // rename, re-scope or delete any of them.
            $role->is_system = false;
            $role->is_active = true;
            $role->save();

            $role->syncGrants(RoleTemplates::expand($template['grants']));

            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * Behavioural defaults. Every one of these is a decision a company might make differently,
     * which is exactly why none of them is a constant in code.
     */
    public function seedSettings(Organization $organization): void
    {
        $now = now();

        $defaults = [
            // --- structure labels: a company may call a Division a "Directorate" -----------
            'org.label.division' => 'Division',
            'org.label.department' => 'Department',
            'org.label.section' => 'Section',
            'org.label.team' => 'Team',

            // --- working pattern ---------------------------------------------------------
            'work.week_starts_on' => 'monday',
            'work.working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'work.day_start' => '08:00',
            'work.day_end' => '17:00',
            'work.hours_per_day' => 8,

            // --- workflow behaviour ------------------------------------------------------
            'workflow.escalate_after_days' => 3,
            'workflow.reminder_after_days' => 1,
            'workflow.allow_delegation' => true,
            'workflow.auto_approve_on_timeout' => false,   // never default to approving silently
            'workflow.require_rejection_reason' => true,

            // --- documents ---------------------------------------------------------------
            'document.numbering_format' => '{PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}',
            'document.revision_scheme' => 'alpha',          // alpha (A,B,C) | numeric (1,2,3)
            'document.require_approval_before_issue' => true,
            'document.max_upload_mb' => 25,
            'document.retain_superseded' => true,

            // --- notifications -----------------------------------------------------------
            'notify.channels' => ['database', 'mail'],
            'notify.digest_hour' => 7,
            'notify.escalation_to_supervisor' => true,

            // --- executive reporting -----------------------------------------------------
            'report.daily_executive_hour' => 7,
            'report.daily_executive_enabled' => true,

            // --- security ----------------------------------------------------------------
            'security.password_min_length' => 12,
            'security.password_expiry_days' => 0,           // 0 = never; forced rotation is
            'security.session_idle_minutes' => 120,         // widely counter-productive
            'security.max_login_attempts' => 5,
            'security.lockout_minutes' => 15,
            'security.require_two_factor_for_sensitive' => false,
        ];

        $rows = [];

        foreach ($defaults as $key => $value) {
            $rows[] = [
                'organization_id' => $organization->id,
                'key' => $key,
                'value' => json_encode($value),
                'group' => explode('.', $key)[0],
                'type' => match (true) {
                    is_bool($value) => 'boolean',
                    is_int($value) => 'integer',
                    is_array($value) => 'array',
                    default => 'string',
                },
                'is_editable' => true,
                'is_encrypted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('settings')->upsert($rows, ['organization_id', 'key'], ['updated_at']);
    }
}
