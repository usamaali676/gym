<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Draft / published workflow for existing content (nothing disappears: existing rows stay published) ---
        Schema::table('plans', function (Blueprint $table) {
            $table->string('status', 10)->default('published')->index(); // draft | published | archived
            $table->timestamp('published_at')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('required_entitlement', 40)->nullable(); // e.g. programs.premium
        });
        DB::table('plans')->update(['published_at' => DB::raw('created_at')]);

        Schema::table('exercises', function (Blueprint $table) {
            // `is_active` stays the website's switch; `status` is the richer state and the two are kept in step.
            $table->string('status', 10)->default('published')->index();
            $table->timestamp('published_at')->nullable();
            $table->string('required_entitlement', 40)->nullable(); // e.g. library.premium
        });
        DB::table('exercises')->where('is_active', true)->update(['status' => 'published', 'published_at' => DB::raw('created_at')]);
        DB::table('exercises')->where('is_active', false)->update(['status' => 'archived']);

        // --- Sprints and phases above the weekly template (admin-defined; the seeded PDF plan has none) ---
        Schema::create('program_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('phase'); // sprint | phase
            $table->string('name', 80);
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('starts_week'); // 1-based, relative to the user's plan_started_on
            $table->unsignedSmallInteger('ends_week')->nullable(); // null = until the end of the program
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->string('status', 10)->default('published');
            $table->timestamps();

            $table->index(['plan_id', 'sort_order']);
        });

        // --- Exercise media: rendered demonstrations, images, instructional videos ---
        Schema::create('exercise_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // demo_video | instruction_video | image | thumbnail
            $table->string('view', 10)->nullable(); // angle | side | front
            $table->string('disk', 20)->default('media');
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedBigInteger('bytes');
            $table->char('checksum', 64); // sha256 of the file: apps use it as a cache key
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 10)->default('published');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['exercise_id', 'kind', 'view']);
        });

        // --- App content managed from the dashboard ---
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('severity', 12)->default('info'); // info | promo | warning | maintenance
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->json('audience')->nullable(); // list of membership plan slugs; null = everyone
            $table->boolean('pinned')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 10)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('subtitle', 160)->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_mime', 60)->nullable();
            $table->char('image_checksum', 64)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->string('placement', 20)->default('home'); // home | programs | library
            $table->json('audience')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 10)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('category', 40)->default('general');
            $table->string('title', 140);
            $table->text('body'); // markdown subset: paragraphs, **bold**, lists, links
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 10)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->boolean('is_public')->default(true); // only public settings are sent to apps
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // --- Change feed for incremental sync ---
        Schema::create('content_changes', function (Blueprint $table) {
            $table->id(); // the sync cursor: strictly increasing, independent of clocks
            $table->string('collection', 24);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 6)->default('upsert'); // upsert | delete
            $table->timestamp('created_at')->useCurrent();

            $table->index(['collection', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_changes');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('exercise_media');
        Schema::dropIfExists('program_phases');
        Schema::table('exercises', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'published_at', 'required_entitlement']);
        });
        Schema::table('plans', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'published_at', 'description', 'cover_path', 'required_entitlement']);
        });
    }
};
