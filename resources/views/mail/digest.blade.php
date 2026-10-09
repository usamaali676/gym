@php $t = $report['training']; @endphp
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#07090a;font-family:Arial,Helvetica,sans-serif;color:#e8f5e9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#07090a;"><tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#0d1210;border:1px solid #1d2a21;border-radius:16px;">
        <tr><td style="padding:24px 24px 8px;">
            <p style="margin:0;color:#39ff14;font-weight:bold;letter-spacing:3px;font-size:12px;">{{ strtoupper(config('app.name')) }}</p>
            <h1 style="margin:8px 0 0;font-size:24px;">Your week: {{ $report['period']['label'] }}</h1>
        </td></tr>
        <tr><td style="padding:8px 24px;">
            <p style="font-size:16px;margin:0 0 4px;"><strong style="color:#39ff14;font-size:28px;">{{ $t['completed'] }}</strong> of {{ $t['planned'] }} planned sessions done</p>
            <p style="margin:0;color:#9db5a2;font-size:14px;">{{ number_format($t['volume_kg']) }} kg lifted · {{ $t['sets'] }} sets · +{{ number_format($report['xp']['earned']) }} XP · streak {{ $report['streak'] }}</p>
        </td></tr>
        @if ($report['highlights'])
            <tr><td style="padding:12px 24px 0;"><p style="margin:0 0 6px;font-weight:bold;text-transform:uppercase;font-size:12px;letter-spacing:1px;">Highlights</p>
                <ul style="margin:0;padding-left:18px;color:#cfe3d2;font-size:14px;line-height:1.6;">@foreach ($report['highlights'] as $h)<li>{{ $h }}</li>@endforeach</ul></td></tr>
        @endif
        @if (! empty($report['focus']))
            <tr><td style="padding:12px 24px 0;"><p style="margin:0 0 6px;font-weight:bold;text-transform:uppercase;font-size:12px;letter-spacing:1px;">Focus for next week</p>
                <ol style="margin:0;padding-left:18px;color:#cfe3d2;font-size:14px;line-height:1.6;">@foreach ($report['focus'] as $f)<li>{{ $f }}</li>@endforeach</ol></td></tr>
        @endif
        <tr><td style="padding:20px 24px;"><a href="{{ $reportUrl }}" style="display:inline-block;background:#39ff14;color:#041006;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:10px;">Open the full report</a></td></tr>
        <tr><td style="padding:0 24px 24px;"><p style="margin:0;color:#6f857a;font-size:12px;">You get this because the weekly digest is switched on in your profile. <a href="{{ $unsubscribeUrl }}" style="color:#6f857a;">Unsubscribe in one click</a>.</p></td></tr>
    </table>
</td></tr></table>
</body>
</html>
