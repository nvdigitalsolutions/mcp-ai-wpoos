<?php

namespace App\Support;

/**
 * GENERATED FILE — do not edit.
 *
 * Source: ADS DTCG tokens at
 * `examples/nvoos-astro-velocity/src/tokens`
 * Regenerate: `php artisan admin:theme`
 * Verify:     `php artisan admin:theme --check` (Pest: AdminThemeDriftTest)
 *
 * Proposal 149 T-0.6. Resolved for the light theme; dark mode is a
 * separate pass (T-0.6 follow-up) and must reuse the same generator.
 */
final class AdminTheme
{
    public const COLORS = array(
        'ink' => '#1a1a1a',
        'muted' => '#6b6b6b',
        'surface' => '#ffffff',
        'surface-alt' => '#f5f5f4',
        'line' => '#e4e4e2',
        'line-strong' => '#767676',
        'accent' => '#0f6b4f',
        'accent-ink' => '#ffffff',
        'danger' => '#b3261e',
        'success' => '#2e7d32',
        'warning' => '#8a5300',
        'info' => '#0b5fa5',
    );

    public const FOCUS_RING = '0 0 0 2px #ffffff, 0 0 0 4px #0f6b4f';

    public const FILAMENT_COLORS = array(
        'primary' => '#0f6b4f',
        'danger' => '#b3261e',
        'gray' => '#6b6b6b',
        'success' => '#2e7d32',
        'warning' => '#8a5300',
        'info' => '#0b5fa5',
    );

    public const MISSING_FILAMENT_KEYS = array();
}
