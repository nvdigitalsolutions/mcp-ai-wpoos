<?php
/**
 * Forms bridge tests — the origin/rate/spam gates, the native-dispatch seams
 * (JFB + Elementor), the typed 404/501 contracts, captcha, and the no-JS
 * redirect floor. Zero HTTP: handler plugins are simulated through the
 * availability filters + dispatch seams; captcha calls are intercepted with
 * pre_http_request.
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Settings;

/**
 * Forms suite.
 */
class Test_Forge_Bridge_Forms extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * The origin used in the tests.
	 */
	private const ORIGIN = 'https://static.example.com';

	/**
	 * Reset state + boot a fresh REST server.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( Settings::option_keys() as $option ) {
			delete_option( $option );
		}
		// Rate counters are per-IP; clear the known keys.
		delete_transient( 'nvoos_forge_bridge_forms_rate_' . md5( 'unknown' ) );
		delete_transient( 'nvoos_forge_bridge_forms_rate_' . md5( '10.0.0.9' ) );
		remove_all_filters( 'nvoos_forge_bridge_forms_jfb_available' );
		remove_all_filters( 'nvoos_forge_bridge_forms_elementor_available' );
		remove_all_filters( 'nvoos_forge_bridge_forms_jfb_dispatch' );
		remove_all_filters( 'nvoos_forge_bridge_forms_elementor_dispatch' );
		remove_all_actions( 'nvoos_forge_bridge_form_submission' );
		unset( $_SERVER['REMOTE_ADDR'] );

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = rest_get_server();
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Enable forms with the test origin (+ optional captcha).
	 *
	 * @param bool $captcha With captcha.
	 * @return void
	 */
	private function enable_forms( bool $captcha = false ): void {
		update_option( Settings::OPTION_FORMS_ENABLED, '1' );
		update_option( Settings::OPTION_FORMS_ORIGINS, self::ORIGIN );
		if ( $captcha ) {
			update_option( Settings::OPTION_FORMS_CAPTCHA_PROVIDER, 'turnstile' );
			update_option( Settings::OPTION_FORMS_CAPTCHA_SECRET, 'turnstile-secret' );
		}
	}

	/**
	 * A JSON submit request from the allowlisted origin.
	 *
	 * @param array $body Body.
	 * @param bool  $from_allowed_origin With the allowlisted Origin header.
	 * @return WP_REST_Request
	 */
	private function json_request( array $body, bool $from_allowed_origin = true ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/forms/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $from_allowed_origin ) {
			$request->set_header( 'Origin', self::ORIGIN );
		}
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * The default valid JSON payload.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function valid_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'form'      => '7',
				'fields'    => array( 'email' => 'jane@example.com' ),
				'honeypot'  => '',
				'timestamp' => (int) round( microtime( true ) * 1000 ),
				'page'      => self::ORIGIN . '/contact/',
			),
			$overrides
		);
	}

	/**
	 * The submit route registers under nvoos-forge/v1.
	 */
	public function test_route_registered(): void {
		$routes = $this->server->get_routes( 'nvoos-forge/v1' );
		$this->assertArrayHasKey( '/nvoos-forge/v1/forms/submit', $routes );
	}

	/**
	 * Disabled forms: the surface does not exist (404).
	 */
	public function test_disabled_returns_404(): void {
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'nvoos_forge_forms_disabled', $response->get_data()['code'] );
	}

	/**
	 * Origin gate: missing and non-allowlisted origins are rejected; the
	 * allowlisted origin proceeds past the gate.
	 */
	public function test_origin_gate(): void {
		$this->enable_forms();

		// No Origin header at all.
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload(), false ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'nvoos_forge_bad_origin', $response->get_data()['code'] );

		// A non-allowlisted origin.
		$request = $this->json_request( $this->valid_payload(), false );
		$request->set_header( 'Origin', 'https://evil.example.com' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );

		// The allowlisted origin passes the gate (and lands on the 501 —
		// no handler in the test env — which proves the gate let it through).
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'nvoos_forge_no_form_handler', $response->get_data()['code'] );
	}

	/**
	 * Rate limit: a saturated per-IP window is a 429 before anything else.
	 */
	public function test_rate_limit(): void {
		$this->enable_forms();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
		set_transient( 'nvoos_forge_bridge_forms_rate_' . md5( '10.0.0.9' ), 5, 600 );

		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'nvoos_forge_forms_rate_limited', $response->get_data()['code'] );
	}

	/**
	 * Honeypot: a filled field is a silent discard — 200, no dispatch, no audit.
	 */
	public function test_honeypot_discard(): void {
		$this->enable_forms();
		$dispatched = 0;
		$audited    = 0;
		add_filter(
			'nvoos_forge_bridge_forms_jfb_available',
			function () {
				return true;
			}
		);
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			function () use ( &$dispatched ) {
				$dispatched++;
				return array( 'ok' => true, 'message' => 'Thanks' );
			}
		);
		add_action(
			'nvoos_forge_bridge_form_submission',
			function () use ( &$audited ) {
				$audited++;
			}
		);

		$response = $this->server->dispatch(
			$this->json_request( $this->valid_payload( array( 'honeypot' => 'spam-bot' ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 0, $dispatched );
		$this->assertSame( 0, $audited );
	}

	/**
	 * Timestamp gate (JSON path): missing or stale timestamps are silent
	 * discards; a fresh one proceeds.
	 */
	public function test_timestamp_gate(): void {
		$this->enable_forms();
		$dispatched = 0;
		add_filter(
			'nvoos_forge_bridge_forms_jfb_available',
			function () {
				return true;
			}
		);
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			function () use ( &$dispatched ) {
				$dispatched++;
				return array( 'ok' => true, 'message' => 'Thanks' );
			}
		);

		// Missing timestamp.
		$payload  = $this->valid_payload();
		unset( $payload['timestamp'] );
		$response = $this->server->dispatch( $this->json_request( $payload ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $dispatched );

		// Stale timestamp (3 hours old).
		$response = $this->server->dispatch(
			$this->json_request( $this->valid_payload( array( 'timestamp' => (int) round( microtime( true ) * 1000 ) - 10800000 ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $dispatched );

		// Fresh timestamp → dispatch runs (form not found → 404, but the
		// ladder passed the gate).
		$this->assertSame( 0, $dispatched );
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 404, $response->get_status() ); // form 7 is not a JFB post in the test env.
	}

	/**
	 * The no-JS floor: a form-encoded submission may omit the timestamp
	 * (honeypot + origin + rate still gate it).
	 */
	public function test_form_encoded_without_timestamp(): void {
		$this->enable_forms();
		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/forms/submit' );
		$request->set_header( 'Origin', self::ORIGIN );
		$request->set_body_params(
			array(
				'form'     => '7',
				'email'    => 'jane@example.com',
				'honeypot' => '',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 501, $response->get_status() ); // Passed the gates; no handler.
		$this->assertSame( 'nvoos_forge_no_form_handler', $response->get_data()['code'] );
	}

	/**
	 * Malformed JSON is a typed 400 — WP 6.9's REST server rejects the body
	 * before the callback runs (rest_invalid_json); the callback's own
	 * invalid-JSON branch is the defense for older cores.
	 */
	public function test_bad_json_400(): void {
		$this->enable_forms();
		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/forms/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Origin', self::ORIGIN );
		$request->set_body( '{not json' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_json', $response->get_data()['code'] );
	}

	/**
	 * Missing form id / fields are typed 400s.
	 */
	public function test_missing_form_and_fields_400(): void {
		$this->enable_forms();
		$payload  = $this->valid_payload();
		unset( $payload['form'] );
		$response = $this->server->dispatch( $this->json_request( $payload ) );
		$this->assertSame( 400, $response->get_status() );

		$payload  = $this->valid_payload();
		unset( $payload['fields'] );
		$response = $this->server->dispatch( $this->json_request( $payload ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * No handler plugin → typed 501.
	 */
	public function test_no_handler_501(): void {
		$this->enable_forms();
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'nvoos_forge_no_form_handler', $response->get_data()['code'] );
	}

	/**
	 * JFB path: the seam receives the sanitized fields and the envelope
	 * passes the handler result through; the audit hook fires.
	 */
	public function test_jfb_dispatch_success_and_sanitization(): void {
		$this->enable_forms();
		$form_id = wp_insert_post( array( 'post_type' => 'jet-form-builder', 'post_title' => 'Contact', 'post_status' => 'publish' ) );
		$seen    = null;
		$audited = 0;
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			function ( $override, $form_id_seen, $fields ) use ( &$seen ) {
				$seen = array( 'form_id' => $form_id_seen, 'fields' => $fields );
				return array( 'ok' => true, 'message' => 'Thanks' );
			},
			10,
			3
		);
		add_action(
			'nvoos_forge_bridge_form_submission',
			function ( $outcome ) use ( &$audited ) {
				$audited = $outcome;
			}
		);

		$response = $this->server->dispatch(
			$this->json_request(
				$this->valid_payload(
					array(
						'form'   => (string) $form_id,
						'fields' => array( 'email' => '<script>alert(1)</script>jane@example.com' ),
					)
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 'Thanks', $response->get_data()['message'] );
		$this->assertSame( (int) $form_id, $seen['form_id'] );
		// Fields arrive server-side sanitized (script stripped).
		$this->assertSame( 'jane@example.com', $seen['fields']['email'] );
		$this->assertSame( 'accepted', $audited );
	}

	/**
	 * JFB failure envelope passes validation errors through.
	 */
	public function test_jfb_dispatch_failure(): void {
		$this->enable_forms();
		$form_id = wp_insert_post( array( 'post_type' => 'jet-form-builder', 'post_title' => 'Contact', 'post_status' => 'publish' ) );
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			function () {
				return array( 'ok' => false, 'message' => 'Invalid email.', 'errors' => array( 'email' => 'Invalid email.' ) );
			}
		);

		$response = $this->server->dispatch(
			$this->json_request( $this->valid_payload( array( 'form' => (string) $form_id ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['ok'] );
		$this->assertSame( 'Invalid email.', $response->get_data()['message'] );
		$this->assertArrayHasKey( 'email', $response->get_data()['errors'] );
	}

	/**
	 * A handler is present but the form id resolves to nothing → 404.
	 */
	public function test_jfb_form_not_found_404(): void {
		$this->enable_forms();
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'form' => '999999' ) ) ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'nvoos_forge_form_not_found', $response->get_data()['code'] );
	}

	/**
	 * Elementor path: form existence via _elementor_data, the form_fields
	 * mapping, and the seam envelope.
	 */
	public function test_elementor_dispatch(): void {
		$this->enable_forms();
		$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Contact', 'post_status' => 'publish' ) );
		update_post_meta(
			$post_id,
			'_elementor_data',
			wp_json_encode(
				array(
					array(
						'widgetType' => 'form',
						'settings'   => array( 'form_id' => 'widget-1' ),
					),
				)
			)
		);
		$seen = null;
		add_filter( 'nvoos_forge_bridge_forms_elementor_available', '__return_true' );
		add_filter(
			'nvoos_forge_bridge_forms_elementor_dispatch',
			function ( $override, $form_id_seen, $post_id_seen, $fields ) use ( &$seen ) {
				$seen = array( 'form_id' => $form_id_seen, 'post_id' => $post_id_seen, 'fields' => $fields );
				return array( 'ok' => true, 'message' => 'Sent.' );
			},
			10,
			4
		);

		$response = $this->server->dispatch(
			$this->json_request(
				$this->valid_payload(
					array(
						'form'    => 'widget-1',
						'post_id' => $post_id,
						'fields'  => array( 'form_fields[name]' => 'Jane' ),
					)
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 'widget-1', $seen['form_id'] );
		$this->assertSame( $post_id, $seen['post_id'] );
		// The Elementor wire shape: form_fields[name] flattens into the map.
		$this->assertSame( 'Jane', $seen['fields']['name'] );
	}

	/**
	 * Elementor present but no such form → 404.
	 */
	public function test_elementor_form_not_found_404(): void {
		$this->enable_forms();
		add_filter( 'nvoos_forge_bridge_forms_elementor_available', '__return_true' );
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'form' => 'ghost-widget' ) ) ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'nvoos_forge_form_not_found', $response->get_data()['code'] );
	}

	/**
	 * Captcha: enabled → a missing/failed token is a 403; a verified token
	 * (intercepted siteverify) proceeds; action/hostname mismatches fail.
	 */
	public function test_captcha_gate(): void {
		$this->enable_forms( true );

		// Missing token → 403 before any dispatch.
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload() ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'nvoos_forge_forms_captcha_failed', $response->get_data()['code'] );

		// Successful verification (intercepted) → proceeds to the 501.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'success'  => true,
							'action'   => 'nvoos_forge_form',
							'hostname' => 'static.example.com',
						)
					),
				);
			}
		);
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'token' => 'turnstile-ok' ) ) ) );
		$this->assertSame( 501, $response->get_status() );
		remove_all_filters( 'pre_http_request' );

		// Wrong action → fail closed.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'success'  => true,
							'action'   => 'someone_elses_form',
							'hostname' => 'static.example.com',
						)
					),
				);
			}
		);
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'token' => 'turnstile-wrong-action' ) ) ) );
		$this->assertSame( 403, $response->get_status() );
		remove_all_filters( 'pre_http_request' );

		// Wrong hostname → fail closed.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'success'  => true,
							'action'   => 'nvoos_forge_form',
							'hostname' => 'evil.example.com',
						)
					),
				);
			}
		);
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'token' => 'turnstile-wrong-host' ) ) ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The no-JS redirect floor: a form-encoded submission redirects back to
	 * the allowlisted page with ?form=ok; foreign redirect targets fall back
	 * to the origin root.
	 */
	public function test_nojs_redirect(): void {
		$this->enable_forms();
		$form_id = wp_insert_post( array( 'post_type' => 'jet-form-builder', 'post_title' => 'Contact', 'post_status' => 'publish' ) );
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			function () {
				return array( 'ok' => true, 'message' => 'Thanks' );
			}
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/forms/submit' );
		$request->set_header( 'Origin', self::ORIGIN );
		$request->set_body_params(
			array(
				'form'      => (string) $form_id,
				'email'     => 'jane@example.com',
				'honeypot'  => '',
				'_redirect' => '/contact/',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( self::ORIGIN . '/contact/?form=ok', $response->get_headers()['Location'] );

		// A cross-origin redirect target is refused (open-redirect guard).
		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/forms/submit' );
		$request->set_header( 'Origin', self::ORIGIN );
		$request->set_body_params(
			array(
				'form'      => (string) $form_id,
				'email'     => 'jane@example.com',
				'honeypot'  => '',
				'_redirect' => 'https://evil.example.com/steal',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( self::ORIGIN . '/?form=ok', $response->get_headers()['Location'] );
	}

	/**
	 * Visibility gate: a JetFormBuilder form that is not published (or is
	 * password protected) is treated as unknown — the public route never
	 * dispatches into non-public content.
	 */
	public function test_jfb_nonpublic_form_404(): void {
		$this->enable_forms();
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );

		$draft = wp_insert_post(
			array(
				'post_type'   => 'jet-form-builder',
				'post_title'  => 'Draft Contact',
				'post_status' => 'draft',
			)
		);
		$private   = wp_insert_post(
			array(
				'post_type'   => 'jet-form-builder',
				'post_title'  => 'Private Contact',
				'post_status' => 'private',
			)
		);
		$protected = wp_insert_post(
			array(
				'post_type'     => 'jet-form-builder',
				'post_title'    => 'Locked Contact',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		foreach ( array( $draft, $private, $protected ) as $form_id ) {
			$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'form' => (string) $form_id ) ) ) );
			$this->assertSame( 404, $response->get_status(), 'Form ' . $form_id . ' must not be dispatchable.' );
			$this->assertSame( 'nvoos_forge_form_not_found', $response->get_data()['code'] );
		}

		// Name resolution is status-scoped too: a draft title does not resolve.
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'form' => 'Draft Contact' ) ) ) );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * An Elementor form on a non-public carrier post is not dispatchable.
	 */
	public function test_elementor_nonpublic_carrier_404(): void {
		$this->enable_forms();
		add_filter( 'nvoos_forge_bridge_forms_elementor_available', '__return_true' );

		$draft = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Draft Carrier',
				'post_status' => 'draft',
			)
		);
		update_post_meta(
			$draft,
			'_elementor_data',
			wp_json_encode(
				array(
					array(
						'widgetType' => 'form',
						'settings'   => array( 'form_id' => 'draft-widget' ),
					),
				)
			)
		);

		$response = $this->server->dispatch(
			$this->json_request( $this->valid_payload( array( 'form' => 'draft-widget', 'post_id' => $draft ) ) )
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'nvoos_forge_form_not_found', $response->get_data()['code'] );

		// The global lookup (no post_id) is scoped to published posts too.
		$response = $this->server->dispatch( $this->json_request( $this->valid_payload( array( 'form' => 'draft-widget' ) ) ) );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A spoofed X-Forwarded-For header cannot evade the per-IP rate limit: with
	 * no trusted proxy configured, REMOTE_ADDR is authoritative.
	 */
	public function test_forwarded_for_ignored_without_trusted_proxy(): void {
		$this->enable_forms();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
		set_transient( 'nvoos_forge_bridge_forms_rate_' . md5( '10.0.0.9' ), 5, 600 );

		$request = $this->json_request( $this->valid_payload() );
		$request->set_header( 'X-Forwarded-For', '203.0.113.7' );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'nvoos_forge_forms_rate_limited', $response->get_data()['code'] );
	}

	/**
	 * With a trusted proxy configured, the rightmost untrusted hop is used —
	 * a client-supplied prefix cannot rewrite its own address.
	 */
	public function test_forwarded_for_used_with_trusted_proxy(): void {
		$this->enable_forms();
		$form_id = wp_insert_post(
			array(
				'post_type'   => 'jet-form-builder',
				'post_title'  => 'Contact',
				'post_status' => 'publish',
			)
		);
		add_filter( 'nvoos_forge_bridge_forms_jfb_available', '__return_true' );
		add_filter(
			'nvoos_forge_bridge_forms_jfb_dispatch',
			static function () {
				return array( 'ok' => true, 'message' => 'Thanks' );
			}
		);
		add_filter(
			'nvoos_forge_bridge_trusted_proxies',
			static function () {
				return array( '10.0.0.0/8' );
			}
		);
		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';

		$seen = null;
		add_action(
			'nvoos_forge_bridge_form_submission',
			static function ( $outcome, $form, $origin, $ip ) use ( &$seen ) {
				$seen = $ip;
			},
			10,
			4
		);

		// Chain: spoofed prefix, real client, trusted proxy.
		$request = $this->json_request( $this->valid_payload( array( 'form' => (string) $form_id ) ) );
		$request->set_header( 'X-Forwarded-For', '198.51.100.9, 203.0.113.7, 10.9.9.9' );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '203.0.113.7', $seen );
	}

	/**
	 * A submitted field named like JFB's routing carrier cannot re-target the
	 * dispatch — the resolved form is the one whose visibility was checked.
	 */
	public function test_jfb_routing_carriers_cannot_be_overridden(): void {
		$fields = Nvoos_Test_Forms_Capture::strip_carriers(
			array(
				'_jet_engine_booking_form_id' => '999999',
				'_jet_engine_refer'           => 'https://evil.example/',
				'email'                       => 'jane@example.com',
			),
			array( '_jet_engine_booking_form_id', '_jet_engine_refer' )
		);

		$this->assertSame( array( 'email' => 'jane@example.com' ), $fields );
	}

	/**
	 * capture_json() establishes the ajax contract, decodes the emitted JSON,
	 * and restores the environment afterwards.
	 */
	public function test_capture_json_emulates_ajax_and_decodes_output(): void {
		$capture = new Nvoos_Test_Forms_Capture();
		$seen    = null;
		$result  = $capture->capture(
			static function () use ( &$seen ) {
				$seen = wp_doing_ajax();
				echo wp_json_encode( array( 'success' => true, 'data' => array( 'message' => 'ok' ) ) );
			}
		);

		$this->assertTrue( $seen );
		$this->assertSame( array( 'success' => true, 'data' => array( 'message' => 'ok' ) ), $result );
		// The emulation must not leak past the dispatch.
		$this->assertFalse( wp_doing_ajax() );
	}

	/**
	 * A native wp_send_json*() call is captured instead of ending the request.
	 * Runs isolated: a regression would call exit() and take the suite down.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_capture_json_intercepts_wp_send_json(): void {
		$capture = new Nvoos_Test_Forms_Capture();
		$result  = $capture->capture(
			static function () {
				wp_send_json_success( array( 'message' => 'native' ) );
			}
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'native', $result['data']['message'] );
	}
}

/**
 * Test proxy that exposes the protected capture helper.
 */
class Nvoos_Test_Forms_Capture extends \NvoosForgeBridge\Rest\Forms {

	/**
	 * Run the capture helper.
	 *
	 * @param callable $dispatch Dispatch.
	 * @return array|null
	 */
	public function capture( callable $dispatch ) {
		return $this->capture_json( $dispatch );
	}

	/**
	 * Expose the carrier-stripping helper.
	 *
	 * @param array    $fields   Fields.
	 * @param string[] $carriers Carriers.
	 * @return array
	 */
	public static function strip_carriers( array $fields, array $carriers ): array {
		return self::without_reserved_carriers( $fields, $carriers );
	}
}
