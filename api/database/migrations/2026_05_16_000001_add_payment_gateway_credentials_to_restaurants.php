<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // TEXT porque o ciphertext (encrypted cast) é bem maior que o token puro
            $table->text('mercadopago_access_token')->nullable()->after('terminal_acquirer');
            $table->text('mercadopago_webhook_secret')->nullable()->after('mercadopago_access_token');
            $table->string('mercadopago_public_key', 255)->nullable()->after('mercadopago_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'mercadopago_access_token',
                'mercadopago_webhook_secret',
                'mercadopago_public_key',
            ]);
        });
    }
};
