<?php

namespace App\Http\Controllers;

use App\Models\WeeklyReport;
use App\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReportController extends Controller
{
    public const SHARE_DAYS = [7, 14, 30];

    public function __construct(private readonly WeeklyReportService $reports) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('profile');
        $current = $this->reports->weekStart($user);
        $weeks = collect(range(0, 11))->map(fn (int $i) => $current->subWeeks($i));

        $stored = WeeklyReport::where('user_id', $user->id)
            ->whereDate('week_start', '>=', $weeks->last()->toDateString())->get()
            ->keyBy(fn (WeeklyReport $r) => $r->week_start->toDateString());

        return view('reports.index', [
            'weeks' => $weeks->map(fn (CarbonImmutable $w) => [
                'start' => $w,
                'is_current' => $w->equalTo($current),
                'payload' => $stored->get($w->toDateString())?->payload,
            ]),
            'current' => $this->reports->forWeek($user, $current),
        ]);
    }

    public function show(Request $request, string $week): View
    {
        $user = $request->user()->load('profile');
        $start = $this->resolveWeek($user, $week);
        $report = $this->reports->forWeek($user, $start, refresh: $request->boolean('refresh'));
        $current = $this->reports->weekStart($user);

        return view('reports.show', [
            'report' => $report,
            'r' => $report->payload,
            'start' => $start,
            'prev' => $start->subWeek(),
            'next' => $start->lt($current) ? $start->addWeek() : null,
            'shareUrl' => $report->share_token && $report->shared_until?->isFuture() ? route('reports.public', $report->share_token) : null,
            'shareDays' => self::SHARE_DAYS,
        ]);
    }

    public function pdf(Request $request, string $week): Response
    {
        $user = $request->user()->load('profile');
        $start = $this->resolveWeek($user, $week);
        $report = $this->reports->forWeek($user, $start);

        $options = new Options;
        $options->set('isRemoteEnabled', false);   // nothing is ever fetched while rendering
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot([resource_path('views')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.pdf', ['r' => $report->payload, 'app' => config('app.name')])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ironpulse-week-'.$start->toDateString().'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function share(Request $request, string $week): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'in:'.implode(',', self::SHARE_DAYS)]]);
        $user = $request->user()->load('profile');
        $report = $this->reports->forWeek($user, $this->resolveWeek($user, $week));

        $report->forceFill(['share_token' => Str::random(32), 'shared_until' => now()->addDays((int) $data['days'])])->save();

        return redirect()->route('reports.show', $week)->with('status', 'Share link created. It works for '.$data['days'].' days or until you stop sharing.');
    }

    public function unshare(Request $request, string $week): RedirectResponse
    {
        $user = $request->user();
        $report = WeeklyReport::where('user_id', $user->id)->whereDate('week_start', $this->resolveWeek($user, $week)->toDateString())->first();
        $report?->forceFill(['share_token' => null, 'shared_until' => null])->save();

        return redirect()->route('reports.show', $week)->with('status', 'Sharing stopped. The old link no longer works.');
    }

    /** A Monday, not in the future and not absurdly old. */
    private function resolveWeek($user, string $week): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, $user->timezone());
        abort_if(! $date || $date->toDateString() !== $week || $date->dayOfWeekIso !== 1, 404);
        abort_if($date->gt($this->reports->weekStart($user)) || $date->lt($user->localToday()->subYears(2)), 404);

        return $date;
    }
}
