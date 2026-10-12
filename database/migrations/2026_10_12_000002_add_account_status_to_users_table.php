<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // active | suspended. A suspended account cannot sign in and its API tokens stop working.
            $table->string('status', 20)->default('active')->index();
            $table->string('suspended_reason', 190)->nullable();
            $table->timestamp('suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'suspended_reason', 'suspended_at']);
        });
    }
};
