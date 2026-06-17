<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Test_Toolkit_Server_Execution
 *
 * Phase 3a — covers `tools/call`, `resources/read`, `prompts/get` on the
 * per-toolkit MCP REST controller.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/**
 * Tiny no-op tool used to verify the JSON-RPC dispatch path without needing
 * any of the Pro toolkits' real dependencies.
 */
if ( ! class_exists( 'WP_MCP_AI_Toolkit_MCP_Test_Echo_Tool' ) ) {
	/**
	 * Test echo tool for toolkit server execution tests.
	 */
	class WP_MCP_AI_Toolkit_MCP_Test_Echo_Tool implements WP_MCP_AI_Tool_Interface {
		use WP_MCP_AI_Tool_Default_Capability;

		/**
		 * Get slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'toolkit_mcp_test_echo';
		}
		/**
		 * Get name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'Echo';
		}
		/**
		 * Get description.
		 *
		 * @return string
		 */
		public function get_description() {
			return 'Echo test tool';
		}
		/**
		 * Get parameters schema.
		 *
		 * @return array
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'msg' => array( 'type' => 'string' ),
				),
			);
		}
		/**
		 * Execute tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			return array(
				'echo'    => isset( $arguments['msg'] ) ? (string) $arguments['msg'] : '',
				'context' => isset( $context['toolkit_mcp_server'] ) ? (string) $context['toolkit_mcp_server'] : '',
			);
		}
	}
}

/**
 * Summary.
 *
 * @phpcs:ignore Universal.Files.OneObjectStructurePerFile.MultipleFound
 *
 * @group toolkit-mcp-servers
 */
class Test_Toolkit_Server_Execution extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	/** Set up test. */
	public function set_up() {
		parent::set_up();

		// Register the echo tool against the core registry.
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Toolkit_MCP_Test_Echo_Tool() );

		// Expose the echo tool through the CRM server's candidate list.
		add_filter(
			'wp_mcp_ai_toolkit_mcp_server_crm_candidate_tools',
			static function ( $slugs ) {
				$slugs[] = 'toolkit_mcp_test_echo';
				return $slugs;
			}
		);

		// Reset any persisted server config.
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );

		// Ensure registry is primed with CRM (manual register — init may have already fired).
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );

		// Ensure REST routes are registered for this test process.
		WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance()->init();
		do_action( 'rest_api_init' );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/** Tear down test. */
	public function tear_down() {
		WP_MCP_AI_Tool_Registry::get_instance()->unregister_tool( 'toolkit_mcp_test_echo' );
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		parent::tear_down();
	}

	/**
	 * Helper — issue a JSON-RPC POST and return the response body.
	 *
	 * @param string $slug   Server slug.
	 * @param array  $body   JSON-RPC payload.
	 * @return array
	 */
	private function rpc( $slug, $body ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/mcp/' . $slug );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		return $response->get_data();
	}

	/** Test tools call routes through registry.
	 */
	public function test_tools_call_routes_through_registry() {
		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'toolkit_mcp_test_echo',
					'arguments' => array( 'msg' => 'hello' ),
				),
			)
		);
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( 1, $data['id'] );
		$this->assertArrayHasKey( 'result', $data, 'Expected success result, got: ' . wp_json_encode( $data ) );
		$this->assertSame( false, $data['result']['isError'] );
		$this->assertSame( 'text', $data['result']['content'][0]['type'] );
		$payload = json_decode( $data['result']['content'][0]['text'], true );
		$this->assertSame( 'hello', $payload['echo'] );
		$this->assertSame( 'crm', $payload['context'], 'Server slug should be threaded through execution context.' );
	}

	/** Test tools call rejects tool outside allowlist.
	 */
	public function test_tools_call_rejects_tool_outside_allowlist() {
		// Set an allowlist that excludes the echo tool.
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration(
			array(
				'enabled'         => true,
				'tools_allowlist' => array( 'crm_search_contacts' ),
			)
		);

		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'toolkit_mcp_test_echo',
					'arguments' => array(),
				),
			)
		);

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'] );
		$this->assertSame( 'toolkit_mcp_test_echo', $data['error']['data']['tool'] );
	}

	/** Test tools call missing name returns invalid params.
	 */
	public function test_tools_call_missing_name_returns_invalid_params() {
		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array( 'arguments' => array( 'msg' => 'x' ) ),
			)
		);
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
	}

	/** Test tools call disabled server rejects method.
	 */
	public function test_tools_call_disabled_server_rejects_method() {
		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$server->update_configuration( array( 'enabled' => false ) );

		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'toolkit_mcp_test_echo',
					'arguments' => array(),
				),
			)
		);
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32601, $data['error']['code'] );
	}

	/** Test resources read returns descriptor for native uri.
	 */
	public function test_resources_read_returns_descriptor_for_native_uri() {
		$server  = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$entries = $server->get_resources();
		$this->assertNotEmpty( $entries );
		$uri = $entries[0]['uri'];

		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => $uri ),
			)
		);
		$this->assertArrayHasKey( 'result', $data, wp_json_encode( $data ) );
		$this->assertSame( $uri, $data['result']['contents'][0]['uri'] );
		$body = json_decode( $data['result']['contents'][0]['text'], true );
		$this->assertSame( 'crm', $body['server'] );
		$this->assertFalse( $body['mounted'] );
	}

	/** Test resources read unknown uri errors.
	 */
	public function test_resources_read_unknown_uri_errors() {
		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'nvoos://crm/nonexistent' ),
			)
		);
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
	}

	/** Test prompts get returns messages.
	 */
	public function test_prompts_get_returns_messages() {
		$server  = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'crm' );
		$prompts = $server->get_prompts();
		$this->assertNotEmpty( $prompts );
		$name = $prompts[0]['name'];

		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => $name ),
			)
		);
		$this->assertArrayHasKey( 'result', $data, wp_json_encode( $data ) );
		$this->assertNotEmpty( $data['result']['messages'] );
		$this->assertSame( 'user', $data['result']['messages'][0]['role'] );
	}

	/** Test prompts get unknown name errors.
	 */
	public function test_prompts_get_unknown_name_errors() {
		$data = $this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 8,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => 'crm.no_such_prompt' ),
			)
		);
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
	}

	/** Test before and after call hooks fire.
	 */
	public function test_before_and_after_call_hooks_fire() {
		$before = 0;
		$after  = 0;
		add_action(
			'wp_mcp_ai_toolkit_mcp_before_call',
			static function ( $slug, $args, $server ) use ( &$before ) {
				if ( 'toolkit_mcp_test_echo' === $slug && $server instanceof WP_MCP_AI_Toolkit_Server_Interface ) {
					$before++;
				}
			},
			10,
			3
		);
		add_action(
			'wp_mcp_ai_toolkit_mcp_after_call',
			static function ( $slug, $args, $result, $server ) use ( &$after ) {
				if ( 'toolkit_mcp_test_echo' === $slug && $server instanceof WP_MCP_AI_Toolkit_Server_Interface ) {
					$after++;
				}
			},
			10,
			4
		);

		$this->rpc(
			'crm',
			array(
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'toolkit_mcp_test_echo',
					'arguments' => array( 'msg' => 'hi' ),
				),
			)
		);
		$this->assertSame( 1, $before );
		$this->assertSame( 1, $after );
	}

	/** Test mounted uri is marked read only.
	 */
	public function test_mounted_uri_is_marked_read_only() {
		// Architectural Design mounts the Healthcare consolidate page.
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$registry->register( new WP_MCP_AI_Healthcare_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architectural_Design_MCP_Server() );

		$server      = $registry->get( 'architectural-design' );
		$entries     = $server->get_resources();
		$mounted_uri = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['annotations']['readOnly'] ) && true === $entry['annotations']['readOnly'] ) {
				$mounted_uri = $entry['uri'];
				break;
			}
		}
		$this->assertNotNull( $mounted_uri, 'Architectural Design should mount at least one read-only resource from Healthcare.' );

		$data = $this->rpc(
			'architectural-design',
			array(
				'jsonrpc' => '2.0',
				'id'      => 10,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => $mounted_uri ),
			)
		);
		$this->assertArrayHasKey( 'result', $data, wp_json_encode( $data ) );
		$body = json_decode( $data['result']['contents'][0]['text'], true );
		$this->assertTrue( $body['mounted'] );
		$this->assertTrue( $body['read_only'] );
	}
}
