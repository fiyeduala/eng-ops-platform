<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every authorization-sensitive table hangs off this.
 * See docs/04-access-model.md section 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('registration_no')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('industry')->nullable();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->nullable();

            $table->string('logo_path')->nullable();
            $table->string('primary_color', 9)->default('#0f4c81');
            $table->string('currency', 3)->default('NGN');
            $table->string('timezone')->default('Africa/Lagos');
            $table->string('date_format')->default('d M Y');
            $table->string('locale', 8)->default('en');

            // Bumped whenever roles, grants, assignments or org structure change.
            // Forms part of the permission cache key so invalidation is instant and global.
            $table->unsignedBigInteger('permissions_version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
