<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Used to retire abandoned guest accounts; touched at most once an hour per user.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('onboarded_at');
        });

        // One digest email per user per week, even if the scheduler runs more than once.
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->timestamp('digest_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', fn (Blueprint $table) => $table->dropColumn('digest_sent_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_seen_at'));
    }
};
