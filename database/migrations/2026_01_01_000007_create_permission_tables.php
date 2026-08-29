<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC with scoped grants (sections 5, 6, 7).
 *
 * `permissions` is a GLOBAL catalogue — the vocabulary of things that can be done. It is not
 * tenant-scoped, because "document.approve" means the same thing everywhere.
 *
 * `roles` and the GRANTS are tenant-scoped, so every company invents its own roles without
 * code changes.
 *
 * Two independent levels of scoping, and both matter:
 *   permission_role.scope       -> how far this permission reaches by default
 *   role_user.scope_type/_id    -> narrows an entire role assignment to one unit or project
 *
 * The second is what makes section 10 work: a "Project Administrator" is an ordinary role whose
 * assignment is pinned to one project, so no administrator is implicitly unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. document.approve
            $table->string('module', 64)->index();    // e.g. document
            $table->string('action', 64)->index();    // e.g. approve
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('group', 64)->nullable();  // UI grouping, e.g. "Operations"
            // Grants reach into money, personal data or the audit trail. Drives a warning in the
            // role editor and a line in the daily executive report — never an enforcement point.
            $table->boolean('is_sensitive')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 128);
            $table->string('description')->nullable();
            // Ordering hint for UI and for "who outranks whom" display. NOT a security control.
            $table->unsignedSmallInteger('level')->default(50);
            // Protected roles cannot be deleted or have their key grants removed via the UI.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            // AccessScope: own|assigned|team|section|department|division|project|organization
            $table->string('scope', 20)->default('own');
            $table->timestamps();

            // A role may grant the same permission at two scopes (e.g. department AND project);
            // the resolver unions them, so the pair must include the scope.
            $table->unique(['role_id', 'permission_id', 'scope'], 'permission_role_unique');
            $table->index('permission_id');
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Narrows the whole assignment. null = unrestricted within the user's own reach.
            $table->string('scope_type', 20)->nullable();   // org_unit|project
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'user_id', 'scope_type', 'scope_id'], 'role_user_unique');
            $table->index(['user_id', 'starts_at', 'ends_at']);
            $table->index(['scope_type', 'scope_id']);
        });

        // Gate 4: per-record ACL. An explicit DENY always wins over any grant.
        Schema::create('record_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->string('grantee_type', 20);          // user|role|org_unit|project|project_role
            $table->unsignedBigInteger('grantee_id');
            // A FULL permission key ("document.approve"), or "*" for every ability on the record.
            // Storing the bare action would be ambiguous the moment two modules share a record
            // type, and would let a grant of `view` on one module silently apply to another.
            $table->string('ability', 64);
            $table->string('effect', 8)->default('allow'); // allow|deny
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['record_type', 'record_id'], 'record_permissions_record_idx');
            $table->index(['grantee_type', 'grantee_id'], 'record_permissions_grantee_idx');
            $table->index(['organization_id', 'expires_at']);
            $table->unique(
                ['record_type', 'record_id', 'grantee_type', 'grantee_id', 'ability'],
                'record_permissions_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_permissions');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
