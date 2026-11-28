<?php
/**
 * Async `db_export` on the WordPress host (Proposal 167 phase P7, task 7.1).
 *
 * The Laravel side is covered in `lib/core/tests/Unit/Tool/Database/`. What is
 * WordPress-specific is the dispatch and the job store, and the property that
 * matters most is the one the worker owns: **the grant is re-checked when the
 * job runs**, not remembered from when it was queued. That refusal happens
 * before any connection is opened, which is why this file needs no database.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Db_Export_Async_Test extends WP_UnitTestCase {

	/**
	 * Ensure the job table exists before the first transaction starts.
	 *
	 * `CREATE TABLE` implicitly commits, so it must not run inside a test or the
	 * rollback that isolates each one is lost.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			return;
		}

		if ( ! WP_MCP_AI_Job_Store::table_exists() ) {
			WP_MCP_AI_Job_Store::create_table();
		}
	}

	/**
	 * Skip when the kernel or the toolkit classes are absent; load them if needed.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Db_Runtime' ) || ! WP_MCP_AI_Db_Runtime::load() ) {
			$this->markTestSkipped( 'The database toolkit kernel is not available in this environment.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Job_Store' ) || ! WP_MCP_AI_Job_Store::table_exists() ) {
			$this->markTestSkipped( 'The job store is not installed in this environment.' );
		}

		$this->load_toolkit_classes();

		delete_option( 'wp_mcp_ai_database_scopes' );
		delete_option( 'wp_mcp_ai_database_connections' );

		foreach ( WP_MCP_AI_Job_Store::list_jobs( array( 'handler' => WP_MCP_AI_Db_Export_Job::HOOK ), 100 ) as $row ) {
			WP_MCP_AI_Job_Store::delete( $row['job_id'] );
		}
	}

	/**
	 * Require the toolkit classes when the toggle kept `init.php` from doing it.
	 */
	private function load_toolkit_classes(): void {
		if ( class_exists( 'WP_MCP_AI_Db_Export_Job' ) ) {
			return;
		}

		$dir = WP_MCP_AI_PRO_PATH . 'includes/tools/database/';

		require_once $dir . 'class-wp-mcp-ai-database-tool-base.php';
		require_once $dir . 'class-wp-mcp-ai-db-export-job.php';
		require_once $dir . 'class-wp-mcp-ai-tool-db-export.php';
	}

	/**
	 * Enqueue writes a durable row with a payload the worker can replay.
	 */
	public function test_enqueue_writes_a_job_row_and_strips_the_async_flag() {
		$job_id = WP_MCP_AI_Db_Export_Job::enqueue(
			array(
				'relation' => 'public.orders',
				'format'   => 'csv',
				'async'    => true,
			),
			array( 'user_id' => 7 )
		);

		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'job_', $job_id );

		$row = WP_MCP_AI_Job_Store::get( $job_id );

		$this->assertSame( WP_MCP_AI_Db_Export_Job::HOOK, $row['handler'] );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( '7', (string) $row['user_id'] );

		$payload = json_decode( (string) $row['payload'], true );

		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'async', $payload['arguments'], 'The worker must not be handed the async flag or it would re-enqueue itself.' );
		$this->assertSame( 'public.orders', $payload['arguments']['relation'] );
		$this->assertSame( 7, $payload['context']['user_id'] );

		// Some scheduler accepted it: Action Scheduler when present, WP-Cron otherwise.
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'   => WP_MCP_AI_Db_Export_Job::HOOK,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				)
			);

			$this->assertNotEmpty( $actions, 'Action Scheduler should hold the queued export.' );
		} else {
			$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_handle_async_job' ) );
		}
	}

	/**
	 * The queue depth is capped per principal and surfaces as `db_export_limit`.
	 */
	public function test_the_queue_depth_is_capped_per_principal() {
		for ( $i = 1; $i <= 3; $i++ ) {
			WP_MCP_AI_Job_Store::insert(
				array(
					'job_id'  => 'job_cap_' . $i,
					'handler' => WP_MCP_AI_Db_Export_Job::HOOK,
					'payload' => '{}',
					'status'  => 'queued',
					'user_id' => 7,
				)
			);
		}

		try {
			WP_MCP_AI_Db_Export_Job::enqueue( array( 'relation' => 'public.orders' ), array( 'user_id' => 7 ) );
			$this->fail( 'A principal with three queued exports must not be able to queue a fourth.' );
		} catch ( \Nvoos\Database\Domain\Service\Database\DatabaseRefusal $refusal ) {
			$this->assertSame( 'db_export_limit', $refusal->getRefusalCode() );
		}
	}

	/**
	 * A run whose grant lost the export tier fails the job with the refusal code.
	 *
	 * This is the re-verification property: the job payload named no scope and no
	 * tier, so the only thing the worker can consult is the registry as it is now.
	 */
	public function test_run_fails_the_job_when_the_scope_lost_the_export_tier() {
		$this->seed_grant( array( 'metadata', 'read' ) );

		WP_MCP_AI_Job_Store::insert(
			array(
				'job_id'  => 'job_narrowed',
				'handler' => WP_MCP_AI_Db_Export_Job::HOOK,
				'payload' => '{}',
				'status'  => 'queued',
				'user_id' => 7,
			)
		);

		WP_MCP_AI_Db_Export_Job::run(
			array(
				'_job_id'   => 'job_narrowed',
				'arguments' => array( 'relation' => 'public.orders' ),
				'context'   => array( 'user_id' => 7 ),
			)
		);

		$row = WP_MCP_AI_Job_Store::get( 'job_narrowed' );

		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'db_tier_required', (string) $row['error'] );
	}

	/**
	 * A job id is read back through the same tool, with no relation and no read.
	 */
	public function test_a_job_id_reads_the_export_back() {
		$this->seed_grant( array( 'metadata', 'read', 'export' ) );

		WP_MCP_AI_Job_Store::insert(
			array(
				'job_id'  => 'job_read',
				'handler' => WP_MCP_AI_Db_Export_Job::HOOK,
				'payload' => '{}',
				'status'  => 'queued',
				'user_id' => 7,
			)
		);

		// A finished job reaches `completed` through complete(), which is what
		// writes the result column.
		WP_MCP_AI_Job_Store::complete(
			'job_read',
			array(
				'checksum' => 'sha256:abc',
				'rows'     => 2,
			)
		);

		$result = ( new WP_MCP_AI_Tool_Db_Export() )->execute( array( 'job_id' => 'job_read' ), array( 'user_id' => 7 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'sha256:abc', $result['result']['checksum'] );
	}

	/**
	 * Another principal's job is a 404, not a 403.
	 */
	public function test_another_principals_job_is_not_found() {
		$this->seed_grant( array( 'metadata', 'read', 'export' ) );

		WP_MCP_AI_Job_Store::insert(
			array(
				'job_id'  => 'job_theirs',
				'handler' => WP_MCP_AI_Db_Export_Job::HOOK,
				'payload' => '{}',
				'status'  => 'completed',
				'user_id' => 99,
			)
		);

		$result = ( new WP_MCP_AI_Tool_Db_Export() )->execute( array( 'job_id' => 'job_theirs' ), array( 'user_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_db_export_not_found', $result->get_error_code() );
	}

	/**
	 * Seed a connection and a scope bound to `user:7` with the given tiers.
	 *
	 * @param string[] $tiers Tiers to grant.
	 */
	private function seed_grant( array $tiers ): void {
		update_option(
			'wp_mcp_ai_database_connections',
			array(
				'warehouse' => array(
					'id'       => 'warehouse',
					'label'    => 'Warehouse',
					'engine'   => 'pgsql',
					'host'     => 'db.internal',
					'port'     => 5432,
					'database' => 'analytics',
					'sslmode'  => 'require',
					'probe'    => array(
						'reachable'  => true,
						'can_write'  => false,
						'checked_at' => time(),
					),
				),
			)
		);

		update_option(
			'wp_mcp_ai_database_scopes',
			array(
				'analyst' => array(
					'id'         => 'analyst',
					'label'      => 'Analyst',
					'connection' => 'warehouse',
					'schemas'    => array( 'public' ),
					'tables'     => array( 'public.orders' ),
					'tiers'      => $tiers,
					'principals' => array( 'user:7' ),
					'version'    => 1,
				),
			)
		);
	}

	/**
	 * The cron router fires a registered handler and ignores an unknown one.
	 */
	public function test_the_router_dispatches_only_registered_handlers() {
		$seen = array();

		add_action(
			'wp_mcp_ai_test_router_handler',
			static function ( $args ) use ( &$seen ): void {
				$seen[] = $args;
			}
		);

		WP_MCP_AI_Db_Export_Job::route(
			array(
				'_handler' => 'wp_mcp_ai_test_router_handler',
				'_job_id'  => 'job_router',
			)
		);

		$this->assertCount( 1, $seen, 'A registered handler must receive the routed payload.' );

		// An unknown handler is a no-op, not a fatal.
		WP_MCP_AI_Db_Export_Job::route( array( '_handler' => 'wp_mcp_ai_no_such_handler' ) );

		$this->assertCount( 1, $seen );

		remove_all_actions( 'wp_mcp_ai_test_router_handler' );
	}
}
