<?php

/**
 * Tests for the generated capability-flag catalogue.
 *
 * The catalogue is data, so these check the two properties data can silently
 * lose: that a known slug resolves to the flags WordPress declared, and that an
 * unknown slug answers "nothing" rather than guessing — the read-only guard
 * turns that answer into a denial.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Safety;

use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;
use PHPUnit\Framework\TestCase;

final class ToolCapabilityFlagCatalogueTest extends TestCase
{
    public function test_a_read_only_tool_resolves_to_the_flags_wordpress_declared(): void
    {
        $flags = ToolCapabilityFlagCatalogue::for('analyze_image');

        $this->assertContains('read-only', $flags);
        $this->assertContains('external-api', $flags);
    }

    public function test_a_writer_resolves_to_its_mutating_flags(): void
    {
        $flags = ToolCapabilityFlagCatalogue::for('create_post');

        $this->assertContains('write', $flags);
        $this->assertContains('state-changing', $flags);
        $this->assertNotContains('read-only', $flags);
    }

    public function test_an_unknown_slug_resolves_to_nothing(): void
    {
        $this->assertSame(array(), ToolCapabilityFlagCatalogue::for('not_a_real_tool'));
        $this->assertFalse(ToolCapabilityFlagCatalogue::has('not_a_real_tool'));
    }

    public function test_a_wrapper_inherits_the_flags_of_the_tool_it_wraps(): void
    {
        $this->assertSame(
            ToolCapabilityFlagCatalogue::for('web_search'),
            ToolCapabilityFlagCatalogue::for('web_search_validated')
        );
    }

    public function test_every_entry_is_a_sorted_lowercase_flag_list(): void
    {
        foreach (ToolCapabilityFlagCatalogue::all() as $slug => $flags) {
            $this->assertNotSame(array(), $flags, sprintf('"%s" has an empty flag list.', $slug));
            $this->assertSame($flags, array_values(array_unique($flags)), sprintf('"%s" has duplicates.', $slug));

            foreach ($flags as $flag) {
                $this->assertSame(
                    strtolower($flag),
                    $flag,
                    sprintf('"%s" declares "%s", which is not lowercase.', $slug, $flag)
                );
            }
        }
    }
}
