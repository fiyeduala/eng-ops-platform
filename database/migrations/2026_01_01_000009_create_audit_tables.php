<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine 4 (sections 59, 60).
 *
 * Two tables with different jobs:
 *   audit_logs    - technical, field-level, append-only: old value / new value / IP / agent.
 *   activity_logs - human-readable narrative for record timelines: "Mary uploaded Revision B".
 *
 * audit_logs is APPEND ONLY. The application never issues UPDATE or DELETE against it, there is
 * no route or model method that can, and in production the DB grant should be INSERT+SELECT only.
 * It has no `updated_at` precisely so that an update is obviously wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of who acted, so the entry stays readable if the user is later deleted.
            $table->string('user_name')->nullable();

            $table->string('action', 64)->index();      // created|updated|deleted|approved|downloaded|...
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('module', 64)->nullable();
            $table->string('description')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();
            // Marks actions taken using the platform super-admin flag, for review.
            $table->boolean('is_privileged')->default(false);

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'user_id', 'created_at'], 'audit_logs_org_user_idx');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('causer_name')->nullable();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // Denormalized so a project timeline is one indexed query across every module.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();

            $table->string('verb', 64);                 // created|submitted|approved|uploaded|assigned
            $table->text('description');
            $table->json('properties')->nullable();
            $table->string('icon', 32)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_subject_idx');
            $table->index(['organization_id', 'project_id', 'created_at'], 'activity_project_idx');
            $table->index(['organization_id', 'causer_id', 'created_at'], 'activity_causer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('audit_logs');
    }
};
