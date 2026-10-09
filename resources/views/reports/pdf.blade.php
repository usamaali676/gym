@php
    $t = $r['training'];
    $kg = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $statusText = ['completed' => 'Done', 'skipped' => 'Skipped', 'missed' => 'Missed', 'in_progress' => 'Started', 'today' => 'Today', 'rest' => 'Rest', 'upcoming' => 'Ahead'];
    $statusColor = ['completed' => '#1a8f00', 'skipped' => '#b7791f', 'missed' => '#c53030', 'in_progress' => '#1a8f00'];
    $typeLabel = ['est_1rm' => 'est. 1RM', 'max_weight' => 'heaviest', 'max_reps' => 'most reps', 'best_set_volume' => 'best set'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $app }} weekly report {{ $r['period']['label'] }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #16211a; }
    h1 { font-size: 22px; margin: 0; letter-spacing: 1px; text-transform: uppercase; }
    h2 { font-size: 11px; letter-spacing: 1.2px; text-transform: uppercase; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #39ff14; }
    .brand { color: #1a8f00; font-weight: bold; letter-spacing: 3px; font-size: 10px; }
    .muted { color: #5b6b60; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 5px 6px; text-align: left; vertical-align: top; }
    .grid td { border: 1px solid #d7e2da; width: 25%; }
    .num { font-size: 16px; font-weight: bold; }
    .small { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #5b6b60; }
    .days td { text-align: center; border: 1px solid #d7e2da; width: 14.28%; padding: 7px 2px; }
    .list td { border-bottom: 1px solid #e6ede8; }
    ul { margin: 0; padding-left: 14px; } li { margin-bottom: 3px; }
</style>
</head>
<body>
    <p class="brand">{{ strtoupper($app) }} · WEEKLY REPORT</p>
    <h1>{{ $r['name'] }}: {{ $r['period']['label'] }}</h1>
    <p class="muted">{{ $r['period']['complete'] ? 'Final numbers for the week.' : 'Week in progress at the time of export.' }}</p>

    <h2>Training</h2>
    <p><span class="num">{{ $t['completed'] }}</span> of {{ $t['planned'] }} planned sessions{{ $t['extra'] ? ' (+'.$t['extra'].' extra)' : '' }}</p>
    <table class="days" style="margin-top:6px"><tr>
        @foreach ($t['days'] as $d)<td><span class="small">{{ $d['short'] }}</span><br><strong style="color: {{ $statusColor[$d['status']] ?? '#5b6b60' }}">{{ $statusText[$d['status']] ?? '' }}</strong></td>@endforeach
    </tr></table>
    <table class="grid" style="margin-top:8px"><tr>
        <td><span class="small">Volume</span><br><span class="num">{{ number_format($t['volume_kg']) }}</span> kg</td>
        <td><span class="small">Sets</span><br><span class="num">{{ $t['sets'] }}</span></td>
        <td><span class="small">Time training</span><br><span class="num">{{ $t['minutes'] }}</span> min</td>
        <td><span class="small">Workout streak</span><br><span class="num">{{ $r['streak'] }}</span></td>
    </tr></table>

    @if ($r['records'])
        <h2>Personal records</h2>
        <table class="list">
            @foreach ($r['records'] as $pr)
                <tr><td><strong>{{ $pr['exercise'] }}</strong></td><td style="text-align:right">{{ $kg($pr['value']) }} {{ $pr['type'] === 'max_reps' ? 'reps' : 'kg' }} <span class="muted">({{ $typeLabel[$pr['type']] ?? $pr['type'] }}@if($pr['previous']), was {{ $kg($pr['previous']) }}@endif)</span></td></tr>
            @endforeach
        </table>
    @endif

    @if (($r['posture']['sessions'] ?? 0) > 0)
        <h2>Posture Lab</h2>
        <table class="grid"><tr>
            <td><span class="small">Sets checked</span><br><span class="num">{{ $r['posture']['sessions'] }}</span></td>
            <td><span class="small">Average form</span><br><span class="num">{{ $r['posture']['avg_score'] }}</span> /100</td>
            <td><span class="small">Good reps</span><br><span class="num">{{ $r['posture']['good_reps'] }}</span> / {{ $r['posture']['total_reps'] }}</td>
            <td><span class="small">Best set</span><br>{{ $r['posture']['best']['exercise'] ?? '-' }} ({{ $r['posture']['best']['score'] ?? '-' }})</td>
        </tr></table>
    @endif

    <h2>Food, water, sleep and body</h2>
    <table class="grid">
        <tr>
            <td><span class="small">Calories (avg, logged days)</span><br><span class="num">{{ $r['nutrition']['avg_kcal'] ?? '-' }}</span>@if(! empty($r['nutrition']['target_kcal'])) / {{ $r['nutrition']['target_kcal'] }}@endif</td>
            <td><span class="small">Protein (avg)</span><br><span class="num">{{ $r['nutrition']['avg_protein_g'] ?? '-' }}</span> g @if(! empty($r['nutrition']['target_protein_g'])) / {{ $r['nutrition']['target_protein_g'] }}@endif</td>
            <td><span class="small">Water goal hit</span><br><span class="num">{{ $r['water']['days_hit'] ?? '-' }}</span> / {{ $r['water']['days_tracked'] ?? '-' }} days</td>
            <td><span class="small">Creatine</span><br><span class="num">{{ $r['creatine']['days_taken'] ?? '-' }}</span> / {{ $r['creatine']['days_tracked'] ?? '-' }} days</td>
        </tr>
        <tr>
            <td><span class="small">Sleep (avg)</span><br><span class="num">{{ $r['sleep']['avg_hours'] ?? '-' }}</span> h</td>
            <td><span class="small">Nights logged</span><br><span class="num">{{ $r['sleep']['nights_logged'] ?? '-' }}</span></td>
            <td><span class="small">Weight</span><br><span class="num">{{ $r['body']['weight_kg'] !== null ? $kg($r['body']['weight_kg']) : '-' }}</span> kg</td>
            <td><span class="small">Change this week</span><br><span class="num">{{ $r['body']['change_kg'] !== null ? (($r['body']['change_kg'] > 0 ? '+' : '').$kg($r['body']['change_kg'])) : '-' }}</span> kg</td>
        </tr>
    </table>

    <h2>XP and badges</h2>
    <p>+{{ number_format($r['xp']['earned']) }} XP this week · level {{ $r['xp']['level'] }}@if($r['badges']) · Badges: {{ collect($r['badges'])->pluck('name')->implode(', ') }}@endif</p>

    @if ($r['highlights'])
        <h2>Highlights</h2>
        <ul>@foreach ($r['highlights'] as $h)<li>{{ $h }}</li>@endforeach</ul>
    @endif
    @if (! empty($r['focus']))
        <h2>Focus for next week</h2>
        <ul>@foreach ($r['focus'] as $f)<li>{{ $f }}</li>@endforeach</ul>
    @endif

    <p class="muted" style="margin-top:22px; font-size:8px">Generated by {{ $app }}. Calorie, protein and body numbers come from what you logged; food values are typical estimates.</p>
</body>
</html>
