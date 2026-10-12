@php
    $isToday = $date->isSameDay($today);
    $dayLabel = $isToday ? 'Today' : ($date->isSameDay($today->subDay()) ? 'Yesterday' : ($date->isSameDay($today->addDay()) ? 'Tomorrow' : $date->format('l')));
    $iconBtn = 'btn btn-ghost !min-h-11 !min-w-11 !px-0';
@endphp
<x-layouts.app title="Food log" page="food">
    <script type="application/json" id="food-config">@json($config)</script>

    <div x-data="foodLog" class="space-y-4">
        {{-- Header and date --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Food log</p>
                <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $dayLabel }}</h1>
                <p class="mt-1 text-sm text-muted">{{ $date->format('l, j F Y') }} <span x-show="day.is_training_day" x-cloak class="chip chip-neon ml-1 !text-[0.65rem]"><x-icon name="dumbbell" class="size-3"/>Training day</span><span x-show="!day.is_training_day" x-cloak class="chip ml-1 !text-[0.65rem]"><x-icon name="moon" class="size-3"/>Rest day</span></p>
            </div>
            <nav class="flex items-center gap-1.5" aria-label="Change day">
                <a href="{{ route('food.index', ['date' => $date->subDay()->toDateString()]) }}" class="{{ $iconBtn }}" aria-label="Previous day"><x-icon name="chevron-left" class="size-5"/></a>
                @unless ($isToday)<a href="{{ route('food.index') }}" class="btn btn-ghost btn-sm">Today</a>@endunless
                <a href="{{ route('food.index', ['date' => $date->addDay()->toDateString()]) }}" class="{{ $iconBtn }} {{ $date->gte($today->addDay()) ? 'pointer-events-none opacity-40' : '' }}" aria-label="Next day" @if($date->gte($today->addDay())) aria-disabled="true" tabindex="-1" @endif><x-icon name="chevron-right" class="size-5"/></a>
            </nav>
        </div>

        {{-- Targets and running totals --}}
        <section class="glass glass-neon p-4 sm:p-5" aria-labelledby="totals-title">
            <h2 id="totals-title" class="sr-only">Totals for the day</h2>
            @if ($plan)
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <template x-for="m in meters" :key="m.key">
                        <div :class="m.key === 'calories' ? 'col-span-2 lg:col-span-1' : ''">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted" x-text="m.label"></p>
                                <p class="text-xs" :class="m.over ? (m.key === 'protein_g' || m.key === 'fibre_g' ? 'text-neon' : 'text-amber-300') : 'text-muted'" x-text="m.note"></p>
                            </div>
                            <p class="mt-1"><span class="num text-2xl" x-text="m.consumed"></span><span class="text-sm text-muted"> / <span x-text="m.target"></span> <span x-text="m.unit"></span></span></p>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/10" role="progressbar" :aria-label="m.label" :aria-valuenow="m.pct" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none" :style="`width: ${m.pct}%; background: ${m.color}`"></div>
                            </div>
                        </div>
                    </template>
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-5">
                        <template x-for="m in meters" :key="m.key"><p><span class="num text-xl" x-text="m.consumed"></span> <span class="text-muted" x-text="m.unit + ' ' + m.label.toLowerCase()"></span></p></template>
                    </div>
                    <a href="{{ route('diet.index') }}" class="btn btn-ghost btn-sm">Set daily targets</a>
                </div>
            @endif
        </section>

        {{-- Protein gap --}}
        <section x-show="gapG >= 15 && day.suggestions.length" x-cloak class="rounded-2xl border border-line-strong bg-neon/5 p-4" aria-labelledby="gap-title">
            <h2 id="gap-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="bolt" class="size-4 text-neon"/>About <span class="num text-neon" x-text="Math.round(gapG)"></span> g of protein to go</h2>
            <p class="mt-1 text-sm text-muted">These fit your diet and the calories you have left. One tap adds a portion to your snacks.</p>
            <ul class="mt-3 grid grid-cols-1 gap-2 sm:flex sm:flex-wrap">
                <template x-for="s in day.suggestions" :key="s.food_id">
                    <li class="min-w-0"><button type="button" class="chip chip-neon !min-h-11 w-full cursor-pointer !items-start !whitespace-normal !px-3.5 !py-2 text-left hover:bg-neon/10 sm:w-auto sm:!items-center" @click="quickAdd(s)" :disabled="busy">
                        <x-icon name="plus" class="mt-0.5 size-3.5 shrink-0 sm:mt-0"/><span class="min-w-0"><span class="font-semibold" x-text="s.name"></span> <span class="num block text-muted sm:inline" x-text="`${s.serving} · ${Math.round(s.protein_g)} g protein`"></span></span></button></li>
                </template>
            </ul>
        </section>

        {{-- Actions and status --}}
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-primary" @click="openAdd(nextMeal())"><x-icon name="plus" class="size-5"/>Add food</button>
            <button type="button" class="btn btn-ghost" @click="copyYesterday()" :disabled="busy"><x-icon name="repeat" class="size-5"/>Copy {{ $isToday ? 'yesterday' : 'the day before' }}</button>
            <p class="text-sm text-muted" role="status" aria-live="polite" x-text="message"></p>
        </div>

        {{-- Meals --}}
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <template x-for="(label, meal) in config.meals" :key="meal">
                <section class="glass p-4 sm:p-5" :aria-labelledby="'meal-' + meal">
                    <div class="flex items-center justify-between gap-3">
                        <h2 :id="'meal-' + meal" class="text-base font-bold uppercase" x-text="label"></h2>
                        <p class="num text-sm text-muted"><span class="text-ink" x-text="mealTotals(meal).kcal"></span> kcal · <span x-text="mealTotals(meal).protein"></span> g protein</p>
                    </div>
                    <ul class="mt-2 divide-y divide-line">
                        <template x-for="log in byMeal(meal)" :key="log.id">
                            <li class="flex items-center gap-2 py-2.5">
                                <button type="button" class="min-w-0 flex-1 text-left" @click="openEdit(log)" :aria-label="`Edit ${log.name}`">
                                    <span class="block truncate text-sm font-semibold" x-text="log.name"></span>
                                    <span class="block text-xs text-muted" x-text="amountLabel(log)"></span>
                                </button>
                                <p class="num shrink-0 text-right text-sm"><span x-text="Math.round(log.kcal)"></span><span class="block text-[0.65rem] text-muted"><span x-text="Math.round(log.protein_g)"></span> g P</span></p>
                                <button type="button" class="btn btn-ghost !min-h-10 !min-w-10 !px-0" :class="confirmDelete === log.id ? '!border-danger !text-danger' : ''"
                                        @click="remove(log)" :aria-label="confirmDelete === log.id ? `Confirm: delete ${log.name}` : `Delete ${log.name}`">
                                    <x-icon name="trash" class="size-4"/></button>
                            </li>
                        </template>
                    </ul>
                    <p class="py-3 text-sm text-muted" x-show="byMeal(meal).length === 0">Nothing logged.</p>
                    <button type="button" class="btn btn-ghost btn-sm mt-1" @click="openAdd(meal)"><x-icon name="plus" class="size-4"/>Add to <span x-text="label.toLowerCase()"></span></button>
                </section>
            </template>
        </div>

        {{-- History --}}
        <section class="glass p-4 sm:p-5" aria-labelledby="history-title">
            <h2 id="history-title" class="text-base font-bold uppercase">Last 14 days</h2>
            <p class="mt-1 text-sm text-muted">Calories (bars) against your target, and protein (line).</p>
            <div class="relative mt-3 h-56"><canvas id="nutrition-chart" role="img" aria-label="Calories and protein for the last 14 days compared with your targets"></canvas></div>
            <div class="sr-only"><table>
                <caption>Calories and protein per day for the last 14 days</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Calories</th><th scope="col">Target</th><th scope="col">Protein</th></tr></thead>
                <tbody><template x-for="h in config.history" :key="h.date"><tr><th scope="row" x-text="h.date"></th><td x-text="Math.round(h.calories)"></td><td x-text="h.target_calories ?? '-'"></td><td x-text="Math.round(h.protein_g)"></td></tr></template></tbody>
            </table></div>
        </section>
        <p class="text-xs text-muted">Nutrition values are typical estimates per 100 g from public reference tables (USDA, IFCT) and labels. Real foods vary by brand, ripeness and cooking, so treat totals as close, not exact.</p>

        {{-- ================================ Add food sheet ================================ --}}
        <div x-show="sheet === 'add' || sheet === 'custom'" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="add-title" @keydown.escape.window="closeSheet()">
            <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="closeSheet()" x-transition.opacity></div>
            <div class="relative flex max-h-[92dvh] w-full max-w-lg flex-col overflow-hidden rounded-t-3xl border border-line-strong bg-surface shadow-2xl sm:rounded-3xl" x-trap.noscroll="sheet === 'add' || sheet === 'custom'" x-transition>
                <div class="flex items-center justify-between gap-3 border-b border-line p-4">
                    <h2 id="add-title" class="text-lg font-bold uppercase" x-text="sheet === 'custom' ? 'New food' : 'Add to ' + config.meals[sheetMeal].toLowerCase()"></h2>
                    <button type="button" class="btn btn-ghost !min-h-10 !min-w-10 !px-0" @click="closeSheet()" aria-label="Close"><x-icon name="x" class="size-5"/></button>
                </div>

                {{-- search and results --}}
                <div x-show="sheet === 'add' && !picked" class="min-h-0 flex-1 overflow-y-auto p-4">
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-muted"/>
                        <input x-ref="search" type="search" class="input !pl-11" placeholder="Search foods, e.g. paneer, dal, banana" aria-label="Search foods" maxlength="80" autocomplete="off" x-model="query" @input.debounce.200ms="search()">
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm text-muted" x-show="config.dietPreference && config.dietPreference !== 'nonveg'"><input type="checkbox" class="check" x-model="showAll" @change="search()"> Show foods outside my eating style</label>

                    <p class="mt-3 text-sm text-muted" x-show="searching">Searching…</p>
                    <p class="mt-3 text-sm text-muted" x-show="query.trim() && !searching && results.length === 0">No foods match. <button type="button" class="font-semibold text-neon underline underline-offset-4" @click="openCustom()">Add your own food</button>.</p>

                    <ul class="mt-3 space-y-1.5" aria-label="Search results" x-show="results.length">
                        <template x-for="f in results" :key="f.id">
                            <li><button type="button" class="flex w-full items-center justify-between gap-3 rounded-xl border border-line bg-white/[0.03] p-3 text-left hover:border-line-strong" @click="pick(f)">
                                <span class="min-w-0"><span class="block truncate text-sm font-semibold" x-text="f.name"></span><span class="block text-xs text-muted"><span x-text="Math.round(f.kcal_100g)"></span> kcal · <span x-text="f.protein_100g"></span> g protein per 100 g<span x-show="f.mine"> · yours</span></span></span>
                                <x-icon name="plus" class="size-5 shrink-0 text-neon"/></button></li>
                        </template>
                    </ul>

                    <div x-show="!query.trim() && config.recent.length" class="mt-4">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-muted">Your usual foods</h3>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            <template x-for="f in config.recent" :key="f.id"><li><button type="button" class="chip cursor-pointer !min-h-10 !px-3.5 hover:border-line-strong" @click="pick(f)" x-text="f.name"></button></li></template>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm mt-5" @click="openCustom()"><x-icon name="edit" class="size-4"/>Create a custom food</button>
                </div>

                {{-- amount --}}
                <div x-show="sheet === 'add' && picked" x-cloak class="min-h-0 flex-1 overflow-y-auto p-4">
                    <template x-if="picked">
                        <div>
                            <button type="button" class="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-ink" @click="picked = null; $nextTick(() => $refs.search?.focus())"><x-icon name="chevron-left" class="size-4"/>Back to search</button>
                            <h3 class="text-lg font-bold" x-text="picked.name"></h3>
                            <p class="text-xs text-muted"><span x-text="Math.round(picked.kcal_100g)"></span> kcal · <span x-text="picked.protein_100g"></span> g protein · <span x-text="picked.carbs_100g"></span> g carbs · <span x-text="picked.fat_100g"></span> g fat per 100 g</p>
                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <label class="block"><span class="label">Amount</span>
                                    <input type="number" inputmode="decimal" step="any" min="0.1" max="5000" class="input" x-model.number="quantity" aria-describedby="amount-help" @keydown.enter.prevent="add(false)"></label>
                                <label class="block"><span class="label">Unit</span>
                                    <select class="select" x-model="unit" @change="unitChanged()">
                                        <option value="serving" x-show="picked.servings.length">Servings</option>
                                        <option value="grams">Grams</option>
                                    </select></label>
                            </div>
                            <label class="mt-3 block" x-show="unit === 'serving'"><span class="label">Serving size</span>
                                <select class="select" x-model.number="servingId"><template x-for="s in picked.servings" :key="s.id"><option :value="s.id" x-text="s.label"></option></template></select></label>
                            <p id="amount-help" class="mt-2 text-xs text-muted">= <span class="num text-ink" x-text="Math.round(grams)"></span> g</p>

                            <div class="mt-4 grid grid-cols-4 gap-2 rounded-2xl border border-line bg-white/[0.03] p-3 text-center" aria-live="polite">
                                <p><span class="num block text-xl text-neon" x-text="Math.round(preview.kcal)"></span><span class="text-[0.65rem] uppercase tracking-wider text-muted">kcal</span></p>
                                <p><span class="num block text-xl" x-text="preview.protein_g.toFixed(1)"></span><span class="text-[0.65rem] uppercase tracking-wider text-muted">protein</span></p>
                                <p><span class="num block text-xl" x-text="preview.carbs_g.toFixed(1)"></span><span class="text-[0.65rem] uppercase tracking-wider text-muted">carbs</span></p>
                                <p><span class="num block text-xl" x-text="preview.fat_g.toFixed(1)"></span><span class="text-[0.65rem] uppercase tracking-wider text-muted">fat</span></p>
                            </div>
                            <label class="mt-3 block"><span class="label">Meal</span>
                                <select class="select" x-model="sheetMeal"><template x-for="(label, meal) in config.meals" :key="meal"><option :value="meal" x-text="label"></option></template></select></label>
                            <p class="mt-3 text-sm text-danger" role="alert" x-show="error" x-text="error"></p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary flex-1" @click="add(false)" :disabled="busy || !canAdd"><x-icon name="check" class="size-5"/>Add</button>
                                <button type="button" class="btn btn-ghost" @click="add(true)" :disabled="busy || !canAdd">Add and keep going</button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- custom food --}}
                <form x-show="sheet === 'custom'" x-cloak class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4" @submit.prevent="saveCustom()">
                    <p class="text-sm text-muted">Enter the numbers from the label, per 100 g (or 100 ml).</p>
                    <label class="block"><span class="label">Name</span><input class="input" maxlength="120" required x-model="custom.name" placeholder="e.g. Homemade protein bar"></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="label">Calories</span><input class="input" type="number" step="any" min="0" max="900" required x-model.number="custom.kcal_100g"></label>
                        <label class="block"><span class="label">Protein (g)</span><input class="input" type="number" step="any" min="0" max="100" required x-model.number="custom.protein_100g"></label>
                        <label class="block"><span class="label">Carbs (g)</span><input class="input" type="number" step="any" min="0" max="100" required x-model.number="custom.carbs_100g"></label>
                        <label class="block"><span class="label">Fat (g)</span><input class="input" type="number" step="any" min="0" max="100" required x-model.number="custom.fat_100g"></label>
                        <label class="block"><span class="label">Fibre (g)</span><input class="input" type="number" step="any" min="0" max="100" x-model.number="custom.fibre_100g"></label>
                        <label class="block"><span class="label">Eating style</span>
                            <select class="select" x-model="custom.diet_type"><option value="vegan">Vegan</option><option value="vegetarian">Vegetarian</option><option value="eggetarian">Eggetarian</option><option value="nonveg">Non-vegetarian</option></select></label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="label">A serving is...</span><input class="input" maxlength="60" x-model="custom.serving_label" placeholder="1 bar"></label>
                        <label class="block"><span class="label">...this many grams</span><input class="input" type="number" step="any" min="1" max="2000" x-model.number="custom.serving_grams"></label>
                    </div>
                    <p class="text-sm text-danger" role="alert" x-show="error" x-text="error"></p>
                    <div class="flex gap-2 pt-1"><button class="btn btn-primary flex-1" :disabled="busy">Save and use</button><button type="button" class="btn btn-ghost" @click="sheet = 'add'; error = ''">Back</button></div>
                </form>
            </div>
        </div>

        {{-- ================================ Edit entry sheet ================================ --}}
        <div x-show="sheet === 'edit'" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="edit-title" @keydown.escape.window="closeSheet()">
            <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="closeSheet()" x-transition.opacity></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-t-3xl border border-line-strong bg-surface p-5 shadow-2xl sm:rounded-3xl" x-trap.noscroll="sheet === 'edit'" x-transition>
                <template x-if="editing">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <h2 id="edit-title" class="text-lg font-bold" x-text="editing.name"></h2>
                            <button type="button" class="btn btn-ghost !min-h-10 !min-w-10 !px-0" @click="closeSheet()" aria-label="Close"><x-icon name="x" class="size-5"/></button>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <label class="block"><span class="label">Amount</span><input type="number" inputmode="decimal" step="any" min="0.1" max="5000" class="input" x-model.number="editing.quantity" @keydown.enter.prevent="saveEdit()"></label>
                            <label class="block"><span class="label">Unit</span>
                                <select class="select" x-model="editing.unit" @change="editUnitChanged()">
                                    <option value="serving" x-show="editing.servings.length">Servings</option><option value="grams">Grams</option></select></label>
                        </div>
                        <label class="mt-3 block" x-show="editing.unit === 'serving'"><span class="label">Serving size</span>
                            <select class="select" x-model.number="editing.serving_id"><template x-for="s in editing.servings" :key="s.id"><option :value="s.id" x-text="s.label"></option></template></select></label>
                        <label class="mt-3 block"><span class="label">Meal</span>
                            <select class="select" x-model="editing.meal"><template x-for="(label, meal) in config.meals" :key="meal"><option :value="meal" x-text="label"></option></template></select></label>
                        <p class="mt-3 text-sm text-danger" role="alert" x-show="error" x-text="error"></p>
                        <div class="mt-5 flex gap-2"><button type="button" class="btn btn-primary flex-1" @click="saveEdit()" :disabled="busy">Save</button><button type="button" class="btn btn-ghost" @click="closeSheet()">Cancel</button></div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-layouts.app>
