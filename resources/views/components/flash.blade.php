@if (session('status'))
    <div role="status" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)" x-transition.opacity
         class="glass glass-neon mb-5 flex items-start gap-3 px-4 py-3 text-sm">
        <x-icon name="check" class="mt-0.5 size-5 text-neon"/>
        <p class="flex-1">{{ session('status') }}</p>
        <button type="button" class="text-muted hover:text-ink" @click="show = false" aria-label="Dismiss"><x-icon name="x" class="size-4"/></button>
    </div>
@endif
