<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job titles / positions (section 3). Configurable per organization.
 *
 * A position is NOT a role. A position describes the job ("Mechanical Engineer"); a role
 * describes what the system permits. Separating them is what lets two Mechanical Engineers
 * hold different system access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('title');
            $table->string('code', 32)->nullable();
            $table->text('description')->nullable();
            // Seniority band, used for reporting and default workflow routing hints.
            $table->unsignedSmallInteger('grade')->nullable();
            $table->boolean('is_management')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'org_unit_id', 'is_active']);
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
