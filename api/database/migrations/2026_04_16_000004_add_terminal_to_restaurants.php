<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('terminal_integration_enabled')
                  ->default(false)
                  ->after('show_order_progress');

            // Qual adquirente está ativa: mercadopago | pagseguro | stone
            $table->string('terminal_acquirer', 30)
                  ->default('mercadopago')
                  ->after('terminal_integration_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['terminal_integration_enabled', 'terminal_acquirer']);
        });
    }
};
