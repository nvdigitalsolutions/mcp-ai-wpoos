<?php
/**
 * Integration test for ToolRegistry — verifies end-to-end tool execution
 * flow with real domain events, error handling, and capability checks.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Integration;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use PHPUnit\Framework\TestCase;

/**
 * A simple test tool used throughout integration tests.
 */
final class TestTool implements ToolInterface {

	public function getSlug(): string {
		return 'test_tool';
	}

	public function getName(): string {
		return 'Test Tool';
	}

	public function getDescription(): string {
		return 'A test tool for integration testing.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'value' => array( 'type' => 'string' ),
			),
			'required'   => array(),
		);
	}

	public function getRequiredCapability(): string {
		return '';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$value = $arguments['value'] ?? 'default';
		return array(
			'success' => true,
			'message' => 'Tool executed successfully.',
			'data'    => array( 'value' => $value ),
		);
	}
}

final class ToolRegistryIntegrationTest extends TestCase {

	private ToolRegistry $registry;
	private EventDispatcherInterface $events;
	private ErrorFactoryInterface $errors;

	/** @var array<object> Captured dispatched events. */
	private array $dispatchedEvents = array();

	protected function setUp(): void {
		$this->dispatchedEvents = array();

		// Mock EventDispatcher — captures events for assertion.
		$this->events = $this->createMock( EventDispatcherInterface::class );
		$this->events->method( 'dispatch' )
			->willReturnCallback( function ( object $event ): object {
				$this->dispatchedEvents[] = $event;
				return $event;
			} );

		// Mock ErrorFactory.
		$this->errors = $this->createMock( ErrorFactoryInterface::class );
		$this->errors->method( 'isError' )
			->willReturnCallback( function ( mixed $result ): bool {
				return is_array( $result ) && isset( $result['success'] ) && false === $result['success'];
			} );

		$this->registry = new ToolRegistry( $this->events, $this->errors );
	}

	public function testRegisterAndRetrieveTool(): void {
		$tool = new TestTool();
		$this->registry->register( $tool );

		$this->assertTrue( $this->registry->has( 'test_tool' ) );
		$this->assertSame( $tool, $this->registry->get( 'test_tool' ) );
		$this->assertSame( 1, $this->registry->count() );
	}

	public function testRegisterDuplicateSlugThrowsException(): void {
		$this->registry->register( new TestTool() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "Tool 'test_tool' is already registered." );

		$this->registry->register( new TestTool() );
	}

	public function testExecuteFiresDomainEvents(): void {
		$this->registry->register( new TestTool() );

		$result = $this->registry->execute( 'test_tool', array( 'value' => 'hello' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hello', $result['data']['value'] );

		// Both BeforeToolExecution and AfterToolExecution should fire.
		$this->assertCount( 2, $this->dispatchedEvents );
	}

	public function testExecuteReturnsErrorForUnregisteredTool(): void {
		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'not_found',
				'message' => "Tool 'nonexistent' is not registered.",
			),
		);

		$this->errors->method( 'notFound' )
			->willReturn( $expectedError );

		$result = $this->registry->execute( 'nonexistent' );

		$this->assertSame( $expectedError, $result );
	}

	public function testExecuteReturnsErrorForDisabledTool(): void {
		$this->registry->register( new TestTool() );
		$this->registry->disable( 'test_tool' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'forbidden',
				'message' => "Tool 'test_tool' is disabled.",
			),
		);

		$this->errors->method( 'forbidden' )
			->willReturn( $expectedError );

		$result = $this->registry->execute( 'test_tool' );

		$this->assertSame( $expectedError, $result );
	}

	public function testEnableReenablesDisabledTool(): void {
		$this->registry->register( new TestTool() );
		$this->registry->disable( 'test_tool' );
		$this->assertFalse( $this->registry->has( 'test_tool' ) );

		$this->registry->enable( 'test_tool' );
		$this->assertTrue( $this->registry->has( 'test_tool' ) );
	}

	public function testBuildToolDefinitions(): void {
		$this->registry->register( new TestTool() );

		$definitions = $this->registry->buildToolDefinitions();

		$this->assertCount( 1, $definitions );
		$this->assertSame( 'function', $definitions[0]['type'] );
		$this->assertSame( 'test_tool', $definitions[0]['function']['name'] );
		$this->assertSame( 'A test tool for integration testing.', $definitions[0]['function']['description'] );
	}

	public function testDisabledToolsExcludedFromDefinitions(): void {
		$this->registry->register( new TestTool() );
		$this->registry->disable( 'test_tool' );

		$definitions = $this->registry->buildToolDefinitions();

		$this->assertCount( 0, $definitions );
	}

	public function testEnabledVsAllCounts(): void {
		$tool1 = new TestTool();
		$this->registry->register( $tool1 );

		// Register a second tool with a different slug.
		$tool2 = new class implements ToolInterface {
			public function getSlug(): string { return 'second_tool'; }
			public function getName(): string { return 'Second'; }
			public function getDescription(): string { return 'Second tool.'; }
			public function getParametersSchema(): array {
				return array( 'type' => 'object', 'properties' => array(), 'required' => array() );
			}
			public function getRequiredCapability(): string { return ''; }
			public function execute( array $arguments = array(), array $context = array() ): mixed {
				return array( 'success' => true );
			}
		};
		$this->registry->register( $tool2 );

		$this->assertSame( 2, $this->registry->count() );
		$this->assertSame( 2, $this->registry->enabledCount() );

		$this->registry->disable( 'test_tool' );

		$this->assertSame( 2, $this->registry->count() );
		$this->assertSame( 1, $this->registry->enabledCount() );
	}

	public function testNotifyRegisteredDispatchesEvent(): void {
		$this->registry->register( new TestTool() );

		$this->registry->notifyRegistered();

		$this->assertCount( 1, $this->dispatchedEvents );
	}

	public function testAliasResolution(): void {
		$this->registry->register( new TestTool() );
		$this->registry->registerAlias( 'old_test_tool', 'test_tool' );

		// get() resolves the alias.
		$this->assertNotNull( $this->registry->get( 'old_test_tool' ) );
		$this->assertSame( 'test_tool', $this->registry->get( 'old_test_tool' )->getSlug() );

		// has() resolves the alias.
		$this->assertTrue( $this->registry->has( 'old_test_tool' ) );
	}

	public function testListAllSlugs(): void {
		$this->registry->register( new TestTool() );

		$slugs = $this->registry->getSlugs();

		$this->assertContains( 'test_tool', $slugs );
	}
}
