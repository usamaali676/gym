<x-layouts.app :title="'Report '.$r['period']['label']" page="reports">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="eyebrow">Weekly report</p>
            <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $r['period']['label'] }}</h1>
            <p class="mt-1 text-sm text-muted">{{ $r['period']['complete'] ? 'Final numbers for the week.' : 'The week is still running, so these numbers will keep changing.' }}</p>
        </div>
        <nav class="flex flex-wrap items-center gap-2" aria-label="Report navigation">
            <a class="btn btn-ghost btn-sm" href="{{ route('reports.show', $prev->toDateString()) }}"><x-icon name="chevron-left" class="size-4"/>Previous</a>
            @if ($next)<a class="btn btn-ghost btn-sm" href="{{ route('reports.show', $next->toDateString()) }}">Next<x-icon name="chevron-left" class="size-4 rotate-180"/></a>@endif
            <a class="btn btn-primary btn-sm" href="{{ route('reports.pdf', $start->toDateString()) }}"><x-icon name="download" class="size-4"/>PDF</a>
        </nav>
    </div>

    @include('reports._body', ['r' => $r, 'public' => false])

    <section class="glass mt-4 p-5" aria-labelledby="share-title">
        <h2 id="share-title" class="text-sm font-bold uppercase">Share this report</h2>
        <p class="mt-1 text-sm text-muted">Creates a private link anyone can open without an account. It shows training, records, posture and XP. Your weight, food, water, creatine and sleep are never included.</p>
        @if ($shareUrl)
            <div class="mt-3 space-y-2">
                <label class="block"><span class="label">Share link (expires {{ $report->shared_until->setTimezone(auth()->user()->timezone())->format('j M Y, H:i') }})</span>
                    <input type="text" readonly class="input" value="{{ $shareUrl }}" onclick="this.select()"></label>
                <form method="POST" action="{{ route('reports.unshare', $start->toDateString()) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm">Stop sharing</button></form>
            </div>
        @else
            <form method="POST" action="{{ route('reports.share', $start->toDateString()) }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <label><span class="label !mb-1">Link works for</span>
                    <select name="days" class="select !min-h-10">@foreach ($shareDays as $d)<option value="{{ $d }}">{{ $d }} days</option>@endforeach</select></label>
                <button class="btn btn-ghost btn-sm">Create link</button>
            </form>
        @endif
    </section>
</x-layouts.app>
