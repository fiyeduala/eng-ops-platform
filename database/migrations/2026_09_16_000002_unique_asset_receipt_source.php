<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->unique('goods_receipt_item_id'));
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropUnique(['goods_receipt_item_id']));
    }
};
