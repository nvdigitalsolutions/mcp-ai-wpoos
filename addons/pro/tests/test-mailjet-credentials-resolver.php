<?php
/**
 * Tests for Mailjet credential resolution across connections and settings.
 *
 * The resolver is the part that decides which Mailjet subaccount a tool call
 * spends. These tests pin the rules that make that safe: an explicit ID never
 * falls back, a restricted assistant never reaches an unassigned connection,
 * two candidates are an error rather than a guess, and site-wide settings need
 * the explicit `settings` grant once the assistant is restricted.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.25
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/email-marketing/class-wp-mcp-ai-pro-mailjet-credentials.php';

/**
 * Mailjet credential resolver tests.
 *
 * @since 1.2.25
 */
class Test_Mailjet_Credentials_Resolver extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Save a Mailjet connection fixture.
	 *
	 * @param array $overrides Field overrides.
	 * @return string Connection ID.
	 */
	protected function save_mailjet( array $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'Mailjet',
				'url'             => 'https://api.mailjet.com',
				'connection_type' => 'mailjet_mcp',
				'auth_type'       => 'basic_auth',
				'api_key'         => 'key-one',
				'api_secret'      => 'secret-one',
				'enabled'         => true,
			),
			$overrides
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );

		$this->assertIsString( $result, 'Fixture connection should save.' );

		return $result;
	}

	/**
	 * Create an assistant with an explicit connection allowlist.
	 *
	 * @param string[] $ids Connection IDs to allow.
	 * @return int Assistant post ID.
	 */
	protected function assistant_allowing( array $ids ) {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Mailjet test assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Pro_Mailjet_Credentials::ASSISTANT_META_KEY, $ids );

		return (int) $assistant_id;
	}

	/**
	 * An explicit connection ID resolves and decrypts the key pair.
	 */
	public function test_explicit_connection_resolves_decrypted_credentials() {
		$id = $this->save_mailjet();

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array(), $id );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'key-one', $resolved['api_key'] );
		$this->assertSame( 'secret-one', $resolved['api_secret'] );
		$this->assertSame( 'us', $resolved['region'] );
		$this->assertSame( 'connection', $resolved['source'] );
		$this->assertSame( $id, $resolved['connection_id'] );
	}

	/**
	 * A non-Mailjet connection is refused, never silently substituted.
	 */
	public function test_explicit_connection_of_wrong_type_is_rejected() {
		$id = $this->save_mailjet(
			array(
				'name'            => 'Not Mailjet',
				'connection_type' => 'generic',
			)
		);

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array(), $id );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_mailjet_wrong_connection_type', $resolved->get_error_code() );
	}

	/**
	 * A disabled connection is refused even when named explicitly.
	 */
	public function test_explicit_disabled_connection_is_rejected() {
		$id = $this->save_mailjet( array( 'enabled' => false ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array(), $id );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_mailjet_connection_disabled', $resolved->get_error_code() );
	}

	/**
	 * A restricted assistant cannot reach a connection it was not assigned.
	 */
	public function test_restricted_assistant_rejects_unassigned_connection() {
		$allowed  = $this->save_mailjet( array( 'name' => 'Allowed' ) );
		$other    = $this->save_mailjet( array( 'name' => 'Other' ) );
		$assistant = $this->assistant_allowing( array( $allowed ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve(
			array( 'assistant_id' => $assistant ),
			$other
		);

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_mailjet_connection_not_assigned', $resolved->get_error_code() );
	}

	/**
	 * A single assigned connection is resolved without an explicit ID.
	 */
	public function test_single_candidate_auto_resolves() {
		$id        = $this->save_mailjet();
		$assistant = $this->assistant_allowing( array( $id ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array( 'assistant_id' => $assistant ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( $id, $resolved['connection_id'] );
	}

	/**
	 * Two candidates produce an error that names both and carries no secret.
	 */
	public function test_two_candidates_are_ambiguous_and_leak_nothing() {
		$first     = $this->save_mailjet( array( 'name' => 'Marketing' ) );
		$second    = $this->save_mailjet( array( 'name' => 'Transactional' ) );
		$assistant = $this->assistant_allowing( array( $first, $second ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array( 'assistant_id' => $assistant ) );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_mailjet_ambiguous_connection', $resolved->get_error_code() );

		$message = $resolved->get_error_message();
		$this->assertStringContainsString( 'Marketing', $message );
		$this->assertStringContainsString( 'Transactional', $message );
		$this->assertStringNotContainsString( 'key-one', $message );
		$this->assertStringNotContainsString( 'secret-one', $message );
	}

	/**
	 * Site settings are refused for a restricted assistant without the grant.
	 */
	public function test_settings_fallback_requires_grant_when_restricted() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_api_key'    => 'site-key',
				'mailjet_api_secret' => 'site-secret',
			)
		);

		$assistant = $this->assistant_allowing( array( 'settings-not-granted' ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array( 'assistant_id' => $assistant ) );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_mailjet_settings_not_assigned', $resolved->get_error_code() );
	}

	/**
	 * The synthetic `settings` grant opens the fallback.
	 */
	public function test_settings_grant_allows_fallback() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_api_key'    => 'site-key',
				'mailjet_api_secret' => 'site-secret',
				'mailjet_from_email' => 'sender@example.com',
			)
		);

		$assistant = $this->assistant_allowing( array( WP_MCP_AI_Pro_Mailjet_Credentials::SETTINGS_ENTRY ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array( 'assistant_id' => $assistant ) );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'site-key', $resolved['api_key'] );
		$this->assertSame( 'settings', $resolved['source'] );
		$this->assertSame( 'sender@example.com', $resolved['from_email'] );
	}

	/**
	 * A connection's sender overrides the site-wide sender.
	 */
	public function test_connection_sender_wins_over_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'mailjet_from_email' => 'site@example.com' )
		);

		$id = $this->save_mailjet(
			array(
				'mailjet_from_email' => 'subaccount@example.com',
				'mailjet_from_name'  => 'Subaccount',
			)
		);

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array(), $id );

		$this->assertSame( 'subaccount@example.com', $resolved['from_email'] );
		$this->assertSame( 'Subaccount', $resolved['from_name'] );
	}

	/**
	 * The EU region selects the EU API host.
	 */
	public function test_region_selects_api_host() {
		$id = $this->save_mailjet( array( 'mailjet_region' => 'eu' ) );

		$resolved = WP_MCP_AI_Pro_Mailjet_Credentials::resolve( array(), $id );

		$this->assertSame( 'eu', $resolved['region'] );
		$this->assertSame(
			'https://api.eu.mailjet.com',
			WP_MCP_AI_Pro_Mailjet_Credentials::api_base( $resolved['region'] )
		);
		$this->assertSame(
			'Basic ' . base64_encode( 'key-one:secret-one' ),
			WP_MCP_AI_Pro_Mailjet_Credentials::authorization_header( 'key-one', 'secret-one' )
		);
	}

	/**
	 * The connection list never exposes key material.
	 */
	public function test_list_connections_omits_credentials() {
		$id = $this->save_mailjet();

		$list = WP_MCP_AI_Pro_Mailjet_Credentials::list_connections( array() );

		$this->assertCount( 1, $list );
		$this->assertSame( $id, $list[0]['id'] );
		$this->assertTrue( $list[0]['has_credentials'] );

		$encoded = wp_json_encode( $list );
		$this->assertStringNotContainsString( 'key-one', $encoded );
		$this->assertStringNotContainsString( 'secret-one', $encoded );
	}

	/**
	 * A connection with no MCP bridge is not referenceable from MCP Apps, and
	 * one with a bridge is.
	 */
	public function test_bridge_url_gates_mcp_app_referenceability() {
		$credential_only = $this->save_mailjet( array( 'name' => 'Credential only' ) );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $credential_only );

		$this->assertFalse( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( $connection ) );

		$bridged = $this->save_mailjet(
			array(
				'name'            => 'Bridged',
				'mailjet_mcp_url' => 'https://bridge.example.com/mcp',
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $bridged );

		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_mcp_app_connection( $connection ) );

		$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_mailjet_mcp_app_config( $connection );

		$this->assertSame( 'https://bridge.example.com/mcp', $config['server_url'] );
		$this->assertSame( 'basic', $config['auth_type'] );
		$this->assertSame( 'key-one:secret-one', $config['token'] );
		$this->assertSame( $bridged, $config['connection_ref'] );
	}
}
