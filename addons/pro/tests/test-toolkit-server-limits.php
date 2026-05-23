<?php
/**
 * Test_Toolkit_Server_Limits
 *
 * Phase 3c — per-server configuration overrides (rate limit + payload).
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Toolkit_Server_Limits extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	/** Set up test. */
	public function set_up() {
		parent::set_up();

		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );

		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );

		WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance()->init();
		do_action( 'rest_api_init' );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/** Tear down test. */
	public function tear_down() {
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
		// Drop any rate-limit transients we may have set.
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_tk_mcp_rl_%' OR option_name LIKE '_transient_timeout_wp_mcp_ai_tk_mcp_rl_%'" );
		}
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		parent::tear_down();
	}

	/** Test defaults have zero limits.
	 */
	public function test_defaults_have_zero_limits() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$limits = $server->effective_limits();
		$this->assertSame( 0, $limits['requests_per_minute'] );
		$this->assertSame( 0, $limits['max_payload_bytes'] );
		$this->assertSame( 0, $limits['max_iterations'] );
	}

	/** Test update configuration persists limits.
	 */
	public function test_update_configuration_persists_limits() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'             => true,
				'requests_per_minute' => 5,
				'max_payload_bytes'   => 100,
				'max_iterations'      => 3,
			)
		);
		$limits = $server->effective_limits();
		$this->assertSame( 5, $limits['requests_per_minute'] );
		$this->assertSame( 100, $limits['max_payload_bytes'] );
		$this->assertSame( 3, $limits['max_iterations'] );
	}

	/** Test filter can override effective limits.
	 */
	public function test_filter_can_override_effective_limits() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		add_filter(
			'wp_mcp_ai_toolkit_mcp_server_limits',
			static function ( $limits, $slug ) {
				if ( 'crm' === $slug ) {
					$limits['requests_per_minute'] = 99;
				}
				return $limits;
			},
			10,
			2
		);
		$limits = $server->effective_limits();
		$this->assertSame( 99, $limits['requests_per_minute'] );
	}

	/** Test negative input is clamped to zero.
	 */
	public function test_negative_input_is_clamped_to_zero() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'             => true,
				'requests_per_minute' => -10,
				'max_payload_bytes'   => -1,
				'max_iterations'      => -5,
			)
		);
		$limits = $server->effective_limits();
		$this->assertSame( 0, $limits['requests_per_minute'] );
		$this->assertSame( 0, $limits['max_payload_bytes'] );
		$this->assertSame( 0, $limits['max_iterations'] );
	}

	/** Test payload size limit rejects oversized requests.
	 */
	public function test_payload_size_limit_rejects_oversized_requests() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'           => true,
				'max_payload_bytes' => 50,
			)
		);

		$body    = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array( 'padding' => str_repeat( 'x', 200 ) ),
			)
		);
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32098, $data['error']['code'] );
		$this->assertSame( 50, $data['error']['data']['max_payload_bytes'] );
	}

	/** Test rate limit blocks after threshold.
	 */
	public function test_rate_limit_blocks_after_threshold() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'             => true,
				'requests_per_minute' => 2,
			)
		);

		$dispatch = static function () {
			$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					)
				)
			);
			return rest_get_server()->dispatch( $request )->get_data();
		};

		$first  = $dispatch();
		$second = $dispatch();
		$third  = $dispatch();

		$this->assertArrayHasKey( 'result', $first );
		$this->assertArrayHasKey( 'result', $second );
		$this->assertArrayHasKey( 'error', $third );
		$this->assertSame( -32099, $third['error']['code'] );
	}

	/** Test initialize and ping bypass limits.
	 */
	public function test_initialize_and_ping_bypass_limits() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		// Set a tiny payload limit so any real method would fail.
		$server->update_configuration(
			array(
				'enabled'           => true,
				'max_payload_bytes' => 1,
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/crm' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'ping',
				)
			)
		);

		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertArrayHasKey( 'result', $data, 'ping should bypass payload-size guard.' );
	}

	/** Test descriptor exposes effective limits.
	 */
	public function test_descriptor_exposes_effective_limits() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'             => true,
				'requests_per_minute' => 7,
				'max_payload_bytes'   => 4096,
				'max_iterations'      => 4,
			)
		);
		$descriptor = $server->get_descriptor();
		$this->assertArrayHasKey( 'limits', $descriptor );
		$this->assertSame( 7, $descriptor['limits']['requests_per_minute'] );
		$this->assertSame( 4096, $descriptor['limits']['max_payload_bytes'] );
		$this->assertSame( 4, $descriptor['limits']['max_iterations'] );
	}
}
