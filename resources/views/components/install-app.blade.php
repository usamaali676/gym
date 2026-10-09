{{-- Shown only when the browser can install the app (or on iOS, where it is "Add to Home Screen"), and never once installed. --}}
<div {{ $attributes }} x-data="installApp" x-show="visible" x-cloak>
    <button type="button" class="btn btn-ghost w-full" x-show="canPrompt" @click="install()"><x-icon name="download" class="size-4"/>Install the app</button>
    <p class="rounded-xl border border-line p-3 text-sm text-muted" x-show="!canPrompt && ios">To install on iPhone or iPad: tap the Share button in Safari, then <strong class="text-ink">Add to Home Screen</strong>.</p>
</div>
