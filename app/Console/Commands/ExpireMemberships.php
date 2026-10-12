<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Membership\MembershipService;
use App\Services\Push\PushMessage;
use App\Services\Push\PushService;
use Illuminate\Console\Command;

class ExpireMemberships extends Command
{
    protected $signature = 'membership:maintain {--remind-days=3 : Warn members this many days before their membership ends}';

    protected $description = 'Mark lapsed memberships as expired and remind members whose membership is about to end';

    public function handle(MembershipService $memberships, PushService $push): int
    {
        $expired = $memberships->markLapsed();

        $days = max(1, (int) $this->option('remind-days'));
        $reminded = 0;

        Subscription::query()->effective()->whereNotNull('ends_at')->where('ends_at', '<=', now()->addDays($days))
            ->whereNull('expiry_reminded_at')->where('auto_renew', false)->with(['user.profile', 'plan'])->chunkById(200, function ($subscriptions) use ($push, &$reminded) {
                foreach ($subscriptions as $s) {
                    $push->notify($s->user, new PushMessage('membership', 'Your membership is ending', "{$s->plan->name} ends on {$s->ends_at->format('j M')}.", ['route' => 'membership']));
                    $s->forceFill(['expiry_reminded_at' => now()])->save();
                    $reminded++;
                }
            });

        $this->info("Marked {$expired} lapsed, reminded {$reminded}.");

        return self::SUCCESS;
    }
}
