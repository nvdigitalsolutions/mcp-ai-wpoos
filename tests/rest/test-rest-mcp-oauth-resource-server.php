<?php
/**
 * Tests for the MCP OAuth resource-server contract (ChatGPT plugin bridge).
 *
 * Covers the RFC 9728 protected-resource metadata document, WWW-Authenticate
 * challenge builders, per-tool securitySchemes and profile-tool metadata in
 * tools/list, the OpenAI domain-verification challenge, and the well-known
 * endpoint classes.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_MCP_OAuth_Resource_Server_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Bearer credential for MCP requests.
	 *
	 * @var string
	 */
	protected $bearer_token;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'OAuth Test Assistant',
			)
		);

		// Allow a read-only tool, a write tool, and the profile tool.
		update_post_meta(
			$this->assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOLS,
			array( 'nvoos_get_profile', 'get_user_info', 'save_post' )
		);

		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, 'OAuth Test Client' );
			if ( $credential && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}

		$this->bootstrap_rest_controller();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_OAuth_Resource_Server::OPTION_CHALLENGE_TOKEN );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$this->rest_controller                = new WP_MCP_AI_REST( $registry, $mock_client );
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Configure Auth0 in the plugin settings.
	 *
	 * @param bool $configured Whether to set an Auth0 domain.
	 */
	protected function set_auth0_configuration( $configured ) {
		$settings                  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['auth0_domain']  = $configured ? 'test-tenant.us.auth0.com' : '';
		$settings['auth0_audience'] = $configured ? rest_url( 'mcp-ai/v1/mcp' ) : '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Send a tools/list JSON-RPC message against the MCP endpoint.
	 *
	 * @return WP_REST_Response
	 */
	protected function send_tools_list() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}

		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array(),
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The RFC 9728 document advertises resource, servers, and scopes.
	 */
	public function test_protected_resource_document_shape_with_auth0() {
		$this->set_auth0_configuration( true );

		$document = WP_MCP_AI_OAuth_Resource_Server::build_protected_resource_document();

		$this->assertSame( rest_url( 'mcp-ai/v1/mcp' ), $document['resource'] );
		$this->assertContains( 'https://test-tenant.us.auth0.com/', $document['authorization_servers'] );
		$this->assertContains( 'site:read', $document['scopes_supported'] );
		$this->assertArrayHasKey( 'resource_documentation', $document );
	}

	/**
	 * Without Auth0 configured, no authorization servers are advertised.
	 */
	public function test_protected_resource_document_without_auth0() {
		$this->set_auth0_configuration( false );

		$document = WP_MCP_AI_OAuth_Resource_Server::build_protected_resource_document();

		$this->assertSame( array(), $document['authorization_servers'] );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::is_configured() );
	}

	/**
	 * The WWW-Authenticate challenge stays silent until Auth0 is configured.
	 */
	public function test_www_authenticate_only_when_configured() {
		$this->set_auth0_configuration( false );
		$this->assertSame( '', WP_MCP_AI_OAuth_Resource_Server::build_www_authenticate() );

		$this->set_auth0_configuration( true );
		$challenge = WP_MCP_AI_OAuth_Resource_Server::build_www_authenticate();
		$this->assertStringStartsWith( 'Bearer resource_metadata="', $challenge );
		$this->assertStringContainsString( home_url( '/.well-known/oauth-protected-resource' ), $challenge );
		$this->assertStringContainsString( 'site:read', $challenge );
	}

	/**
	 * Tool-level challenges carry the metadata URL and an error code.
	 */
	public function test_tool_auth_challenge_shape() {
		$this->set_auth0_configuration( true );

		$challenge = WP_MCP_AI_OAuth_Resource_Server::build_tool_auth_challenge( 'insufficient_scope' );

		$this->assertIsArray( $challenge );
		$this->assertCount( 1, $challenge );
		$this->assertStringContainsString( 'resource_metadata=', $challenge[0] );
		$this->assertStringContainsString( 'error="insufficient_scope"', $challenge[0] );
		$this->assertStringContainsString( 'error_description=', $challenge[0] );
	}

	/**
	 * Audience acceptance matches the MCP resource identifier.
	 */
	public function test_audience_matches_resource() {
		$resource = WP_MCP_AI_OAuth_Resource_Server::get_resource_identifier();

		$this->assertTrue( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( $resource ) );
		$this->assertTrue( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( array( 'https://other.example/', $resource ) ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( 'https://other.example/' ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( '' ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::audience_matches_resource( array( 'https://other.example/' ) ) );
	}

	/**
	 * The profile output schema enforces the OpenAI contract.
	 */
	public function test_profile_output_schema_contract() {
		$schema = WP_MCP_AI_OAuth_Resource_Server::get_profile_output_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'id' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayHasKey( 'id', $schema['properties'] );
		$this->assertSame( 1, $schema['properties']['id']['minLength'] );
	}

	/**
	 * Auth error codes are recognised for challenge attachment.
	 */
	public function test_is_auth_error_code() {
		$this->assertTrue( WP_MCP_AI_OAuth_Resource_Server::is_auth_error_code( 'wp_mcp_ai_invalid_bearer_token' ) );
		$this->assertTrue( WP_MCP_AI_OAuth_Resource_Server::is_auth_error_code( 'wp_mcp_ai_mcp_auth_required' ) );
		$this->assertFalse( WP_MCP_AI_OAuth_Resource_Server::is_auth_error_code( 'wp_mcp_ai_invalid_params' ) );
	}

	/**
	 * tools/list advertises per-tool security schemes when Auth0 is configured.
	 */
	public function test_tools_list_advertises_security_schemes() {
		$this->set_auth0_configuration( true );

		$response = $this->send_tools_list();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertNotEmpty( $data['result']['tools'] );

		$by_name = array();
		foreach ( $data['result']['tools'] as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		// Every advertised tool carries an oauth2 scheme.
		foreach ( $by_name as $tool ) {
			$this->assertArrayHasKey( 'securitySchemes', $tool );
			$this->assertSame( 'oauth2', $tool['securitySchemes'][0]['type'] );
		}

		// Read-only tool → site:read only; write-flagged tool adds content:write.
		// (get_user_info resolves to the registry's validated twin, whose flags
		// lack read-only — so it exercises the write-scope branch.)
		$this->assertArrayHasKey( 'nvoos_get_profile', $by_name );
		$this->assertSame( array( 'site:read' ), $by_name['nvoos_get_profile']['securitySchemes'][0]['scopes'] );
		$this->assertArrayHasKey( 'get_user_info', $by_name );
		$this->assertContains( 'content:write', $by_name['get_user_info']['securitySchemes'][0]['scopes'] );

		// Profile tool carries the OpenAI profile marker + output schema.
		$this->assertArrayHasKey( 'nvoos_get_profile', $by_name );
		$this->assertTrue( $by_name['nvoos_get_profile']['_meta']['openai/profile'] );
		$this->assertSame( array( 'id' ), $by_name['nvoos_get_profile']['outputSchema']['required'] );
	}

	/**
	 * Without Auth0, tools/list keeps its legacy shape (no securitySchemes).
	 */
	public function test_tools_list_omits_security_schemes_without_auth0() {
		$this->set_auth0_configuration( false );

		$response = $this->send_tools_list();
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['result']['tools'] );
		foreach ( $data['result']['tools'] as $tool ) {
			$this->assertArrayNotHasKey( 'securitySchemes', $tool );
			$this->assertArrayNotHasKey( '_meta', $tool );
		}
	}

	/**
	 * The well-known classes register query vars and guard canonical redirects.
	 */
	public function test_well_known_classes_register_and_guard() {
		WP_MCP_AI_Well_Known_OAuth_Protected_Resource::init();
		WP_MCP_AI_Well_Known_OpenAI_Challenge::init();

		$vars = apply_filters( 'query_vars', array() );
		$this->assertContains( WP_MCP_AI_Well_Known_OAuth_Protected_Resource::QUERY_VAR, $vars );
		$this->assertContains( WP_MCP_AI_Well_Known_OpenAI_Challenge::QUERY_VAR, $vars );

		$protected = new WP_MCP_AI_Well_Known_OAuth_Protected_Resource();
		$this->assertFalse(
			$protected->prevent_canonical_redirect(
				'https://example.com/canonical',
				'https://example.com/.well-known/oauth-protected-resource'
			)
		);
		$this->assertSame(
			'https://example.com/canonical',
			$protected->prevent_canonical_redirect( 'https://example.com/canonical', 'https://example.com/some-page' )
		);

		$challenge = new WP_MCP_AI_Well_Known_OpenAI_Challenge();
		$this->assertFalse(
			$challenge->prevent_canonical_redirect(
				'https://example.com/canonical',
				'https://example.com/.well-known/openai-apps-challenge'
			)
		);
	}

	/**
	 * The challenge token comes from the option, overridable by filter.
	 */
	public function test_challenge_token_option_and_filter() {
		update_option( WP_MCP_AI_OAuth_Resource_Server::OPTION_CHALLENGE_TOKEN, 'challenge-token-123' );
		$this->assertSame( 'challenge-token-123', WP_MCP_AI_Well_Known_OpenAI_Challenge::get_challenge_token() );

		delete_option( WP_MCP_AI_OAuth_Resource_Server::OPTION_CHALLENGE_TOKEN );
		$this->assertSame( '', WP_MCP_AI_Well_Known_OpenAI_Challenge::get_challenge_token() );

		add_filter(
			'wp_mcp_ai_openai_apps_challenge_token',
			static function () {
				return 'filtered-token';
			}
		);
		$this->assertSame( 'filtered-token', WP_MCP_AI_Well_Known_OpenAI_Challenge::get_challenge_token() );
		remove_all_filters( 'wp_mcp_ai_openai_apps_challenge_token' );
	}
}
