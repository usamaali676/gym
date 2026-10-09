@props(['size' => 'md', 'iconOnly' => false])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <span class="grid place-items-center rounded-xl bg-neon text-[#041006] shadow-[var(--shadow-neon-sm)] {{ $size === 'lg' ? 'size-12' : 'size-9' }}">
        <svg viewBox="0 0 24 24" class="{{ $size === 'lg' ? 'size-7' : 'size-5' }}" fill="currentColor" aria-hidden="true"><path d="M13.2 2 4.5 13.4c-.4.5 0 1.2.6 1.2H10l-1 7.1c-.1.8.9 1.2 1.4.6l8.7-11.4c.4-.5 0-1.2-.6-1.2H14l1-7.1c.1-.8-.9-1.2-1.4-.6Z"/></svg>
    </span>
    @unless($iconOnly)
        <span class="font-display font-black tracking-[0.14em] {{ $size === 'lg' ? 'text-2xl' : 'text-base' }}">IRON<span class="text-neon text-glow">PULSE</span></span>
    @endunless
</span>
