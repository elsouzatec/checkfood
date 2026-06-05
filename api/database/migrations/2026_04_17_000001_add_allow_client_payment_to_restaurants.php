<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // Permite que o cliente efetue o pagamento no próprio dispositivo
            $table->boolean('allow_client_payment')
                  ->default(false)
                  ->after('terminal_acquirer');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('allow_client_payment');
        });
    }
};
