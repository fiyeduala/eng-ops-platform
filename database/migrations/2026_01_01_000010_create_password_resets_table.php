<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reset tokens, keyed by USER rather than by email address.
 *
 * Laravel's stock `password_reset_tokens` table has the email address as its primary key. That
 * assumes email is globally unique — and here it deliberately is not: the users table is unique
 * on (organization_id, email), because two tenants may legitimately employ people whose addresses
 * collide, and a global unique index would leak the existence of an account in another tenant
 * (§75). With the stock table, one tenant requesting a reset would overwrite the other's token,
 * and a token issued for one company could be redeemed against an account in another.
 *
 * Keying on user_id removes the ambiguity entirely: a token grants a reset of exactly one account.
 *
 * `token` stores a SHA-256 hash, never the value that was emailed. A leaked database backup must
 * not hand the reader working reset links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->index();      // sha256 hex of the emailed token
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // Redeeming sweeps by user; expiry cleanup sweeps by date.
            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
