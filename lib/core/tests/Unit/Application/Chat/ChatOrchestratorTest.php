<?php
/**
 * Tests for ChatOrchestrator — agentic loop, cost accumulation,
 * cooperative cancellation, and per-tool capability enforcement.
 *
 * Uses an anonymous ProviderRouter subclass with scripted responses so
 * the loop runs without any network access.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Chat;

use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use PHPUnit\Framework\TestCase;

final class ChatOrchestratorTest extends TestCase {

	/**
	 * Minimal in-memory error factory for the standalone (no-platform) path.
	 */
	private function errors(): ErrorFactoryInterface {
		return new class() implements ErrorFactoryInterface {
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
				if ( is_array( $error ) && isset( $error['error'] ) ) {
					return $error['error'];
				}

				return array( 'code' => 'unknown', 'message' => (string) $error, 'data' => array() );
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
	}

	/**
	 * Scripted provider router — returns queued responses in order.
	 */
	private function router( array $responses, ErrorFactoryInterface $errors ): ProviderRouter {
		$settings = $this->createMock( SettingsStoreInterface::class );

		return new class( $responses, $errors, $settings ) extends ProviderRouter {

			/** @var array<int, array> */
			private array $queue;

			public function __construct( array $queue, ErrorFactoryInterface $errors, SettingsStoreInterface $settings ) {
				parent::__construct( $settings, $errors );
				$this->queue = array_values( $queue );
			}

			public function chat( array $messages, array $options = array(), array $assistantConfig = array() ): mixed {
				if ( array() === $this->queue ) {
					return array( 'choices' => array() );
				}

				return array_shift( $this->queue );
			}
		};
	}

	/**
	 * A scripted tool that counts executions.
	 *
	 * @return array{0: ToolInterface, 1: \stdClass}
	 */
	private function echoTool( string $capability = '' ): array {
		$counter       = new \stdClass();
		$counter->runs = 0;

		$tool = new class( $counter, $capability ) implements ToolInterface {
			private \stdClass $counter;

			private string $capability;

			public function __construct( \stdClass $counter, string $capability ) {
				$this->counter    = $counter;
				$this->capability = $capability;
			}

			public function getSlug(): string {
				return 'echo_tool';
			}

			public function getName(): string {
				return 'Echo Tool';
			}

			public function getDescription(): string {
				return 'Echoes the provided text back.';
			}

			public function getParametersSchema(): array {
				return array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
				);
			}

			public function getRequiredCapability(): string {
				return $this->capability;
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				++$this->counter->runs;

				return array(
					'success' => true,
					'message' => (string) ( $arguments['text'] ?? '' ),
					'data'    => array(),
				);
			}
		};

		return array( $tool, $counter );
	}

	/**
	 * Build an orchestrator with the given tool and scripted responses.
	 */
	private function orchestrator( ToolInterface $tool, array $responses, ?AuthProviderInterface $auth = null ): array {
		$errors     = $this->errors();
		$dispatcher = $this->createMock( EventDispatcherInterface::class );
		$dispatcher->method( 'dispatch' )->willReturnCallback( static fn( object $event ): object => $event );

		$registry = new ToolRegistry( $dispatcher, $errors );
		$registry->register( $tool );

		$orchestrator = new ChatOrchestrator(
			$registry,
			$this->router( $responses, $errors ),
			$dispatcher,
			$errors,
			new CostCalculator(),
			new SseHandler( $this->createMock( PlatformFlushInterface::class ) ),
		);

		if ( null !== $auth ) {
			$orchestrator->setAuthProvider( $auth );
		}

		return array( $orchestrator, $errors );
	}

	private function toolCallResponse(): array {
		return array(
			'choices' => array(
				array(
					'message'      => array(
						'role'      => 'assistant',
						'content'   => null,
						'tool_calls' => array(
							array(
								'id'       => 'call_1',
								'function' => array(
									'name'      => 'echo_tool',
									'arguments' => '{"text":"hello"}',
								),
							),
						),
					),
					'finish_reason' => 'tool_calls',
				),
			),
			'usage'   => array( 'prompt_tokens' => 100, 'completion_tokens' => 10 ),
		);
	}

	private function finalResponse(): array {
		return array(
			'choices' => array(
				array(
					'message'      => array( 'role' => 'assistant', 'content' => 'Done.' ),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array( 'prompt_tokens' => 150, 'completion_tokens' => 5 ),
		);
	}

	public function testAccumulatesCostAcrossAgenticIterations(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertFalse( $result['cancelled'] );
		$this->assertSame( 1, $result['iterations'] );
		$this->assertArrayHasKey( 'cost', $result );
		$this->assertSame( 250, $result['cost']['prompt_tokens'] );
		$this->assertSame( 15, $result['cost']['completion_tokens'] );
		$this->assertSame( 1, $result['cost']['agentic_iterations_count'] );
		$this->assertSame( 250, $result['cost']['agentic_accumulated']['total_prompt_tokens'] );
	}

	public function testCancellationStopsLoopAndRecordsReason(): void {
		[$tool, $counter] = $this->echoTool();

		$probeCalls = 0;
		$token      = new CancellationToken(
			null,
			static function () use ( &$probeCalls ): bool {
				// Cancel on the 4th probe: after the first tool executed,
				// at the post-tool boundary check.
				return ++$probeCalls >= 4;
			}
		);

		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->toolCallResponse(), $this->finalResponse() )
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			cancellation: $token,
		);

		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( 'cancelled', $result['cancel_reason'] );
		$this->assertSame( 1, $counter->runs, 'The in-flight tool executed once; the loop then stopped.' );
	}

	public function testCancellationBeforeInitialCallReturnsEarly(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator( $tool, array() );

		$token  = new CancellationToken();
		$token->cancel( 'user_abort' );

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			cancellation: $token,
		);

		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( 'user_abort', $result['cancel_reason'] );
		$this->assertSame( 0, $result['iterations'] );
	}

	public function testCapabilityEnforcedWhenAuthProviderInjected(): void {
		[$tool, $counter] = $this->echoTool( 'edit_posts' );

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'userCan' )->willReturn( false );

		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() ),
			$auth
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			userId: 7,
		);

		$this->assertSame( 0, $counter->runs, 'Denied tool must not execute.' );
		$this->assertSame( 1, $result['iterations'] );
	}

	public function testCapabilityGrantAllowsExecution(): void {
		[$tool, $counter] = $this->echoTool( 'edit_posts' );

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'userCan' )->willReturn( true );

		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() ),
			$auth
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			userId: 7,
		);

		$this->assertSame( 1, $counter->runs );
		$this->assertFalse( $result['cancelled'] );
	}
}
