<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\OperatorPanelProvider;
use App\Providers\WifiServiceProvider;

/**
 * Providers that boot on every request.
 *
 * The two Filament panel providers are registered explicitly rather than left
 * to package discovery: the panel ids (`operator`, `admin`) are load-bearing —
 * `AdminAccessPolicy` dispatches on them — so they must be visible in the
 * bootstrap, not implied by a vendor default.
 */
return [
    AppServiceProvider::class,
    WifiServiceProvider::class,
    OperatorPanelProvider::class,
    AdminPanelProvider::class,
];
