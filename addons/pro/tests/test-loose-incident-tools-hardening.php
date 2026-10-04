<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.
/**
 * Tests for the loose incident/maintenance tools.
 *
 * The toolkit audit sweep covers the loose tools living directly in
 * `addons/pro/includes/tools/*.php` (create_incident, update_incident,
 * resolve_incident, schedule_maintenance, get_service_status). These five
 * tools are pure WordPress-layer implementations — no shell, no provider
 * clients, no response parsing — so the failure-class scan verified them
 * clean. This suite locks the contracts that keep them clean:
 *
 *  - incident lifecycle via WP_MCP_AI_Incident_CPT (phases, timeline,
 *    transition validation, resolution timestamp),
 *  - maintenance-window validation (ISO dates, ordering, storage),
 *  - the status registry wiring (source registration, per-component and
 *    overall status, unknown-slug errors),
 *  - entry-point input guards (missing required keys return honest
 *    WP_Errors instead of PHP warnings).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group incident-tools
 * @group pro
 */

/**
 * Stub service-status source used by the registry tests.
 */
class WP_MCP_AI_Test_Status_Source implements Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'test_component';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Test Component';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_group() {
		return 'test';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check_health() {
		return array(
			'status'     => 'operational',
			'message'    => 'Test OK',
			'checked_at' => time(),
			'latency_ms' => 12,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_public() {
		return true;
	}
}

/**
 * Loose incident/maintenance tools hardening test case.
 */
class Test_Loose_Incident_Tools_Hardening extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-incident-cpt.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-maintenance-cpt.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-incident.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-update-incident.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-resolve-incident.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-maintenance.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-service-status.php';

		if ( ! interface_exists( 'Interface_WP_MCP_AI_Service_Status_Source' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-service-status-source.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-registry.php';
		}

		// Reset the registry static state and cached snapshots.
		delete_option( WP_MCP_AI_Service_Status_Registry::OPTION_KEY );
		delete_option( WP_MCP_AI_Service_Status_Registry::LAST_CHECK_KEY );
		delete_transient( WP_MCP_AI_Service_Status_Registry::CACHE_FRESH_KEY );

		$instance = new ReflectionProperty( 'WP_MCP_AI_Service_Status_Registry', 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Create an incident through the tool and verify post + meta + timeline.
	 */
	public function test_create_incident_creates_post_with_phase_and_timeline() {
		$tool = new WP_MCP_AI_Tool_Create_Incident();

		$result = $tool->execute(
			array(
				'title'    => 'Checkout down',
				'severity' => 'major',
				'message'  => 'Payments failing',
				'services' => array( 'checkout' ),
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$post = get_post( $result['incident_id'] );
		$this->assertNotNull( $post );
		$this->assertSame( WP_MCP_AI_Incident_CPT::POST_TYPE, $post->post_type );
		$this->assertSame( 'major', get_post_meta( $post->ID, '_mcp_ai_incident_severity', true ) );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_DETECTED, get_post_meta( $post->ID, '_mcp_ai_incident_phase', true ) );
		$this->assertSame( array( 'checkout' ), get_post_meta( $post->ID, '_mcp_ai_incident_services', true ) );

		$timeline = get_post_meta( $post->ID, '_mcp_ai_incident_timeline', true );
		$this->assertIsArray( $timeline );
		$this->assertCount( 1, $timeline );
		$this->assertSame( 'Payments failing', $timeline[0]['message'] );
		$this->assertSame( $this->admin_user, $timeline[0]['operator_id'] );
	}

	/**
	 * A missing title must return an honest WP_Error, not a PHP warning.
	 */
	public function test_create_incident_missing_title_errors() {
		$tool = new WP_MCP_AI_Tool_Create_Incident();

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_user ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	/**
	 * Update with a valid phase transition must move the phase and append a
	 * timeline entry.
	 */
	public function test_update_incident_transitions_phase() {
		$create  = new WP_MCP_AI_Tool_Create_Incident();
		$created = $create->execute( array( 'title' => 'API latency' ), array( 'user_id' => $this->admin_user ) );
		$post_id = $created['incident_id'];

		$tool   = new WP_MCP_AI_Tool_Update_Incident();
		$result = $tool->execute(
			array(
				'incident_id' => $post_id,
				'phase'       => WP_MCP_AI_Incident_CPT::PHASE_INVESTIGATING,
				'message'     => 'Investigating the slow endpoint.',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame(
			WP_MCP_AI_Incident_CPT::PHASE_INVESTIGATING,
			get_post_meta( $post_id, '_mcp_ai_incident_phase', true )
		);

		$timeline = get_post_meta( $post_id, '_mcp_ai_incident_timeline', true );
		$this->assertCount( 2, $timeline );
		$this->assertSame( 'Investigating the slow endpoint.', $timeline[1]['message'] );
	}

	/**
	 * The advertised phase enum is validated by the CPT transition table —
	 * an invalid transition must surface as an error.
	 */
	public function test_update_incident_rejects_invalid_transition() {
		$create  = new WP_MCP_AI_Tool_Create_Incident();
		$created = $create->execute( array( 'title' => 'Cache miss' ), array( 'user_id' => $this->admin_user ) );

		$tool   = new WP_MCP_AI_Tool_Update_Incident();
		$result = $tool->execute(
			array(
				'incident_id' => $created['incident_id'],
				// detected → identified is not in VALID_TRANSITIONS.
				'phase'       => WP_MCP_AI_Incident_CPT::PHASE_IDENTIFIED,
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_transition', $result->get_error_code() );
	}

	/**
	 * Update with only a message must append a timeline entry under the
	 * current phase.
	 */
	public function test_update_incident_appends_timeline_message() {
		$create  = new WP_MCP_AI_Tool_Create_Incident();
		$created = $create->execute( array( 'title' => 'Queue backlog' ), array( 'user_id' => $this->admin_user ) );
		$post_id = $created['incident_id'];

		$tool   = new WP_MCP_AI_Tool_Update_Incident();
		$result = $tool->execute(
			array(
				'incident_id' => $post_id,
				'message'     => 'Queue drained to 50%.',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$timeline = get_post_meta( $post_id, '_mcp_ai_incident_timeline', true );
		$this->assertCount( 2, $timeline );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_DETECTED, $timeline[1]['phase'] );
		$this->assertSame( 'Queue drained to 50%.', $timeline[1]['message'] );
	}

	/**
	 * Resolve must move the incident to the resolved phase and timestamp it.
	 */
	public function test_resolve_incident_sets_resolved_phase() {
		$create  = new WP_MCP_AI_Tool_Create_Incident();
		$created = $create->execute( array( 'title' => 'SSL expiry' ), array( 'user_id' => $this->admin_user ) );
		$post_id = $created['incident_id'];

		$tool   = new WP_MCP_AI_Tool_Resolve_Incident();
		$result = $tool->execute(
			array(
				'incident_id' => $post_id,
				'message'     => 'Certificate renewed.',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED, get_post_meta( $post_id, '_mcp_ai_incident_phase', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_mcp_ai_incident_resolved_at', true ) );
	}

	/**
	 * Resolving a nonexistent incident must return the not-found error.
	 */
	public function test_resolve_incident_unknown_id_errors() {
		$tool = new WP_MCP_AI_Tool_Resolve_Incident();

		$result = $tool->execute( array( 'incident_id' => 9999999 ), array( 'user_id' => $this->admin_user ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_not_found', $result->get_error_code() );
	}

	/**
	 * The status tool must return the checked status for a known component.
	 */
	public function test_get_service_status_single_component() {
		// Isolate from the bootstrap-registered default sources.
		remove_all_filters( 'wp_mcp_ai_service_status_sources' );
		add_filter(
			'wp_mcp_ai_service_status_sources',
			function ( $sources ) {
				$sources['test_component'] = new WP_MCP_AI_Test_Status_Source();
				return $sources;
			}
		);

		$tool   = new WP_MCP_AI_Tool_Get_Service_Status();
		$result = $tool->execute( array( 'component_slug' => 'test_component' ), array( 'user_id' => $this->admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'test_component', $result['slug'] );
		$this->assertSame( 'Test Component', $result['name'] );
		$this->assertSame( 'operational', $result['status'] );
	}

	/**
	 * Querying an unknown component must return the unknown-component error.
	 */
	public function test_get_service_status_unknown_component_errors() {
		$tool   = new WP_MCP_AI_Tool_Get_Service_Status();
		$result = $tool->execute( array( 'component_slug' => 'nope' ), array( 'user_id' => $this->admin_user ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_unknown_component', $result->get_error_code() );
	}

	/**
	 * Omitting the slug must return the overall status plus public components.
	 */
	public function test_get_service_status_overall() {
		// Isolate from the bootstrap-registered default sources.
		remove_all_filters( 'wp_mcp_ai_service_status_sources' );
		add_filter(
			'wp_mcp_ai_service_status_sources',
			function ( $sources ) {
				$sources['test_component'] = new WP_MCP_AI_Test_Status_Source();
				return $sources;
			}
		);

		$tool   = new WP_MCP_AI_Tool_Get_Service_Status();
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_user ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'operational', $result['overall_status'] );
		$this->assertCount( 1, $result['components'] );
		$this->assertSame( 'test_component', $result['components'][0]['slug'] );
	}

	/**
	 * Malformed dates must return the invalid-date error.
	 */
	public function test_schedule_maintenance_rejects_invalid_dates() {
		$tool = new WP_MCP_AI_Tool_Schedule_Maintenance();

		$result = $tool->execute(
			array(
				'title' => 'Upgrade',
				'start' => 'not-a-date',
				'end'   => '2026-08-01T04:00:00Z',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_date', $result->get_error_code() );
	}

	/**
	 * An end time before the start time must return the invalid-range error.
	 */
	public function test_schedule_maintenance_rejects_end_before_start() {
		$tool = new WP_MCP_AI_Tool_Schedule_Maintenance();

		$result = $tool->execute(
			array(
				'title' => 'Upgrade',
				'start' => '2026-08-01T04:00:00Z',
				'end'   => '2026-08-01T02:00:00Z',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_range', $result->get_error_code() );
	}

	/**
	 * A valid window must be stored as a scheduled maintenance post.
	 */
	public function test_schedule_maintenance_creates_window() {
		$tool = new WP_MCP_AI_Tool_Schedule_Maintenance();

		$result = $tool->execute(
			array(
				'title'    => 'Database upgrade',
				'message'  => 'Maintenance work',
				'start'    => '2026-08-01T02:00:00Z',
				'end'      => '2026-08-01T04:00:00Z',
				'services' => array( 'db' ),
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$post = get_post( $result['window_id'] );
		$this->assertNotNull( $post );
		$this->assertSame( WP_MCP_AI_Maintenance_CPT::POST_TYPE, $post->post_type );
		$this->assertSame(
			WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED,
			get_post_meta( $post->ID, '_mcp_ai_maintenance_status', true )
		);
		$this->assertSame( '2026-08-01T02:00:00Z', get_post_meta( $post->ID, '_mcp_ai_maintenance_start', true ) );
		$this->assertSame( array( 'db' ), get_post_meta( $post->ID, '_mcp_ai_maintenance_services', true ) );
	}
}
