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
use Nvoos\Core\Application\Security\OutputGuard;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Decision\AgentPreStepDecision;
use Nvoos\Core\Domain\Decision\AgentRequestDecision;
use Nvoos\Core\Domain\Decision\AgentRequestErrorDecision;
use Nvoos\Core\Domain\Security\ChatProfile;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Infrastructure\Cost\CostCalculator;
use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Nvoos\Core\Tests\Unit\Support\InMemoryDispatcher;
use PHPUnit\Framework\TestCase;

final class ChatOrchestratorTest extends TestCase
{
    /**
     * Minimal in-memory error factory for the standalone (no-platform) path.
     */
    private function errors(): ErrorFactoryInterface
    {
        return new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array(
                    'success' => false,
                    'error'   => array( 'code' => $code, 'message' => $message, 'data' => $data ),
                );
            }

            public function isError(mixed $value): bool
            {
                return is_array($value) && isset($value['success']) && false === $value['success'];
            }

            public function normalize(mixed $error): array
            {
                if (is_array($error) && isset($error['error'])) {
                    return $error['error'];
                }

                return array( 'code' => 'unknown', 'message' => (string) $error, 'data' => array() );
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, array( 'errors' => $errors ));
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ));
            }
        };
    }

    /**
     * Scripted provider router — returns queued responses in order.
     */
    private function router(array $responses, ErrorFactoryInterface $errors, ?InMemoryDispatcher $capture = null): ProviderRouter
    {
        $settings = $this->createMock(SettingsStoreInterface::class);

        return new class ($responses, $errors, $settings, $capture) extends ProviderRouter {
            /** @var array<int, array> */
            private array $queue;

            /** @var array<int, array> */
            public array $receivedMessages = array();

            /** @var array<int, array> */
            public array $receivedOptions = array();

            /** @var array<int, string> */
            public array $streamChunks = array();

            public function __construct(array $queue, ErrorFactoryInterface $errors, SettingsStoreInterface $settings, ?InMemoryDispatcher $capture)
            {
                parent::__construct($settings, $errors);
                $this->queue = array_values($queue);

                if (null !== $capture) {
                    $capture->router = $this;
                }
            }

            public function chat(array $messages, array $options = array(), array $assistantConfig = array()): mixed
            {
                $this->receivedMessages[] = $messages;
                $this->receivedOptions[] = $options;

                if (array() === $this->queue) {
                    return array( 'choices' => array() );
                }

                return array_shift($this->queue);
            }

            /**
             * Drive the chunk callback, then return the queued response.
             *
             * Mirrors a provider that streams tokens and then yields the
             * assembled response, which is what the orchestrator expects.
             */
            public function stream(array $messages, array $options = array(), array $assistantConfig = array(), ?callable $onChunk = null): mixed
            {
                $this->receivedMessages[] = $messages;
                $this->receivedOptions[]  = $options;

                if (null !== $onChunk) {
                    foreach ($this->streamChunks as $chunk) {
                        $onChunk($chunk);
                    }
                }

                if (array() === $this->queue) {
                    return array( 'choices' => array() );
                }

                return array_shift($this->queue);
            }
        };
    }

    /**
     * A scripted tool that counts executions.
     *
     * @return array{0: ToolInterface, 1: \stdClass}
     */
    private function echoTool(string $capability = ''): array
    {
        $counter       = new \stdClass();
        $counter->runs = 0;
        $counter->context = null;

        $tool = new class ($counter, $capability) implements ToolInterface {
            private \stdClass $counter;

            private string $capability;

            public function __construct(\stdClass $counter, string $capability)
            {
                $this->counter    = $counter;
                $this->capability = $capability;
            }

            public function getSlug(): string
            {
                return 'echo_tool';
            }

            public function getName(): string
            {
                return 'Echo Tool';
            }

            public function getDescription(): string
            {
                return 'Echoes the provided text back.';
            }

            public function getParametersSchema(): array
            {
                return array(
                    'type'       => 'object',
                    'properties' => array( 'text' => array( 'type' => 'string' ) ),
                );
            }

            public function getRequiredCapability(): string
            {
                return $this->capability;
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
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
    private function orchestrator(ToolInterface $tool, array $responses, ?AuthProviderInterface $auth = null, ?SseHandler $sse = null): array
    {
        $errors     = $this->errors();
        $dispatcher = new InMemoryDispatcher();

        $registry = new ToolRegistry($dispatcher, $errors);
        $registry->register($tool);

        $sse = $sse ?? new SseHandler($this->createMock(PlatformFlushInterface::class));

        $orchestrator = new ChatOrchestrator(
            $registry,
            $this->router($responses, $errors, $dispatcher),
            $dispatcher,
            $errors,
            new CostCalculator(),
            $sse,
        );

        if (null !== $auth) {
            $orchestrator->setAuthProvider($auth);
        }

        return array( $orchestrator, $errors, $dispatcher );
    }

    /**
     * A resolver bound to one profile, for the hint assertions.
     */
    private function chatProfile(ChatProfile $profile): ChatProfileResolverInterface
    {
        return new class ($profile) implements ChatProfileResolverInterface {
            public function __construct(private readonly ChatProfile $profile)
            {
            }

            public function resolve(array $context): ChatProfile
            {
                return $this->profile;
            }

            public function defaultProfile(): ChatProfile
            {
                return ChatProfile::write();
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    private function toolCallResponse(): array
    {
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

    private function finalResponse(): array
    {
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
    private function recordingRateLimiter(): array
    {
        $capture            = new \stdClass();
        $capture->isAllowed = array();
        $capture->record    = array();

        $limiter = new class ($capture) implements RateLimiterInterface {
            private \stdClass $capture;

            public function __construct(\stdClass $capture)
            {
                $this->capture = $capture;
            }

            public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
            {
                $this->capture->isAllowed[] = array( $key, $maxRequests, $windowSeconds );
                return true;
            }

            public function record(string $key, int $windowSeconds = 60): void
            {
                $this->capture->record[] = array( $key, $windowSeconds );
            }

            public function remaining(string $key, int $maxRequests, int $windowSeconds): int
            {
                return $maxRequests;
            }

            public function reset(string $key): void
            {
            }
        };

        return array( $limiter, $capture );
    }

    public function testAccumulatesCostAcrossAgenticIterations(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertFalse($result['cancelled']);
        $this->assertSame(1, $result['iterations']);
        $this->assertArrayHasKey('cost', $result);
        $this->assertSame(250, $result['cost']['prompt_tokens']);
        $this->assertSame(15, $result['cost']['completion_tokens']);
        $this->assertSame(1, $result['cost']['agentic_iterations_count']);
        $this->assertSame(250, $result['cost']['agentic_accumulated']['total_prompt_tokens']);
    }

    public function testCancellationStopsLoopAndRecordsReason(): void
    {
        [$tool, $counter] = $this->echoTool();

        $probeCalls = 0;
        $token      = new CancellationToken(
            null,
            static function () use (&$probeCalls): bool {
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

        $this->assertTrue($result['cancelled']);
        $this->assertSame('cancelled', $result['cancel_reason']);
        $this->assertSame(1, $counter->runs, 'The in-flight tool executed once; the loop then stopped.');
    }

    public function testCancellationBeforeInitialCallReturnsEarly(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator($tool, array());

        $token  = new CancellationToken();
        $token->cancel('user_abort');

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            cancellation: $token,
        );

        $this->assertTrue($result['cancelled']);
        $this->assertSame('user_abort', $result['cancel_reason']);
        $this->assertSame(0, $result['iterations']);
    }

    public function testCapabilityEnforcedWhenAuthProviderInjected(): void
    {
        [$tool, $counter] = $this->echoTool('edit_posts');

        $auth = $this->createMock(AuthProviderInterface::class);
        $auth->method('userCan')->willReturn(false);

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

        $this->assertSame(0, $counter->runs, 'Denied tool must not execute.');
        $this->assertSame(1, $result['iterations']);
    }

    public function testCapabilityGrantAllowsExecution(): void
    {
        [$tool, $counter] = $this->echoTool('edit_posts');

        $auth = $this->createMock(AuthProviderInterface::class);
        $auth->method('userCan')->willReturn(true);

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

        $this->assertSame(1, $counter->runs);
        $this->assertFalse($result['cancelled']);
    }

    // ─── Loop waterfalls (R2) ─────────────────────────────────────

    public function testPreStepRejectSkipsProviderAndClosesTurn(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator($tool, array( $this->finalResponse() ));

        $dispatcher->listenWaterfall(
            'agent/pre_step',
            static function (object $event, callable $next): AgentPreStepDecision {
                return AgentPreStepDecision::reject();
            }
        );

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(array(), $result['response']);
        $this->assertSame(0, $result['iterations']);
        $this->assertSame(array(), $dispatcher->router->receivedMessages, 'Provider must not be called on reject.');
    }

    public function testPreStepEnterRewritesEnteringBatch(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator($tool, array( $this->finalResponse() ));

        $dispatcher->listenWaterfall(
            'agent/pre_step',
            static function (object $event, callable $next): AgentPreStepDecision {
                $messages   = $event->messages;
                $messages[] = array( 'role' => 'system', 'content' => 'injected-by-policy' );

                return AgentPreStepDecision::enter($messages);
            }
        );

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $sent = $dispatcher->router->receivedMessages[0];
        $this->assertSame('injected-by-policy', $sent[ count($sent) - 1 ]['content']);
    }

    public function testRequestPolicyReplacesCallConfigWithMerge(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator($tool, array( $this->finalResponse() ));

        $dispatcher->listenWaterfall(
            'agent/request',
            static function (object $event, callable $next): AgentRequestDecision {
                return AgentRequestDecision::replace(array( 'model' => 'gpt-4o-mini' ));
            }
        );

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $options = $dispatcher->router->receivedOptions[0];
        $this->assertSame('gpt-4o-mini', $options['model']);
        $this->assertSame('openai', $options['provider'], 'Merge must preserve untouched keys.');
    }

    public function testRequestErrorRetryRecoversWhenListenerOwnsRecovery(): void
    {
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
            static function (object $event, callable $next): AgentRequestErrorDecision {
                return AgentRequestErrorDecision::retry();
            }
        );

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(2, count($dispatcher->router->receivedMessages), 'Retry must issue a second provider call.');
        $this->assertSame('Done.', $result['response']['choices'][0]['message']['content']);
        $this->assertFalse($result['cancelled']);
    }

    public function testRequestErrorStaysTerminalWithoutRetryDecision(): void
    {
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

        $this->assertSame(1, count($dispatcher->router->receivedMessages));
        $this->assertSame('fatal', $result['response']['code']);
    }

    public function testShadowModePropagatesToToolContext(): void
    {
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

        $this->assertSame(1, $counter->runs);
        $this->assertTrue(
            $counter->context['shadow_mode'] ?? false,
            'Shadow mode must reach the tool-execution context.',
        );
    }

    public function testShadowModeDefaultsFalseInToolContext(): void
    {
        [$tool, $counter] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertFalse($counter->context['shadow_mode'] ?? true);
    }

    public function testTurnStoppingSerialFiresBeforeClose(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator($tool, array( $this->finalResponse() ));

        $seen = array();
        $dispatcher->listenSerial(
            'agent/turn_stopping',
            static function (object $event) use (&$seen): void {
                $seen[] = \get_class($event);
            }
        );

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertContains('Nvoos\Core\Domain\Event\AgentTurnStopping', $seen);
    }

    public function testToolResultCarriesTelemetryFacts(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , ] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
        $orchestrator->setSessionLog($sessionLog);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array(
                'tools'    => array( 'echo_tool' ),
                'provider' => 'openai',
                'model'    => 'gpt-4o',
            ),
        );

        $toolResult = null;

        foreach ($sessionLog->events() as $event) {
            if (\Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_RESULT === $event->type) {
                $toolResult = $event->data;
            }
        }

        $this->assertNotNull($toolResult, 'A tool_result entry must be logged.');
        $this->assertSame('echo_tool', $toolResult['name']);
        $this->assertSame('success', $toolResult['outcome']);
        $this->assertIsFloat($toolResult['duration_ms']);
        $this->assertGreaterThanOrEqual(0.0, $toolResult['duration_ms']);
        $this->assertSame(0, $toolResult['user_id']);
        $this->assertSame(0, $toolResult['assistant_id']);
    }

    public function testSessionTelemetryTapFansOutAppendedEvents(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , ] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
        $orchestrator->setSessionLog($sessionLog);

        $telemetry = new \Nvoos\Core\Application\Session\SessionTelemetry();
        $seen      = array();

        $telemetry->subscribe(
            static function (\Nvoos\Core\Application\Session\SessionEvent $event) use (&$seen): void {
                $seen[] = $event->type;
            }
        );

        $orchestrator->setSessionTelemetry($telemetry);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array(
                'tools'    => array( 'echo_tool' ),
                'provider' => 'openai',
                'model'    => 'gpt-4o',
            ),
        );

        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_STARTED, $seen);
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_CALL, $seen);
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TOOL_RESULT, $seen);
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_ENDED, $seen);
        $this->assertSame($sessionLog->count(), \count($seen), 'The tap observes exactly the log entries.');
    }

    public function testSessionLogReplayReproducesProviderMessages(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
        $orchestrator->setSessionLog($sessionLog);

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
        $this->assertGreaterThanOrEqual(2, \count($sentMessages), 'Scripted flow must issue two provider calls.');

        // The durable history is: everything the model saw (the final
        // provider input) PLUS the final assistant reply (the output of
        // that call, which the next turn would inherit).
        $expected = $sentMessages[ \count($sentMessages) - 1 ];
        $expected[] = array( 'role' => 'assistant', 'content' => 'Done.' );

        $this->assertSame(
            $expected,
            $sessionLog->deriveMessages(),
            'History derived from the log must reproduce the model-visible facts.',
        );

        // The turn is durable: boundaries + exit reason are recorded.
        $types = \array_map(
            static function (\Nvoos\Core\Application\Session\SessionEvent $event): string {
                return $event->type;
            },
            $sessionLog->events(),
        );
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_STARTED, $types);
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_TURN_ENDED, $types);
    }

    public function testCompactionTriggersBetweenStepsAndIsLogged(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->toolCallResponse(), $this->finalResponse() )
        );

        // Force-triggering compaction: any continuation step from the 3rd
        // iteration onward compacts by appending a marker message.
        $orchestrator->setCompactionProvider(
            new class () extends \Nvoos\Core\Application\Chat\CompactionProvider {
                public function shouldCompact(array $messages, int $contextLimit, int $iteration, float $threshold = 0.85): bool
                {
                    return $iteration >= 2;
                }

                public function compact(array $messages, string $model = ''): array
                {
                    $messages[] = array( 'role' => 'system', 'content' => 'COMPACTED' );

                    return $messages;
                }
            }
        );
        $orchestrator->setTokenBudgetManager(new \Nvoos\Core\Infrastructure\Token\TokenBudgetManager());
        $sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
        $orchestrator->setSessionLog($sessionLog);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        // The provider call after the compacted step carries the marker.
        $lastCallMessages = $dispatcher->router->receivedMessages[ \count($dispatcher->router->receivedMessages) - 1 ];
        $this->assertContains(
            'COMPACTED',
            \array_column($lastCallMessages, 'content'),
            'The compacted message list must reach the provider.',
        );

        // Compaction is durable.
        $types = \array_map(
            static function (\Nvoos\Core\Application\Session\SessionEvent $event): string {
                return $event->type;
            },
            $sessionLog->events(),
        );
        $this->assertContains(\Nvoos\Core\Application\Session\SessionLog::TYPE_CONTEXT_COMPACTED, $types);
    }

    public function testSessionLogRoundtripReplayFeedsContinuation(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, , $dispatcher] = $this->orchestrator(
            $tool,
            array( $this->toolCallResponse(), $this->finalResponse() )
        );

        $sessionLog = new \Nvoos\Core\Application\Session\SessionLog();
        $orchestrator->setSessionLog($sessionLog);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        // Export → rebuild → derive: the durable history survives a full
        // store roundtrip and can seed the next turn.
        $rebuilt  = \Nvoos\Core\Application\Session\SessionLog::fromExported($sessionLog->export());
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

    public function testChatRateLimitDefaultsToSixtyRequestsPerSixtySeconds(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator($tool, array( $this->finalResponse() ));

        [$limiter, $capture] = $this->recordingRateLimiter();
        $orchestrator->setRateLimiter($limiter);

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $this->assertSame('Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).');
        $this->assertCount(1, $capture->isAllowed);
        $this->assertSame(array( 'chat:7:0', 60, 60 ), $capture->isAllowed[0]);
        $this->assertSame(array( 'chat:7:0', 60 ), $capture->record[0]);
    }

    public function testChatRateLimitIsConfigurableViaSetter(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator($tool, array( $this->finalResponse() ));

        [$limiter, $capture] = $this->recordingRateLimiter();
        $orchestrator->setRateLimiter($limiter);
        $orchestrator->setChatRateLimit(120, 30);

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $this->assertSame('Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).');
        $this->assertCount(1, $capture->isAllowed);
        $this->assertSame(array( 'chat:7:0', 120, 30 ), $capture->isAllowed[0]);
        $this->assertSame(array( 'chat:7:0', 30 ), $capture->record[0]);
    }

    public function testChatRateLimitWindowDefaultsToSixtySeconds(): void
    {
        [$tool, ] = $this->echoTool();
        [$orchestrator, ] = $this->orchestrator($tool, array( $this->finalResponse() ));

        [$limiter, $capture] = $this->recordingRateLimiter();
        $orchestrator->setRateLimiter($limiter);
        $orchestrator->setChatRateLimit(120);

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Say hello.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $this->assertSame('Done.', $result['response']['choices'][0]['message']['content'] ?? null, 'The turn completed normally (not rate limited).');
        $this->assertCount(1, $capture->isAllowed);
        $this->assertSame(array( 'chat:7:0', 120, 60 ), $capture->isAllowed[0]);
        $this->assertSame(array( 'chat:7:0', 60 ), $capture->record[0]);
    }

    // ── Output guard ──────────────────────────────────────────────

    private function leakResponse(): array
    {
        return array(
            'choices' => array(
                array(
                    'message'       => array( 'role' => 'assistant', 'content' => 'Reach me at alice@example.com for details.' ),
                    'finish_reason' => 'stop',
                ),
            ),
            'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
        );
    }

    private function contentOf(array $result): mixed
    {
        return $result['response']['choices'][0]['message']['content'] ?? null;
    }

    public function test_without_a_guard_the_response_is_untouched(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ));

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame('Reach me at alice@example.com for details.', $this->contentOf($result));
        $this->assertNull($result['output_guard']);
    }

    public function test_warn_mode_annotates_the_delivered_response(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ));
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'warn'));

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame('[⚠️ sensitive] Reach me at alice@example.com for details.', $this->contentOf($result));
        $this->assertFalse($result['output_guard']['blocked']);
        $this->assertSame(array( 'sensitive' ), $result['output_guard']['findings']);
    }

    public function test_block_mode_withholds_the_response(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ));
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'block'));

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(OutputGuard::WITHHELD_NOTICE, $this->contentOf($result));
        $this->assertStringNotContainsString('alice@', (string) $this->contentOf($result));
        $this->assertTrue($result['output_guard']['blocked']);
    }

    public function test_a_clean_response_is_delivered_and_not_reported(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'block'));

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame('Done.', $this->contentOf($result));
        $this->assertNull($result['output_guard'], 'A safe response produces no verdict.');
    }

    public function test_a_profile_can_turn_the_guard_on_without_a_wired_guard(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ));

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(
            array( 'output_guard' => array( 'enabled' => true, 'mode' => 'block', 'strictness' => 'medium' ) )
        );
        $orchestrator->setHarnessProfileProvider($profiles);

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(OutputGuard::WITHHELD_NOTICE, $this->contentOf($result));
    }

    /**
     * A profile may switch the guard on but never off: a stale profile must not
     * be able to disable a control the runtime wired.
     */
    public function test_a_profile_with_the_guard_off_leaves_the_wired_guard_active(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ));
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'block'));

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(array( 'output_guard' => array( 'enabled' => false ) ));
        $orchestrator->setHarnessProfileProvider($profiles);

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(OutputGuard::WITHHELD_NOTICE, $this->contentOf($result));
    }

    public function test_streaming_emits_deltas_when_no_guard_is_wired(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ), null, $sse);
        $dispatcher->router->streamChunks = array( 'Do', 'ne', '.' );

        ob_start();
        $result = $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
        );
        $output = ob_get_clean();

        $this->assertSame(3, substr_count((string) $output, '"delta"'), 'One message event per token.');
        $this->assertSame('Done.', $this->contentOf($result));
    }

    public function test_streaming_suppresses_deltas_while_the_guard_is_enabled(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ), null, $sse);
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'block'));
        $dispatcher->router->streamChunks = array( 'Reach', ' me', ' at' );

        ob_start();
        $result = $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
        );
        $output = ob_get_clean();

        $this->assertSame(1, substr_count((string) $output, '"delta"'), 'One chunk, sent after validation.');
        $this->assertSame(OutputGuard::WITHHELD_NOTICE, $this->contentOf($result));
        $this->assertStringNotContainsString('alice@', (string) $output);
        $this->assertStringNotContainsString('Reach', (string) $output);
    }

    public function test_streaming_payload_carries_the_guard_verdict(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->leakResponse() ), null, $sse);
        $orchestrator->setOutputGuard(new OutputGuard(mode: 'warn'));
        $dispatcher->router->streamChunks = array();

        ob_start();
        $result = $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
        );
        $output = (string) ob_get_clean();

        $this->assertSame('[⚠️ sensitive] Reach me at alice@example.com for details.', $this->contentOf($result));
        $this->assertStringContainsString('output_guard', $output);
        $this->assertSame(1, substr_count($output, '"delta"'), 'The annotated text arrives as a single chunk.');
        $this->assertStringContainsString('⚠️ sensitive', $output);
    }

    // ── Guardrail prompt injection ─────────────────────────────────

    private function guardrailProfile(string $strictness = 'medium'): HarnessProfileProviderInterface
    {
        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(
            array(
                'enabled' => true,
                'guardrails' => array( 'enabled' => true, 'strictness' => $strictness, 'allowed_topics' => array() ),
            )
        );

        return $profiles;
    }

    public function test_no_profile_leaves_the_messages_alone(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertCount(1, $dispatcher->router->receivedMessages[0]);
        $this->assertSame('user', $dispatcher->router->receivedMessages[0][0]['role']);
    }

    public function test_an_enabled_layer_inserts_a_system_turn(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile());

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertSame('system', $sent[0]['role'], 'The block goes first.');
        $this->assertStringStartsWith('[Guardrails — Stay on Target]', $sent[0]['content']);
        $this->assertSame('user', $sent[1]['role'], 'The user turn is preserved after it.');
    }

    public function test_an_existing_system_turn_is_prefixed_not_replaced(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile());

        $orchestrator->handleChat(
            array(
                array( 'role' => 'system', 'content' => 'You are a support assistant.' ),
                array( 'role' => 'user', 'content' => 'Hi.' ),
            ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertCount(2, $sent, 'No extra system turn is added.');
        $this->assertStringStartsWith('[Guardrails — Stay on Target]', $sent[0]['content']);
        $this->assertStringEndsWith('You are a support assistant.', $sent[0]['content']);
    }

    public function test_a_disabled_guardrails_layer_injects_nothing(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(array( 'enabled' => true, 'guardrails' => array( 'enabled' => false ) ));
        $orchestrator->setHarnessProfileProvider($profiles);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertCount(1, $dispatcher->router->receivedMessages[0]);
    }

    /**
     * A client that already injected the block must not get it twice: doubling
     * it doubles its weight in the prompt.
     */
    public function test_injection_is_idempotent(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile());

        $preInjected = "[Guardrails — Stay on Target]\nAlready here.";

        $orchestrator->handleChat(
            array(
                array( 'role' => 'system', 'content' => $preInjected ),
                array( 'role' => 'user', 'content' => 'Hi.' ),
            ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertSame($preInjected, $sent[0]['content']);
        $this->assertSame(1, substr_count($sent[0]['content'], '[Guardrails — Stay on Target]'));
    }

    /**
     * The console warned when an assistant had no system prompt but never sent
     * the one it had, so every assistant ran on the user's words alone.
     */
    public function test_the_assistant_instructions_reach_the_provider(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array(
                'tools' => array(),
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a support assistant.',
            ),
        );

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertSame('system', $sent[0]['role'], 'The instructions go first.');
        $this->assertSame('You are a support assistant.', $sent[0]['content']);
        $this->assertSame('user', $sent[1]['role'], 'The user turn follows it.');
    }

    /**
     * WordPress composes `guardrail . "\n\n" . prompt`; the rails read as a
     * preamble to the instructions rather than a footnote.
     */
    public function test_the_assistant_instructions_follow_the_guardrail_block(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile());

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array(
                'tools' => array(),
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a support assistant.',
            ),
        );

        $content = $dispatcher->router->receivedMessages[0][0]['content'];

        $this->assertStringStartsWith('[Guardrails — Stay on Target]', $content);
        $this->assertStringEndsWith('You are a support assistant.', $content);
        $this->assertSame(1, substr_count($content, 'You are a support assistant.'));
    }

    /**
     * A conversation that already carries the instructions must not collect a
     * second copy: doubling them doubles their weight in the prompt.
     */
    public function test_instructions_already_in_the_conversation_are_not_duplicated(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $orchestrator->handleChat(
            array(
                array( 'role' => 'system', 'content' => 'You are a support assistant.' ),
                array( 'role' => 'user', 'content' => 'Hi.' ),
            ),
            array(
                'tools' => array(),
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a support assistant.',
            ),
        );

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertCount(2, $sent, 'No extra system turn is added.');
        $this->assertSame(1, substr_count($sent[0]['content'], 'You are a support assistant.'));
    }

    /**
     * The instructions are the assistant's own configuration, so turning the
     * guardrails layer off must not take them with it.
     */
    public function test_instructions_survive_with_the_guardrails_off(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(array( 'enabled' => true, 'guardrails' => array( 'enabled' => false ) ));
        $orchestrator->setHarnessProfileProvider($profiles);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array(
                'tools' => array(),
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a support assistant.',
            ),
        );

        $this->assertSame('You are a support assistant.', $dispatcher->router->receivedMessages[0][0]['content']);
    }

    public function test_high_strictness_reaches_the_injected_block(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile('high'));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertStringContainsString('default to declining', $dispatcher->router->receivedMessages[0][0]['content']);
    }

    public function test_the_streaming_path_injects_too(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ), null, $sse);
        $orchestrator->setHarnessProfileProvider($this->guardrailProfile());
        $dispatcher->router->streamChunks = array();

        ob_start();
        $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
        );
        ob_end_clean();

        $sent = $dispatcher->router->receivedMessages[0];

        $this->assertSame('system', $sent[0]['role']);
        $this->assertStringStartsWith('[Guardrails — Stay on Target]', $sent[0]['content']);
    }

    // ── Chat-profile hint ───────────────────────────────────────

    /**
     * The hint tells the model where the boundary is before it hits it.
     *
     * Without it the model discovers read-only by having a write refused, and
     * spends its remaining iterations retrying a call that cannot succeed.
     */
    public function test_the_read_only_hint_reaches_the_system_prompt(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setChatProfileResolver($this->chatProfile(ChatProfile::readOnly()));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $system = $dispatcher->router->receivedMessages[0][0];

        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Read-only mode', $system['content']);
        $this->assertStringContainsString('blocked at the execution layer', $system['content']);
        $this->assertStringContainsString('A blocked call is final', $system['content']);
    }

    public function test_the_permissive_profile_contributes_no_hint(): void
    {
        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setChatProfileResolver($this->chatProfile(ChatProfile::write()));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $this->assertStringNotContainsString('Read-only mode', $dispatcher->router->receivedMessages[0][0]['content']);
    }

    /**
     * A resumed turn may already carry the hint; a second copy would double
     * its weight in the prompt.
     */
    public function test_the_hint_is_not_duplicated_when_the_turn_already_carries_it(): void
    {
        $hint = ChatProfile::readOnly()->promptHint();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $orchestrator->setChatProfileResolver($this->chatProfile(ChatProfile::readOnly()));

        $orchestrator->handleChat(
            array(
                array( 'role' => 'system', 'content' => $hint ),
                array( 'role' => 'user', 'content' => 'Hi.' ),
            ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            userId: 7,
        );

        $this->assertSame(
            1,
            \substr_count($dispatcher->router->receivedMessages[0][0]['content'], 'A blocked call is final')
        );
    }

    public function test_the_streaming_path_carries_the_hint_too(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ), null, $sse);
        $orchestrator->setChatProfileResolver($this->chatProfile(ChatProfile::readOnly()));
        $dispatcher->router->streamChunks = array();

        \ob_start();
        $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
            userId: 7,
        );
        \ob_end_clean();

        $this->assertStringContainsString(
            'Read-only mode',
            $dispatcher->router->receivedMessages[0][0]['content']
        );
    }

    // ── Scored tool routing ───────────────────────────────────────

    /**
     * A tool with a slug and capability flags, for routing assertions.
     *
     * @param array<int, string> $flags Capability flags.
     */
    private function flaggedTool(string $slug, array $flags): ToolInterface
    {
        return new class ($slug, $flags) implements ToolInterface, ToolCapabilityFlagsInterface {
            /** @param array<int, string> $flags */
            public function __construct(private string $slug, private array $flags)
            {
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
                return '';
            }

            public function getParametersSchema(): array
            {
                return array();
            }

            public function getRequiredCapability(): string
            {
                return '';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return array();
            }

            /** @return array<int, string> */
            public function getCapabilityFlags(): array
            {
                return $this->flags;
            }
        };
    }

    /**
     * An orchestrator with several registered tools.
     *
     * @param array<int, ToolInterface> $tools
     * @return array{0: ChatOrchestrator, 1: InMemoryDispatcher}
     */
    private function orchestratorWithTools(array $tools, array $responses): array
    {
        $errors     = $this->errors();
        $dispatcher = new InMemoryDispatcher();

        $registry = new ToolRegistry($dispatcher, $errors);
        foreach ($tools as $tool) {
            $registry->register($tool);
        }

        $orchestrator = new ChatOrchestrator(
            $registry,
            $this->router($responses, $errors, $dispatcher),
            $dispatcher,
            $errors,
            new CostCalculator(),
            new SseHandler($this->createMock(PlatformFlushInterface::class)),
        );

        return array( $orchestrator, $dispatcher );
    }

    /**
     * @return array<int, string>
     */
    private function offeredToolNames(InMemoryDispatcher $dispatcher): array
    {
        return \array_map(
            static fn (array $definition): string => (string) ( $definition['function']['name'] ?? '' ),
            $dispatcher->router->receivedOptions[0]['tools']
        );
    }

    private function routingProfile(string $router, string $taskClass = 'general', bool $trace = false): HarnessProfileProviderInterface
    {
        $profile = array(
            'enabled' => true,
            'tools' => array( 'router' => $router ),
            'memory' => array( 'task_class' => $taskClass ),
        );

        if ($trace) {
            $profile['trace_capture'] = array( 'enabled' => true, 'retention_runs' => 20 );
        }

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn($profile);

        return $profiles;
    }

    private function readerAndWriter(): array
    {
        return array(
            $this->flaggedTool('writer', array( 'write', 'state-changing' )),
            $this->flaggedTool('reader', array( 'read-only' )),
        );
    }

    public function test_the_default_router_keeps_the_configured_order(): void
    {
        list( $orchestrator, $dispatcher ) = $this->orchestratorWithTools($this->readerAndWriter(), array( $this->finalResponse() ));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array( 'writer', 'reader' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(array( 'writer', 'reader' ), $this->offeredToolNames($dispatcher));
    }

    public function test_a_scored_router_puts_the_read_tool_first_for_a_rag_task(): void
    {
        list( $orchestrator, $dispatcher ) = $this->orchestratorWithTools($this->readerAndWriter(), array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->routingProfile('scored', 'rag'));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array( 'writer', 'reader' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertSame(array( 'reader', 'writer' ), $this->offeredToolNames($dispatcher));
    }

    public function test_scoring_reorders_but_never_filters(): void
    {
        list( $orchestrator, $dispatcher ) = $this->orchestratorWithTools($this->readerAndWriter(), array( $this->finalResponse() ));
        $orchestrator->setHarnessProfileProvider($this->routingProfile('scored', 'rag'));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array( 'writer', 'reader' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $this->assertCount(2, $this->offeredToolNames($dispatcher), 'A capability outage is worse than a ranking miss.');
    }

    public function test_a_trace_records_the_router_and_the_order(): void
    {
        list( $orchestrator ) = $this->orchestratorWithTools($this->readerAndWriter(), array( $this->finalResponse() ));
        $store = $this->traceStore();

        $orchestrator->setTraceStore($store);
        $orchestrator->setHarnessProfileProvider($this->routingProfile('scored', 'rag', true));

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array( 'writer', 'reader' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            assistantId: 7,
        );

        $routing = $store->records[1];

        $this->assertSame('routing', $routing['type']);
        $this->assertSame('scored', $routing['router']);
        $this->assertSame(array( 'reader', 'writer' ), $routing['tools'], 'The trace records the order the model saw.');
    }

    // ── Trace capture ─────────────────────────────────────────────

    /**
     * In-memory trace store, so the orchestration tests can see the shape of a
     * run without a database.
     */
    private function traceStore(): TraceStoreInterface
    {
        return new class () implements TraceStoreInterface {
            /** @var array<int, string> */
            public array $types = array();

            /** @var array<int, array<string, mixed>> */
            public array $records = array();

            public int $prunes = 0;

            public function append(int $assistantId, string $runId, string $artifact, array $record): bool
            {
                $this->records[] = $record;
                $this->types[]   = (string) ( $record['type'] ?? '' );

                return true;
            }

            public function prune(int $assistantId, int $keepRuns): int
            {
                ++$this->prunes;

                return 0;
            }
        };
    }

    private function tracedProfile(): HarnessProfileProviderInterface
    {
        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(
            array(
                'enabled' => true,
                'trace_capture' => array( 'enabled' => true, 'retention_runs' => 30 ),
            )
        );

        return $profiles;
    }

    public function test_no_trace_store_is_wired_by_default(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));

        $this->assertNull($orchestrator->traceStore());
    }

    public function test_a_turn_writes_start_iteration_and_end(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $store = $this->traceStore();

        $orchestrator->setTraceStore($store);
        $orchestrator->setHarnessProfileProvider($this->tracedProfile());

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            assistantId: 7,
        );

        $this->assertSame(array( 'start', 'routing', 'iteration', 'end' ), $store->types);
        $this->assertSame(1, $store->prunes, 'The run is swept when it closes.');
        $this->assertSame('gpt-4o', $store->records[0]['model']);
        $this->assertSame('fixed', $store->records[1]['router']);
        $this->assertSame('stop', $store->records[2]['finish_reason']);
        $this->assertSame(1, $store->records[3]['provider_calls'], 'The capture counts the call even when the loop never increments.');
    }

    public function test_capture_is_gated_by_the_profile_even_with_a_store(): void
    {
        list( $orchestrator ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ));
        $store = $this->traceStore();

        $orchestrator->setTraceStore($store);

        $profiles = $this->createMock(HarnessProfileProviderInterface::class);
        $profiles->method('getProfile')->willReturn(array( 'enabled' => true ));
        $orchestrator->setHarnessProfileProvider($profiles);

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            array( 'tools' => array(), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            assistantId: 7,
        );

        $this->assertSame(array(), $store->types);
        $this->assertSame(0, $store->prunes);
    }

    public function test_a_trace_records_one_iteration_per_provider_call(): void
    {
        list( $orchestrator ) = $this->orchestrator(
            $this->echoTool()[0],
            array( $this->toolCallResponse(), $this->finalResponse() )
        );
        $store = $this->traceStore();

        $orchestrator->setTraceStore($store);
        $orchestrator->setHarnessProfileProvider($this->tracedProfile());

        $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Do it.' ) ),
            array( 'tools' => array( 'echo_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
            assistantId: 7,
        );

        // The tool record sits between the two provider calls: one execution
        // happened, and the trace says which tool, for how long and with what
        // outcome — the shape per-tool metrics are derived from.
        $this->assertSame(
            array( 'start', 'routing', 'iteration', 'tool', 'iteration', 'end' ),
            $store->types
        );
        $this->assertSame('tool_calls', $store->records[2]['finish_reason']);

        $this->assertSame('echo_tool', $store->records[3]['tool']);
        $this->assertSame('success', $store->records[3]['outcome']);
        $this->assertGreaterThanOrEqual(0.0, (float) $store->records[3]['duration_ms']);

        $this->assertSame('stop', $store->records[4]['finish_reason']);
        $this->assertSame(2, $store->records[5]['provider_calls']);
        $this->assertSame(1, $store->records[5]['iterations'], 'The orchestrator loop counted one continuation.');
    }

    public function test_the_streaming_path_traces_too(): void
    {
        $sse = new SseHandler($this->createMock(PlatformFlushInterface::class));
        $sse->markHeadersSent();

        list( $orchestrator, , $dispatcher ) = $this->orchestrator($this->echoTool()[0], array( $this->finalResponse() ), null, $sse);
        $store = $this->traceStore();

        $orchestrator->setTraceStore($store);
        $orchestrator->setHarnessProfileProvider($this->tracedProfile());
        $dispatcher->router->streamChunks = array();

        ob_start();
        $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Hi.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array() ),
            assistantId: 7,
        );
        ob_end_clean();

        $this->assertSame(array( 'start', 'routing', 'iteration', 'end' ), $store->types);
        $this->assertSame(1, $store->prunes);
    }

    /**
     * A tool whose result carries an encoded payload and no `message` key, so
     * it takes the sanitize-and-encode branch of the loop.
     */
    private function payloadTool(string $slug, mixed $result): ToolInterface
    {
        return new class ($slug, $result) implements ToolInterface {
            public function __construct(private string $slug, private mixed $result)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getName(): string
            {
                return 'Payload Tool';
            }

            public function getDescription(): string
            {
                return 'Returns an encoded payload.';
            }

            public function getParametersSchema(): array
            {
                return array( 'type' => 'object', 'properties' => array() );
            }

            public function getRequiredCapability(): string
            {
                return '';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return $this->result;
            }
        };
    }

    /**
     * The same result shape, but the tool declares how the model should see it.
     */
    private function declaringPayloadTool(string $slug, mixed $result, mixed $copy): ToolInterface
    {
        return new class ($slug, $result, $copy) implements ToolInterface, \Nvoos\Core\Domain\Contract\LlmSanitizerInterface {
            public function __construct(private string $slug, private mixed $result, private mixed $copy)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getName(): string
            {
                return 'Declaring Payload Tool';
            }

            public function getDescription(): string
            {
                return 'Returns a payload with a declared copy.';
            }

            public function getParametersSchema(): array
            {
                return array( 'type' => 'object', 'properties' => array() );
            }

            public function getRequiredCapability(): string
            {
                return '';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return $this->result;
            }

            public function sanitizeForLlm(mixed $result): mixed
            {
                return $this->copy;
            }
        };
    }

    private function toolCallResponseFor(string $name): array
    {
        return array(
            'choices' => array(
                array(
                    'message'      => array(
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => array(
                            array(
                                'id'       => 'call_1',
                                'function' => array(
                                    'name'      => $name,
                                    'arguments' => '{}',
                                ),
                            ),
                        ),
                    ),
                    'finish_reason' => 'tool_calls',
                ),
            ),
            'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
        );
    }

    public function test_a_payload_is_stripped_before_the_model_sees_it(): void
    {
        $tool = $this->payloadTool('payload_tool', array(
            'images' => array(
                array( 'b64_json' => str_repeat('A', 500), 'url' => 'https://example.test/a.png' ),
            ),
        ));

        [ $orchestrator, ] = $this->orchestratorWithTools(
            array( $tool ),
            array( $this->toolCallResponseFor('payload_tool'), $this->finalResponse() )
        );

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Make one.' ) ),
            array( 'tools' => array( 'payload_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $content = (string) $result['tool_results'][0]['content'];

        $this->assertStringNotContainsString('b64_json', $content);
        $this->assertStringContainsString('a.png', $content);
    }

    public function test_a_declared_copy_is_what_the_model_sees(): void
    {
        $tool = $this->declaringPayloadTool(
            'declared_tool',
            array( 'images' => array( array( 'b64_json' => str_repeat('A', 500) ) ) ),
            array( 'note' => 'declared-copy' )
        );

        [ $orchestrator, ] = $this->orchestratorWithTools(
            array( $tool ),
            array( $this->toolCallResponseFor('declared_tool'), $this->finalResponse() )
        );

        $result = $orchestrator->handleChat(
            array( array( 'role' => 'user', 'content' => 'Make one.' ) ),
            array( 'tools' => array( 'declared_tool' ), 'provider' => 'openai', 'model' => 'gpt-4o' ),
        );

        $content = (string) $result['tool_results'][0]['content'];

        $this->assertStringContainsString('declared-copy', $content);
        $this->assertStringNotContainsString('images', $content);
    }

    public function test_the_streaming_path_sanitizes_the_same_way(): void
    {
        $tool = $this->payloadTool('payload_tool', array(
            'images' => array(
                array( 'b64_json' => str_repeat('B', 500), 'url' => 'https://example.test/streamed.png' ),
            ),
        ));

        [ $orchestrator, $dispatcher ] = $this->orchestratorWithTools(
            array( $tool ),
            array( $this->toolCallResponseFor('payload_tool'), $this->finalResponse() )
        );

        // This path used to encode the raw result, so the payload reached the
        // model here and not on the non-streaming path. Assert on what was sent
        // to the provider rather than on stdout: the SSE handler flushes output
        // buffers, so a captured buffer is not a reliable witness.
        //
        // `sendHeaders()` drains every buffer by design, which PHPUnit reports as
        // "closed output buffers other than its own". Restoring the level keeps
        // that check honest instead of suppressing it.
        $level = \ob_get_level();

        $orchestrator->handleChatStreaming(
            messages: array( array( 'role' => 'user', 'content' => 'Make one.' ) ),
            assistantConfig: array( 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => array( 'payload_tool' ) ),
            assistantId: 7,
        );

        while (\ob_get_level() < $level) {
            \ob_start();
        }

        $sent = (string) \json_encode($dispatcher->router->receivedMessages);

        $this->assertStringNotContainsString('b64_json', $sent);
        $this->assertStringContainsString('streamed.png', $sent);
    }
}
