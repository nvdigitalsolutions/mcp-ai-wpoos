<?php

/**
 * The runtime identity tool.
 *
 * The host is a mock; what is pinned here is that the payload keeps the shape
 * the schema promises even when a host returns a partial array, and that a host
 * with no identity says so instead of reporting blank fields as if they were
 * values.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Tool\McpServerInfoTool;
use PHPUnit\Framework\TestCase;

final class McpServerInfoToolTest extends TestCase
{
    public function test_it_reports_the_host_identity_under_server(): void
    {
        $result = $this->tool($this->identity())->execute();

        $this->assertTrue($result['success']);
        $this->assertSame('MCP server identity: NV oOS.', $result['message']);

        $server = $result['data']['server'];

        $this->assertSame('NV oOS', $server['name']);
        $this->assertSame('2.1.0', $server['version']);
        $this->assertSame('laravel', $server['runtime']);
        $this->assertSame('mcp-ai/v1', $server['contract']);
        $this->assertSame('Acme Ops', $server['site_name']);
        $this->assertSame('https://ops.example.test', $server['site_url']);
        $this->assertSame('https://ops.example.test/api/v1', $server['api_base']);
    }

    public function test_a_host_without_identity_says_so(): void
    {
        $tool = new McpServerInfoTool($this->createMock(ErrorFactoryInterface::class), null);

        $result = $tool->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['server']);
        $this->assertSame('This host does not expose a runtime identity.', $result['message']);
    }

    public function test_a_partial_identity_still_matches_the_promised_shape(): void
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(array('name' => 'NV oOS'));

        $server = $this->tool($identity)->execute()['data']['server'];

        $this->assertSame(
            array('name', 'version', 'runtime', 'contract', 'site_name', 'site_url', 'api_base'),
            array_keys($server),
            'Every field the schema promises is present, even when the host omitted it.'
        );
        $this->assertSame('', $server['version']);
    }

    public function test_the_schema_accepts_no_arguments(): void
    {
        $schema = $this->tool($this->identity())->getParametersSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame(array(), $schema['properties']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_it_declares_the_read_capability_and_read_only_flags(): void
    {
        $tool = $this->tool($this->identity());

        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
    }

    private function tool(RuntimeIdentityInterface $identity): McpServerInfoTool
    {
        return new McpServerInfoTool($this->createMock(ErrorFactoryInterface::class), $identity);
    }

    private function identity(): RuntimeIdentityInterface
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(
            array(
                'name'      => 'NV oOS',
                'version'   => '2.1.0',
                'runtime'   => 'laravel',
                'contract'  => 'mcp-ai/v1',
                'site_name' => 'Acme Ops',
                'site_url'  => 'https://ops.example.test',
                'api_base'  => 'https://ops.example.test/api/v1',
            )
        );

        return $identity;
    }
}
