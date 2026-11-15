<?php
/**
 * Tests for the public workflow-trigger webhook receiver.
 *
 * The route is public by design (CI/external systems cannot log in), so the
 * HMAC over the raw body is the authorization. These tests pin the fail-closed
 * contract: no secret → 503, wrong signature → 401, disabled → 403, and only a
 * correctly signed enabled trigger fires.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file; PHPUnit naming convention.

/**
 * Trigger webhook REST tests.
 */
class Test_REST_Triggers_Webhook extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Boot the CPT + REST server.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( WP_MCP_AI_Workflow_Trigger_CPT::CPT ) ) {
			WP_MCP_AI_Workflow_Trigger_CPT::register_cpt();
		}

		// Keep the success path deterministic (no Engine V2 execution).
		add_filter(
			'wp_mcp_ai_workflow_executor',
			static function () {
				return array( 'executed' => true );
			}
		);

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = rest_get_server();
	}

	/**
	 * Reset global state.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'wp_mcp_ai_workflow_executor' );
		parent::tearDown();
	}

	/**
	 * Create an enabled trigger.
	 *
	 * @param string $secret Signing secret ('' = none configured).
	 * @param bool   $enabled Enabled flag.
	 * @return int Trigger post ID.
	 */
	private function make_trigger( string $secret, bool $enabled = true ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Workflow_Trigger_CPT::CPT,
				'post_title'  => 'Test Trigger',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, '_wp_mcp_ai_trigger_enabled', $enabled ? '1' : '0' );
		update_post_meta(
			$id,
			'_wp_mcp_ai_trigger_config',
			wp_json_encode( '' !== $secret ? array( 'secret' => $secret ) : array() )
		);
		return (int) $id;
	}

	/**
	 * Dispatch a webhook request.
	 *
	 * @param int         $id        Trigger ID.
	 * @param string      $body      Raw body.
	 * @param string|null $signature Signature header (null = omit).
	 * @return WP_REST_Response
	 */
	private function dispatch( int $id, string $body, ?string $signature = null ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/triggers/webhook/' . $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		if ( null !== $signature ) {
			$request->set_header( 'X-WP-MCP-AI-Signature-256', $signature );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Build the expected signature.
	 *
	 * @param string $body   Raw body.
	 * @param string $secret Secret.
	 * @return string
	 */
	private function sign( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * An unknown trigger ID is a typed 404.
	 */
	public function test_unknown_trigger_is_404() {
		$response = $this->dispatch( 999999, '{"a":1}' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['code'] );
	}

	/**
	 * No configured secret fails closed (previously it skipped verification
	 * and executed the workflow unauthenticated).
	 */
	public function test_missing_secret_fails_closed() {
		$id       = $this->make_trigger( '' );
		$response = $this->dispatch( $id, '{"event":"ping"}' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'trigger_not_configured', $response->get_data()['code'] );
	}

	/**
	 * A missing or wrong signature is rejected.
	 */
	public function test_wrong_signature_is_401() {
		$secret = 'shared-secret';
		$body   = '{"event":"ping"}';
		$id     = $this->make_trigger( $secret );

		$missing = $this->dispatch( $id, $body );
		$this->assertSame( 401, $missing->get_status() );
		$this->assertSame( 'invalid_signature', $missing->get_data()['code'] );

		$wrong = $this->dispatch( $id, $body, 'sha256=deadbeef' );
		$this->assertSame( 401, $wrong->get_status() );
	}

	/**
	 * A correctly signed enabled trigger fires.
	 */
	public function test_valid_signature_is_accepted() {
		$secret   = 'shared-secret';
		$body     = '{"event":"ping"}';
		$id       = $this->make_trigger( $secret );
		$response = $this->dispatch( $id, $body, $this->sign( $body, $secret ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( $id, (int) $response->get_data()['trigger_id'] );
	}

	/**
	 * A disabled trigger is rejected even with a valid signature.
	 */
	public function test_disabled_trigger_is_403() {
		$secret = 'shared-secret';
		$body   = '{"event":"ping"}';
		$id     = $this->make_trigger( $secret, false );

		$response = $this->dispatch( $id, $body, $this->sign( $body, $secret ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'trigger_disabled', $response->get_data()['code'] );
	}
}
