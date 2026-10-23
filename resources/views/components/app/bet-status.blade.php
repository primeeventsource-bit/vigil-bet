@props(['bet'])

{{--
    The result, as a word and a colour. The word matters: roughly one man
    in twelve cannot use the colour, and "push" and "void" are different
    outcomes that would be indistinguishable as two shades of grey.
--}}
<span @class([
    'badge',
    'badge-edge' => $bet->status === 'won',
    'badge-pass' => in_array($bet->status, ['push', 'void'], true),
])>
    {{ match ($bet->status) {
        'won' => 'Won',
        'lost' => 'Lost',
        'push' => 'Push',
        'void' => 'Void',
        default => 'Running',
    } }}
</span>
