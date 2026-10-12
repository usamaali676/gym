<?php

namespace App\Console\Commands;

use App\Models\ContentChange;
use App\Models\IdempotencyKey;
use App\Models\SyncOperation;
use Illuminate\Console\Command;

class PruneApiData extends Command
{
    protected $signature = 'api:prune {--changes-days=90 : Keep the content change feed this long} {--sync-days=30 : Keep applied offline operations this long}';

    protected $description = 'Delete expired idempotency keys, old offline-sync records and old content-feed entries';

    public function handle(): int
    {
        $keys = IdempotencyKey::where('expires_at', '<', now())->delete();
        $ops = SyncOperation::where('created_at', '<', now()->subDays((int) $this->option('sync-days')))->delete();

        // Keep the newest row always, so clients always have a cursor to resume from (older cursors get a 410 and resync).
        $latest = ContentChange::latestId();
        $changes = ContentChange::where('created_at', '<', now()->subDays((int) $this->option('changes-days')))->where('id', '<', $latest)->delete();

        $this->info("Pruned {$keys} idempotency keys, {$ops} sync operations, {$changes} content changes.");

        return self::SUCCESS;
    }
}
