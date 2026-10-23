@props(['title', 'updated' => null])

{{--
    One shell for all four legal pages, so they cannot drift apart in
    layout, and so "last updated" is never quietly missing from one of
    them. Indexable: these are pages a customer is entitled to read before
    they are a customer.
--}}
<x-layouts.marketing :title="$title">
    <article class="mx-auto max-w-3xl px-6 py-12">
        <h1 class="font-[family-name:var(--font-display)] text-3xl font-bold">{{ $title }}</h1>

        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            Last updated {{ $updated ?? 'on first publication' }}.
            This is a plain-English summary and the full terms together; there is no separate
            short version that says something different.
        </p>

        <div class="prose-vigil mt-8 space-y-5 text-[15px] leading-relaxed text-[var(--vg-paper)]">
            {{ $slot }}
        </div>

        <p class="mt-10 border-t border-[var(--vg-line)] pt-6 text-sm text-[var(--vg-slate)]">
            Questions about this page: {{ config('brand.company.support_email') }}
        </p>
    </article>
</x-layouts.marketing>
