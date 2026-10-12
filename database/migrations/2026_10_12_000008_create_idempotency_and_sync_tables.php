<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A retried request with the same Idempotency-Key gets the stored answer instead of running twice.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->char('fingerprint', 64); // method + path + body: the same key must always mean the same request
            $table->unsignedSmallInteger('response_code')->nullable(); // null while the first attempt is still running
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });

        // One row per offline operation the app replays, so the same operation is applied once.
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('op_id');
            $table->string('type', 40);
            $table->string('status', 12); // applied | conflict | rejected
            $table->json('result')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'op_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('idempotency_keys');
    }
};
