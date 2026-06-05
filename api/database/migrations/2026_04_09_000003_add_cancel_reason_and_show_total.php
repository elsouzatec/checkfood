<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancel_reason', 30)->nullable()->after('fiscal_status');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('show_total_during_service')->default(true)->after('qr_phrase');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancel_reason');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('show_total_during_service');
        });
    }
};
