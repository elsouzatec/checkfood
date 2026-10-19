<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conta do cliente (por restaurante): o cliente da mesa (role=client) ganha
 * senha e passa a ter uma conta. Sem registered_at = cliente anônimo da mesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('registered_at')->nullable()->after('is_active');
            $table->timestamp('terms_accepted_at')->nullable()->after('registered_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');

            // Pedidos anteriores a esta data ficam ocultos no histórico da conta.
            // Enquanto o telefone não é verificado, quem cadastra o número de
            // outra pessoa não herda o histórico dela. null = histórico completo.
            $table->timestamp('history_visible_from')->nullable()->after('terms_version');

            // Uma conta por telefone em cada restaurante (staff tem outro role,
            // então o mesmo telefone pode ser funcionário e cliente).
            $table->unique(['restaurant_id', 'role', 'phone'], 'users_restaurant_role_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_restaurant_role_phone_unique');
            $table->dropColumn(['registered_at', 'terms_accepted_at', 'terms_version', 'history_visible_from']);
        });
    }
};
