<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('deployment_mode', 20)->default('domain')->after('is_active');
            // URL base canônica do tenant, usada para gerar QR codes e links.
            // Ex.: https://restaurante.com  ou  http://192.168.0.50:8080
            $table->string('frontend_url')->nullable()->after('deployment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['deployment_mode', 'frontend_url']);
        });
    }
};
