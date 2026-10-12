@php $isNew = ! $food->exists; @endphp
<x-admin.shell :title="$isNew ? 'New food' : 'Edit '.$food->name" section="foods" :heading="$isNew ? 'New food' : $food->name">
    <p class="mb-4"><a href="{{ route('admin.foods.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All foods</a></p>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ $isNew ? route('admin.foods.store') : route('admin.foods.update', $food) }}" class="space-y-6" x-data="{ servings: @js(array_values($servings)) }">
        @csrf @if(! $isNew) @method('PATCH') @endif
        <section class="glass grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3" aria-label="Details">
            <x-field name="name" label="Name" :value="$food->name" maxlength="160" required class="lg:col-span-2"/>
            <x-field name="brand" label="Brand (optional)" :value="$food->brand" maxlength="80"/>
            <label class="block"><span class="label">Category</span><select name="category" class="select">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category', $food->category) === $c)>{{ str_replace('_', ' ', $c) }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Diet type</span><select name="diet_type" class="select">@foreach ($diets as $d)<option value="{{ $d->value }}" @selected(old('diet_type', $food->diet_type) === $d->value)>{{ $d->label() }}</option>@endforeach</select></label>
            <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="hidden" name="is_verified" value="0"><input type="checkbox" class="check" name="is_verified" value="1" @checked(old('is_verified', $food->is_verified))> Values checked against a label or database</label>
        </section>
        <section class="glass p-5" aria-label="Nutrition per 100 g">
            <h2 class="text-sm font-bold uppercase">Per 100 g</h2>
            <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-5">
                <x-field name="kcal_100g" type="number" label="Calories" :value="$food->kcal_100g" step="0.1" min="0" max="900" required/>
                <x-field name="protein_100g" type="number" label="Protein (g)" :value="$food->protein_100g" step="0.1" min="0" max="100" required/>
                <x-field name="carbs_100g" type="number" label="Carbs (g)" :value="$food->carbs_100g" step="0.1" min="0" max="100" required/>
                <x-field name="fat_100g" type="number" label="Fat (g)" :value="$food->fat_100g" step="0.1" min="0" max="100" required/>
                <x-field name="fibre_100g" type="number" label="Fibre (g)" :value="$food->fibre_100g" step="0.1" min="0" max="100"/>
            </div>
        </section>
        <section class="glass p-5" aria-label="Servings">
            <h2 class="text-sm font-bold uppercase">Servings</h2>
            <p class="mt-1 text-xs text-muted">The first row is the default portion shown when someone adds this food.</p>
            <div class="mt-3 space-y-2">
                <template x-for="(s, i) in servings" :key="i">
                    <div class="grid grid-cols-6 items-end gap-2">
                        <label class="col-span-3"><span class="label !mb-1" x-show="i === 0">Label</span><input type="text" class="input !min-h-10" :name="`servings[${i}][label]`" x-model="s.label" maxlength="60" placeholder="e.g. 1 medium egg" :aria-label="`Serving ${i + 1} label`"></label>
                        <label class="col-span-2"><span class="label !mb-1" x-show="i === 0">Grams</span><input type="number" step="0.1" min="1" max="2000" class="input !min-h-10" :name="`servings[${i}][grams]`" x-model="s.grams" :aria-label="`Serving ${i + 1} grams`"></label>
                        <button type="button" class="btn btn-ghost btn-sm" @click="servings.splice(i, 1)" :disabled="servings.length < 2" :aria-label="`Remove serving ${i + 1}`"><x-icon name="trash" class="size-4"/></button>
                    </div>
                </template>
            </div>
            <button type="button" class="btn btn-ghost btn-sm mt-3" @click="servings.push({ label: '', grams: '' })" x-show="servings.length < 8"><x-icon name="plus" class="size-4"/>Add serving</button>
        </section>
        <button class="btn btn-primary">{{ $isNew ? 'Add food' : 'Save food' }}</button>
    </form>

    @unless ($isNew)
        <form method="POST" action="{{ route('admin.foods.destroy', $food) }}" class="mt-8" onsubmit="return confirm('Delete this food? Meals already logged keep their saved nutrition.')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Delete food</button></form>
    @endunless
</x-admin.shell>
