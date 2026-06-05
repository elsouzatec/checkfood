<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('phone', 20)->nullable()->after('name');
            $table->string('role', 20)->default('client')->after('email');
            $table->boolean('is_active')->default(true)->after('role');

            // Password já existe mas precisa ser nullable (clientes não tem senha)
            $table->string('password')->nullable()->change();

            $table->index(['restaurant_id', 'phone']);
            $table->index(['restaurant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['restaurant_id']);
            $table->dropIndex(['restaurant_id', 'phone']);
            $table->dropIndex(['restaurant_id', 'role']);
            $table->dropColumn(['restaurant_id', 'phone', 'role', 'is_active']);
        });
    }
};
