<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description');
            $table->string('icon', 40);
            $table->string('category', 30);
            $table->enum('tier', ['bronze', 'silver', 'gold', 'special'])->default('bronze');
            $table->unsignedInteger('xp_reward')->default(0);
            $table->json('criteria');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('xp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->integer('amount');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 160);
            $table->timestamp('created_at')->useCurrent();

            // Idempotent awards: retrying a request cannot award the same source twice.
            $table->unique(['user_id', 'type', 'source_type', 'source_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('coach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->json('payload');
            $table->string('share_token', 40)->nullable()->unique();
            $table->timestamp('shared_until')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
        Schema::dropIfExists('coach_messages');
        Schema::dropIfExists('xp_events');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
    }
};
