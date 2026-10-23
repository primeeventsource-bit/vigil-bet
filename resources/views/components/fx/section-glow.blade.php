@props(['tone' => 'edge', 'class' => 'left-1/2 top-0 h-72 w-[42rem] -translate-x-1/2 -translate-y-1/3'])

<span aria-hidden="true" @class(['fx-glow', 'fx-glow-edge' => $tone === 'edge', 'fx-glow-signal' => $tone === 'signal', $class])></span>
