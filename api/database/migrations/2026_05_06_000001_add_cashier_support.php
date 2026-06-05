<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona suporte ao app Caixa.
 *
 * 1. restaurants.cashier_mode — quando true, garçom não recebe pagamento;
 *    encaminha mesa ao caixa que confirma o recebimento.
 *
 * 2. sessions_app.payment_method_chosen — meio de pagamento sugerido pelo
 *    garçom no momento do encaminhamento. O caixa pode confirmar igual ou
 *    alterar na hora (cliente trocou de ideia).
 *
 * 3. sessions_app.at_cashier_at — quando o garçom encaminhou ao caixa.
 *    Permite calcular tempo de espera na fila do caixa, alertas visuais
 *    para mesas esquecidas (>30min), auditoria.
 *
 * NOTA: o status 'at_cashier' é adicionado no enum SessionStatus em código
 *       (não no banco) — o campo `status` já é VARCHAR.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('cashier_mode')
                  ->default(false)
                  ->after('terminal_acquirer')
                  ->comment('Quando true, encaminhamento ao caixa em vez de pagamento via garçom');
        });

        Schema::table('sessions_app', function (Blueprint $table) {
            $table->string('payment_method_chosen', 20)
                  ->nullable()
                  ->after('status')
                  ->comment('Meio de pagamento sugerido pelo garçom: pix, dinheiro, cartao, maquininha');

            $table->timestamp('at_cashier_at')
                  ->nullable()
                  ->after('closed_at')
                  ->comment('Instante em que o garçom encaminhou a sessão ao caixa');

            // Índice para performance da listagem do app caixa
            $table->index(['restaurant_id', 'status', 'at_cashier_at'], 'idx_cashier_queue');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_app', function (Blueprint $table) {
            $table->dropIndex('idx_cashier_queue');
            $table->dropColumn(['payment_method_chosen', 'at_cashier_at']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('cashier_mode');
        });
    }
};
