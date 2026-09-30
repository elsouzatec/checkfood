<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite (suíte de testes) não tem ALTER ... DROP FOREIGN KEY / MODIFY;
            // o Schema builder recria a tabela com o mesmo resultado final.
            Schema::table('sessions_app', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table('sessions_app', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        } else {
            // 1. Remove FK existente (NOT NULL + cascade)
            DB::statement('ALTER TABLE sessions_app DROP FOREIGN KEY sessions_app_user_id_foreign');

            // 2. Torna a coluna nullable
            DB::statement('ALTER TABLE sessions_app MODIFY COLUMN user_id BIGINT UNSIGNED NULL');

            // 3. Recria FK com SET NULL (sessões de guest não têm usuário)
            DB::statement(
                'ALTER TABLE sessions_app ADD CONSTRAINT sessions_app_user_id_foreign
                 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        }

        // 4. Coluna para o nome do cliente informado pelo garçom
        Schema::table('sessions_app', function (Blueprint $table) {
            $table->string('guest_name')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_app', function (Blueprint $table) {
            $table->dropColumn('guest_name');
        });

        // Remove sessões sem usuário antes de reverter o NOT NULL
        DB::statement('DELETE FROM sessions_app WHERE user_id IS NULL');

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('sessions_app', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table('sessions_app', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            return;
        }

        DB::statement('ALTER TABLE sessions_app DROP FOREIGN KEY sessions_app_user_id_foreign');
        DB::statement('ALTER TABLE sessions_app MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE sessions_app ADD CONSTRAINT sessions_app_user_id_foreign
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
        );
    }
};
