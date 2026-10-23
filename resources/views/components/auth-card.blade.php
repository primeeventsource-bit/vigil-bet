@props(['title', 'subtitle' => null])

<x-layouts.marketing :title="$title">
    <div class="mx-auto flex w-full max-w-md min-w-0 flex-col px-6 py-12">
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-2 text-sm text-[var(--vg-slate)]">{{ $subtitle }}</p>
        @endif

        @if (session('status'))
            <p role="status" class="mt-4 rounded-md border border-[var(--vg-edge)] px-3 py-2 text-sm text-[var(--vg-edge)]">
                {{ session('status') }}
            </p>
        @endif

        @if (session('error'))
            <p role="alert" class="mt-4 rounded-md border border-[var(--vg-loss)] px-3 py-2 text-sm text-[var(--vg-loss)]">
                {{ session('error') }}
            </p>
        @endif

        @if ($errors->any())
            {{-- Errors are announced, not just coloured. --}}
            <div role="alert" class="mt-4 rounded-md border border-[var(--vg-loss)] px-3 py-2 text-sm text-[var(--vg-loss)]">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="panel mt-6 p-6">
            {{ $slot }}
        </div>
    </div>
</x-layouts.marketing>
