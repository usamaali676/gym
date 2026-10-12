<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'food_id']);
        });

        // A reusable meal: "Post-workout shake", "Usual breakfast". Items keep a grams amount; the macros are
        // recomputed from the food when the meal is logged, so edited foods never leave stale numbers behind.
        Schema::create('saved_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('meal', 12)->nullable(); // breakfast | lunch | snack | dinner (default slot when applied)
            $table->json('items'); // [{"food_id": 12, "grams": 150}]
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_meals');
        Schema::dropIfExists('food_favorites');
    }
};
