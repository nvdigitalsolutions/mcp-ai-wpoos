<?php

/**
 * The runtime's self-description tool.
 *
 * The properties that matter are the ones a caller can be misled by: the list
 * must reflect what the registry actually holds (not a manifest claim), it must
 * not list itself, and the pre-page total must be the total *matching*, not the
 * total returned — otherwise a paging client believes it has seen everything.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Tool\ListMcpToolsTool;
use PHPUnit\Framework\TestCase;

final class ListMcpToolsToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private ToolRegistry $registry;

    private ListMcpToolsTool $tool;

    protected function setUp(): void
    {
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);
        $this->registry = new ToolRegistry(
            $this->createMock(EventDispatcherInterface::class),
            $this->errors,
        );
        $this->tool     = new ListMcpToolsTool($this->errors, $this->registry);

        $this->registry->register($this->tool);
    }

    public function test_it_lists_enabled_tools_sorted_and_never_itself(): void
    {
        $this->registry->register($this->stub('zeta_tool', 'Last.', array('read-only')));
        $this->registry->register($this->stub('alpha_tool', 'First.', array('read-only')));

        $result = $this->tool->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame('Found 2 tool(s) (total matching: 2).', $result['message']);
        $this->assertSame(
            array('alpha_tool', 'zeta_tool'),
            array_column($result['data']['tools'], 'name'),
            'The catalogue is sorted, and `list_mcp_tools` is not one of its rows.'
        );
    }

    public function test_it_reports_the_total_before_paging(): void
    {
        foreach (array('a_tool', 'b_tool', 'c_tool', 'd_tool') as $slug) {
            $this->registry->register($this->stub($slug, 'A tool.', array('read-only')));
        }

        $result = $this->tool->execute(array('limit' => 2, 'offset' => 1));

        $this->assertSame(4, $result['data']['total'], 'total is what matched, not what was returned.');
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['limit']);
        $this->assertSame(1, $result['data']['offset']);
        $this->assertSame(array('b_tool', 'c_tool'), array_column($result['data']['tools'], 'name'));
    }

    public function test_the_limit_is_clamped_rather_than_trusted(): void
    {
        $this->registry->register($this->stub('a_tool', 'A tool.', array('read-only')));

        $result = $this->tool->execute(array('limit' => 5000));

        $this->assertSame(200, $result['data']['limit']);
    }

    public function test_search_matches_slug_or_description(): void
    {
        $this->registry->register($this->stub('find_orders', 'Looks up an order.', array('read-only')));
        $this->registry->register($this->stub('send_email', 'Sends a message.', array('read-only')));

        $bySlug = $this->tool->execute(array('search' => 'orders'));
        $this->assertSame(array('find_orders'), array_column($bySlug['data']['tools'], 'name'));

        $byDescription = $this->tool->execute(array('search' => 'MESSAGE'));
        $this->assertSame(array('send_email'), array_column($byDescription['data']['tools'], 'name'));
    }

    public function test_a_disabled_tool_is_absent_from_the_catalogue(): void
    {
        $this->registry->register($this->stub('hidden_tool', 'Should not appear.', array('read-only')));
        $this->registry->disable('hidden_tool');

        $result = $this->tool->execute();

        $this->assertSame(0, $result['data']['total']);
    }

    public function test_include_schemas_false_omits_the_schema(): void
    {
        $this->registry->register($this->stub(
            'typed_tool',
            'Takes one argument.',
            array('read-only'),
            array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'))),
        ));

        $lean = $this->tool->execute(array('include_schemas' => false));
        $this->assertArrayNotHasKey('inputSchema', $lean['data']['tools'][0]);

        $full = $this->tool->execute();
        $this->assertSame('integer', $full['data']['tools'][0]['inputSchema']['properties']['id']['type']);
    }

    public function test_tool_slug_returns_one_full_definition(): void
    {
        $this->registry->register($this->stub(
            'typed_tool',
            'Takes one argument.',
            array('read-only'),
            array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'))),
        ));

        $result = $this->tool->execute(array('tool_slug' => 'typed_tool'));

        $this->assertSame('Tool definition retrieved.', $result['message']);
        $this->assertSame('typed_tool', $result['data']['name']);
        $this->assertSame('Takes one argument.', $result['data']['description']);
        $this->assertSame('integer', $result['data']['inputSchema']['properties']['id']['type']);
        $this->assertSame('low', $result['data']['risk_level']);
    }

    public function test_an_unknown_tool_slug_is_a_not_found_error(): void
    {
        $this->errors->expects($this->once())
            ->method('notFound')
            ->willReturn(array('success' => false, 'error' => array('code' => 'not_found')));

        $result = $this->tool->execute(array('tool_slug' => 'nope'));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_asking_for_itself_falls_through_to_the_list(): void
    {
        $result = $this->tool->execute(array('tool_slug' => 'list_mcp_tools'));

        $this->assertSame('Found 0 tool(s) (total matching: 0).', $result['message']);
    }

    public function test_risk_level_follows_the_capability_flags(): void
    {
        $this->registry->register($this->stub('destructive_tool', 'Removes things.', array('destructive')));
        $this->registry->register($this->stub('writing_tool', 'Writes things.', array('state-changing')));
        $this->registry->register($this->stub('reading_tool', 'Reads things.', array('read-only')));
        $this->registry->register($this->stub('unknown_tool', 'Unknown.', array()));

        $risks = array();

        foreach ($this->tool->execute()['data']['tools'] as $entry) {
            $risks[ $entry['name'] ] = $entry['risk_level'];
        }

        $this->assertSame('high', $risks['destructive_tool']);
        $this->assertSame('medium', $risks['writing_tool']);
        $this->assertSame('low', $risks['reading_tool']);
        $this->assertSame('info', $risks['unknown_tool']);
    }

    /**
     * A tool stub carrying only what the catalogue reads.
     *
     * @param array<int, string>   $flags
     * @param array<string, mixed> $schema
     */
    private function stub(string $slug, string $description, array $flags, array $schema = array()): ToolInterface
    {
        return new class ($slug, $description, $flags, $schema) implements ToolInterface, ToolCapabilityFlagsInterface {
            /**
             * @param array<int, string>   $flags
             * @param array<string, mixed> $schema
             */
            public function __construct(
                private readonly string $slug,
                private readonly string $description,
                private readonly array $flags,
                private readonly array $schema,
            ) {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function getParametersSchema(): array
            {
                return $this->schema;
            }

            public function getRequiredCapability(): string
            {
                return 'read';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return array( 'success' => true );
            }

            public function getCapabilityFlags(): array
            {
                return $this->flags;
            }
        };
    }
}
