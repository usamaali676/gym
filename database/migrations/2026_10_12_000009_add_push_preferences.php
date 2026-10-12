<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            // Remote push categories (the apps' on-device reminders keep using the *_at columns above).
            $table->boolean('push_workout_reminders')->default(true);
            $table->boolean('push_membership')->default(true);
            $table->boolean('push_achievements')->default(true);
            $table->boolean('push_announcements')->default(true);
            $table->boolean('push_nutrition')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            // Off by default: a lock screen can be read by anyone, so notifications say only that something is new.
            $table->boolean('lock_screen_details')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn(['push_workout_reminders', 'push_membership', 'push_achievements', 'push_announcements', 'push_nutrition', 'quiet_hours_start', 'quiet_hours_end', 'lock_screen_details']);
        });
    }
};
