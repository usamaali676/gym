@php
    $sites = ['neck' => 'Neck', 'shoulders' => 'Shoulders', 'chest' => 'Chest', 'waist' => 'Waist', 'hips' => 'Hips', 'bicep_left' => 'Left bicep', 'bicep_right' => 'Right bicep', 'thigh' => 'Thigh', 'calf' => 'Calf'];
    $change = $summary['change_30d'];
    $recordLabels = ['max_weight' => 'Heaviest', 'max_reps' => 'Most reps', 'est_1rm' => 'Est. 1RM', 'best_set_volume' => 'Best set volume'];
@endphp
<x-layouts.app title="Progress" page="progress">
    <script type="application/json" id="progress-config">@json($config)</script>

    <div class="mb-6">
        <p class="eyebrow">Progress</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Is it working?</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted">Weight, tape measurements, photos and your strongest lifts in one place. Look at trends over weeks, not single days.</p>
    </div>

    {{-- Summary --}}
    <dl class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="Body summary">
        <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Weight</dt><dd class="num mt-1 text-2xl">{{ $summary['latest']?->weight_kg ?? $user->profile?->weight_kg ?? '-' }}<span class="text-sm text-muted"> kg</span></dd></div>
        <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">30-day change</dt><dd class="num mt-1 text-2xl {{ $change === null ? '' : ($change <= 0 ? 'text-neon' : 'text-amber-300') }}">{{ $change === null ? '-' : (($change > 0 ? '+' : '').$change.' kg') }}</dd>@if ($summary['trend_per_week'] !== null)<dd class="text-xs text-muted">{{ $summary['trend_per_week'] > 0 ? '+' : '' }}{{ $summary['trend_per_week'] }} kg a week</dd>@endif</div>
        <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Goal weight</dt><dd class="num mt-1 text-2xl">{{ $target ?? '-' }}@if ($target)<span class="text-sm text-muted"> kg</span>@endif</dd>@if ($target && ($summary['latest']?->weight_kg ?? $user->profile?->weight_kg))<dd class="text-xs text-muted">{{ number_format(abs(($summary['latest']?->weight_kg ?? $user->profile->weight_kg) - $target), 1) }} kg to go</dd>@endif</div>
        <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">BMI</dt><dd class="num mt-1 text-2xl">{{ $summary['bmi'] ?? '-' }}</dd>@if ($summary['bmi_category'])<dd class="text-xs text-muted">{{ $summary['bmi_category'] }}</dd>@endif</div>
        <div class="glass col-span-2 p-4 lg:col-span-1"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Body fat</dt><dd class="num mt-1 text-2xl">{{ $summary['body_fat'] !== null ? $summary['body_fat'].'%' : '-' }}</dd><dd class="text-xs text-muted">{{ $summary['body_fat_source'] === 'estimated' ? 'Estimated from your tape (US Navy method)' : ($summary['body_fat_source'] === 'logged' ? 'As you logged it' : (($user->profile?->sex?->value ?? 'unspecified') === 'male' ? 'Add neck and waist to estimate' : 'Add neck, waist and hips to estimate')) }}</dd></div>
    </dl>
    <p class="-mt-3 mb-6 text-xs text-muted">BMI and body-fat estimates are rough population formulas. BMI cannot tell muscle from fat, so people who lift often read "overweight" while lean. Trust the trend and the mirror over any single number.</p>

    <div x-data="progressPage" class="space-y-6">
        {{-- Weight --}}
        <section class="glass p-4 sm:p-5" aria-labelledby="weight-title">
            <h2 id="weight-title" class="text-base font-bold uppercase">Body weight</h2>
            @if (count($summary['weight_points']) >= 2)
                <div class="relative mt-3 h-60"><canvas id="weight-chart" role="img" aria-label="Body weight over time"></canvas></div>
            @else
                <p class="mt-2 text-sm text-muted">Log your weight on two different days to see the trend.</p>
            @endif
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            {{-- Log measurements --}}
            <section class="glass p-4 sm:p-5 lg:col-span-2" aria-labelledby="log-title">
                <h2 id="log-title" class="text-base font-bold uppercase">Log measurements</h2>
                <form method="POST" action="{{ route('progress.measurements.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <x-field name="measured_on" type="date" label="Date" :value="old('measured_on', $today->toDateString())" :max="$today->toDateString()"/>
                        <x-field name="weight_kg" type="number" label="Weight (kg)" :value="old('weight_kg')" step="0.1" min="30" max="300"/>
                        <x-field name="body_fat_pct" type="number" label="Body fat (%)" :value="old('body_fat_pct')" step="0.1" min="2" max="60"/>
                    </div>
                    <details class="rounded-xl border border-line p-3" @if($errors->hasAny(array_map(fn ($s) => $s.'_cm', array_keys($sites)))) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold">Tape measurements (cm)</summary>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            @foreach ($sites as $key => $label)<x-field :name="$key.'_cm'" type="number" :label="$label" :value="old($key.'_cm')" step="0.1" min="5" max="250"/>@endforeach
                        </div>
                        <p class="mt-2 text-xs text-muted">Measure first thing in the morning, relaxed. Neck and waist (and hips for women) let the app estimate body fat.</p>
                    </details>
                    @error('weight_kg')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-primary w-full">Save</button>
                </form>
            </section>

            {{-- History --}}
            <section class="glass p-4 sm:p-5 lg:col-span-3" aria-labelledby="hist-title">
                <h2 id="hist-title" class="text-base font-bold uppercase">Recent entries</h2>
                @if ($recent->isEmpty())
                    <p class="mt-2 text-sm text-muted">Nothing logged yet.</p>
                @else
                    <div class="relative mt-3 overflow-x-auto">
                        <table class="w-full min-w-[26rem] text-left text-sm">
                            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="py-2 pr-3" scope="col">Date</th><th class="py-2 pr-3" scope="col">Weight</th><th class="py-2 pr-3" scope="col">Body fat</th><th class="py-2 pr-3" scope="col">Waist</th><th class="py-2" scope="col"><span class="sr-only">Delete</span></th></tr></thead>
                            <tbody>
                                @foreach ($recent as $m)
                                    <tr class="border-t border-line">
                                        <th class="py-2 pr-3 font-medium" scope="row">{{ $m->measured_on->format('j M Y') }}</th>
                                        <td class="num py-2 pr-3">{{ $m->weight_kg ? $m->weight_kg.' kg' : '-' }}</td>
                                        <td class="num py-2 pr-3">{{ $m->body_fat_pct ? $m->body_fat_pct.'%' : '-' }}</td>
                                        <td class="num py-2 pr-3">{{ $m->waist_cm ? $m->waist_cm.' cm' : '-' }}</td>
                                        <td class="py-2 text-right"><form method="POST" action="{{ route('progress.measurements.destroy', $m) }}" onsubmit="return confirm('Delete this entry?')">@csrf @method('DELETE')<button class="btn btn-ghost !min-h-9 !min-w-9 !px-0" aria-label="Delete entry from {{ $m->measured_on->format('j M Y') }}"><x-icon name="trash" class="size-4"/></button></form></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        {{-- Strength --}}
        <section class="glass p-4 sm:p-5" aria-labelledby="pr-title">
            <h2 id="pr-title" class="text-base font-bold uppercase">Strength and personal records</h2>
            @if ($records->isEmpty())
                <p class="mt-2 text-sm text-muted">Finish a workout with weights logged and your records appear here.</p>
            @else
                <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-5">
                    <div class="lg:col-span-3">
                        <label class="block"><span class="label">Exercise</span>
                            <select class="select" x-model="slug" @change="loadStrength()"><template x-for="e in exercises" :key="e.slug"><option :value="e.slug" x-text="e.name"></option></template></select></label>
                        <div class="relative mt-3 h-56"><canvas id="strength-chart" role="img" aria-label="Estimated one rep max over time for the selected exercise"></canvas></div>
                        <p class="mt-2 text-sm text-muted" x-show="empty" x-cloak>No weighted sets logged for this exercise yet.</p>
                    </div>
                    <ul class="grid content-start gap-2 lg:col-span-2" aria-label="Personal records">
                        @foreach ($records->take(8) as $r)
                            <li class="rounded-xl border border-line bg-white/[0.03] p-3">
                                <p class="text-sm font-semibold">{{ $r['exercise']->name }}</p>
                                <p class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted">
                                    @foreach ($recordLabels as $type => $label)
                                        @isset($r['records'][$type])<span>{{ $label }} <strong class="num text-ink">{{ rtrim(rtrim(number_format($r['records'][$type]->value, 1), '0'), '.') }}</strong></span>@endisset
                                    @endforeach
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- Photos --}}
        <section class="glass p-4 sm:p-5" aria-labelledby="photo-title">
            <h2 id="photo-title" class="text-base font-bold uppercase">Progress photos</h2>
            <p class="mt-1 text-sm text-muted">Private to you: stored on the server's private disk, re-encoded to strip location data, and only ever shown to you.</p>
            <form method="POST" action="{{ route('progress.photos.store') }}" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 items-end gap-3 sm:grid-cols-5">
                @csrf
                <label class="block sm:col-span-2"><span class="label">Photo</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="input !py-2 file:mr-3 file:rounded-lg file:border-0 file:bg-neon/10 file:px-3 file:py-1.5 file:text-neon"></label>
                <label class="block"><span class="label">Pose</span><select name="pose" class="select"><option value="front">Front</option><option value="side">Side</option><option value="back">Back</option></select></label>
                <label class="block"><span class="label">Taken on</span><input type="date" name="taken_on" class="input" value="{{ old('taken_on', $today->toDateString()) }}" max="{{ $today->toDateString() }}" required></label>
                <button class="btn btn-primary">Upload</button>
                @error('photo')<p class="text-sm text-danger sm:col-span-5" role="alert">{{ $message }}</p>@enderror
                @error('taken_on')<p class="text-sm text-danger sm:col-span-5" role="alert">{{ $message }}</p>@enderror
            </form>

            @if ($photos->isNotEmpty())
                <div class="mt-5 grid grid-cols-1 gap-3 rounded-2xl border border-line p-3 sm:grid-cols-2">
                    <label class="block"><span class="label">Before</span><select class="select" x-model="compareA"><option value="">Choose a photo</option>@foreach ($photos as $p)<option value="{{ $p->id }}">{{ $p->taken_on->format('j M Y') }} · {{ $p->pose }}</option>@endforeach</select></label>
                    <label class="block"><span class="label">After</span><select class="select" x-model="compareB"><option value="">Choose a photo</option>@foreach ($photos as $p)<option value="{{ $p->id }}">{{ $p->taken_on->format('j M Y') }} · {{ $p->pose }}</option>@endforeach</select></label>
                    <figure class="aspect-[3/4] overflow-hidden rounded-xl border border-line bg-black/30" x-show="compareA" x-cloak><img :src="compareA ? '{{ url('/progress/photos') }}/' + compareA : null" alt="Before photo" class="size-full object-contain"></figure>
                    <figure class="aspect-[3/4] overflow-hidden rounded-xl border border-line bg-black/30" x-show="compareB" x-cloak><img :src="compareB ? '{{ url('/progress/photos') }}/' + compareB : null" alt="After photo" class="size-full object-contain"></figure>
                </div>
                <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($photos as $p)
                        <li class="group relative">
                            <img src="{{ route('progress.photos.show', $p) }}" alt="{{ ucfirst($p->pose) }} photo from {{ $p->taken_on->format('j M Y') }}" loading="lazy" class="aspect-[3/4] w-full rounded-xl border border-line object-cover">
                            <p class="mt-1 text-xs text-muted">{{ $p->taken_on->format('j M Y') }} · {{ $p->pose }}</p>
                            <form method="POST" action="{{ route('progress.photos.destroy', $p) }}" class="absolute right-1.5 top-1.5" onsubmit="return confirm('Delete this photo for good?')">@csrf @method('DELETE')<button class="btn !min-h-9 !min-w-9 !border-line-strong !bg-black/70 !px-0" aria-label="Delete photo from {{ $p->taken_on->format('j M Y') }}"><x-icon name="trash" class="size-4"/></button></form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
