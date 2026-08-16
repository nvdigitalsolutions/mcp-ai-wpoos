<?php
/**
 * Tests for the ToolRegistry execution pipeline — pre-execute waterfall,
 * monotonic guards, around-dispatch wrappers, and post-execute policy.
 *
 * Uses an in-memory dispatcher implementing both the PSR-14-style emit
 * contract and the waterfall contract so no platform code is required.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Nvoos\Core\Domain\Decision\PreToolDecision;
use Nvoos\Core\Domain\Decision\PostToolDecision;
use Nvoos\Core\Domain\Event\WaterfallChain;
use PHPUnit\Framework\TestCase;

/**
 * In-memory dispatcher with both dispatch modes.
 */
final class InMemoryDispatcher implements EventDispatcherInterface, WaterfallEventDispatcherInterface {

	/** @var array<string, callable[]> */
	public array $dispatched = array();

	/** @var array<string, array<int, callable[]>> */
	private array $waterfalls = array();

	/** @var array<string, array<int, callable[]>> */
	private array $serials = array();

	public function dispatch( object $event ): object {
		$this->dispatched[] = \get_class( $event );

		return $event;
	}

	public function filter( string $eventName, mixed $value, mixed ...$args ): mixed {
		return $value;
	}

	public function listen( string $eventName, callable $listener, int $priority = 10 ): void {}

	public function listenFilter( string $eventName, callable $filter, int $priority = 10 ): void {}

	public function removeListener( string $eventName, callable $listener ): bool {
		return false;
	}

	public function listenWaterfall( string $eventName, callable $listener, int $priority = 10 ): void {
		$this->waterfalls[ $eventName ][ $priority ][] = $listener;
	}

	public function waterfall( string $eventName, object $event, callable $final ): mixed {
		$listeners = array();
		foreach ( $this->waterfalls[ $eventName ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$listeners[] = $callback;
			}
		}

		return WaterfallChain::build( $listeners, $final )( $event );
	}

	public function listenSerial( string $eventName, callable $listener, int $priority = 10 ): void {
		$this->serials[ $eventName ][ $priority ][] = $listener;
	}

	public function serial( string $eventName, object $event ): void {
		foreach ( $this->serials[ $eventName ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback( $event );
			}
		}
	}
}

final class ToolRegistryPipelineTest extends TestCase {

	private InMemoryDispatcher $dispatcher;

	private ErrorFactoryInterface $errors;

	private ToolRegistry $registry;

	/** @var \stdClass */
	private object $toolState;

	protected function setUp(): void {
		$this->dispatcher = new InMemoryDispatcher();

		$this->errors = new class() implements ErrorFactoryInterface {
			public function create( string $code, string $message, array $data = array() ): mixed {
				return array(
					'success' => false,
					'error'   => array( 'code' => $code, 'message' => $message, 'data' => $data ),
				);
			}

			public function isError( mixed $value ): bool {
				return is_array( $value ) && isset( $value['success'] ) && false === $value['success'];
			}

			public function normalize( mixed $error ): array {
				return is_array( $error ) && isset( $error['error'] )
					? $error['error']
					: array( 'code' => 'unknown', 'message' => (string) $error, 'data' => array() );
			}

			public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
				return $this->create( 'not_found', $message, $data );
			}

			public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
				return $this->create( 'forbidden', $message, $data );
			}

			public function validationFailed( string $message, array $errors = array() ): mixed {
				return $this->create( 'validation_failed', $message, array( 'errors' => $errors ) );
			}

