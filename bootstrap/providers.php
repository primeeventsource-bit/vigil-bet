<?php

use App\Providers\AppServiceProvider;
use App\Providers\EdgeServiceProvider;
use App\Providers\VigilServiceProvider;

return [
    AppServiceProvider::class,
    // The engine: its enums, its model registry, its odds client.
    EdgeServiceProvider::class,
    VigilServiceProvider::class,
];
