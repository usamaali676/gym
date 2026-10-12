<?php

use App\Support\Entitlement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->unsignedInteger('price_minor')->default(0); // smallest currency unit (cents)
            $table->char('currency', 3)->default('USD');
            $table->string('billing_interval', 10)->default('none'); // none | week | month | year | lifetime
            $table->unsignedTinyInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('duration_days')->nullable(); // fixed-term passes (no renewal)
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedTinyInteger('grace_days')->default(0); // access kept after a failed renewal
            $table->boolean('auto_renews')->default(true);
            $table->json('features')->nullable(); // marketing bullets shown on the plan card
            $table->json('entitlements')->nullable(); // {"nutrition": true, ...}
            $table->json('limits')->nullable(); // {"custom_foods": 25, ...}
            $table->json('store_product_ids')->nullable(); // {"apple": "...", "google": "..."}
            $table->boolean('is_default')->default(false); // the plan everyone without a subscription gets
            $table->string('status', 10)->default('draft')->index(); // draft | published | archived
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->string('status', 12)->index(); // trialing | active | canceled | expired | revoked
            $table->string('source', 12); // admin | promo | apple | google
            $table->string('external_id', 190)->nullable(); // store transaction / purchase-token id
            $table->timestamp('started_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            // The moment access stops (period end + grace). NULL = no expiry. Entitlements are computed from this
            // timestamp, so a lapsed membership loses access even before any job flips its status.
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 190)->nullable();
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ends_at']);
            $table->unique(['source', 'external_id']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('provider', 12)->nullable();
            $table->string('external_event_id', 190)->nullable()->unique(); // makes store notifications idempotent
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Every deployment gets the fallback plan. It grants everything the app did before memberships existed,
        // so applying this migration changes nobody's access; restricting it is an explicit admin decision.
        DB::table('membership_plans')->insert([
            'slug' => 'free',
            'name' => 'Free',
            'description' => 'Everything you can use today.',
            'price_minor' => 0,
            'currency' => 'USD',
            'billing_interval' => 'none',
            'features' => json_encode(['Full training plan', 'Exercise library', 'Nutrition tracking', 'Posture coach']),
            'entitlements' => json_encode(array_fill_keys(Entitlement::keys(), true)),
            'limits' => json_encode((object) []),
            'is_default' => true,
            'status' => 'published',
            'published_at' => now(),
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('membership_plans');
    }
};
