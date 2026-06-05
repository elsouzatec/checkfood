<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('sessions_app')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('tables');
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 30)->default('pendente_validacao');
            $table->string('origin', 20)->default('salao'); // Porta omnichannel
            $table->string('customer_document', 20)->nullable(); // Porta fiscal (CPF)
            $table->string('fiscal_status', 20)->nullable(); // Porta NFC-e

            // Timestamps granulares para analytics
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('kitchen_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['session_id']);
            $table->index(['table_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
