<?php
/**
 * Tests for the Schedule Anything preset registry + REST endpoints.
 *
 * Covers catalogue integrity, search/filter/pagination, override
 * whitelisting (mass-assignment guard), install idempotency, and the
 * permission matrix for the preset routes.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_SA_Presets_REST.
 */
class Test_SA_Presets_REST extends WP_UnitTestCase {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * Load the Schedule Anything surface.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'Pro Schedule Manager not available.' );
		}

		// __DIR__ = <plugin>/addons/pro/tests → dirname ×3 = <plugin> root.
		$base = dirname( __DIR__, 3 );

		require_once $base . '/addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-sa-toolkit-adapter.php';
		require_once $base . '/addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-schedule-presets.php';
		require_once $base . '/addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php';

		WP_MCP_AI_SA_REST_Controller::init();
	}

	/**
	 * Reset state + register routes between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_SA_Schedule_Presets::INSTALLS_OPTION );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	protected function dispatch( $method, $route, array $body = array() ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Catalogue integrity: unique ids + required fields + valid types.
	 */
	public function test_catalogue_integrity() {
		$presets     = WP_MCP_AI_SA_Schedule_Presets::get_catalogue();
		$valid_types = array( 'task', 'workflow', 'assistant_run', 'channel_broadcast', 'workflow_builder' );

		$this->assertNotEmpty( $presets );

		foreach ( $presets as $id => $preset ) {
			foreach ( array( 'name', 'description', 'toolkit', 'category', 'icon', 'schedule_type', 'schedule', 'tags', 'schedule_data' ) as $field ) {
				$this->assertArrayHasKey( $field, $preset, "Preset {$id} missing {$field}." );
			}
			$this->assertContains( $preset['schedule_type'], $valid_types, "Preset {$id} has invalid schedule_type." );
			$this->assertArrayHasKey( 'schedule', $preset['schedule_data'], "Preset {$id} schedule_data missing cron slug." );
			$this->assertArrayHasKey( 'hook', $preset['schedule_data'], "Preset {$id} schedule_data missing hook." );
		}

		$this->assertCount( count( $presets ), array_unique( array_keys( $presets ) ) );
	}

	/**
	 * Search narrows the catalogue.
	 */
	public function test_get_presets_search() {
		$result = WP_MCP_AI_SA_Schedule_Presets::get_presets( array( 'search' => 'inventory' ) );

		$this->assertEquals( 1, $result['total'] );
		$this->assertEquals( 'ecom-inventory-alert', $result['presets'][0]['id'] );
	}

	/**
	 * Toolkit filter narrows the catalogue.
	 */
	public function test_get_presets_toolkit_filter() {
		$result = WP_MCP_AI_SA_Schedule_Presets::get_presets( array( 'toolkit' => 'analytics' ) );

		$this->assertEquals( 2, $result['total'] );
	}

	/**
	 * Pagination slices correctly.
	 */
	public function test_get_presets_pagination() {
		$result = WP_MCP_AI_SA_Schedule_Presets::get_presets(
			array(
				'page'     => 1,
				'per_page' => 5,
			)
		);

		$this->assertEquals( 5, count( $result['presets'] ) );
		$this->assertGreaterThan( 5, $result['total'] );
	}

	/**
	 * Install keys are deterministic and fingerprint the overrides.
	 */
	public function test_install_key_deterministic() {
		$a = WP_MCP_AI_SA_Schedule_Presets::install_key( 'crm-lead-nurture', array( 'name' => 'X' ) );
		$b = WP_MCP_AI_SA_Schedule_Presets::install_key( 'crm-lead-nurture', array( 'name' => 'X' ) );
		$c = WP_MCP_AI_SA_Schedule_Presets::install_key( 'crm-lead-nurture', array( 'name' => 'Y' ) );

		$this->assertEquals( $a, $b );
		$this->assertNotEquals( $a, $c );
	}

	/**
	 * Overrides are whitelisted — unknown keys are dropped.
	 */
	public function test_sanitize_overrides_whitelist() {
		$clean = WP_MCP_AI_SA_Schedule_Presets::sanitize_overrides(
			array(
				'name'      => ' Custom ',
				'evil'      => '<script>',
				'injection' => array( 'x' => 'y' ),
			)
		);

		$this->assertEquals( array( 'name' => 'Custom' ), $clean );
	}

	/**
	 * Unauthenticated preset list is forbidden.
	 */
	public function test_list_requires_permission() {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/presets' );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Authenticated list returns the full catalogue.
	 */
	public function test_list_returns_presets() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/presets' );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertEquals( count( WP_MCP_AI_SA_Schedule_Presets::get_catalogue() ), $data['total'] );
	}

	/**
	 * Unknown preset returns 404.
	 */
	public function test_get_preset_404() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/presets/does-not-exist' );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Installing a preset creates exactly one schedule.
	 */
	public function test_install_creates_schedule() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/presets/cal-reminder-followup/install', array( 'overrides' => array() ) );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertFalse( $data['replayed'] );
		$this->assertArrayHasKey( 'id', $data['schedule'] );

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$this->assertCount( 1, $schedules );
	}

	/**
	 * Replaying the same install is idempotent — no duplicate schedule.
	 */
	public function test_install_replay_idempotent() {
		wp_set_current_user( $this->admin_id );

		$first  = $this->dispatch( 'POST', '/mcp-ai-pro/v1/presets/cal-reminder-followup/install', array( 'overrides' => array() ) );
		$second = $this->dispatch( 'POST', '/mcp-ai-pro/v1/presets/cal-reminder-followup/install', array( 'overrides' => array() ) );

		$first_data  = $first->get_data();
		$second_data = $second->get_data();

		$this->assertTrue( $second_data['replayed'] );
		$this->assertEquals( $first_data['schedule']['id'], $second_data['schedule']['id'] );

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$this->assertCount( 1, $schedules );
	}

	/**
	 * Whitelisted overrides apply to the created schedule.
	 */
	public function test_install_overrides_apply() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/mcp-ai-pro/v1/presets/cal-reminder-followup/install',
			array(
				'overrides' => array(
					'name'    => 'Custom Reminder Name',
					'enabled' => true,
				),
			)
		);
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Custom Reminder Name', $data['schedule']['name'] );
		$this->assertTrue( $data['schedule']['enabled'] );
	}

	/**
	 * Unknown install preset returns 404.
	 */
	public function test_install_unknown_preset_404() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/presets/does-not-exist/install', array( 'overrides' => array() ) );

		$this->assertEquals( 404, $response->get_status() );
	}
}
