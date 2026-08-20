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
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Decision\AgentPreStepDecision;
use Nvoos\Core\Domain\Decision\AgentRequestDecision;
use Nvoos\Core\Domain\Decision\AgentRequestErrorDecision;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Tests\Unit\Support\InMemoryDispatcher;
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
	private function router( array $responses, ErrorFactoryInterface $errors, ?InMemoryDispatcher $capture = null ): ProviderRouter {
		$settings = $this->createMock( SettingsStoreInterface::class );

		return new class( $responses, $errors, $settings, $capture ) extends ProviderRouter {

			/** @var array<int, array> */
			private array $queue;

			/** @var array<int, array> */
			public array $receivedMessages = array();

			/** @var array<int, array> */
			public array $receivedOptions = array();

			public function __construct( array $queue, ErrorFactoryInterface $errors, SettingsStoreInterface $settings, ?InMemoryDispatcher $capture ) {
				parent::__construct( $settings, $errors );
				$this->queue = array_values( $queue );

				if ( null !== $capture ) {
					$capture->router = $this;
				}
			}

			public function chat( array $messages, array $options = array(), array $assistantConfig = array() ): mixed {
				$this->receivedMessages[] = $messages;
				$this->receivedOptions[] = $options;

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
		$counter->context = null;

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
				$this->counter->context = $context;

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
		$dispatcher = new InMemoryDispatcher();

		$registry = new ToolRegistry( $dispatcher, $errors );
		$registry->register( $tool );

		$orchestrator = new ChatOrchestrator(
			$registry,
			$this->router( $responses, $errors, $dispatcher ),
			$dispatcher,
			$errors,
			new CostCalculator(),
			new SseHandler( $this->createMock( PlatformFlushInterface::class ) ),
		);

		if ( null !== $auth ) {
			$orchestrator->setAuthProvider( $auth );
		}

		return array( $orchestrator, $errors, $dispatcher );
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

	/**
	 * A recording rate limiter that always allows and captures the limit
	 * arguments the orchestrator consults.
	 *
	 * @return array{0: RateLimiterInterface, 1: \stdClass}
	 */
	private function recordingRateLimiter(): array {
		$capture            = new \stdClass();
		$capture->isAllowed = array();
		$capture->record    = array();

		$limiter = new class( $capture ) implements RateLimiterInterface {
			private \stdClass $capture;

			public function __construct( \stdClass $capture ) {
				$this->capture = $capture;
			}

			public function isAllowed( string $key, int $maxRequests, int $windowSeconds ): bool {
				$this->capture->isAllowed[] = array( $key, $maxRequests, $windowSeconds );
				return true;
			}

			public function record( string $key, int $windowSeconds = 60 ): void {
				$this->capture->record[] = array( $key, $windowSeconds );
			}

			public function remaining( string $key, int $maxRequests, int $windowSeconds ): int {
				return $maxRequests;
			}

			public function reset( string $key ): void {}
		};

		return array( $limiter, $capture );
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

	// ─── Loop waterfalls (R2) ─────────────────────────────────────

	public function testPreStepRejectSkipsProviderAndClosesTurn(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		$dispatcher->listenWaterfall(
			'agent/pre_step',
			static function ( object $event, callable $next ): AgentPreStepDecision {
				return AgentPreStepDecision::reject();
			}
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertSame( array(), $result['response'] );
		$this->assertSame( 0, $result['iterations'] );
		$this->assertSame( array(), $dispatcher->router->receivedMessages, 'Provider must not be called on reject.' );
	}

	public function testPreStepEnterRewritesEnteringBatch(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		$dispatcher->listenWaterfall(
			'agent/pre_step',
			static function ( object $event, callable $next ): AgentPreStepDecision {
				$messages   = $event->messages;
				$messages[] = array( 'role' => 'system', 'content' => 'injected-by-policy' );

				return AgentPreStepDecision::enter( $messages );
			}
		);

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$sent = $dispatcher->router->receivedMessages[0];
		$this->assertSame( 'injected-by-policy', $sent[ count( $sent ) - 1 ]['content'] );
	}

	public function testRequestPolicyReplacesCallConfigWithMerge(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		$dispatcher->listenWaterfall(
			'agent/request',
			static function ( object $event, callable $next ): AgentRequestDecision {
				return AgentRequestDecision::replace( array( 'model' => 'gpt-4o-mini' ) );
			}
		);

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$options = $dispatcher->router->receivedOptions[0];
		$this->assertSame( 'gpt-4o-mini', $options['model'] );
		$this->assertSame( 'openai', $options['provider'], 'Merge must preserve untouched keys.' );
	}

	public function testRequestErrorRetryRecoversWhenListenerOwnsRecovery(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator(
			$tool,
			array(
				array( 'success' => false, 'error' => array( 'code' => 'transient', 'message' => 'Boom.' ) ),
				$this->finalResponse(),
			)
		);

		$dispatcher->listenWaterfall(
			'agent/request_error',
			static function ( object $event, callable $next ): AgentRequestErrorDecision {
				return AgentRequestErrorDecision::retry();
			}
		);

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertSame( 2, count( $dispatcher->router->receivedMessages ), 'Retry must issue a second provider call.' );
		$this->assertSame( 'Done.', $result['response']['choices'][0]['message']['content'] );
		$this->assertFalse( $result['cancelled'] );
	}

	public function testRequestErrorStaysTerminalWithoutRetryDecision(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator(
			$tool,
			array(
				array( 'success' => false, 'error' => array( 'code' => 'fatal', 'message' => 'Boom.' ) ),
			)
		);

		// No agent/request_error listener — the default is terminal.
		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertSame( 1, count( $dispatcher->router->receivedMessages ) );
		$this->assertSame( 'fatal', $result['response']['code'] );
	}

	public function testShadowModePropagatesToToolContext(): void {
		[$tool, $counter] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array(
				'tools'    => array( 'echo_tool' ),
				'provider' => 'openai',
				'model'    => 'gpt-4o',
			),
			options: array( 'shadow_mode' => true ),
		);

		$this->assertSame( 1, $counter->runs );
		$this->assertTrue(
			$counter->context['shadow_mode'] ?? false,
			'Shadow mode must reach the tool-execution context.',
		);
	}

	public function testShadowModeDefaultsFalseInToolContext(): void {
		[$tool, $counter] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertFalse( $counter->context['shadow_mode'] ?? true );
	}

	public function testTurnStoppingSerialFiresBeforeClose(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		$seen = array();
		$dispatcher->listenSerial(
			'agent/turn_stopping',
			static function ( object $event ) use ( &$seen ): void {
				$seen[] = \get_class( $event );
			}
		);

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertContains( 'Nvoos\Core\Domain\Event\AgentTurnStopping', $seen );
	}

	public function testToolResultCarriesTelemetryFacts(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
		$orchestrator->setSessionLog( $sessionLog );

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array(
				'tools'    => array( 'echo_tool' ),
				'provider' => 'openai',
				'model'    => 'gpt-4o',
			),
		);

		$toolResult = null;

		foreach ( $sessionLog->events() as $event ) {
			if ( \Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_RESULT === $event->type ) {
				$toolResult = $event->data;
			}
		}

		$this->assertNotNull( $toolResult, 'A tool_result entry must be logged.' );
		$this->assertSame( 'echo_tool', $toolResult['name'] );
		$this->assertSame( 'success', $toolResult['outcome'] );
		$this->assertIsFloat( $toolResult['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0.0, $toolResult['duration_ms'] );
		$this->assertSame( 0, $toolResult['user_id'] );
		$this->assertSame( 0, $toolResult['assistant_id'] );
	}

	public function testSessionTelemetryTapFansOutAppendedEvents(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , ] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
		$orchestrator->setSessionLog( $sessionLog );

		$telemetry = new \Nvoos\Core\Application\Session\SessionTelemetry();
		$seen      = array();

		$telemetry->subscribe(
			static function ( \Nvoos\Core\Application\Session\SessionEvent $event ) use ( &$seen ): void {
				$seen[] = $event->type;
			}
		);

		$orchestrator->setSessionTelemetry( $telemetry );

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array(
				'tools'    => array( 'echo_tool' ),
				'provider' => 'openai',
				'model'    => 'gpt-4o',
			),
		);

		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_STARTED, $seen );
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_CALL, $seen );
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_RESULT, $seen );
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_ENDED, $seen );
		$this->assertSame( $sessionLog->count(), \count( $seen ), 'The tap observes exactly the log entries.' );
	}

	public function testSessionLogReplayReproducesProviderMessages(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
		$orchestrator->setSessionLog( $sessionLog );

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array(
				'tools'      => array( 'echo_tool' ),
				'provider'   => 'openai',
				'model'      => 'gpt-4o',
				'session_id' => 'replay-test',
			),
		);

		$sentMessages = $dispatcher->router->receivedMessages;
		$this->assertGreaterThanOrEqual( 2, \count( $sentMessages ), 'Scripted flow must issue two provider calls.' );

		// The durable history is: everything the model saw (the final
		// provider input) PLUS the final assistant reply (the output of
		// that call, which the next turn would inherit).
		$expected = $sentMessages[ \count( $sentMessages ) - 1 ];
		$expected[] = array( 'role' => 'assistant', 'content' => 'Done.' );

		$this->assertSame(
			$expected,
			$sessionLog->deriveMessages(),
			'History derived from the log must reproduce the model-visible facts.',
		);

		// The turn is durable: boundaries + exit reason are recorded.
		$types = \array_map(
			static function ( \Nvoos\Core\Application\Session\SessionEvent $event ): string {
				return $event->type;
			},
			$sessionLog->events(),
		);
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_STARTED, $types );
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_ENDED, $types );
	}

	public function testCompactionTriggersBetweenStepsAndIsLogged(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->toolCallResponse(), $this->finalResponse() )
		);

		// Force-triggering compaction: any continuation step from the 3rd
		// iteration onward compacts by appending a marker message.
		$orchestrator->setCompactionProvider(
			new class() extends \Nvoos\Core\Application\Chat\CompactionProvider {
				public function shouldCompact( array $messages, int $contextLimit, int $iteration, float $threshold = 0.85 ): bool {
					return $iteration >= 2;
				}

				public function compact( array $messages, string $model = '' ): array {
					$messages[] = array( 'role' => 'system', 'content' => 'COMPACTED' );

					return $messages;
				}
			}
		);
		$orchestrator->setTokenBudgetManager( new \Nvoos\Core\Infrastructure\Token\TokenBudgetManager() );
		$sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
		$orchestrator->setSessionLog( $sessionLog );

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		// The provider call after the compacted step carries the marker.
		$lastCallMessages = $dispatcher->router->receivedMessages[ \count( $dispatcher->router->receivedMessages ) - 1 ];
		$this->assertContains(
			'COMPACTED',
			\array_column( $lastCallMessages, 'content' ),
			'The compacted message list must reach the provider.',
		);

		// Compaction is durable.
		$types = \array_map(
			static function ( \Nvoos\Core\Application\Session\SessionEvent $event ): string {
				return $event->type;
			},
			$sessionLog->events(),
		);
		$this->assertContains( \Nvoos\Core\Application\Session\SessionLog::TYPE_CONTEXT_COMPACTED, $types );
	}

	public function testSessionLogRoundtripReplayFeedsContinuation(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, , $dispatcher] = $this->orchestrator(
			$tool,
			array( $this->toolCallResponse(), $this->finalResponse() )
		);

		$sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
		$orchestrator->setSessionLog( $sessionLog );

		$orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		// Export → rebuild → derive: the durable history survives a full
		// store roundtrip and can seed the next turn.
		$rebuilt  = \Nvoos\Core\Application\Session\SessionLog::fromExported( $sessionLog->export() );
		$derived  = $rebuilt->deriveMessages();

		// Fresh orchestrator for the continuation turn.
		[$continuation, , $continuationDispatcher] = $this->orchestrator(
			$tool,
			array( $this->finalResponse() )
		);

		$continuation->handleChat(
			$derived,
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
		);

		$this->assertSame(
			$derived,
			$continuationDispatcher->router->receivedMessages[0],
			'The replayed history must reach the provider unchanged.',
		);
	}

	public function testChatRateLimitDefaultsToSixtyRequestsPerSixtySeconds(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		[$limiter, $capture] = $this->recordingRateLimiter();
		$orchestrator->setRateLimiter( $limiter );

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			userId: 7,
		);

		$this->assertSame( 'Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).' );
		$this->assertCount( 1, $capture->isAllowed );
		$this->assertSame( array( 'chat:7:0', 60, 60 ), $capture->isAllowed[0] );
		$this->assertSame( array( 'chat:7:0', 60 ), $capture->record[0] );
	}

	public function testChatRateLimitIsConfigurableViaSetter(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		[$limiter, $capture] = $this->recordingRateLimiter();
		$orchestrator->setRateLimiter( $limiter );
		$orchestrator->setChatRateLimit( 120, 30 );

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			userId: 7,
		);

		$this->assertSame( 'Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).' );
		$this->assertCount( 1, $capture->isAllowed );
		$this->assertSame( array( 'chat:7:0', 120, 30 ), $capture->isAllowed[0] );
		$this->assertSame( array( 'chat:7:0', 30 ), $capture->record[0] );
	}

	public function testChatRateLimitWindowDefaultsToSixtySeconds(): void {
		[$tool, ] = $this->echoTool();
		[$orchestrator, ] = $this->orchestrator( $tool, array( $this->finalResponse() ) );

		[$limiter, $capture] = $this->recordingRateLimiter();
		$orchestrator->setRateLimiter( $limiter );
		$orchestrator->setChatRateLimit( 120 );

		$result = $orchestrator->handleChat(
			array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
			array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
			userId: 7,
		);

		$this->assertSame( 'Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).' );
		$this->assertCount( 1, $capture->isAllowed );
		$this->assertSame( array( 'chat:7:0', 120, 60 ), $capture->isAllowed[0] );
		$this->assertSame( array( 'chat:7:0', 60 ), $capture->record[0] );
	}
}
