<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);

            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('cost_type', 40)->default('procurement');
            $table->string('description', 500);

            $table->decimal('quantity', 18, 4);
            $table->string('uom', 40)->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->string('currency', 8);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('amount', 18, 2);
            $table->string('base_currency', 8);
            $table->decimal('base_amount', 18, 2);

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->timestamp('incurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'number']);
            $table->unique(['source_type', 'source_id'], 'project_costs_source_unique');
            $table->index(['organization_id', 'project_id', 'incurred_at']);
            $table->index(['organization_id', 'org_unit_id', 'incurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_costs');
    }
};
