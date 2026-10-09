<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('source')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_default')->default(false);
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('public_name', 60)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->enum('sex', ['male', 'female', 'unspecified'])->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('target_weight_kg', 5, 2)->nullable();
            $table->enum('activity_level', ['sedentary', 'light', 'moderate', 'active', 'very_active'])->nullable();
            $table->enum('fitness_level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->enum('goal', ['cutting', 'bulking', 'maintenance', 'recomposition'])->nullable();
            $table->enum('diet_preference', ['nonveg', 'vegetarian', 'vegan', 'eggetarian'])->nullable();
            $table->json('equipment')->nullable();
            $table->unsignedTinyInteger('days_per_week')->default(5);
            $table->json('training_days')->nullable();
            $table->enum('unit_system', ['metric', 'imperial'])->default('metric');
            $table->string('timezone', 64)->default('UTC');
            $table->date('plan_started_on')->nullable();
            $table->unsignedInteger('water_goal_ml')->nullable();
            $table->decimal('sleep_goal_hours', 3, 1)->default(8.0);
            $table->decimal('creatine_dose_g', 3, 1)->default(5.0);
            $table->boolean('sound_enabled')->default(true);
            $table->boolean('voice_coach')->default(false);
            $table->boolean('leaderboard_opt_in')->default(false);
            $table->boolean('ai_coach_opt_in')->default(false);
            $table->unsignedTinyInteger('onboarding_step')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('workout_reminder_at')->nullable();
            $table->boolean('water_reminders')->default(false);
            $table->unsignedSmallInteger('water_interval_minutes')->default(90);
            $table->time('creatine_reminder_at')->nullable();
            $table->time('sleep_reminder_at')->nullable();
            $table->boolean('browser_notifications')->default(false);
            $table->boolean('email_digest')->default(false);
            $table->unsignedTinyInteger('digest_day')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('plans');
    }
};
