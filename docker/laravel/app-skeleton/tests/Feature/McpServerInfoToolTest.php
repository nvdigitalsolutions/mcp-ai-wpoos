<?php

namespace Tests\Feature;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * `mcp_server_info` against this deployment — Proposal 090-M §14.
 *
 * The unit test pins the shape. This pins the values an agent actually receives:
 * the product name, the runtime family and contract the diagnostics payload also
 * declares, and an API base that ends where the console routes begin. It also
 * asserts the payload carries no credential-shaped keys, because "no secrets" is
 * a rule of the contract rather than a property of one implementation.
 */
final class McpServerInfoToolTest extends TestCase
{
    public function test_the_container_builds_the_identity_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('mcp_server_info'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_reports_this_deployments_identity(): void
    {
        $server = $this->server();

        $this->assertSame('NV oOS', $server['name']);
        $this->assertSame('laravel', $server['runtime']);
        $this->assertSame('mcp-ai/v1', $server['contract']);

        $this->assertNotSame('', $server['version']);
        $this->assertNotSame('', $server['site_url']);
        $this->assertStringEndsWith('/api/v1', $server['api_base']);

        $this->assertSame(
            (string) config('app.name'),
            $server['site_name'],
            'The site name is the deployment\'s, not the product\'s.'
        );
    }

    public function test_it_returns_no_credential_shaped_keys(): void
    {
        $keys   = array();
        $server = $this->server();

        array_walk_recursive(
            $server,
            static function ($value, $key) use (&$keys): void {
                $keys[] = strtolower((string) $key);
            }
        );

        foreach (array('token', 'secret', 'password', 'api_key', 'authorization', 'email') as $needle) {
            $this->assertNotContains($needle, $keys, 'Identity must not carry "'.$needle.'".');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function server(): array
    {
        $tool = $this->app->make(ToolRegistry::class)->get('mcp_server_info');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        $result = $tool->execute();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('server', $result['data']);

        return $result['data']['server'];
    }
}
