<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PruneGuests extends Command
{
    protected $signature = 'ironpulse:prune-guests {--days= : Override the inactivity limit (default: IRONPULSE_GUEST_TTL_DAYS)} {--dry-run : List what would be removed}';

    protected $description = 'Delete guest accounts that nobody has used for a while (accounts that were claimed are never touched).';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('ironpulse.guest_ttl_days')));
        $cutoff = now()->subDays($days);

        $stale = User::query()
            ->where('is_guest', true)
            ->where(fn ($q) => $q->where('last_seen_at', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('created_at', '<', $cutoff)))
            ->get(['id']);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$stale->count()} guest account(s) idle for more than {$days} days.");

            return self::SUCCESS;
        }

        // One by one so the model's deleting hook can remove each user's stored files.
        $stale->each(fn (User $guest) => $guest->delete());
        $this->info("Deleted {$stale->count()} guest account(s) idle for more than {$days} days.");

        return self::SUCCESS;
    }
}
