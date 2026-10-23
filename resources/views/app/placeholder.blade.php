<x-layouts.app :title="$heading">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">{{ $heading }}</h1>

    <div class="panel mt-6 p-6">
        <p class="badge badge-pass">Not built yet</p>
        <p class="mt-3 text-sm text-[var(--vg-slate)]">{{ $step }}</p>
        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            Nothing is shown here rather than something invented to fill the screen.
        </p>
    </div>
</x-layouts.app>
