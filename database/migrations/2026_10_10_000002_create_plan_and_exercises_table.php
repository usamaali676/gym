<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // ISO: 1 = Monday ... 7 = Sunday
            $table->string('slug');
            $table->string('name', 20);
            $table->enum('session_type', ['push', 'pull', 'legs', 'rest']);
            $table->string('label', 30);
            $table->string('focus', 60);
            $table->boolean('is_rest')->default(false);
            $table->text('description')->nullable();
            $table->text('recovery_note')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'day_of_week']);
            $table->unique(['plan_id', 'slug']);
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name', 120)->index();
            $table->enum('kind', ['plan', 'alternative', 'helper', 'mobility'])->default('plan');
            $table->enum('muscle_group', [
                'chest', 'back', 'shoulders', 'biceps', 'triceps', 'quads', 'hamstrings',
                'glutes', 'calves', 'traps', 'forearms', 'core', 'full_body',
            ]);
            $table->json('secondary_groups')->nullable();
            $table->json('target_regions')->nullable();
            $table->enum('mechanic', ['compound', 'isolation']);
            $table->json('equipment')->nullable();
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced'])->default('beginner');
            $table->boolean('is_timed')->default(false);
            $table->unsignedSmallInteger('rest_min_seconds')->default(60);
            $table->unsignedSmallInteger('rest_max_seconds')->default(90);
            $table->unsignedSmallInteger('default_rest_seconds')->default(75);
            $table->string('demo_key')->nullable();
            $table->string('glb_path')->nullable();
            $table->text('description')->nullable();
            $table->json('form_cues')->nullable();
            $table->json('common_mistakes')->nullable();
            $table->text('breathing')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'muscle_group']);
            $table->index('difficulty');
        });

        Schema::create('plan_day_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unsignedTinyInteger('sets');
            $table->unsignedTinyInteger('reps_min');
            $table->unsignedTinyInteger('reps_max');
            $table->string('focus', 120);
            $table->string('label_override', 120)->nullable();
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('rest_seconds_override')->nullable();
            $table->timestamps();

            $table->unique(['plan_day_id', 'position']);
            $table->unique(['plan_day_id', 'exercise_id']);
        });

        Schema::create('posture_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->enum('tier', ['full', 'simplified', 'generic']);
            $table->enum('camera_view', ['side', 'front', 'three_quarter']);
            $table->json('config');
            $table->json('guidance')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exercise_id', 'version']);
        });

        Schema::create('user_exercise_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_day_exercise_id')->constrained('plan_day_exercises')->cascadeOnDelete();
            $table->foreignId('replacement_exercise_id')->constrained('exercises')->cascadeOnDelete();
            $table->boolean('is_permanent')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'plan_day_exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_exercise_swaps');
        Schema::dropIfExists('posture_rules');
        Schema::dropIfExists('plan_day_exercises');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('plan_days');
    }
};
