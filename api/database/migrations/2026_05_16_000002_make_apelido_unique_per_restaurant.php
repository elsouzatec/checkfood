<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove o unique global criado em 2026_04_16_000001
            $table->dropUnique(['apelido']);

            // Apelido passa a ser único por restaurante (NULLs são distintos
            // no MySQL/MariaDB, então clientes sem apelido não conflitam).
            $table->unique(['restaurant_id', 'apelido'], 'users_restaurant_apelido_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_restaurant_apelido_unique');
            $table->unique('apelido');
        });
    }
};
