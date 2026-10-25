@props(['at' => null, 'format' => 'j M, H:i', 'empty' => ''])

{{--
    An instant, on the clock the product is read on.

    Views use this rather than calling format() on the column, because
    the column is UTC and a one o'clock kickoff printed raw says 17:00.
    See App\Support\Clock.
--}}
{{ \App\Support\Clock::format($at, $format) ?? $empty }}
