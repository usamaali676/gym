<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\NotificationSetting;
use App\Services\WeeklyReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyDigests extends Command
{
    protected $signature = 'ironpulse:send-digests {--force : Ignore the hour and the weekday and send to everyone who opted in} {--dry-run : List recipients without sending}';

    protected $description = 'Email the weekly report to users who opted in. Runs hourly and sends at 18:00 in each user\'s own timezone.';

    public function handle(WeeklyReportService $reports): int
    {
        $sent = 0;

        NotificationSetting::query()->where('email_digest', true)->with('user.profile.plan')->each(function (NotificationSetting $settings) use ($reports, &$sent) {
            $user = $settings->user;
            // Real addresses only: guests have none, and nobody gets mail they did not ask for.
            if (! $user || $user->is_guest || ! $user->email || ! $user->isOnboarded()) {
                return;
            }

            $now = $user->localNow();
            $due = $now->dayOfWeekIso === (int) $settings->digest_day && $now->hour === 18;
            if (! $due && ! $this->option('force')) {
                return;
            }

            // Sunday: the week that is just ending. Any other day: the last complete week.
            $anchor = $now->dayOfWeekIso === 7 ? $now : $now->subWeek();
            $weekStart = $reports->weekStart($user, $anchor);
            $report = $reports->forWeek($user, $weekStart, refresh: true);

            if ($report->digest_sent_at && ! $this->option('force')) {
                return;
            }
            if ($this->option('dry-run')) {
                $this->line("Would send to user #{$user->id} for week {$weekStart->toDateString()}");

                return;
            }

            Mail::to($user->email)->send(new WeeklyDigestMail($user, $report->payload, route('reports.show', $weekStart->toDateString())));
            $report->forceFill(['digest_sent_at' => now()])->save();
            $sent++;
        });

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
