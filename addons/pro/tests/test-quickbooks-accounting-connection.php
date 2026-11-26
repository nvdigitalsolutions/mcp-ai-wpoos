<?php
/**
 * QuickBooks Online (Accounting) connection behaviour.
 *
 * Covers the three defects that made the legacy connection type unusable:
 * save-time validation required a 60-minute bearer token, the report tool
 * never consumed the auto-refreshing OAuth grant, and "Test Connection"
 * probed the WordPress REST API instead of QuickBooks.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Tests for save-time validation, credential resolution and the probe.
 */
class Test_QuickBooks_Accounting_Connection extends WP_UnitTestCase {

	/**
	 * Administrator ID used as the current user.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Company / realm ID used across the fixtures.
	 *
	 * @var string
	 */
	private $realm = '193514562011054';

	/**
	 * Prepare a clean settings surface and an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Remove HTTP mocks, connections and settings between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Save a QuickBooks (Accounting) connection with the given overrides.
	 *
	 * @param array $overrides Connection fields to override.
	 * @return string|WP_Error Connection ID or error.
	 */
	private function save_quickbooks_connection( array $overrides = array() ) {
		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array_merge(
				array(
					'name'            => 'NV Quickbooks',
					'url'             => 'https://quickbooks.api.intuit.com',
					'connection_type' => 'quickbooks',
					'client_id'       => 'ABoNsXTbgoFaKWbuTqd5Dt0DMYTzgPLRsPuibr2JRj90LgwmW5',
					'company_id'      => $this->realm,
					'enabled'         => true,
				),
				$overrides
			)
		);
	}

	/**
	 * Store an OAuth grant in the site-wide settings.
	 *
	 * @param array $overrides Settings overrides.
	 */
	private function store_oauth_grant( array $overrides = array() ) {
		$settings = array_merge(
			WP_MCP_AI_Admin_Settings::get_default_settings(),
			array(
				'quickbooks_connected'        => true,
				'quickbooks_access_token'     => 'stale-token',
				'quickbooks_refresh_token'    => 'refresh-token',
				'quickbooks_client_id'        => 'oauth-client-id',
				'quickbooks_client_secret'    => 'oauth-client-secret',
				'quickbooks_token_expires_at' => time() + 3600,
				'quickbooks_company_id'       => $this->realm,
			),
			$overrides
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Mock an authenticated JSON response.
	 *
	 * @param array $body Response body.
	 * @param int   $code HTTP status code.
	 * @return array
	 */
	private function http_response( array $body, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A connection can be provisioned from the Intuit app identity alone.
	 */
	public function test_connection_saves_without_an_access_token() {
		$connection_id = $this->save_quickbooks_connection();

		$this->assertNotWPError( $connection_id );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertSame( 'quickbooks', $connection['connection_type'] );
		$this->assertSame( $this->realm, $connection['company_id'] );
		$this->assertEmpty( $connection['client_secret'] );
	}

	/**
	 * The Intuit app identity is still mandatory.
	 */
	public function test_connection_requires_a_client_id() {
		$result = $this->save_quickbooks_connection( array( 'client_id' => '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_quickbooks_credentials', $result->get_error_code() );
	}

	/**
	 * A token pasted on the connection wins over every site-wide source.
	 */
	public function test_resolver_prefers_the_connection_token() {
		$this->store_oauth_grant();

		$connection_id = $this->save_quickbooks_connection( array( 'client_secret' => 'conn-token-1' ) );
		$this->assertNotWPError( $connection_id );

		$resolved = WP_MCP_AI_Pro_Remote_Site_Manager::resolve_quickbooks_credentials(
			WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
		);

		$this->assertNotWPError( $resolved );
		$this->assertSame( 'connection', $resolved['source'] );
		$this->assertSame( 'conn-token-1', $resolved['token'] );
	}

	/**
	 * A connection with no pasted token falls back to the OAuth grant.
	 */
	public function test_resolver_uses_the_oauth_grant_when_no_connection_token_exists() {
		$this->store_oauth_grant();

		$connection_id = $this->save_quickbooks_connection();

		$resolved = WP_MCP_AI_Pro_Remote_Site_Manager::resolve_quickbooks_credentials(
			WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
		);

		$this->assertNotWPError( $resolved );
		$this->assertSame( 'oauth', $resolved['source'] );
		$this->assertSame( 'stale-token', $resolved['token'] );
	}

	/**
	 * A token minted for one company is never spent against another.
	 */
	public function test_resolver_refuses_a_realm_mismatch() {
		$this->store_oauth_grant( array( 'quickbooks_company_id' => '111111111' ) );

		$connection_id = $this->save_quickbooks_connection();

		$resolved = WP_MCP_AI_Pro_Remote_Site_Manager::resolve_quickbooks_credentials(
			WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
		);

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_quickbooks_realm_mismatch', $resolved->get_error_code() );
	}

	/**
	 * The report tool authenticates with the connection token and reports the
	 * credential source it used.
	 */
	public function test_report_uses_the_connection_token() {
		$connection_id = $this->save_quickbooks_connection( array( 'client_secret' => 'conn-token-2' ) );

		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requests ) {
				$requests[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
				);

				return $this->http_response( array( 'ReportName' => 'ProfitAndLoss' ) );
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report();
		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'report'        => 'ProfitAndLoss',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'connection', $result['credential_source'] );
		$this->assertCount( 1, $requests );
		$this->assertStringContainsString(
			'/v3/company/' . $this->realm . '/reports/ProfitAndLoss',
			$requests[0]['url']
		);
		$this->assertSame( 'Bearer conn-token-2', $requests[0]['headers']['Authorization'] );
	}

	/**
	 * A 401 from QuickBooks triggers exactly one forced refresh and a retry.
	 */
	public function test_report_refreshes_and_retries_once_on_401() {
		$this->store_oauth_grant();

		$connection_id = $this->save_quickbooks_connection();

		$report_calls = 0;
		$auth_headers = array();
		$token_calls  = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$report_calls, &$auth_headers, &$token_calls ) {
				if ( false !== strpos( $url, 'oauth.platform.intuit.com' ) ) {
					++$token_calls;

					return $this->http_response(
						array(
							'access_token'  => 'fresh-token',
							'refresh_token' => 'rotated-refresh-token',
							'expires_in'    => 3600,
						)
					);
				}

				++$report_calls;
				$auth_headers[] = $args['headers']['Authorization'];

				if ( 1 === $report_calls ) {
					return $this->http_response( array( 'Fault' => array() ), 401 );
				}

				return $this->http_response( array( 'ReportName' => 'ProfitAndLoss' ) );
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report();
		$result = $tool->execute(
			array(
				'connection_id' => $connection_id,
				'report'        => 'ProfitAndLoss',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'oauth', $result['credential_source'] );
		$this->assertSame( 2, $report_calls );
		$this->assertSame( 1, $token_calls );
		$this->assertSame( 'Bearer stale-token', $auth_headers[0] );
		$this->assertSame( 'Bearer fresh-token', $auth_headers[1] );
	}

	/**
	 * Test Connection performs a real authenticated QuickBooks probe.
	 */
	public function test_test_connection_probes_companyinfo() {
		$connection_id = $this->save_quickbooks_connection( array( 'client_secret' => 'probe-token' ) );

		$probed = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$probed ) {
				$probed[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
				);

				return $this->http_response(
					array(
						'CompanyInfo' => array(
							'CompanyName' => 'NV Digital Solutions',
						),
					)
				);
			},
			10,
			3
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection(
			WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['quickbooks'] );
		$this->assertSame( 'NV Digital Solutions', $result['company_name'] );
		$this->assertSame( 'connection', $result['token_source'] );
		$this->assertCount( 1, $probed );
		$this->assertStringContainsString(
			'/v3/company/' . $this->realm . '/companyinfo/' . $this->realm,
			$probed[0]['url']
		);
		$this->assertSame( 'Bearer probe-token', $probed[0]['headers']['Authorization'] );
	}

	/**
	 * A rejected token is reported as such rather than as a generic HTTP error.
	 */
	public function test_test_connection_reports_a_rejected_token() {
		$connection_id = $this->save_quickbooks_connection( array( 'client_secret' => 'expired-token' ) );

		add_filter(
			'pre_http_request',
			function () {
				return $this->http_response( array( 'Fault' => array() ), 401 );
			}
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection(
			WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_token_rejected', $result->get_error_code() );
	}
}
