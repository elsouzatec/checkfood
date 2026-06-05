<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->integer('number');
            $table->integer('capacity')->default(4);
            $table->uuid('qr_code_token')->unique();
            $table->string('status', 20)->default('livre');
            $table->timestamps();

            $table->unique(['restaurant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
