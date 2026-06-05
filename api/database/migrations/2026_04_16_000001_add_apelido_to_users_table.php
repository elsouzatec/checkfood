<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Apelido/nick para funcionários (garçom, cozinha, gerente).
            // Clientes mantêm NULL. UNIQUE ignora NULLs no MariaDB/MySQL.
            $table->string('apelido', 50)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apelido']);
            $table->dropColumn('apelido');
        });
    }
};
