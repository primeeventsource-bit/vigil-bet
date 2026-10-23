@props(['action', 'method' => 'POST', 'label' => 'Save', 'confirm' => null])

{{--
    Any admin write, with its reason.

    The reason field is not optional and not decorative. An entitlement
    granted with no note is indistinguishable from a bug a month later,
    and "support said it was fine" is not a record of anything. §11 asks
    for every admin write to be audited; a blank reason would make the
    audit log a list of timestamps.
--}}
<form method="POST" action="{{ $action }}"
      @if ($confirm) onsubmit="return confirm(@js($confirm))" @endif
      class="mt-3 flex flex-wrap items-end gap-2">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    {{ $slot }}

    <label class="block min-w-[14rem] flex-1 text-xs">
        <span class="text-[var(--vg-slate)]">Reason (recorded)</span>
        <input name="reason" required minlength="8" maxlength="200"
               placeholder="Why this is being done"
               class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
    </label>

    <button type="submit"
            class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-xs font-semibold hover:text-[var(--vg-paper)]">
        {{ $label }}
    </button>
</form>
