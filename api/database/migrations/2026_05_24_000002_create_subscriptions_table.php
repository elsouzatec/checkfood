<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->unique()->constrained('restaurants')->cascadeOnDelete();
            $table->decimal('monthly_amount', 10, 2)->default(0);
            $table->unsignedTinyInteger('billing_day')->nullable(); // dia de vencimento (1-28)
            $table->date('paid_until')->nullable();                 // pago até (inclusive)
            $table->string('status', 20)->default('trial');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
