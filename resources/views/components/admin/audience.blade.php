@props(['plans', 'selected' => []])
<fieldset class="md:col-span-2">
    <legend class="label">Who sees it</legend>
    <p class="hint !mt-0 mb-2">Leave everything unticked to show it to all members.</p>
    <div class="flex flex-wrap gap-x-5 gap-y-2">
        @foreach ($plans as $plan)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="audience[]" value="{{ $plan->slug }}" @checked(in_array($plan->slug, old('audience', $selected ?? []), true))> {{ $plan->name }} members</label>
        @endforeach
    </div>
</fieldset>
