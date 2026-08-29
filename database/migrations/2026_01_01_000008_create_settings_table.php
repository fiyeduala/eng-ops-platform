<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration engine storage (sections 9, 72).
 *
 * Anything that differs between companies is a row here, not a constant in code. This includes
 * the executive-visibility posture (settings.access.md_sees_all etc), approval thresholds,
 * working hours, and branding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // Null organization_id = platform default, overridden by a tenant row of the same key.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('group', 64)->index();
            $table->string('key');
            $table->json('value')->nullable();
            $table->string('type', 20)->default('string'); // string|integer|boolean|json|money|date
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            // Settings a tenant admin may edit in the UI vs. platform-managed ones.
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
