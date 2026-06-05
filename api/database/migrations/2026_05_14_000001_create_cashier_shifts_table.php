<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turno de caixa por operador.
 *
 * Cada operador pode ter no máximo um turno aberto por vez. O turno persiste
 * entre logins/F5/troca de dispositivo até ser fechado explicitamente pela
 * tela de fechamento (que registra closing_amount, expected_amount e
 * difference para conciliação).
 *
 * Pagamentos registrados pelo caixa passam a referenciar cashier_shift_id,
 * permitindo relatórios e fechamento ancorados no turno (não em "desde
 * meia-noite").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('opening_amount', 10, 2);
            $table->decimal('closing_amount', 10, 2)->nullable();
            $table->decimal('expected_amount', 10, 2)->nullable()
                  ->comment('opening_amount + total recebido em dinheiro no turno');
            $table->decimal('difference', 10, 2)->nullable()
                  ->comment('closing_amount - expected_amount (positivo = sobra, negativo = falta)');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cashier_shift_id')
                  ->nullable()
                  ->after('created_by')
                  ->constrained('cashier_shifts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cashier_shift_id');
        });

        Schema::dropIfExists('cashier_shifts');
    }
};
