<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Garçom vinculado à sessão (setado na primeira aprovação de pedido)
        Schema::table('sessions_app', function (Blueprint $table) {
            $table->foreignId('waiter_id')->nullable()->after('user_id')
                  ->constrained('users')->nullOnDelete();
        });

        // Chamados do cliente para o garçom
        Schema::create('waiter_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('sessions_app')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('tables');
            $table->foreignId('client_user_id')->constrained('users');
            $table->foreignId('waiter_id')->constrained('users');
            $table->string('status', 20)->default('pending'); // pending, seen, dismissed, expired
            $table->timestamp('expires_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['waiter_id', 'status']);
            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiter_calls');

        Schema::table('sessions_app', function (Blueprint $table) {
            $table->dropForeign(['waiter_id']);
            $table->dropColumn('waiter_id');
        });
    }
};
