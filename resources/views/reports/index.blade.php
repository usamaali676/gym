<x-layouts.app title="Weekly reports" page="reports">
    <div class="mb-6">
        <p class="eyebrow">Weekly report</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">How did the week go?</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted">One page per week: training, records, form, food, water, sleep and a few things to work on. Download it as a PDF, or share the training part by link.</p>
    </div>

    <a href="{{ route('reports.show', $current->week_start->toDateString()) }}" class="glass glass-neon glass-hover mb-6 block p-5">
        <p class="eyebrow">This week</p>
        <p class="mt-2 text-xl font-bold">{{ $current->payload['period']['label'] }}</p>
        <p class="mt-1 text-sm text-muted"><span class="num text-neon">{{ $current->payload['training']['completed'] }}</span> of {{ $current->payload['training']['planned'] }} sessions done so far · +{{ number_format($current->payload['xp']['earned']) }} XP</p>
    </a>

    <h2 class="mb-3 text-lg font-bold uppercase">Previous weeks</h2>
    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($weeks->reject(fn ($w) => $w['is_current']) as $w)
            @php $p = $w['payload']; @endphp
            <li>
                <a href="{{ route('reports.show', $w['start']->toDateString()) }}" class="glass glass-hover block p-4">
                    <p class="font-bold">{{ $w['start']->format('j M') }} - {{ $w['start']->addDays(6)->format('j M Y') }}</p>
                    <p class="mt-1 text-sm text-muted">@if ($p)<span class="num text-ink">{{ $p['training']['completed'] }}</span> of {{ $p['training']['planned'] }} sessions · +{{ number_format($p['xp']['earned']) }} XP @else Open to build this week's report @endif</p>
                </a>
            </li>
        @endforeach
    </ul>
</x-layouts.app>
