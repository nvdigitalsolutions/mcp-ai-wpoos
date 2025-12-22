<?php
/**
 * Tests for the OpenAI Batch API client methods and tools.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-batch.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-batch-status.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-batches.php';

/**
 * Test class for WP_MCP_AI OpenAI Batch API.
 */
class WP_MCP_AI_Batch_API_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that Batch API endpoint constant is defined.
	 */
	public function test_batches_endpoint_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_OpenAI_Client::BATCHES_ENDPOINT' ) );
		$this->assertSame( 'https://api.openai.com/v1/batches', WP_MCP_AI_OpenAI_Client::BATCHES_ENDPOINT );
	}

	/**
	 * Test create_batch method requires API key.
	 */
	public function test_create_batch_requires_api_key() {
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch( 'file-test123', '/v1/chat/completions' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_batch validates input_file_id.
	 */
	public function test_create_batch_validates_input_file_id() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch( '', '/v1/chat/completions' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input_file_id', $result->get_error_code() );
	}

	/**
	 * Test create_batch validates endpoint.
	 */
	public function test_create_batch_validates_endpoint() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch( 'file-test123', '/v1/invalid' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_batch_endpoint', $result->get_error_code() );
	}

	/**
	 * Test successful batch creation.
	 */
	public function test_create_batch_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'id'                => 'batch_test123',
				'object'            => 'batch',
				'endpoint'          => '/v1/chat/completions',
				'input_file_id'     => 'file-test456',
				'completion_window' => '24h',
				'status'            => 'validating',
				'created_at'        => time(),
				'metadata'          => array(
					'project' => 'test-project',
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch(
			'file-test456',
			'/v1/chat/completions',
			array(
				'metadata' => array( 'project' => 'test-project' ),
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'batch_test123', $result['id'] );
		$this->assertSame( 'validating', $result['status'] );

		// Verify request was made to correct endpoint.
		$this->assertNotNull( $captured_request );
		$this->assertSame( 'https://api.openai.com/v1/batches', $captured_request['url'] );
		$this->assertSame( 'POST', $captured_request['args']['method'] );
	}

	/**
	 * Test retrieve_batch method.
	 */
	public function test_retrieve_batch_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'id'              => 'batch_test123',
				'object'          => 'batch',
				'endpoint'        => '/v1/chat/completions',
				'status'          => 'completed',
				'output_file_id'  => 'file-output789',
				'created_at'      => time() - 3600,
				'completed_at'    => time(),
				'request_counts'  => array(
					'total'     => 100,
					'completed' => 100,
					'failed'    => 0,
				),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->retrieve_batch( 'batch_test123' );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertSame( 'batch_test123', $result['id'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertArrayHasKey( 'output_file_id', $result );
	}

	/**
	 * Test cancel_batch method.
	 */
	public function test_cancel_batch_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'id'     => 'batch_test123',
				'status' => 'cancelling',
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->cancel_batch( 'batch_test123' );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertSame( 'cancelling', $result['status'] );

		// Verify correct endpoint was called.
		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( '/cancel', $captured_request['url'] );
		$this->assertSame( 'POST', $captured_request['args']['method'] );
	}

	/**
	 * Test list_batches method.
	 */
	public function test_list_batches_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'object'   => 'list',
				'data'     => array(
					array(
						'id'         => 'batch_test1',
						'status'     => 'completed',
						'created_at' => time() - 7200,
					),
					array(
						'id'         => 'batch_test2',
						'status'     => 'in_progress',
						'created_at' => time() - 3600,
					),
				),
				'first_id' => 'batch_test1',
				'last_id'  => 'batch_test2',
				'has_more' => false,
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_batches( array( 'limit' => 20 ) );

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 2, $result['data'] );
		$this->assertFalse( $result['has_more'] );
	}

	/**
	 * Test create_batch tool requires admin permissions.
	 */
	public function test_create_batch_tool_requires_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Batch();
		$result = $tool->execute(
			array(
				'input_file_id' => 'file-test123',
				'endpoint'      => '/v1/chat/completions',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test create_batch tool success.
	 */
	public function test_create_batch_tool_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'id'            => 'batch_test123',
				'status'        => 'validating',
				'endpoint'      => '/v1/chat/completions',
				'created_at'    => time(),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Create_Batch();
		$result = $tool->execute(
			array(
				'input_file_id' => 'file-test123',
				'endpoint'      => '/v1/chat/completions',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'batch_id', $result );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test get_batch_status tool.
	 */
	public function test_get_batch_status_tool_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'id'              => 'batch_test123',
				'status'          => 'completed',
				'output_file_id'  => 'file-output789',
				'created_at'      => time() - 3600,
				'completed_at'    => time(),
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Get_Batch_Status();
		$result = $tool->execute(
			array( 'batch_id' => 'batch_test123' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test list_batches tool.
	 */
	public function test_list_batches_tool_success() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$http_stub = function ( $preempt, $args, $url ) {
			$payload = array(
				'object'   => 'list',
				'data'     => array(
					array(
						'id'         => 'batch_test1',
						'status'     => 'completed',
						'created_at' => time() - 7200,
					),
					array(
						'id'         => 'batch_test2',
						'status'     => 'in_progress',
						'created_at' => time() - 3600,
					),
				),
				'has_more' => false,
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_List_Batches();
		$result = $tool->execute(
			array( 'limit' => 20 ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'batches', $result );
		$this->assertCount( 2, $result['batches'] );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test tool slugs are correct.
	 */
	public function test_tool_slugs() {
		$create_tool = new WP_MCP_AI_Tool_Create_Batch();
		$this->assertSame( 'create_batch', $create_tool->get_slug() );

		$status_tool = new WP_MCP_AI_Tool_Get_Batch_Status();
		$this->assertSame( 'get_batch_status', $status_tool->get_slug() );

		$list_tool = new WP_MCP_AI_Tool_List_Batches();
		$this->assertSame( 'list_batches', $list_tool->get_slug() );
	}

	/**
	 * Test tools have proper capability flags.
	 */
	public function test_tool_capability_flags() {
		$create_tool = new WP_MCP_AI_Tool_Create_Batch();
		$flags       = $create_tool->get_capability_flags();
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'consumes-tokens', $flags );

		$status_tool = new WP_MCP_AI_Tool_Get_Batch_Status();
		$flags       = $status_tool->get_capability_flags();
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );

		$list_tool = new WP_MCP_AI_Tool_List_Batches();
		$flags     = $list_tool->get_capability_flags();
		$this->assertContains( 'paginated', $flags );
		$this->assertContains( 'read-only', $flags );
	}
}
