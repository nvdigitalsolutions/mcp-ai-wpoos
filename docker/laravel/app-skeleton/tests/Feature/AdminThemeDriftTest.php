<?php

namespace Tests\Feature;

use App\Support\AdminTheme;
use App\Support\AdminThemeGenerator;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Proposal 149 T-0.6 — the admin palette is generated, not authored.
 *
 * Two independent gates live here:
 *
 *  1. **Drift.** The committed AdminTheme.php must equal a fresh generation
 *     from the ADS tokens. Editing a token without regenerating fails, which is
 *     the same discipline as the storefront's `check:tokens`.
 *  2. **Accessibility.** The generated pairs must meet WCAG 2.2 AA. A token edit
 *     that looks fine in isolation but drops a pair below 4.5:1 fails here
 *     rather than in an audit.
 */
class AdminThemeDriftTest extends TestCase
{
    public function test_the_committed_theme_matches_a_fresh_generation(): void
    {
        $generator = app(AdminThemeGenerator::class);
        $target = base_path(AdminThemeGenerator::TARGET);

        $this->assertTrue(
            $generator->isInSync($target),
            'AdminTheme.php is out of date with the ADS design tokens. Run: php artisan admin:theme'
        );
    }

    public function test_every_declared_token_resolves_to_a_hex_colour(): void
    {
        $colors = app(AdminThemeGenerator::class)->resolve()['colors'];

        foreach (AdminThemeGenerator::COLORS as $key) {
            $this->assertArrayHasKey($key, $colors, "Semantic token '{$key}' did not resolve from the DTCG source.");
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $colors[$key],
                "Semantic token '{$key}' resolved to '{$colors[$key]}', which is not a 6-digit hex colour."
            );
        }
    }

    /**
     * The focus ring is a composite shadow, not a colour, so it is carried
     * verbatim. This pins that the `{core.*}` references inside it were
     * resolved — an unresolved `{core.palette.accent}` would render as an
     * invalid CSS value and silently drop the ring.
     */
    public function test_the_focus_ring_composite_resolves_its_references(): void
    {
        $ring = app(AdminThemeGenerator::class)->resolve()['focusRing'];

        $this->assertNotSame('', $ring);
        $this->assertStringNotContainsString('{', $ring);
        $this->assertStringContainsString(AdminTheme::COLORS['accent'], $ring);
    }

    public function test_the_filament_palette_maps_the_tokens_that_exist(): void
    {
        $this->assertSame(AdminTheme::COLORS['accent'], AdminTheme::FILAMENT_COLORS['primary']);
        $this->assertSame(AdminTheme::COLORS['danger'], AdminTheme::FILAMENT_COLORS['danger']);
        $this->assertSame(AdminTheme::COLORS['muted'], AdminTheme::FILAMENT_COLORS['gray']);
        $this->assertSame(AdminTheme::COLORS['success'], AdminTheme::FILAMENT_COLORS['success']);
        $this->assertSame(AdminTheme::COLORS['warning'], AdminTheme::FILAMENT_COLORS['warning']);
        $this->assertSame(AdminTheme::COLORS['info'], AdminTheme::FILAMENT_COLORS['info']);

        // The brand accent is green and so is success; they must not be the
        // same token or a success badge reads as a primary button.
        $this->assertNotSame(AdminTheme::FILAMENT_COLORS['primary'], AdminTheme::FILAMENT_COLORS['success']);
    }

    /**
     * Q-4 (2026-11-16): `success`/`warning`/`info` were added to the ADS
     * palette, so the generator's report is now empty. The mechanism is kept,
     * because a token removed from ADS must surface here rather than silently
     * reverting that state to Filament's default colour.
     */
    public function test_every_filament_key_is_filled_by_a_token(): void
    {
        $this->assertSame(array(), AdminTheme::MISSING_FILAMENT_KEYS);

        foreach (AdminThemeGenerator::FILAMENT_KEYS as $key) {
            $this->assertArrayHasKey($key, AdminTheme::FILAMENT_COLORS);
        }
    }

    /**
     * The report path itself: a Filament key whose token is absent is dropped
     * from the palette (and therefore lands in MISSING_FILAMENT_KEYS), never
     * filled with a guess.
     */
    public function test_a_missing_token_drops_its_filament_key(): void
    {
        $reduced = AdminTheme::COLORS;
        unset($reduced['success']);

        $palette = app(AdminThemeGenerator::class)->filamentColors($reduced);

        $this->assertArrayNotHasKey('success', $palette);
        $this->assertArrayHasKey('primary', $palette);
    }

    public function test_both_panels_use_the_generated_palette(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $this->assertSame(
                AdminTheme::FILAMENT_COLORS,
                Filament::getPanel($id)->getColors(),
                "Panel '{$id}' does not use the generated palette — a hard-coded colour cannot be drift-gated."
            );
        }
    }

    /**
     * WCAG 2.2 AA on the pairs an admin actually renders.
     *
     *  - ink on surface            4.5:1 — SC 1.4.3 (body text)
     *  - muted on surface          4.5:1 — SC 1.4.3 (secondary text)
     *  - accent-ink on accent      4.5:1 — SC 1.4.3 (primary button label)
     *  - danger on surface         4.5:1 — SC 1.4.3 (destructive text)
     *  - line-strong on surface    3:1   — SC 1.4.11 (control border)
     */
    public function test_the_authored_pairs_meet_wcag_2_2(): void
    {
        $pairs = array(
            array('ink', 'surface', 4.5),
            array('muted', 'surface', 4.5),
            array('accent-ink', 'accent', 4.5),
            array('danger', 'surface', 4.5),
            array('success', 'surface', 4.5),
            array('warning', 'surface', 4.5),
            array('info', 'surface', 4.5),
            array('line-strong', 'surface', 3.0),
        );

        foreach ($pairs as list($foreground, $background, $minimum)) {
            $ratio = $this->contrastRatio(
                AdminTheme::COLORS[$foreground],
                AdminTheme::COLORS[$background]
            );

            $this->assertGreaterThanOrEqual(
                $minimum,
                $ratio,
                sprintf(
                    '%s on %s is %.2f:1, below the %.1f:1 minimum (WCAG 2.2 AA).',
                    $foreground,
                    $background,
                    $ratio,
                    $minimum
                )
            );
        }
    }

    /**
     * WCAG 2.2 relative-luminance contrast ratio.
     *
     * Implemented here rather than pulled from a dependency: the formula is
     * fixed by the specification, and the admin must not gain an npm or
     * Composer dependency just to assert five pairs.
     */
    private function contrastRatio(string $foreground, string $background): float
    {
        $a = $this->relativeLuminance($foreground);
        $b = $this->relativeLuminance($background);

        $lighter = max($a, $b);
        $darker = min($a, $b);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channels = array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );

        $linear = array_map(function (int $value): float {
            $srgb = $value / 255;

            return $srgb <= 0.03928
                ? $srgb / 12.92
                : pow((($srgb + 0.055) / 1.055), 2.4);
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
