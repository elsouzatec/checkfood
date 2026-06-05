<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('wifi_network', 100)->nullable()->after('logo_url');
            $table->string('wifi_password', 100)->nullable()->after('wifi_network');
            $table->string('qr_phrase', 150)->nullable()->after('wifi_password');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['wifi_network', 'wifi_password', 'qr_phrase']);
        });
    }
};
