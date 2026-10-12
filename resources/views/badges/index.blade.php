@php
    $categoryLabels = ['training' => 'Training', 'posture' => 'Posture Lab', 'nutrition' => 'Nutrition', 'habits' => 'Habits', 'body' => 'Body', 'level' => 'Levels'];
    $tierClass = ['bronze' => 'text-amber-600 border-amber-700/50', 'silver' => 'text-slate-200 border-slate-400/50', 'gold' => 'text-yellow-300 border-yellow-400/60', 'special' => 'text-neon border-neon/60'];
@endphp
<x-layouts.app title="Badges" page="badges">
    <div class="mb-6">
        <p class="eyebrow">Badges</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Badges and leaderboard</h1>
        <p class="mt-1 text-sm text-muted"><span class="num text-ink">{{ $earnedCount }}</span> of <span class="num text-ink">{{ $overview->count() }}</span> earned. Each one pays XP the moment you earn it.</p>
    </div>

    @foreach ($categoryLabels as $key => $label)
        @continue(! isset($groups[$key]))
        <section class="mb-8" aria-labelledby="cat-{{ $key }}">
            <h2 id="cat-{{ $key }}" class="mb-3 text-lg font-bold uppercase">{{ $label }}</h2>
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups[$key] as $row)
                    @php $b = $row['badge']; @endphp
                    <li class="glass flex gap-4 p-4 {{ $row['earned'] ? 'glass-neon' : '' }}">
                        <span class="grid size-14 shrink-0 place-items-center rounded-2xl border {{ $row['earned'] ? $tierClass[$b->tier] ?? '' : 'border-line text-muted/50' }} bg-black/30" aria-hidden="true"><x-icon :name="$b->icon" class="size-7"/></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-bold {{ $row['earned'] ? '' : 'text-muted' }}">{{ $b->name }}</h3>
                                <span class="chip !text-[0.6rem] uppercase">{{ $b->tier }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-muted">{{ $b->description }}</p>
                            @if ($row['earned'])
                                <p class="mt-2 flex items-center gap-1.5 text-xs text-neon"><x-icon name="check" class="size-3.5"/>Earned {{ $row['awarded_at']->diffForHumans() }} · +{{ $b->xp_reward }} XP</p>
                            @else
                                <div class="mt-2">
                                    <div class="h-1.5 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-label="Progress towards {{ $b->name }}" aria-valuenow="{{ $row['pct'] }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-neon/70" style="width: {{ $row['pct'] }}%"></div></div>
                                    <p class="mt-1 text-[0.7rem] text-muted"><span class="num">{{ number_format($row['value']) }}</span> / {{ number_format($row['target']) }} · +{{ $b->xp_reward }} XP</p>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    <section class="glass p-5" aria-labelledby="lb-title">
        <h2 id="lb-title" class="flex items-center gap-2 text-lg font-bold uppercase"><x-icon name="trophy" class="size-5 text-neon"/>This week's leaderboard</h2>
        <p class="mt-1 text-sm text-muted">XP earned since Monday. Only people who opted in are listed, under the public name they chose.</p>
        @if ($leaderboard->isEmpty())
            <p class="mt-4 text-sm text-muted">Nobody has opted in yet this week.</p>
        @else
            <ol class="mt-4 divide-y divide-line" aria-label="Leaderboard">
                @foreach ($leaderboard as $row)
                    <li class="flex items-center gap-3 py-2.5 {{ $row['me'] ? 'text-neon' : '' }}">
                        <span class="num grid size-8 place-items-center rounded-lg bg-white/5">{{ $row['rank'] }}</span>
                        <span class="min-w-0 flex-1 truncate font-semibold">{{ $row['name'] }}@if ($row['me']) <span class="text-xs">(you)</span>@endif</span>
                        <span class="text-xs text-muted">Lv {{ $row['level'] }}</span>
                        <span class="num w-20 text-right">{{ number_format($row['xp']) }} XP</span>
                    </li>
                @endforeach
            </ol>
        @endif
        <p class="mt-4 text-sm text-muted">
            @if ($optedIn) You are on the leaderboard. @elseif (auth()->user()->is_guest) Create an account to join the leaderboard. @else You are not on the leaderboard. @endif
            <a href="{{ route('profile.edit') }}#privacy" class="font-semibold text-neon underline underline-offset-4">Change this in your profile</a>.
        </p>
    </section>
</x-layouts.app>
