<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diet_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('goal', ['cutting', 'bulking', 'maintenance', 'recomposition']);
            $table->enum('diet_preference', ['nonveg', 'vegetarian', 'vegan', 'eggetarian']);
            $table->unsignedTinyInteger('age');
            $table->enum('sex', ['male', 'female', 'unspecified']);
            $table->decimal('height_cm', 5, 1);
            $table->decimal('weight_kg', 5, 2);
            $table->enum('activity_level', ['sedentary', 'light', 'moderate', 'active', 'very_active']);
            $table->unsignedTinyInteger('training_days_per_week')->default(5);
            $table->boolean('calorie_cycling')->default(false);
            $table->unsignedInteger('bmr');
            $table->unsignedInteger('tdee');
            $table->unsignedInteger('calories');
            $table->unsignedInteger('protein_g');
            $table->unsignedInteger('carbs_g');
            $table->unsignedInteger('fat_g');
            $table->unsignedInteger('fibre_g');
            $table->unsignedInteger('water_ml');
            $table->json('rest_day_targets')->nullable();
            $table->decimal('protein_g_per_kg', 3, 2);
            $table->string('formula_version', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('name_search', 160)->index();
            $table->string('brand', 80)->nullable();
            $table->string('category', 40)->index();
            $table->enum('diet_type', ['nonveg', 'eggetarian', 'vegetarian', 'vegan'])->default('vegan');
            $table->decimal('kcal_100g', 6, 1);
            $table->decimal('protein_100g', 5, 1);
            $table->decimal('carbs_100g', 5, 1);
            $table->decimal('fat_100g', 5, 1);
            $table->decimal('fibre_100g', 5, 1)->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });

        Schema::create('food_servings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->string('label', 60);
            $table->decimal('grams', 7, 1);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('food_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->date('logged_on');
            $table->enum('meal', ['breakfast', 'lunch', 'snack', 'dinner']);
            $table->string('name_snapshot', 160);
            $table->decimal('quantity', 8, 2);
            $table->enum('unit', ['grams', 'serving'])->default('grams');
            $table->string('serving_label', 60)->nullable();
            $table->decimal('grams', 8, 1);
            $table->decimal('kcal', 7, 1);
            $table->decimal('protein_g', 7, 1);
            $table->decimal('carbs_g', 7, 1);
            $table->decimal('fat_g', 7, 1);
            $table->decimal('fibre_g', 7, 1);
            $table->timestamps();

            $table->index(['user_id', 'logged_on']);
        });

        Schema::create('meal_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('diet_preference', ['nonveg', 'vegetarian', 'vegan', 'eggetarian']);
            $table->enum('goal', ['cutting', 'bulking', 'maintenance', 'recomposition'])->nullable();
            $table->enum('slot', ['breakfast', 'lunch', 'snack', 'dinner']);
            $table->string('name');
            $table->json('items');
            $table->unsignedInteger('kcal');
            $table->unsignedInteger('protein_g');
            $table->unsignedInteger('carbs_g');
            $table->unsignedInteger('fat_g');
            $table->unsignedInteger('fibre_g');
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('water_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('logged_on');
            $table->unsignedInteger('amount_ml');
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['user_id', 'logged_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('water_logs');
        Schema::dropIfExists('meal_templates');
        Schema::dropIfExists('food_logs');
        Schema::dropIfExists('food_servings');
        Schema::dropIfExists('foods');
        Schema::dropIfExists('diet_plans');
    }
};
