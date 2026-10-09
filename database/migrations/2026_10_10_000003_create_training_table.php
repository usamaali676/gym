<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_day_id')->nullable()->constrained('plan_days')->nullOnDelete();
            $table->date('scheduled_for');
            $table->enum('source', ['game', 'manual'])->default('game');
            $table->enum('status', ['in_progress', 'paused', 'completed', 'skipped', 'abandoned'])->default('in_progress');
            $table->unsignedInteger('week_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->decimal('volume_kg', 10, 2)->default(0);
            $table->unsignedInteger('sets_completed')->default(0);
            $table->unsignedInteger('xp_earned')->default(0);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();
            $table->json('autosave_state')->nullable();
            $table->uuid('client_uuid')->unique();
            $table->timestamps();

            $table->index(['user_id', 'scheduled_for']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('session_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_exercise_id')->nullable()->constrained('exercises')->nullOnDelete();
            $table->foreignId('plan_day_exercise_id')->nullable()->constrained('plan_day_exercises')->nullOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unsignedTinyInteger('target_sets');
            $table->unsignedTinyInteger('target_reps_min');
            $table->unsignedTinyInteger('target_reps_max');
            $table->decimal('target_weight_kg', 6, 2)->nullable();
            $table->enum('status', ['pending', 'done', 'skipped'])->default('pending');
            $table->timestamps();
        });

        Schema::create('session_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_exercise_id')->constrained('session_exercises')->cascadeOnDelete();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('set_number');
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->unsignedTinyInteger('reps')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('rpe', 3, 1)->nullable();
            $table->boolean('is_warmup')->default(false);
            $table->boolean('is_pr')->default(false);
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['exercise_id', 'completed_at']);
        });

        Schema::create('posture_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->foreignId('posture_rule_id')->nullable()->constrained('posture_rules')->nullOnDelete();
            $table->foreignId('workout_session_id')->nullable()->constrained('workout_sessions')->nullOnDelete();
            $table->uuid('client_uuid')->unique(); // generated in the browser so a retried save cannot be stored twice
            $table->unsignedInteger('rule_version')->default(1);
            $table->string('camera_view', 20);
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('total_reps')->default(0);
            $table->unsignedInteger('good_reps')->default(0);
            $table->unsignedInteger('bad_reps')->default(0);
            $table->unsignedInteger('hold_seconds')->nullable();
            $table->unsignedTinyInteger('form_score_avg')->default(0);
            $table->unsignedTinyInteger('form_score_min')->default(0);
            $table->json('error_counts')->nullable();
            $table->json('rep_log')->nullable();
            $table->decimal('avg_fps', 4, 1)->nullable();
            $table->string('model_variant', 20)->default('lite');
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'exercise_id']);
        });

        Schema::create('personal_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['max_weight', 'max_reps', 'est_1rm', 'best_set_volume']);
            $table->decimal('value', 8, 2);
            // The best value from before the current holder's session (the baseline a PR must beat).
            $table->decimal('previous_value', 8, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->unsignedTinyInteger('reps')->nullable();
            $table->foreignId('session_set_id')->nullable()->constrained('session_sets')->nullOnDelete();
            $table->timestamp('achieved_at');
            $table->timestamps();

            $table->unique(['user_id', 'exercise_id', 'type']);
        });

        Schema::create('progression_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_day_exercise_id')->constrained('plan_day_exercises')->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_weight_kg', 6, 2)->nullable();
            $table->unsignedTinyInteger('target_reps');
            $table->string('reason', 160);
            $table->foreignId('based_on_session_id')->nullable()->constrained('workout_sessions')->nullOnDelete();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['user_id', 'plan_day_exercise_id']);
            $table->index(['user_id', 'exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progression_targets');
        Schema::dropIfExists('personal_records');
        Schema::dropIfExists('posture_sessions');
        Schema::dropIfExists('session_sets');
        Schema::dropIfExists('session_exercises');
        Schema::dropIfExists('workout_sessions');
    }
};
