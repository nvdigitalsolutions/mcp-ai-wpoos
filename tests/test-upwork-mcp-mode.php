<?php
/**
 * Tests for Upwork freelance-marketplace connections in MCP mode.
 *
 * Covers:
 * - save_connection(): 'mcp' mode acceptance, default MCP endpoint,
 *   org_uid persistence, and the absence of an API-credential requirement.
 * - update_mcp_oauth(): persistence + rejection for non-MCP connections.
 * - Search Upwork Jobs: MCP gateway search with job normalization,
 *   org_uid discovery via list_accounts, and web-search fallback when no
 *   MCP token blob exists.
 * - Import Upwork Project: MCP detail fetch flowing into a CRM deal with
 *   external-source meta.
 * - MCP Apps OAuth REST flow: connection_ref initiation + persistence to
 *   the central Remote Sites store.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-deal-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-engine.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-upwork-mcp-bridge.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/upwork/class-wp-mcp-ai-tool-import-upwork-project.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php';

/**
 * Test suite for the Upwork MCP connection mode.
 */
class Test_Upwork_MCP_Mode extends WP_UnitTestCase {

	/**
	 * Requests captured by the HTTP mock.
	 *
	 * @var array<int, array>
	 */
	protected $captured = array();

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 1 );

		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Clean up after each test run.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create an Upwork connection in MCP mode.
	 *
	 * @param bool $with_oauth  Whether to store an MCP OAuth token blob.
	 * @param bool $with_org_uid Whether to store an org_uid.
	 * @return string Connection ID.
	 */
	protected function create_mcp_connection( $with_oauth = true, $with_org_uid = true ) {
		$data = array(
			'name'            => 'Upwork MCP Test',
			'url'             => 'https://mcp.upwork.com/mcp',
			'connection_type' => 'upwork',
			'auth_type'       => 'none',
			'enabled'         => true,
			'upwork_mode'     => 'mcp',
		);

		if ( $with_oauth ) {
			$data['mcp_oauth'] = wp_json_encode(
				array(
					'access_token'  => 'tok-live',
					'refresh_token' => 'refresh-live',
					'token_type'    => 'Bearer',
					'expires_in'    => 3600,
					'issued_at'     => time(),
					'client_id'     => 'dyn-client-123',
				)
			);
		}

		if ( $with_org_uid ) {
			$data['upwork_org_uid'] = 'org-123456';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
	}

	/**
	 * Install a pre_http_request mock for the Upwork MCP gateway.
	 *
	 * Answers the sessionful initialize handshake and dispatches tools/call
	 * by tool name + action.
	 *
	 * @return void
	 */
	protected function install_mcp_mock() {
		$captured =& $this->captured;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre, $url );
				$captured[] = $args;

				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';
				$params  = is_array( $payload ) && isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();

				$rpc = function ( $result, $headers = array() ) {
					return array(
						'headers'  => $headers,
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'result'  => $result,
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				};

				$tool = function ( $payload_data ) use ( $rpc ) {
					return $rpc(
						array(
							'content' => array(
								array(
									'type' => 'text',
									'text' => wp_json_encode( $payload_data ),
								),
							),
						)
					);
				};

				if ( 'initialize' === $method ) {
					return $rpc(
						array(
							'protocolVersion' => '2025-06-18',
							'serverInfo'      => array(
								'name'    => 'envoy-ai-gateway',
								'version' => 'v1.1.0',
							),
							'capabilities'    => array( 'tools' => new stdClass() ),
						),
						array( 'mcp-session-id' => 'sess-upwork-test' )
					);
				}

				if ( 'tools/call' === $method ) {
					$tool_name = isset( $params['name'] ) ? $params['name'] : '';
					$tool_args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
					$action    = isset( $tool_args['action'] ) ? $tool_args['action'] : '';

					if ( 'upwork__list_accounts' === $tool_name ) {
						return $tool(
							array(
								'accounts' => array(
									array(
										'org_uid' => 'org-987654',
										'type'    => 'freelancer',
									),
								),
							)
						);
					}

					if ( 'upwork__find_jobs' === $tool_name ) {
						if ( 'get' === $action ) {
							return $tool(
								array(
									'id'          => '0123456789abcdef',
									'title'       => 'WordPress Developer',
									'description' => 'Full job description for the CRM.',
									'job_type'    => 'hourly',
									'budget'      => array(
										'amount'   => 2500,
										'currency' => 'USD',
									),
									'skills'      => array( array( 'prettyName' => 'WordPress' ) ),
									'client'      => array(
										'total_hires' => 30,
										'total_spent' => 50000,
										'country'     => 'United States',
									),
								)
							);
						}

						return $tool(
							array(
								'jobs'     => array(
									array(
										'id'             => '0123456789abcdef',
										'title'          => 'WordPress Developer',
										'description'    => 'Build an Elementor site for a client.',
										'job_type'       => 'hourly',
										'budget'         => array(
											'amount'   => 2500,
											'currency' => 'USD',
										),
										'skills'         => array(
											array( 'prettyName' => 'WordPress' ),
											array( 'prettyName' => 'PHP' ),
										),
										'proposal_count' => 12,
										'created_date'   => '2026-09-20T10:00:00Z',
										'client'         => array(
											'rating'      => 4.5,
											'total_hires' => 30,
											'total_spent' => 50000,
											'country'     => 'United States',
										),
									),
								),
								'pageInfo' => array( 'hasNextPage' => false ),
							)
						);
					}
				}

				return $rpc( new stdClass() );
			},
			10,
			3
		);
	}

	/**
	 * Find a captured tools/call request for a tool name.
	 *
	 * @param string $tool_name Tool name to find.
	 * @return array|null Request args or null.
	 */
	protected function find_tool_call( $tool_name ) {
		foreach ( $this->captured as $args ) {
			$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
			if ( is_array( $payload ) && isset( $payload['method'] ) && 'tools/call' === $payload['method'] ) {
				$params = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();
				if ( isset( $params['name'] ) && $tool_name === $params['name'] ) {
					return $params;
				}
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Connection storage & validation
	// -------------------------------------------------------------------------

	/**
	 * MCP mode saves with the new fields and defaults the MCP endpoint.
	 */
	public function test_save_connection_accepts_mcp_mode_and_fields() {
		$id = $this->create_mcp_connection( true, true );
		$this->assertIsString( $id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'mcp', $connection['upwork_mode'] );
		$this->assertSame( 'https://mcp.upwork.com/mcp', $connection['upwork_mcp_url'] );
		$this->assertSame( 'org-123456', $connection['upwork_org_uid'] );
		$this->assertNotEmpty( $connection['mcp_oauth'] );

		// The stored OAuth blob is encrypted at rest.
		$this->assertNotSame( 'tok-live', $connection['mcp_oauth'] );
	}

	/**
	 * Omitting the MCP URL falls back to the official gateway default.
	 */
	public function test_save_connection_defaults_mcp_url() {
		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork MCP Default URL',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'auth_type'       => 'none',
				'enabled'         => true,
				'upwork_mode'     => 'mcp',
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'https://mcp.upwork.com/mcp', $connection['upwork_mcp_url'] );
	}

	/**
	 * MCP mode does not require GraphQL API credentials to save.
	 */
	public function test_mcp_mode_saves_without_api_credentials() {
		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork MCP No API Creds',
				'url'             => 'https://mcp.upwork.com/mcp',
				'connection_type' => 'upwork',
				'auth_type'       => 'none',
				'enabled'         => true,
				'upwork_mode'     => 'mcp',
			)
		);

		$this->assertIsString( $id );
	}

	/**
	 * Update_mcp_oauth persists token refreshes onto Upwork MCP-mode
	 * connections and rejects other connection types.
	 */
	public function test_update_mcp_oauth_persists_for_mcp_mode() {
		$id      = $this->create_mcp_connection( true, true );
		$updated = WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$id,
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'refresh-new',
				'expires_in'    => 7200,
			)
		);

		$this->assertTrue( $updated );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$blob       = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertSame( 'tok-new', $blob['access_token'] );
		$this->assertSame( 'refresh-new', $blob['refresh_token'] );

		// An API-mode Upwork connection never accepts an mcp_oauth write.
		$api_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork API Mode',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'auth_type'       => 'none',
				'enabled'         => true,
				'upwork_mode'     => 'api',
				'client_id'       => 'api-client',
				'client_secret'   => 'api-secret',
			)
		);
		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
				$api_id,
				array( 'access_token' => 'should-not-persist' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Search tool — MCP mode
	// -------------------------------------------------------------------------

	/**
	 * Search in MCP mode calls the gateway and returns normalized jobs.
	 */
	public function test_search_uses_mcp_mode_and_normalizes_jobs() {
		$this->install_mcp_mock();
		$id   = $this->create_mcp_connection( true, true );
		$tool = new WP_MCP_AI_Tool_Search_Upwork_Jobs();

		$result = $tool->execute(
			array(
				'connection_id' => $id,
				'query'         => 'WordPress developer',
				'limit'         => 5,
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'mcp', $result['mode'] );
		$this->assertCount( 1, $result['jobs'] );

		$job = $result['jobs'][0];
		$this->assertSame( '0123456789abcdef', $job['id'] );
		$this->assertSame( 'WordPress Developer', $job['title'] );
		$this->assertSame( 2500.0, $job['budget'] );
		$this->assertContains( 'WordPress', $job['skills'] );
		$this->assertSame( 12, $job['applicants'] );
		$this->assertSame( 30, $job['client']['total_hires'] );
		$this->assertSame( 'United States', $job['client']['country'] );
		$this->assertStringContainsString( 'upwork.com/jobs/', $job['url'] );

		// The gateway search call carries the stored org_uid and mapped params.
		$call = $this->find_tool_call( 'upwork__find_jobs' );
		$this->assertNotNull( $call );
		$this->assertSame( 'org-123456', $call['arguments']['org_uid'] );
		$this->assertSame( 'search', $call['arguments']['action'] );
		$this->assertSame( 'WordPress developer', $call['arguments']['params']['query'] );
		$this->assertSame( 5, $call['arguments']['params']['limit'] );
	}

	/**
	 * Without a stored org_uid the search resolves one via list_accounts.
	 */
	public function test_search_resolves_org_uid_via_list_accounts() {
		$this->install_mcp_mock();
		$id   = $this->create_mcp_connection( true, false );
		$tool = new WP_MCP_AI_Tool_Search_Upwork_Jobs();

		$result = $tool->execute(
			array(
				'connection_id' => $id,
				'query'         => 'PHP developer',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'mcp', $result['mode'] );

		// list_accounts was consulted to resolve the org_uid.
		$this->assertNotNull( $this->find_tool_call( 'upwork__list_accounts' ) );

		$call = $this->find_tool_call( 'upwork__find_jobs' );
		$this->assertNotNull( $call );
		$this->assertSame( 'org-987654', $call['arguments']['org_uid'] );
	}

	/**
	 * An MCP-mode connection without a token blob falls back to web search.
	 */
	public function test_search_falls_back_without_tokens() {
		$this->install_mcp_mock();
		$id   = $this->create_mcp_connection( false, true );
		$tool = new WP_MCP_AI_Tool_Search_Upwork_Jobs();

		$result = $tool->execute(
			array(
				'connection_id' => $id,
				'query'         => 'WordPress developer',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'fallback', $result['mode'] );
	}

	// -------------------------------------------------------------------------
	// Import tool — MCP mode
	// -------------------------------------------------------------------------

	/**
	 * Importing in MCP mode fetches gateway details and creates a CRM deal
	 * with external-source meta for deduplication.
	 */
	public function test_import_creates_crm_entity_via_mcp() {
		$this->install_mcp_mock();
		$id   = $this->create_mcp_connection( true, true );
		$tool = new WP_MCP_AI_Tool_Import_Upwork_Project();

		$result = $tool->execute(
			array(
				'job_id'        => '0123456789abcdef',
				'connection_id' => $id,
				'save_as'       => 'deal',
			),
			array( 'user_id' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['api_fetched'] );

		$post_id = $result['entity_id'];
		$this->assertIsInt( $post_id );
		$this->assertSame( 'upwork', get_post_meta( $post_id, '_external_source_platform', true ) );
		$this->assertSame( '0123456789abcdef', get_post_meta( $post_id, '_external_source_id', true ) );
		$this->assertStringContainsString( 'Full job description', get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 2500.0, (float) get_post_meta( $post_id, 'deal_amount', true ) );

		// The gateway detail call used action=get with the job ID.
		$call = $this->find_tool_call( 'upwork__find_jobs' );
		$this->assertNotNull( $call );
		$this->assertSame( 'get', $call['arguments']['action'] );
		$this->assertSame( '0123456789abcdef', $call['arguments']['params']['id'] );
	}

	// -------------------------------------------------------------------------
	// OAuth flow — connection_ref
	// -------------------------------------------------------------------------

	/**
	 * Install a pre_http_request mock for the MCP OAuth discovery + DCR flow.
	 *
	 * @return void
	 */
	protected function install_oauth_mock() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $pre, $args );

				if ( false !== strpos( $url, '/.well-known/oauth-authorization-server' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'issuer'                 => 'https://mcp.upwork.com',
								'authorization_endpoint' => 'https://www.upwork.com/ab/account-security/oauth2/authorize',
								'token_endpoint'         => 'https://www.upwork.com/ab/account-security/oauth2/token',
								'registration_endpoint'  => 'https://www.upwork.com/ab/account-security/oauth2/register',
								'scopes_supported'       => array( 'jobs:read' ),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/oauth2/register' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'client_id'   => 'dyn-client-123',
								'client_name' => 'Test Client',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( new stdClass() ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * The OAuth initiation accepts a connection_ref and the flow persists
	 * tokens into the Upwork connection's encrypted central store.
	 */
	public function test_oauth_flow_persists_tokens_to_connection_ref() {
		$this->install_oauth_mock();
		$id = $this->create_mcp_connection( false, true );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/oauth/init' );
		$request->set_param( 'server_url', 'https://mcp.upwork.com/mcp' );
		$request->set_param( 'connection_ref', $id );

		$response = $controller->initiate_oauth( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['state'] );
		$this->assertNotEmpty( $data['authorization_url'] );

		// The flow state carries the connection_ref so the completion step
		// knows where to persist the exchanged tokens.
		$flow_state = get_transient( WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $data['state'] );
		$this->assertIsArray( $flow_state );
		$this->assertSame( $id, $flow_state['connection_ref'] );

		// Simulate the completion persistence step.
		$reflection = new ReflectionMethod( $controller, 'finalize_oauth_flow' );
		$reflection->setAccessible( true );
		$reflection->invokeArgs(
			$controller,
			array(
				$flow_state,
				array(
					'access_token'  => 'tok-final',
					'refresh_token' => 'refresh-final',
					'token_type'    => 'Bearer',
					'expires_in'    => 3600,
				),
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$blob       = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertSame( 'tok-final', $blob['access_token'] );
		$this->assertSame( 'refresh-final', $blob['refresh_token'] );
		$this->assertSame( 'dyn-client-123', $blob['client_id'] );
	}
}
