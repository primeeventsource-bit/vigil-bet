@php
    /*
     * The Super Admin tabs are appended rather than filtered out of a
     * fixed list, so an ordinary operator's markup never mentions a page
     * they cannot open. The middleware 404s it either way; this is just
     * not advertising it.
     */
    $tabs = [
        ['route' => 'admin.overview', 'label' => 'Overview'],
        ['route' => 'admin.users', 'label' => 'Users'],
        ...(auth()->user()?->isSuperAdmin() ? [
            ['route' => 'admin.admins', 'label' => 'Administrators'],
            ['route' => 'admin.roles', 'label' => 'Roles'],
        ] : []),
        ['route' => 'admin.billing', 'label' => 'Billing'],
        ['route' => 'admin.picks', 'label' => 'Picks ledger'],
        ['route' => 'admin.ai', 'label' => 'AI cost'],
        ['route' => 'admin.learning', 'label' => 'Learning'],
        ['route' => 'admin.sync-health', 'label' => 'Sync health'],
        ['route' => 'admin.shadow', 'label' => 'Shadow record'],
        ['route' => 'admin.audit', 'label' => 'Audit log'],
    ];
@endphp

<nav aria-label="Admin" class="mt-4 flex flex-wrap gap-1.5 border-b border-[var(--vg-line)] pb-4">
    @foreach ($tabs as $tab)
        @php($current = request()->routeIs($tab['route']))
        <a href="{{ route($tab['route']) }}"
           @if ($current) aria-current="page" @endif
           @class([
               'rounded-md px-3 py-1.5 text-xs font-semibold transition',
               'bg-[color-mix(in_srgb,var(--vg-paper)_12%,transparent)] text-[var(--vg-paper)]' => $current,
               'text-[var(--vg-slate)] hover:text-[var(--vg-paper)]' => ! $current,
           ])>{{ $tab['label'] }}</a>
    @endforeach
</nav>
