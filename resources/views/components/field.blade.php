@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null, 'required' => true])

@php($id = $name)

<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-medium text-[var(--vg-paper)]">
        {{ $label }}
        @unless ($required)
            <span class="font-normal text-[var(--vg-slate)]">(optional)</span>
        @endunless
    </label>

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-xs text-[var(--vg-slate)]">{{ $hint }}</p>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @if ($required) required @endif
        @if ($hint) aria-describedby="{{ $id }}-hint" @endif
        @error($name) aria-invalid="true" @enderror
        {{ $attributes->merge([
            'class' => 'w-full rounded-md border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2 text-[var(--vg-paper)] placeholder:text-[var(--vg-slate)]',
        ]) }}
    >

    @error($name)
        <p class="text-xs text-[var(--vg-loss)]">{{ $message }}</p>
    @enderror
</div>
