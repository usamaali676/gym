<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\Device;
use App\Models\User;
use App\Services\Membership\EntitlementService;
use App\Services\Push\PushMessage;
use App\Services\Push\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pushes one published announcement to the members it is meant for, once. */
class SendAnnouncementPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $announcementId) {}

    public function handle(PushService $push, EntitlementService $entitlements): void
    {
        $announcement = Announcement::query()->published()->liveNow()->find($this->announcementId);
        if (! $announcement || $announcement->push_sent_at) {
            return;
        }
        $announcement->forceFill(['push_sent_at' => now()])->save();

        $message = new PushMessage('announcements', $announcement->title, str($announcement->body)->limit(120)->toString(), ['route' => 'announcements', 'id' => (string) $announcement->id]);

        User::query()->whereIn('id', Device::query()->whereNotNull('push_token')->whereNotNull('personal_access_token_id')->select('user_id'))
            ->chunkById(200, function ($users) use ($push, $entitlements, $announcement, $message) {
                foreach ($users as $user) {
                    if ($announcement->audience && ! in_array($entitlements->for($user)->plan?->slug, $announcement->audience, true)) {
                        continue;
                    }
                    $push->notify($user, $message);
                }
            });
    }
}
