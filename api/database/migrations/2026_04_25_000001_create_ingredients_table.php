<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name', 120);
            // Unidade de cadastro/estoque: kg | l | un
            $table->enum('unit', ['kg', 'l', 'un']);
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->decimal('min_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
