<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scope the users table and add account security fields.
 *
 * `is_super_admin` is a PLATFORM OPERATOR flag, not a role, and it does NOT exempt the holder
 * from Gate 1 (organization isolation) — a super administrator still operates inside exactly one
 * organization context. See docs/04-access-model.md section 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            $table->string('username')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('locale', 8)->nullable()->after('avatar_path');
            $table->string('timezone')->nullable()->after('locale');

            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_super_admin');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'name']);
        });

        // Email must be unique PER ORGANIZATION, not globally: two tenants may legitimately
        // employ people whose addresses collide, and a global unique index would leak the
        // existence of an account in another tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['organization_id', 'email']);
            $table->unique(['organization_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'email']);
            $table->dropUnique(['organization_id', 'username']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn([
                'username', 'phone', 'avatar_path', 'locale', 'timezone',
                'is_super_admin', 'is_active', 'must_change_password', 'password_changed_at',
                'last_login_at', 'last_login_ip', 'failed_login_attempts', 'locked_until',
                'deleted_at',
            ]);
            $table->unique('email');
        });
    }
};
