@php
    $macroMeta = [
        'protein_g' => ['Protein', '#39FF14', 4],
        'carbs_g' => ['Carbs', '#FFC83D', 4],
        'fat_g' => ['Fat', '#4DA3FF', 9],
    ];
    $restTargets = $plan?->calorie_cycling ? $plan->rest_day_targets : null;
    $trainingTargets = $plan ? $plan->targetsFor(true) : null;
    $goals = \App\Enums\Goal::cases();
    $diets = \App\Enums\DietPreference::cases();
    $pct = fn ($v, $t) => $t > 0 ? min(100, round($v / $t * 100)) : 0;
    $mealConfig = $plan ? [
        'meals' => $meals,
        'date' => $today->toDateString(),
        'batchUrl' => route('api.food-logs.batch'),
        'target' => ['calories' => $targets['calories'], 'protein_g' => $targets['protein_g']],
    ] : null;
@endphp
<x-layouts.app title="Diet" page="diet">
    @if ($plan === null)
        <div class="glass mx-auto mt-6 max-w-xl p-8 text-center">
            <x-icon name="utensils" class="mx-auto size-10 text-neon"/>
            <h1 class="mt-3 text-xl font-black uppercase">Set up your body profile first</h1>
            <p class="mt-2 text-sm text-muted">Calorie and macro targets are calculated from your age, sex, height, weight, activity and goal.</p>
            <a href="{{ route('profile.edit') }}" class="btn btn-primary mt-5">Open profile</a>
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow">Diet</p>
                <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Your daily targets</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted">Calculated for <strong class="text-ink">{{ $plan->goal->label() }}</strong>, {{ strtolower($plan->diet_preference->label()) }}. Today is a <strong class="text-ink">{{ $isTraining ? 'training' : 'rest' }} day</strong>.</p>
            </div>
            <a href="{{ route('food.index') }}" class="btn btn-primary"><x-icon name="apple" class="size-5"/>Log food</a>
        </div>

        @if ($stale)
            <form method="POST" action="{{ route('diet.recalculate') }}" class="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-line-strong bg-neon/5 p-4 text-sm" role="status">
                @csrf
                <input type="hidden" name="goal" value="{{ $user->profile->goal->value }}">
                <input type="hidden" name="diet_preference" value="{{ $user->profile->diet_preference->value }}">
                <input type="hidden" name="calorie_cycling" value="{{ $plan->calorie_cycling ? 1 : 0 }}">
                <x-icon name="info" class="size-5 shrink-0 text-neon"/>
                <p class="flex-1">Your profile changed since these targets were calculated (weight, goal, activity or training days).</p>
                <button class="btn btn-primary btn-sm">Recalculate</button>
            </form>
        @endif

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
            {{-- Targets --}}
            <section class="glass glass-neon p-5 sm:p-6 lg:col-span-2" aria-labelledby="targets-title">
                <h2 id="targets-title" class="sr-only">Daily targets</h2>
                <div class="grid grid-cols-1 items-center gap-6 sm:grid-cols-5">
                    <div class="sm:col-span-2">
                        <x-macro-donut :protein="$targets['protein_g']" :carbs="$targets['carbs_g']" :fat="$targets['fat_g']" :calories="$targets['calories']"
                                       :label="($isTraining ? 'Training' : 'Rest').' day calories'"/>
                        <p class="mt-2 text-center text-xs text-muted">{{ $isTraining ? 'Training day' : 'Rest day' }} target</p>
                    </div>
                    <dl class="space-y-3 sm:col-span-3">
                        @foreach ($macroMeta as $key => [$label, $color, $kcalPerG])
                            @php $share = round($targets[$key] * $kcalPerG / max(1, $targets['calories']) * 100); @endphp
                            <div class="grid grid-cols-[1fr_auto] items-baseline gap-x-2">
                                <dt class="flex items-center gap-2 text-sm font-semibold"><span class="size-2.5 rounded-sm" style="background: {{ $color }}" aria-hidden="true"></span>{{ $label }}</dt>
                                <dd><span class="num text-xl">{{ $targets[$key] }}</span> g <span class="text-xs text-muted">· {{ $share }}%</span></dd>
                                <dd class="col-span-2 mt-1 h-1.5 overflow-hidden rounded-full bg-white/10" aria-hidden="true"><span class="block h-full rounded-full" style="width: {{ $share }}%; background: {{ $color }}"></span></dd>
                            </div>
                        @endforeach
                    </dl>
                    <dl class="grid grid-cols-2 gap-3 pt-1 text-sm sm:col-span-3">
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Fibre</dt><dd class="num mt-0.5 text-lg">{{ $targets['fibre_g'] }} g</dd></div>
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Water</dt><dd class="num mt-0.5 text-lg">{{ number_format($plan->water_ml + ($isTraining ? config('ironpulse.water.training_day_bonus_ml') : 0)) }} ml</dd></div>
                    </dl>
                </div>

                @if ($restTargets)
                    <div class="relative mt-6 overflow-x-auto rounded-xl border border-line">
                        <table class="w-full min-w-[22rem] text-left text-sm">
                            <caption class="sr-only">Calorie cycling targets</caption>
                            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Day type</th><th class="p-3" scope="col">Calories</th><th class="p-3" scope="col">Protein</th><th class="p-3" scope="col">Carbs</th><th class="p-3" scope="col">Fat</th></tr></thead>
                            <tbody>
                                <tr class="border-t border-line"><th class="p-3 font-semibold" scope="row">Training</th><td class="num p-3">{{ $trainingTargets['calories'] }}</td><td class="num p-3">{{ $trainingTargets['protein_g'] }} g</td><td class="num p-3">{{ $trainingTargets['carbs_g'] }} g</td><td class="num p-3">{{ $trainingTargets['fat_g'] }} g</td></tr>
                                <tr class="border-t border-line"><th class="p-3 font-semibold" scope="row">Rest</th><td class="num p-3">{{ $restTargets['calories'] }}</td><td class="num p-3">{{ $restTargets['protein_g'] }} g</td><td class="num p-3">{{ $restTargets['carbs_g'] }} g</td><td class="num p-3">{{ $restTargets['fat_g'] }} g</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-muted">Rest days eat 12% less and training days eat the difference, so the week averages out to the same {{ number_format(round(($trainingTargets['calories'] * $plan->training_days_per_week + $restTargets['calories'] * (7 - $plan->training_days_per_week)) / 7)) }} kcal a day.</p>
                @endif

                <details class="mt-6 rounded-xl border border-line p-4 text-sm">
                    <summary class="cursor-pointer font-semibold">How these numbers are calculated</summary>
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-muted">
                        <li><strong class="text-ink">BMR {{ number_format($explain['bmr']) }} kcal.</strong> Mifflin-St Jeor: 10 × weight (kg) + 6.25 × height (cm) − 5 × age {{ $plan->sex->value === 'male' ? '+ 5' : ($plan->sex->value === 'female' ? '− 161' : '− 78 (the midpoint, since no sex was given)') }}.</li>
                        <li><strong class="text-ink">TDEE {{ number_format($explain['tdee']) }} kcal.</strong> BMR × {{ $explain['multiplier'] }} for your activity level.</li>
                        <li><strong class="text-ink">Goal.</strong> {{ $plan->goal->label() }}: {{ $explain['goal_pct'] === null ? 'the weekly average is set from your TDEE, then spread across training and rest days' : (($explain['goal_pct'] > 0 ? '+' : '').$explain['goal_pct'].'% of TDEE') }}. Cuts never go below your BMR; bulks add 200 to 500 kcal.</li>
                        <li><strong class="text-ink">Protein {{ $explain['protein_g_per_kg'] }} g per kg.</strong> Higher on a cut to protect muscle (2.2 to 2.4), lower on a bulk (1.8 to 2.0).</li>
                        <li><strong class="text-ink">Fat 20 to 30% of calories, carbs the rest</strong> (at least 100 g so training stays hard), fibre 14 g per 1,000 kcal, water 35 ml per kg plus 500 ml on training days.</li>
                    </ol>
                    <p class="mt-3 text-muted">These are population formulas: an estimate, not medical advice. Weigh yourself weekly. If your weight is not moving the way you want after 2 to 3 weeks, change calories by about 5 to 10% and recalculate.</p>
                </details>
            </section>

            {{-- Settings --}}
            <aside class="space-y-4">
                <form method="POST" action="{{ route('diet.recalculate') }}" class="glass space-y-4 p-5" aria-labelledby="settings-title">
                    @csrf
                    <h2 id="settings-title" class="text-sm font-bold uppercase">Adjust</h2>
                    <label class="block"><span class="label">Goal</span>
                        <select name="goal" class="select">@foreach ($goals as $g)<option value="{{ $g->value }}" @selected($plan->goal === $g)>{{ $g->label() }}</option>@endforeach</select></label>
                    <label class="block"><span class="label">Eating style</span>
                        <select name="diet_preference" class="select">@foreach ($diets as $d)<option value="{{ $d->value }}" @selected($plan->diet_preference === $d)>{{ $d->label() }}</option>@endforeach</select></label>
                    <label class="flex items-start gap-3 text-sm"><input type="checkbox" class="check mt-0.5" name="calorie_cycling" value="1" @checked($plan->calorie_cycling)><span><strong>Calorie cycling</strong><br><span class="text-muted">Eat a little less on rest days and more on training days. Same weekly total.</span></span></label>
                    <button class="btn btn-primary w-full">Recalculate</button>
                    <p class="text-xs text-muted">Weight, height, age and activity come from your <a class="text-neon underline underline-offset-4" href="{{ route('profile.edit') }}">profile</a>: {{ rtrim(rtrim(number_format($plan->weight_kg, 1), '0'), '.') }} kg, {{ rtrim(rtrim(number_format($plan->height_cm, 1), '0'), '.') }} cm, {{ $plan->age }} years.</p>
                </form>

                <section class="glass p-5" aria-labelledby="today-title">
                    <h2 id="today-title" class="text-sm font-bold uppercase">Today so far</h2>
                    @foreach ([['Calories', 'calories', 'kcal'], ['Protein', 'protein_g', 'g']] as [$label, $key, $unit])
                        <div class="mt-3">
                            <div class="flex items-baseline justify-between text-sm"><span>{{ $label }}</span><span><span class="num">{{ round($totals[$key]) }}</span> / {{ $targets[$key] }} {{ $unit }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-label="{{ $label }} today" aria-valuenow="{{ $pct($totals[$key], $targets[$key]) }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-neon" style="width: {{ $pct($totals[$key], $targets[$key]) }}%"></div></div>
                        </div>
                    @endforeach
                    <a href="{{ route('food.index') }}" class="btn btn-ghost btn-sm mt-4 w-full">Open food log</a>
                </section>
            </aside>
        </div>

        {{-- Week --}}
        <section class="mt-6" aria-labelledby="week-title">
            <h2 id="week-title" class="mb-3 text-lg font-bold uppercase">This week</h2>
            <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                @foreach ($week as $d)
                    <li class="glass p-3 text-center {{ $d['today'] ? 'glass-neon' : '' }}" @if($d['today']) aria-current="date" @endif>
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $d['date']->format('D') }}</p>
                        <p class="num mt-1 text-xl">{{ number_format($d['targets']['calories']) }}</p>
                        <p class="text-[0.7rem] text-muted">kcal · {{ $d['training'] ? 'training' : 'rest' }}</p>
                        <p class="mt-1 text-[0.7rem] text-muted">{{ $d['targets']['protein_g'] }} g protein</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Meal plan --}}
        <section class="mt-8" aria-labelledby="meals-title" x-data="mealPlan">
            <script type="application/json" id="meal-plan-config">@json($mealConfig)</script>
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="meals-title" class="text-lg font-bold uppercase">A day of eating that fits</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted">Portions are scaled so each meal carries its share of today's calories and protein. Swap any meal, then log it in one tap.</p>
                </div>
                <p class="num text-sm text-muted" aria-live="polite">Plan total: <span class="text-ink" x-text="dayTotals.kcal"></span> kcal · <span class="text-ink" x-text="dayTotals.protein_g"></span> g protein <span class="text-xs">(target {{ $targets['calories'] }} kcal · {{ $targets['protein_g'] }} g)</span></p>
            </div>

            <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <template x-for="slot in slots" :key="slot.slot">
                    <li class="glass p-4 sm:p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="eyebrow" x-text="slot.label"></p>
                                <h3 class="mt-1 text-base font-bold" x-text="current(slot).name"></h3>
                            </div>
                            <p class="num shrink-0 text-right"><span class="text-xl" x-text="current(slot).kcal"></span><span class="block text-[0.65rem] uppercase tracking-wider text-muted">kcal</span></p>
                        </div>
                        <ul class="mt-3 space-y-1 text-sm">
                            <template x-for="item in current(slot).items" :key="item.food_id">
                                <li class="flex justify-between gap-3"><span class="min-w-0 truncate" x-text="item.name"></span><span class="num shrink-0 text-muted"><span x-text="item.grams"></span> g</span></li>
                            </template>
                        </ul>
                        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted"><span><strong class="num text-ink" x-text="current(slot).protein_g"></strong> g protein</span><span><strong class="num text-ink" x-text="current(slot).carbs_g"></strong> g carbs</span><span><strong class="num text-ink" x-text="current(slot).fat_g"></strong> g fat</span></p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" @click="swap(slot)" :disabled="slot.alternatives.length === 0"><x-icon name="swap" class="size-4"/>Swap meal</button>
                            <button type="button" class="btn btn-primary btn-sm" @click="log(slot)" :disabled="busy === slot.slot"><x-icon name="plus" class="size-4"/><span x-text="logged[slot.slot] ? 'Logged' : 'Log this meal'"></span></button>
                        </div>
                    </li>
                </template>
            </ul>
            <p class="mt-3 text-sm text-muted" role="status" aria-live="polite" x-text="message"></p>
        </section>
    @endif
</x-layouts.app>
