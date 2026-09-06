<?php
/**
 * GitHub OAuth handler port tests (Wave E4, sub-cluster 2).
 *
 * Characterization suite for the ported `GithubOAuthHandler`: the
 * constants, the `allowed_redirect_hosts` filter (default + filtered
 * endpoint), the callback branches, and the end-to-end happy path.
 *
 * Branch assertions read the accumulated `get_settings_errors()`
 * queue: under the `wp_mcp_ai_github_oauth_redirect_terminate` test
 * filter every redirect returns instead of exiting, so the handler
 * byte-identically falls through to the later branches (the base
 * behaves the same way) — the first queued error identifies the first
 * branch taken. All callback tests intercept HTTP so the fall-through
 * never reaches the network. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Integrations\GithubOAuthHandler;

/**
 * GitHub OAuth handler characterization.
 */
class Test_OAuth_Github extends \WP_UnitTestCase {

	/**
	 * Handler instance.
	 *
	 * @var GithubOAuthHandler
	 */
	private $handler;

	/**
	 * Canned HTTP responses by URL.
	 *
	 * @var array<string, array|\WP_Error>
	 */
	private $responses = array();

	public function setUp(): void {
		parent::setUp();
		$this->handler = new GithubOAuthHandler();
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( 'settings_errors' );
		$GLOBALS['wp_settings_errors'] = array();
		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_github_oauth_redirect_terminate', '__return_false' );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( 'settings_errors' );
		unset( $_GET['state'], $_GET['code'], $_GET['error'], $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests, routing by URL. The default response is a
	 * generic 200 JSON body; tests override per-URL as needed.
	 *
	 * @param bool   $preempt Preempt flag.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array|\WP_Error|false
	 */
	public function intercept_http( $preempt, $args, $url ) {
		foreach ( $this->responses as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);
	}

	/**
	 * Create an administrator and current user.
	 *
	 * @return int
	 */
	private function make_admin(): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		return $admin;
	}

	/**
	 * Arm the test redirect-termination filter.
	 *
	 * @return void
	 */
	private function arm_terminate_filter(): void {
		add_filter( 'wp_mcp_ai_github_oauth_redirect_terminate', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'https://github.com/login/oauth/authorize', GithubOAuthHandler::GITHUB_OAUTH_AUTHORIZE_ENDPOINT );
		$this->assertSame( 'https://github.com/login/oauth/access_token', GithubOAuthHandler::GITHUB_OAUTH_TOKEN_ENDPOINT );
		$this->assertSame( 'https://api.github.com', GithubOAuthHandler::GITHUB_API_BASE );
		$this->assertSame( 'repo,user,codespace', GithubOAuthHandler::GITHUB_OAUTH_SCOPES );
		$this->assertSame( 'wp_mcp_ai_settings', GithubOAuthHandler::OPTION_NAME );
	}

	public function test_allow_github_oauth_redirect_host_default(): void {
		$hosts = $this->handler->allow_github_oauth_redirect_host( array( 'example.com' ), '' );

		$this->assertContains( 'github.com', $hosts );
		$this->assertContains( 'example.com', $hosts );
	}

	public function test_allow_github_oauth_redirect_host_filtered_endpoint(): void {
		add_filter( 'wp_mcp_ai_github_oauth_authorize_endpoint', array( $this, 'filter_endpoint' ) );

		$hosts = $this->handler->allow_github_oauth_redirect_host( array(), '' );

		remove_filter( 'wp_mcp_ai_github_oauth_authorize_endpoint', array( $this, 'filter_endpoint' ) );

		$this->assertContains( 'github-enterprise.example.com', $hosts );
	}

	/**
	 * Filter the authorize endpoint to a custom host.
	 *
	 * @return string
	 */
	public function filter_endpoint() {
		return 'https://github-enterprise.example.com/login/oauth/authorize';
	}

	public function test_callback_requires_capability(): void {
		$this->expectException( 'WPDieException' );
		$this->handler->handle_github_oauth_callback();
	}

	public function test_callback_error_branch_queues_notice(): void {
		$this->make_admin();
		$this->arm_terminate_filter();
		// Seed defined-but-empty credentials: the gate below still fires on
		// empty values, and the byte-identical fall-through's direct key
		// access stays defined.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'github_client_id'     => '',
				'github_client_secret' => '',
			)
		);

		$_GET['error'] = 'access_denied';

		$this->handler->handle_github_oauth_callback();

		$errors = get_settings_errors();
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'github_oauth_error', $errors[0]['code'] );
	}

	public function test_callback_state_mismatch_queues_notice(): void {
		$this->make_admin();
		$this->arm_terminate_filter();
		// Seed defined-but-empty credentials (see the error-branch test).
		update_option(
			'wp_mcp_ai_settings',
			array(
				'github_client_id'     => '',
				'github_client_secret' => '',
			)
		);

		$_GET['state'] = 'unknown-state';
		$_GET['code']  = 'code-1';

		$this->handler->handle_github_oauth_callback();

		$errors = get_settings_errors();
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'github_oauth_state_mismatch', $errors[0]['code'] );
	}

	public function test_callback_missing_credentials_queues_notice(): void {
		$admin = $this->make_admin();
		$this->arm_terminate_filter();
		// Seed defined-but-empty credentials: the gate fires on empty values
		// while the fall-through's direct key access stays defined.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'github_client_id'     => '',
				'github_client_secret' => '',
			)
		);

		$state = wp_generate_uuid4();
		set_transient(
			'wp_mcp_ai_github_state_' . md5( $state ),
			array(
				'user_id' => $admin,
				'time'    => time(),
			),
			600
		);

		$_GET['state'] = $state;
		$_GET['code']  = 'code-1';

		$this->handler->handle_github_oauth_callback();

		$errors = get_settings_errors();
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'github_oauth_missing_client', $errors[0]['code'] );
	}

	public function test_callback_happy_path_persists_token_and_username(): void {
		$admin = $this->make_admin();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'github_client_id'     => 'client-id',
				'github_client_secret' => 'client-secret',
			)
		);

		$state = wp_generate_uuid4();
		set_transient(
			'wp_mcp_ai_github_state_' . md5( $state ),
			array(
				'user_id' => $admin,
				'time'    => time(),
			),
			600
		);

		$this->responses = array(
			'login/oauth/access_token' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'access_token' => 'gho_test_token_123' ) ),
			),
			'api.github.com/user'      => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'login' => 'octocat' ) ),
			),
		);
		$this->arm_terminate_filter();

		$_GET['state'] = $state;
		$_GET['code']  = 'code-1';

		$this->handler->handle_github_oauth_callback();

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'gho_test_token_123', $settings['github_access_token'] );
		$this->assertSame( 'octocat', $settings['github_username'] );

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'github_oauth_success', $notice[0]['code'] );
		$this->assertSame( 'updated', $notice[0]['type'] );
	}
}
