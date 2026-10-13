@props(['class' => ''])
{{-- "Designed and developed by" credit. The name and the link live in config/ironpulse.php. --}}
<aside {{ $attributes->merge(['class' => 'credit-card '.$class]) }} aria-label="Credit">
    <p class="credit-eyebrow">Designed &amp; developed by</p>
    <a href="{{ config('ironpulse.credit.url') }}" target="_blank" rel="noopener noreferrer" class="credit-link">
        <span class="credit-name">{{ config('ironpulse.credit.name') }}</span>
        <span class="credit-cta">View on LinkedIn <span aria-hidden="true">&rarr;</span></span>
    </a>
</aside>
