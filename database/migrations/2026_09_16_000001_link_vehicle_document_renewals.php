<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->foreignId('renews_id')->nullable()->unique()->constrained('vehicle_documents')->restrictOnDelete();
            $table->date('superseded_on')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->dropForeign(['renews_id']);
            $table->dropUnique(['renews_id']);
            $table->dropIndex(['superseded_on']);
            $table->dropColumn(['renews_id', 'superseded_on']);
        });
    }
};
