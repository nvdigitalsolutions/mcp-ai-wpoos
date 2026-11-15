<?php

/**
 * Tests for the generated tool-preset catalogue.
 *
 * The catalogue exists so `tools.preset_weights` do something outside
 * WordPress. Two properties matter: the data is real (not an empty shell that
 * silently makes every weight inert), and the router can actually apply a
 * weight through it.
 *
 * Drift against the WordPress source is caught by
 * `tests/test-tool-preset-catalogue-parity.php`, not here — this file pins the
 * behaviour, that one pins the data.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue;
use Nvoos\Core\Domain\Service\Harness\ToolRouterHarness;
use PHPUnit\Framework\TestCase;

final class ToolPresetCatalogueTest extends TestCase
{
    /**
     * A tool stub with only what the router reads.
     *
     * @param array<int, string> $flags
     */
    private function tool(string $slug, array $flags): object
    {
        return new class ($slug, $flags) implements ToolCapabilityFlagsInterface {
            /**
             * @param array<int, string> $flags
             */
            public function __construct(private readonly string $slug, private readonly array $flags)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getCapabilityFlags(): array
            {
                return $this->flags;
            }
        };
    }

    public function test_the_catalogue_is_populated(): void
    {
        $presets = ToolPresetCatalogue::presets();

        $this->assertGreaterThan(50, \count($presets), 'The catalogue is a real one, not a stub.');

        foreach ($presets as $slug => $preset) {
            $this->assertArrayHasKey('tools', $preset, 'Preset ' . $slug . ' declares no tools.');
            $this->assertNotSame(array(), $preset['tools'], 'Preset ' . $slug . ' is empty.');
            $this->assertArrayHasKey('label', $preset);
        }
    }

    public function test_a_known_family_contains_a_known_tool(): void
    {
        $this->assertContains('search_content', ToolPresetCatalogue::toolsFor('essentials_internal'));
        $this->assertTrue(ToolPresetCatalogue::has('essentials_internal'));
    }

    public function test_an_unknown_family_is_empty_rather_than_an_error(): void
    {
        $this->assertSame(array(), ToolPresetCatalogue::toolsFor('not_a_family'));
        $this->assertFalse(ToolPresetCatalogue::has('not_a_family'));
    }

    public function test_the_index_maps_a_tool_to_its_families(): void
    {
        $index = ToolPresetCatalogue::index();

        $this->assertContains('essentials_internal', $index['search_content'] ?? array());
    }

    /**
     * The regression guard for the wiring: before the catalogue existed, the
     * orchestrator passed no index, so every preset weight scored zero.
     */
    public function test_a_preset_weight_applies_through_the_real_catalogue(): void
    {
        $index = ToolPresetCatalogue::index();
        $tool = $this->tool('search_content', array('read-only'));

        $base = ToolRouterHarness::scoreTool($tool, 'general');

        $boosted = ToolRouterHarness::scoreTool(
            $tool,
            'general',
            array(),
            array('essentials_internal' => 3.0),
            null,
            $index
        );

        $this->assertSame($base + 3.0, $boosted);

        // And the same weight does nothing to a tool in no family at all.
        $stranger = $this->tool('made_up_tool_xyz', array('read-only'));

        $this->assertSame(
            ToolRouterHarness::scoreTool($stranger, 'general'),
            ToolRouterHarness::scoreTool(
                $stranger,
                'general',
                array(),
                array('essentials_internal' => 3.0),
                null,
                $index
            )
        );
    }
}
