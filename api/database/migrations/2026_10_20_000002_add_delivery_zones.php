<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega por distância (mesma regra do CheckAutomate): a loja marca a
 * localização no mapa e define as faixas, em linha reta (haversine):
 *   0 → free_km: grátis · free_km → max_km: delivery_fee · > max_km: não entrega.
 * Sem localização/raio máximo, a entrega segue com a taxa fixa de antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('delivery_free_km', 6, 2)->nullable();
            $table->decimal('delivery_max_km', 6, 2)->nullable();
        });

        Schema::table('sessions_app', function (Blueprint $table) {
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->decimal('delivery_distance_km', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sessions_app', function (Blueprint $table) {
            $table->dropColumn(['delivery_lat', 'delivery_lng', 'delivery_distance_km']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'delivery_free_km', 'delivery_max_km']);
        });
    }
};
