<?php

namespace Tests\Feature;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * `list_mcp_tools` against this host's real registry — Proposal 090-M §11.
 *
 * The unit test proves the shaping rules with stubs. This proves the wiring the
 * unit test cannot: that the manifest row is servable here, that the container
 * can construct a tool which depends on the registry itself without a cycle, and
 * that what it lists is this host's surface rather than a package fixture.
 */
final class ListMcpToolsToolTest extends TestCase
{
    public function test_the_container_builds_the_catalogue_tool(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue(
            $registry->has('list_mcp_tools'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_lists_this_hosts_real_tool_surface(): void
    {
        $result = $this->catalogue()->execute();

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['data']['total']);

        $names = array_column($result['data']['tools'], 'name');

        $this->assertNotContains(
            'list_mcp_tools',
            $names,
            'The catalogue does not list itself.'
        );
        $this->assertLessThanOrEqual($result['data']['total'], $result['data']['count']);

        $first = $result['data']['tools'][0];

        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertContains($first['risk_level'], array('high', 'medium', 'low', 'info'));
        $this->assertArrayHasKey('inputSchema', $first);
    }

    public function test_it_fetches_one_definition_by_slug(): void
    {
        $result = $this->catalogue()->execute(array('tool_slug' => 'format_bytes'));

        $this->assertSame('Tool definition retrieved.', $result['message']);
        $this->assertSame('format_bytes', $result['data']['name']);
        $this->assertSame('object', $result['data']['inputSchema']['type']);
        $this->assertContains($result['data']['risk_level'], array('high', 'medium', 'low', 'info'));
    }

    /**
     * The registered catalogue tool, or a failure that names the wiring gap.
     */
    private function catalogue(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('list_mcp_tools');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
