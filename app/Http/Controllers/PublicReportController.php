<?php

namespace App\Http\Controllers;

use App\Models\WeeklyReport;
use App\Services\WeeklyReportService;
use Illuminate\Http\Response;

/** A report shared by link. Anyone with the link can read the training summary; nothing about body, food or sleep is included. */
class PublicReportController extends Controller
{
    public function show(string $token, WeeklyReportService $reports): Response
    {
        $report = WeeklyReport::where('share_token', $token)->where('shared_until', '>', now())->first();
        abort_unless($report, 404);

        return response()->view('reports.public', ['r' => $reports->shareable($report->payload)])->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
