<?php
/**
 * Queue manager port tests (Wave E2).
 *
 * Characterization suite for the ported `QueueManager`: byte-identical
 * constants and feature-flag contract, the hook surface (registered only
 * when enabled), the execution-mode decision matrix, the deferred-result
 * envelope, priority mapping, parallel/sequential fallback, and the
 * capability-flag parallelization rules. RabbitMQ and the tool registry
 * are stubbed through the protected seams so the assertions hold in both
 * install matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\QueueManager;

/**
 * @group queues
 */
class Test_Queue_Manager extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		\remove_all_filters( 'wp_mcp_ai_rabbitmq_enabled' );
		\wp_cache_flush();
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_rabbitmq_enabled' );
		$this->reset_enabled_static();
		\wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * Reset the QueueManager enablement static cache.
	 *
	 * @return void
	 */
	private function reset_enabled_static(): void {
		$reflection = new \ReflectionProperty( QueueManager::class, 'enabled' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, null );
	}

	/**
	 * Fake RabbitMQ client recording queue calls.
	 *
	 * @param bool $available Whether the fake reports availability.
	 * @return object
	 */
	private function fake_rabbitmq( bool $available = true ) {
		return new class( $available ) {
			/** @var bool */
			private $available;
			/** @var array<int, string> */
			private $priorities = array();
			/** @var array<string, array{result: mixed}> */
			private $results = array();
			/** @var int */
			private $counter = 0;

			public function __construct( bool $available ) {
				$this->available = $available;
			}

			public function is_available(): bool {
				return $this->available;
			}

			public function queue_tool_execution( $tool_name, array $arguments, array $context, $priority = 'normal' ) {
				++$this->counter;
				$job_id                   = 'job-' . $this->counter;
				$this->priorities[]       = (string) $priority;
				$this->results[ $job_id ] = array(
					'result' => array(
						'tool'   => $tool_name,
						'queued' => true,
					),
				);
				return $job_id;
			}

			public function get_job_result( $job_id ) {
				return $this->results[ $job_id ] ?? null;
			}

			public function get_queue_stats() {
				return array(
					'queues'   => 1,
					'messages' => 0,
				);
			}

			public function priorities(): array {
				return $this->priorities;
			}
		};
	}

	/**
	 * Fake tool exposing capability flags + a sync executor.
	 *
	 * @param string $slug     Tool slug.
	 * @param array  $flags    Capability flags.
	 * @param mixed  $result   Result of execute().
	 * @return object
	 */
	private function fake_tool( string $slug, array $flags, $result = 'executed' ) {
		return new class( $slug, $flags, $result ) {
			/** @var string */
			private $slug;
			/** @var array */
			private $flags;
			/** @var mixed */
			private $result;

			public function __construct( string $slug, array $flags, $result ) {
				$this->slug   = $slug;
				$this->flags  = $flags;
				$this->result = $result;
			}

			public function getCapabilityFlags(): array {
				return $this->flags;
			}

			public function execute( array $arguments = array(), array $context = array() ): mixed {
				return $this->result;
			}
		};
	}

	/**
	 * Build a manager with stubbed collaborators.
	 *
	 * @param object|null $rabbitmq  Fake RabbitMQ client.
	 * @param array       $tools     Tool slug → tool object.
	 * @param array       $estimates Tool slug → estimated ms.
	 * @return QueueManager
	 */
	private function make_manager( $rabbitmq, array $tools = array(), array $estimates = array() ): QueueManager {
		return new class( $rabbitmq, $tools, $estimates ) extends QueueManager {
			/** @var object|null */
			private $rabbitmq;
			/** @var array */
			private $tools;
			/** @var array */
			private $estimates;

			public function __construct( $rabbitmq, array $tools, array $estimates ) {
				$this->rabbitmq  = $rabbitmq;
				$this->tools     = $tools;
				$this->estimates = $estimates;
			}

			protected function resolve_rabbitmq_client() {
				return $this->rabbitmq;
			}

			protected function resolve_tool( $tool_name ) {
				return $this->tools[ $tool_name ] ?? null;
			}

			protected function estimate_execution_time( $tool_name, array $arguments ) {
				if ( isset( $this->estimates[ $tool_name ] ) ) {
					return $this->estimates[ $tool_name ];
				}
				return parent::estimate_execution_time( $tool_name, $arguments );
			}
		};
	}

	// ─── Constants + feature flag ───────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'sync', QueueManager::MODE_SYNC );
		$this->assertSame( 'queue', QueueManager::MODE_QUEUE );
		$this->assertSame( 'queue_async', QueueManager::MODE_QUEUE_ASYNC );
		$this->assertSame( 'parallel', QueueManager::MODE_PARALLEL );

		$this->assertSame( 'high', QueueManager::PRIORITY_HIGH );
		$this->assertSame( 'normal', QueueManager::PRIORITY_NORMAL );
		$this->assertSame( 'async', QueueManager::PRIORITY_LOW );

		$this->assertSame( 2000, QueueManager::QUICK_TOOL_THRESHOLD );
		$this->assertSame( 10000, QueueManager::ASYNC_TOOL_THRESHOLD );
		$this->assertSame( 'WP_MCP_AI_RABBITMQ_ENABLED', QueueManager::FEATURE_FLAG );
	}

	public function test_is_enabled_defaults_off_and_filter_on(): void {
		$this->assertFalse( QueueManager::is_enabled() );

		\add_filter( 'wp_mcp_ai_rabbitmq_enabled', '__return_true' );
		$this->reset_enabled_static();
		$this->assertTrue( QueueManager::is_enabled() );
	}

	public function test_hooks_register_only_when_enabled(): void {
		// Disabled instance: no hooks.
		$disabled = new \ReflectionClass( QueueManager::class );
		$instance = $disabled->newInstanceWithoutConstructor();

		$method = $disabled->getMethod( 'init_hooks' );
		$method->setAccessible( true );
		$method->invoke( $instance );

		$this->assertFalse( has_filter( 'wp_mcp_ai_before_tool_execute', array( $instance, 'maybe_queue_tool_execution' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $instance, 'ajax_queue_status' ) ) );

		// Enabled: both hooks register at the byte-identical priority.
		\add_filter( 'wp_mcp_ai_rabbitmq_enabled', '__return_true' );
		$this->reset_enabled_static();

		$enabled   = new \ReflectionClass( QueueManager::class );
		$instance2 = $enabled->newInstanceWithoutConstructor();
		$method2   = $enabled->getMethod( 'init_hooks' );
		$method2->setAccessible( true );
		$method2->invoke( $instance2 );

		$this->assertSame( 5, has_filter( 'wp_mcp_ai_before_tool_execute', array( $instance2, 'maybe_queue_tool_execution' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $instance2, 'ajax_queue_status' ) ) );
	}

	// ─── Execution-mode matrix ──────────────────────────────────────

	public function test_mode_unknown_tool_is_sync(): void {
		$manager = $this->make_manager( $this->fake_rabbitmq( true ) );

		$this->assertSame( QueueManager::MODE_SYNC, $manager->get_execution_mode( 'no_such_tool', array(), array() ) );
	}

	public function test_mode_queue_unavailable_is_sync(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( false ),
			array( 'search_content' => $this->fake_tool( 'search_content', array() ) )
		);

		$this->assertSame( QueueManager::MODE_SYNC, $manager->get_execution_mode( 'search_content', array(), array() ) );
	}

	public function test_mode_flag_matrix(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( true ),
			array(
				'required'  => $this->fake_tool( 'required', array( 'queue-required' ) ),
				'async'     => $this->fake_tool( 'async', array( 'async' ) ),
				'preferred' => $this->fake_tool( 'preferred', array( 'queue-preferred' ) ),
				'plain'     => $this->fake_tool( 'plain', array( 'read' ) ),
			),
			array(
				'required'  => 100,
				'async'     => 100,
				'preferred' => 3000,
				'plain'     => 3000,
			)
		);

		$this->assertSame( QueueManager::MODE_QUEUE_ASYNC, $manager->get_execution_mode( 'required', array(), array() ) );
		$this->assertSame( QueueManager::MODE_QUEUE_ASYNC, $manager->get_execution_mode( 'async', array(), array() ) );
		$this->assertSame( QueueManager::MODE_QUEUE, $manager->get_execution_mode( 'preferred', array(), array() ) );
		$this->assertSame( QueueManager::MODE_SYNC, $manager->get_execution_mode( 'plain', array(), array() ) );
	}

	public function test_mode_thresholds_use_estimates_table(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( true ),
			array(
				'quick'            => $this->fake_tool( 'quick', array() ),
				'slow'             => $this->fake_tool( 'slow', array() ),
				'get_current_time' => $this->fake_tool( 'get_current_time', array() ),
				'run_crawl4ai_job' => $this->fake_tool( 'run_crawl4ai_job', array() ),
				'mystery_tool'     => $this->fake_tool( 'mystery_tool', array() ),
			)
		);

		// get_current_time (100ms) is below the quick threshold → sync.
		$this->assertSame( QueueManager::MODE_SYNC, $manager->get_execution_mode( 'get_current_time', array(), array() ) );

		// run_crawl4ai_job (60s) exceeds the async threshold → queue_async.
		$this->assertSame( QueueManager::MODE_QUEUE_ASYNC, $manager->get_execution_mode( 'run_crawl4ai_job', array(), array() ) );

		// Unknown names default to 5s → sync (no queue-preferred flag).
		$this->assertSame( QueueManager::MODE_SYNC, $manager->get_execution_mode( 'mystery_tool', array(), array() ) );
	}

	// ─── Interception + queueing ────────────────────────────────────

	public function test_maybe_queue_passes_through_existing_pre(): void {
		$manager = $this->make_manager( $this->fake_rabbitmq( true ) );

		$this->assertSame( 'decided', $manager->maybe_queue_tool_execution( 'decided', 'x', array(), array() ) );
	}

	public function test_maybe_queue_sync_returns_null(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( true ),
			array( 'quick' => $this->fake_tool( 'quick', array() ) ),
			array( 'quick' => 100 )
		);

		$this->assertNull( $manager->maybe_queue_tool_execution( null, 'quick', array(), array() ) );
	}

	public function test_maybe_queue_deferred_envelope(): void {
		$rabbitmq = $this->fake_rabbitmq( true );
		$manager  = $this->make_manager(
			$rabbitmq,
			array( 'slow' => $this->fake_tool( 'slow', array( 'async' ) ) ),
			array( 'slow' => 100 )
		);

		$result = $manager->maybe_queue_tool_execution( null, 'slow', array(), array( 'user_id' => 7 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['_deferred'] );
		$this->assertSame( 'job-1', $result['job_id'] );
		$this->assertSame( 'slow', $result['tool_name'] );
		$this->assertSame( 'queued', $result['status'] );
		$this->assertStringContainsString( 'slow', $result['message'] );
	}

	public function test_maybe_queue_failure_falls_back_to_null(): void {
		// Unavailable RMQ → queue_tool false → null (sync continues).
		$manager = $this->make_manager(
			$this->fake_rabbitmq( false ),
			array( 'slow' => $this->fake_tool( 'slow', array( 'async' ) ) ),
			array( 'slow' => 100 )
		);

		// Mode detection with unavailable queue → sync already.
		$this->assertNull( $manager->maybe_queue_tool_execution( null, 'slow', array(), array() ) );
	}

	public function test_queue_tool_priority_mapping(): void {
		$rabbitmq = $this->fake_rabbitmq( true );
		$manager  = $this->make_manager(
			$rabbitmq,
			array(
				'realtime' => $this->fake_tool( 'realtime', array( 'realtime' ) ),
				'plain'    => $this->fake_tool( 'plain', array() ),
			)
		);

		$manager->queue_tool( 'plain', array(), array(), QueueManager::MODE_QUEUE );
		$manager->queue_tool( 'plain', array(), array(), QueueManager::MODE_QUEUE_ASYNC );
		$manager->queue_tool( 'realtime', array(), array(), QueueManager::MODE_QUEUE );

		$this->assertSame( array( 'normal', 'async', 'high' ), $rabbitmq->priorities() );
	}

	public function test_queue_tool_unavailable_returns_false(): void {
		$manager = $this->make_manager( $this->fake_rabbitmq( false ) );
		$this->assertFalse( $manager->queue_tool( 'x', array(), array() ) );
	}

	// ─── Parallel + flags ───────────────────────────────────────────

	public function test_execute_parallel_falls_back_to_sequential(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( false ),
			array(
				'a' => $this->fake_tool( 'a', array(), 'ran-a' ),
				'b' => $this->fake_tool( 'b', array(), 'ran-b' ),
			)
		);

		$results = $manager->execute_parallel(
			array(
				'call-1' => array(
					'name'      => 'a',
					'arguments' => array(),
				),
				'call-2' => array(
					'name'      => 'b',
					'arguments' => array(),
				),
			),
			array()
		);

		$this->assertSame( 'ran-a', $results['call-1']['result'] );
		$this->assertSame( 'sync', $results['call-1']['mode'] );
		$this->assertSame( 'ran-b', $results['call-2']['result'] );
		$this->assertSame( 'sync', $results['call-2']['mode'] );
	}

	public function test_execute_parallel_uses_queue_and_awaits(): void {
		$rabbitmq = $this->fake_rabbitmq( true );
		$manager  = $this->make_manager(
			$rabbitmq,
			array(
				'a' => $this->fake_tool( 'a', array() ),
				'b' => $this->fake_tool( 'b', array() ),
			)
		);

		$results = $manager->execute_parallel(
			array(
				'call-1' => array(
					'name'      => 'a',
					'arguments' => array(),
				),
				'call-2' => array(
					'name'      => 'b',
					'arguments' => array(),
				),
			),
			array(),
			2
		);

		$this->assertSame( 'queue', $results['call-1']['mode'] );
		$this->assertSame( 'a', $results['call-1']['result']['tool'] );
		$this->assertSame( 'queue', $results['call-2']['mode'] );
		$this->assertSame( 'b', $results['call-2']['result']['tool'] );
	}

	public function test_can_parallelize_flag_matrix(): void {
		$manager = $this->make_manager(
			$this->fake_rabbitmq( false ),
			array(
				'par'       => $this->fake_tool( 'par', array( 'parallelizable' ) ),
				'stateless' => $this->fake_tool( 'stateless', array( 'stateless' ) ),
				'ro'        => $this->fake_tool( 'ro', array( 'read' ) ),
				'rw'        => $this->fake_tool( 'rw', array( 'read', 'write' ) ),
			)
		);

		$this->assertTrue( $manager->can_parallelize( 'par' ) );
		$this->assertTrue( $manager->can_parallelize( 'stateless' ) );
		$this->assertTrue( $manager->can_parallelize( 'ro' ) );
		$this->assertFalse( $manager->can_parallelize( 'rw' ) );
		$this->assertFalse( $manager->can_parallelize( 'no_such_tool' ) );
	}

	// ─── Stats ──────────────────────────────────────────────────────

	public function test_get_queue_stats_unavailable_shape(): void {
		$manager = $this->make_manager( $this->fake_rabbitmq( false ) );

		$stats = $manager->get_queue_stats();
		$this->assertSame( false, $stats['available'] );
		$this->assertStringContainsString( 'RabbitMQ is not available', $stats['message'] );
	}

	public function test_get_queue_stats_passthrough(): void {
		$manager = $this->make_manager( $this->fake_rabbitmq( true ) );

		$stats = $manager->get_queue_stats();
		$this->assertSame( 1, $stats['queues'] );
	}
}
