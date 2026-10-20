<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega (delivery) por restaurante.
 *
 * - restaurants: liga/desliga e regras simples (taxa fixa, pedido mínimo,
 *   tempo estimado, área atendida em texto).
 * - sessions_app: a entrega é uma sessão do tipo 'entrega', sem mesa, com o
 *   endereço e a forma de pagamento escolhida (paga na entrega).
 * - orders.table_id passa a aceitar null (pedido de entrega não tem mesa).
 * - users.last_delivery_address: preenche o próximo pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('delivery_enabled')->default(false);
            $table->decimal('delivery_fee', 8, 2)->default(0);
            $table->decimal('delivery_min_order', 8, 2)->default(0);
            $table->unsignedSmallInteger('delivery_eta_minutes')->nullable();
            $table->string('delivery_area_note', 255)->nullable();
        });

        Schema::table('sessions_app', function (Blueprint $table) {
            $table->string('type', 10)->default('mesa')->after('status');
            $table->json('delivery_address')->nullable();
            $table->decimal('delivery_fee', 8, 2)->default(0);
            $table->string('delivery_notes', 255)->nullable();
            $table->decimal('change_for', 8, 2)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->index(['restaurant_id', 'type', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('last_delivery_address')->nullable();
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite (suíte de testes): o Schema builder recria a tabela.
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['table_id']);
            });
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('table_id')->nullable()->change();
                $table->foreign('table_id')->references('id')->on('tables');
            });
        } else {
            DB::statement('ALTER TABLE orders DROP FOREIGN KEY orders_table_id_foreign');
            DB::statement('ALTER TABLE orders MODIFY COLUMN table_id BIGINT UNSIGNED NULL');
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_table_id_foreign
                 FOREIGN KEY (table_id) REFERENCES tables(id)'
            );
        }
    }

    public function down(): void
    {
        // Pedidos de entrega não têm mesa: não cabem no NOT NULL antigo.
        DB::table('order_items')->whereIn('order_id', DB::table('orders')->whereNull('table_id')->select('id'))->delete();
        DB::table('orders')->whereNull('table_id')->delete();

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['table_id']);
            });
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('table_id')->nullable(false)->change();
                $table->foreign('table_id')->references('id')->on('tables');
            });
        } else {
            DB::statement('ALTER TABLE orders DROP FOREIGN KEY orders_table_id_foreign');
            DB::statement('ALTER TABLE orders MODIFY COLUMN table_id BIGINT UNSIGNED NOT NULL');
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_table_id_foreign
                 FOREIGN KEY (table_id) REFERENCES tables(id)'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_delivery_address');
        });

        Schema::table('sessions_app', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'type', 'status']);
            $table->dropColumn([
                'type', 'delivery_address', 'delivery_fee', 'delivery_notes', 'change_for',
                'accepted_at', 'dispatched_at', 'delivered_at',
            ]);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_enabled', 'delivery_fee', 'delivery_min_order',
                'delivery_eta_minutes', 'delivery_area_note',
            ]);
        });
    }
};
