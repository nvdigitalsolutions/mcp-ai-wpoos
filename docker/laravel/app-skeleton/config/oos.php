<?php

/**
 * Application-level NV oOS configuration (Proposal 149 T-0.6).
 *
 * This file is intentionally partial. `Nvoos\Laravel` ships defaults under the
 * same `oos` key via `mergeConfigFrom()`, which does
 * `array_merge($packageDefaults, $this)`, so app keys win and every package key
 * this file does not mention keeps its default. Keep it partial — copying the
 * package file here would fork those defaults.
 */

return array(

    /*
    |--------------------------------------------------------------------------
    | Admin theme (Proposal 149 T-0.6)
    |--------------------------------------------------------------------------
    |
    | Where the Filament admin palette is generated from. The admin is a direct
    | ADS consumer (it does NOT round-trip through the NDS export, which drops
    | accent-ink, danger and focus-ring — see AdminThemeGenerator's docblock).
    |
    | Default resolves to the repo's storefront tokens. Both the container
    | (`/app`) and CI (`docker/laravel/app-skeleton`) sit three levels below the
    | repo root, so one relative path serves both.
    |
    */
    'admin_theme' => array(
        'tokens_path' => env(
            'NVOOS_TOKENS_PATH',
            base_path('../../../examples/nvoos-astro-velocity/src/tokens')
        ),
    ),

);
