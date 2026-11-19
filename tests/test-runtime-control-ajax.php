<?php
/**
 * AJAX tests for runtime control handlers.
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_queue_status          (WP_MCP_AI_Queue_Manager::ajax_queue_status)
 *   - wp_mcp_ai_rabbitmq_health       (WP_MCP_AI_Section_RabbitMQ::ajax_health_check)
 *   - wp_mcp_ai_rabbitmq_setup        (WP_MCP_AI_Section_RabbitMQ::ajax_setup_infrastructure)
 *   - wp_mcp_ai_run_timeline_list_runs (WP_MCP_AI_Admin_Run_Timeline::ajax_list_runs)
 *   - wp_mcp_ai_run_timeline_get_run   (WP_MCP_AI_Admin_Run_Timeline::ajax_get_run)
 *   - wp_mcp_ai_control_session        (WP_MCP_AI_Orchestration_Dashboard::ajax_control_session, Pro)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Runtime control (queue, RabbitMQ, run timeline, session).
 */
// Load the Pro admin class under test; the pro addon loads it only in admin
// context, so require it here to keep the suite runnable standalone (mirrors
// CI, where earlier admin-context tests load it).
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	$wp_mcp_ai_orchestration_dashboard = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-orchestration-dashboard.php';
	if ( file_exists( $wp_mcp_ai_orchestration_dashboard ) ) {
		require_once $wp_mcp_ai_orchestration_dashboard;
	}
	unset( $wp_mcp_ai_orchestration_dashboard );
}

class Test_Runtime_Control_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Bind the runtime-control handlers for the duration of the test.
	 *
	 * The run-timeline page and Pro orchestration dashboard register from their
	 * constructors and the queue manager from get_instance(), all triggered on
	 * an admin request in production. Unbound, every dispatch ends with an
	 * empty body that the rejection assertions accept.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( false === has_action( 'wp_ajax_wp_mcp_ai_run_timeline_list_runs' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Admin_Run_Timeline' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-run-timeline.php';
			}
			if ( class_exists( 'WP_MCP_AI_Admin_Run_Timeline' ) ) {
				new WP_MCP_AI_Admin_Run_Timeline();
			}
		}

		if ( false === has_action( 'wp_ajax_wp_mcp_ai_queue_status' )
			&& class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			// The queue manager only binds its AJAX action when RabbitMQ is
			// enabled, and it is a singleton: init_hooks() runs once, in the
			// constructor, so the per-test hook restore has to be repaired by
			// re-binding the existing instance's callback.
			add_filter( 'wp_mcp_ai_rabbitmq_enabled', '__return_true' );
			$queue = WP_MCP_AI_Queue_Manager::get_instance();
			if ( false === has_action( 'wp_ajax_wp_mcp_ai_queue_status' ) && method_exists( $queue, 'ajax_queue_status' ) ) {
				add_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $queue, 'ajax_queue_status' ) );
			}
		}

		if ( false === has_action( 'wp_ajax_wp_mcp_ai_control_session' )
			&& class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			new WP_MCP_AI_Orchestration_Dashboard();
		}
	}

	/**
	 * Nonce shared by queue_status, rabbitmq_health, rabbitmq_setup.
	 */
	const NONCE_ADMIN = 'wp_mcp_ai_admin';

	/**
	 * Nonce shared by run_timeline handlers.
	 */
	const NONCE_TIMELINE = 'wp_mcp_ai_run_timeline';

	/**
	 * Nonce used by control_session (Pro).
	 */
	const NONCE_ORCH = 'wp_mcp_ai_orchestration';

	// ---
	// wp_mcp_ai_queue_status
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_queue_status_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_queue_status' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_queue_status_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_queue_status',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns stats for admin. */
	public function test_queue_status_returns_stats_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_queue_status',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
		}
	}

	// ---
	// wp_mcp_ai_rabbitmq_health
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_rabbitmq_health_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_rabbitmq_health' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_rabbitmq_health_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_rabbitmq_health',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns structured response. */
	public function test_rabbitmq_health_returns_structured_response() {
		$this->as_admin();

		// Stub outbound TCP/HTTP so the handler can't reach a real broker.
		$this->stub_http_response( '', new WP_Error( 'blocked', 'blocked' ) );

		$response = $this->dispatch(
			'wp_mcp_ai_rabbitmq_health',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		// Contract: success:true (with status array) OR success:false (client
		// not loaded or connection refused).
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_rabbitmq_setup
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_rabbitmq_setup_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_rabbitmq_setup' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_rabbitmq_setup_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_rabbitmq_setup',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns structured response without client. */
	public function test_rabbitmq_setup_returns_structured_response_without_client() {
		$this->as_admin();

		$this->stub_http_response( '', new WP_Error( 'blocked', 'blocked' ) );

		$response = $this->dispatch(
			'wp_mcp_ai_rabbitmq_setup',
			array( 'nonce' => wp_create_nonce( self::NONCE_ADMIN ) )
		);

		// Without an active RabbitMQ client the handler returns
		// 'RabbitMQ client not loaded' or an exception-derived error.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_run_timeline_list_runs
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_list_runs_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch( 'wp_mcp_ai_run_timeline_list_runs' );

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_list_runs_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_list_runs',
			array( 'nonce' => wp_create_nonce( self::NONCE_TIMELINE ) )
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Verifies the response returns array for admin. */
	public function test_list_runs_returns_array_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_list_runs',
			array( 'nonce' => wp_create_nonce( self::NONCE_TIMELINE ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertIsArray( $response['data'] );
		}
	}

	// ---
	// wp_mcp_ai_run_timeline_get_run
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_run_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_get_run',
			array( 'run_id' => 'run-001' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_run_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_get_run',
			array(
				'nonce'  => wp_create_nonce( self::NONCE_TIMELINE ),
				'run_id' => 'run-001',
			)
		);

		$this->assertAjaxError( $response, 'Permission denied' );
	}

	/** Validates the missing run id parameter. */
	public function test_get_run_validates_missing_run_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_get_run',
			array(
				'nonce'  => wp_create_nonce( self::NONCE_TIMELINE ),
				'run_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'run_id is required' );
	}

	/** Verifies the response returns not found for unknown run. */
	public function test_get_run_returns_not_found_for_unknown_run() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_run_timeline_get_run',
			array(
				'nonce'  => wp_create_nonce( self::NONCE_TIMELINE ),
				'run_id' => 'definitely-does-not-exist-run-xyz',
			)
		);

		$this->assertAjaxError( $response, 'Run not found' );
	}

	// ---
	// wp_mcp_ai_control_session (Pro only)
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_control_session_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_control_session',
			array(
				'session_id' => 'sess-001',
				'action'     => 'pause',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/** Guards against insufficient capabilities. */
	public function test_control_session_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_control_session',
			array(
				'nonce'      => wp_create_nonce( self::NONCE_ORCH ),
				'session_id' => 'sess-001',
				'action'     => 'pause',
			)
		);

		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_control_session_returns_structured_response_for_admin() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Orchestration_Dashboard (Pro) is not available.' );
		}

		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_control_session',
			array(
				'nonce'      => wp_create_nonce( self::NONCE_ORCH ),
				'session_id' => 'sess-does-not-exist',
				'action'     => 'pause',
			)
		);

		// Contract: a JSON object with a `success` flag.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}
