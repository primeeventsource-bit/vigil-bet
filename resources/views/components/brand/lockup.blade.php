@props(['size' => 'h-8'])

{{--
    Mark plus wordmark. The wordmark is set rather than drawn, so changing
    BRAND_NAME renames the product here too - a drawn wordmark would have
    to be redrawn and would quietly keep saying the old name.
--}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <x-brand.mark :size="$size" class="text-[var(--vg-paper)]" />

    <span class="font-[family-name:var(--font-display)] text-lg font-bold uppercase tracking-[0.18em] text-[var(--vg-paper)]">
        {{ config('brand.name') }}
    </span>
</span>
