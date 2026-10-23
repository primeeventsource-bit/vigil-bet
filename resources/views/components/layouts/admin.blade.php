@props(['title'])

<x-layouts.app :title="'Admin — '.$title">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">{{ $title }}</h1>
        <p class="font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
            Every write on these pages is recorded in the audit log.
        </p>
    </div>

    <x-admin.nav />

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[color-mix(in_srgb,var(--vg-edge)_40%,transparent)] bg-[color-mix(in_srgb,var(--vg-edge)_10%,transparent)] px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{ $slot }}
</x-layouts.app>
