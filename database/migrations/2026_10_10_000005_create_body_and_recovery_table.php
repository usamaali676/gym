<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('body_fat_pct', 4, 1)->nullable();
            foreach (['neck', 'shoulders', 'chest', 'waist', 'hips', 'bicep_left', 'bicep_right', 'thigh', 'calf'] as $site) {
                $table->decimal("{$site}_cm", 5, 1)->nullable();
            }
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'measured_on']);
        });

        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('taken_on');
            $table->enum('pose', ['front', 'side', 'back']);
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'taken_on']);
        });

        Schema::create('daily_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->boolean('creatine_taken')->default(false);
            $table->timestamp('creatine_taken_at')->nullable();
            $table->decimal('sleep_hours', 3, 1)->nullable();
            $table->unsignedTinyInteger('sleep_quality')->nullable();
            $table->time('bedtime')->nullable();
            $table->time('wake_time')->nullable();
            $table->unsignedInteger('mobility_minutes')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_logs');
        Schema::dropIfExists('progress_photos');
        Schema::dropIfExists('measurements');
    }
};
