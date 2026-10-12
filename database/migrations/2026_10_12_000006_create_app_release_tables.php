<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per platform: the rules the apps check at launch and on every resume.
        Schema::create('app_version_policies', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10)->unique(); // android | ios
            $table->string('min_supported', 20)->default('1.0.0'); // older builds are told to update and cannot continue
            $table->string('recommended', 20)->nullable(); // older builds see a dismissible "update available"
            $table->string('latest', 20)->nullable(); // newest build published in the store
            $table->string('store_url', 255)->nullable();
            $table->text('release_notes')->nullable(); // shown with the update prompt
            $table->boolean('maintenance_enabled')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->timestamp('maintenance_ends_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10); // android | ios | all
            $table->string('version', 20);
            $table->string('build_number', 20)->nullable();
            $table->text('notes');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'published_at']);
        });

        foreach (['android', 'ios'] as $platform) {
            DB::table('app_version_policies')->insert(['platform' => $platform, 'min_supported' => '1.0.0', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
        Schema::dropIfExists('app_version_policies');
    }
};
