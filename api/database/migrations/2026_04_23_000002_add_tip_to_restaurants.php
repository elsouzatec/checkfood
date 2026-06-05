<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('tip_enabled')->default(false)->after('allow_client_payment');
            $table->decimal('tip_default_percent', 5, 2)->default(10.00)->after('tip_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['tip_enabled', 'tip_default_percent']);
        });
    }
};
