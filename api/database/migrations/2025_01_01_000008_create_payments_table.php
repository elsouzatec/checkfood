<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela payments existia em produção sem migration no repositório (instalação
 * limpa quebrava em create_cashier_shifts, que altera payments). Recriada a partir
 * do schema de produção; cashier_shift_id continua vindo de create_cashier_shifts.
 *
 * Guarda hasTable: em bancos que já têm a tabela, a migration só é registrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('sessions_app')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('created_by', 20)->nullable();
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_payment_id', 100)->nullable()->index();
            $table->string('gateway_status', 50)->nullable();
            $table->text('qr_code')->nullable();
            $table->text('qr_code_base64')->nullable();
            $table->string('ticket_url', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('customer_document', 20)->nullable(); // Porta fiscal (CPF)
            $table->string('fiscal_status', 20)->nullable();     // Porta NFC-e
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