			public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
				return $this->create( 'rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ) );
			}
		};

		$this->registry  = new ToolRegistry( $this->dispatcher, $this->errors );
		$this->toolState = new \stdClass();
		$this->toolState->runs = 0;

		$this->registry->register( $this->countingTool() );
	}

	private function countingTool(): ToolInterface {
		$state = $this->toolState;

		return new class( $state ) implements ToolInterface {
			public function __construct( private readonly \stdClass $state ) {}

			public function getSlug(): string {
				return 'probe_tool';
			}

			public function getName(): string {
				return 'Probe Tool';
			}

			public function getDescription(): string {
				return 'Probe.';
			}

			public function getParametersSchema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function getRequiredCapability(): string {
				return '';
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				++$this->state->runs;

				return array( 'success' => true, 'message' => 'ran', 'data' => array() );
			}
		};
	}

	private function resultCode( mixed $result ): string {
		return (string) ( $result['error']['code'] ?? '' );
	}

	public function testDefaultAllowExecutesTool(): void {
		$result = $this->registry->execute( 'probe_tool' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $this->toolState->runs );
	}

	public function testPreExecuteDenyBlocksExecution(): void {
		$this->dispatcher->listenWaterfall(
			'tools/pre_execute',
			static function ( object $event, callable $next ): PreToolDecision {
				return PreToolDecision::deny( 'Blocked by test policy.' );
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'tool_denied', $this->resultCode( $result ) );
		$this->assertSame( 0, $this->toolState->runs );
	}

	public function testPreExecuteAskFailsClosedWithoutApproval(): void {
		$this->dispatcher->listenWaterfall(
			'tools/pre_execute',
			static function ( object $event, callable $next ): PreToolDecision {
				return PreToolDecision::ask( 'Confirmation required.' );
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'approval_required', $this->resultCode( $result ) );
		$this->assertSame( 0, $this->toolState->runs );
	}

	public function testInvalidPreDecisionFailsClosed(): void {
		$this->dispatcher->listenWaterfall(
			'tools/pre_execute',
			static function ( object $event, callable $next ): mixed {
				return 'not-a-decision';
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'tool_denied', $this->resultCode( $result ) );
		$this->assertSame( 0, $this->toolState->runs );
	}

	public function testGuardCanDeny(): void {
		$this->registry->addGuard(
			new class() implements ToolGuardInterface {
				public function evaluate( string $slug, array $arguments, array $context ): ?string {
					return $slug === 'probe_tool' ? 'Guarded by test.' : null;
				}
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'tool_guarded', $this->resultCode( $result ) );
		$this->assertSame( 0, $this->toolState->runs );
	}

	public function testGuardReturningNullAllows(): void {
		$this->registry->addGuard(
			new class() implements ToolGuardInterface {
				public function evaluate( string $slug, array $arguments, array $context ): ?string {
					return null;
				}
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $this->toolState->runs );
	}

	public function testExecuteWaterfallWrapsResult(): void {
		$this->dispatcher->listenWaterfall(
			'tools/execute',
			static function ( object $event, callable $next ): mixed {
				$result          = $next( $event );
				$result['data']['wrapped'] = true;

				return $result;
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['wrapped'] );
	}

	public function testPostExecuteReplaceContent(): void {
		$this->dispatcher->listenWaterfall(
			'tools/post_execute',
			static function ( object $event, callable $next ): PostToolDecision {
				return PostToolDecision::replace( array( 'success' => true, 'message' => 'replaced', 'data' => array() ) );
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'replaced', $result['message'] );
		$this->assertSame( 1, $this->toolState->runs );
	}

	public function testPostExecuteBlockTurnsResultIntoError(): void {
		$this->dispatcher->listenWaterfall(
			'tools/post_execute',
			static function ( object $event, callable $next ): PostToolDecision {
				return PostToolDecision::block( 'Unsupported output — retry.' );
			}
		);

		$result = $this->registry->execute( 'probe_tool' );

		$this->assertSame( 'tool_blocked_with_feedback', $this->resultCode( $result ) );
		$this->assertStringContainsString( 'Unsupported output', $result['error']['message'] );
	}

	public function testPlainDispatcherDegradesToDirectExecution(): void {
		// A dispatcher without waterfall support (e.g., craft/laravel
		// adapters) must still execute tools unchanged.
		$plain     = $this->createMock( EventDispatcherInterface::class );
		$plain->method( 'dispatch' )->willReturnCallback( static fn( object $event ): object => $event );
		$registry  = new ToolRegistry( $plain, $this->errors );
		$tool      = $this->countingTool();
		$registry->register( $tool );

		$result = $registry->execute( 'probe_tool' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $this->toolState->runs );
	}
}
