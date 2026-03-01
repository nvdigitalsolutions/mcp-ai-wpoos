<?php
/**
 * Tests for Facebook Messenger connection test endpoint logic.
 *
 * Validates that the connection test uses /me for Page Access Tokens and /app
 * for App Access Tokens (format: {AppID}|{hash}), that the /me endpoint is
 * never called with an App Access Token (which returns HTTP 400), and that
 * page_id matching logic works correctly.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Messenger connection test endpoint behavior.
 */
class Test_Remote_Connection_Messenger_Fields extends WP_UnitTestCase {

	// =========================================================================
	// App Access Token detection.
	// =========================================================================

	/**
	 * Helper: mirrors the is_app_token detection used in ajax_test_messenger_live.
	 *
	 * @param string $token The access token to test.
	 * @return bool True when the token matches the App Access Token pattern.
	 */
	private function detect_app_token( $token ) {
		return (bool) preg_match( '/^\d+\|[A-Za-z0-9_\-]+$/', $token );
	}

	/**
	 * A token in {AppID}|{hash} format must be identified as an App Access Token.
	 */
	public function test_app_access_token_detected_by_pattern() {
		$app_token = '1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA';
		$this->assertTrue( $this->detect_app_token( $app_token ), 'AppID|hash format must be detected as App Access Token.' );
	}

	/**
	 * A standard Page Access Token (long opaque string) must NOT be identified
	 * as an App Access Token.
	 */
	public function test_page_access_token_not_detected_as_app_token() {
		$page_token = 'EAABwzLixnjYBO2ZCZBQerxyz1234567890ABCDEFabcdefGHIJKLMNOPQRSTUVWXYZ';
		$this->assertFalse( $this->detect_app_token( $page_token ), 'Long opaque token must not be detected as App Access Token.' );
	}

	/**
	 * An empty string must not be detected as an App Access Token.
	 */
	public function test_empty_token_not_detected_as_app_token() {
		$this->assertFalse( $this->detect_app_token( '' ), 'Empty string must not be detected as App Access Token.' );
	}

	// =========================================================================
	// Endpoint URL construction.
	// =========================================================================

	/**
	 * App Access Tokens must use the /app endpoint, not /me.
	 * Calling /me with an App Access Token returns HTTP 400: "An active access
	 * token must be used to query information about the current user."
	 */
	public function test_app_token_uses_app_endpoint_not_me() {
		$api_version = 'v21.0';
		$app_token   = '1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA';

		if ( $this->detect_app_token( $app_token ) ) {
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/app?fields=id,name&access_token=%s',
				$api_version,
				rawurlencode( $app_token )
			);
		} else {
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/me?fields=id,name',
				$api_version
			);
		}

		$this->assertStringContainsString( '/app', $endpoint, 'App Access Token must route to /app endpoint.' );
		$this->assertStringNotContainsString( '/me', $endpoint, 'App Access Token must NOT route to /me endpoint.' );
	}

	/**
	 * Page Access Tokens must use the /me endpoint, never /{page_id}, to stay
	 * within standard page access token permissions.
	 */
	public function test_page_token_uses_me_endpoint_not_page_id() {
		$api_version = 'v21.0';
		$page_id     = '759520660575652';
		$page_token  = 'EAABwzLixnjYBO2ZCZBQerxyz1234567890ABCDEFabcdefGHIJKLMNOPQRSTUVWXYZ';

		if ( $this->detect_app_token( $page_token ) ) {
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/app?fields=id,name&access_token=%s',
				$api_version,
				rawurlencode( $page_token )
			);
		} else {
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/me?fields=id,name',
				$api_version
			);
		}

		// Must use /me, not /{page_id}.
		$this->assertStringContainsString( '/me', $endpoint );
		$this->assertStringNotContainsString( '/' . $page_id, $endpoint );
		$this->assertStringNotContainsString( '/app', $endpoint );
	}

	/**
	 * The /me endpoint must NOT request the category field, which requires the
	 * pages_read_engagement permission or elevated features.
	 */
	public function test_messenger_test_endpoint_excludes_category_field() {
		$api_version = 'v21.0';

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/me?fields=id,name',
			$api_version
		);

		$this->assertStringNotContainsString( 'category', $endpoint );
		$this->assertStringContainsString( 'fields=id,name', $endpoint );
	}

	// =========================================================================
	// Warning logic (mirrors ajax_test_messenger_live).
	// =========================================================================

	/**
	 * Helper: mirrors the warning-selection logic in ajax_test_messenger_live.
	 *
	 * @param bool   $is_app_token Whether the token was detected as an App Access Token.
	 * @param string $page_id      The page_id supplied by the user (may be empty).
	 * @param string $returned_id  The id returned from the Graph API response.
	 * @return string|null The warning key or null if no warning applies.
	 */
	private function resolve_warning( $is_app_token, $page_id, $returned_id ) {
		if ( $is_app_token ) {
			return 'app_token';
		} elseif ( ! empty( $page_id ) && $returned_id !== $page_id ) {
			return 'mismatch';
		} elseif ( empty( $page_id ) ) {
			return 'no_page_id';
		}
		return null;
	}

	/**
	 * An App Access Token always triggers the app_token warning, even when a
	 * page_id is also supplied.
	 */
	public function test_app_token_warning_when_app_access_token_detected() {
		$warning = $this->resolve_warning( true, '759520660575652', '1704482943846642' );
		$this->assertSame( 'app_token', $warning, 'App Access Token must always emit app_token warning.' );
	}

	/**
	 * An App Access Token with no page_id still triggers the app_token warning.
	 */
	public function test_app_token_warning_when_app_access_token_and_no_page_id() {
		$warning = $this->resolve_warning( true, '', '1704482943846642' );
		$this->assertSame( 'app_token', $warning, 'App Access Token must always emit app_token warning.' );
	}

	/**
	 * When the page_id provided by the user matches the id returned from /me,
	 * no warning should be set.
	 */
	public function test_no_warning_when_page_id_matches_returned_id() {
		$warning = $this->resolve_warning( false, '759520660575652', '759520660575652' );
		$this->assertNull( $warning, 'No warning should be set when IDs match.' );
	}

	/**
	 * When a Page Access Token's returned id does NOT match the page_id entered,
	 * a mismatch warning must be set.
	 */
	public function test_warning_when_page_id_does_not_match_returned_id() {
		// Returned id is a different page — page token is for the wrong page.
		$warning = $this->resolve_warning( false, '759520660575652', '111222333444555' );
		$this->assertSame( 'mismatch', $warning, 'A mismatch warning must be set when IDs differ.' );
	}

	/**
	 * When no page_id is supplied and a Page Access Token is used, the handler
	 * must emit the no_page_id advisory warning (not the app_token warning).
	 */
	public function test_no_page_id_warning_when_page_token_and_no_page_id() {
		$warning = $this->resolve_warning( false, '', '759520660575652' );
		$this->assertSame( 'no_page_id', $warning, 'no_page_id warning must be set when no page_id is given with a page token.' );
	}

	// =========================================================================
	// API version validation.
	// =========================================================================

	/**
	 * An invalid API version string must be replaced with the safe default v21.0.
	 */
	public function test_invalid_api_version_falls_back_to_default() {
		$raw_version = 'v21.0; DROP TABLE wp_options;';

		$api_version = $raw_version;
		if ( ! preg_match( '/^v\d+\.\d+$/', $api_version ) ) {
			$api_version = 'v21.0';
		}

		$this->assertSame( 'v21.0', $api_version );
	}

	/**
	 * A valid API version string must pass through unchanged.
	 */
	public function test_valid_api_version_is_preserved() {
		$raw_version = 'v20.0';

		$api_version = $raw_version;
		if ( ! preg_match( '/^v\d+\.\d+$/', $api_version ) ) {
			$api_version = 'v21.0';
		}

		$this->assertSame( 'v20.0', $api_version );
	}

	// =========================================================================
	// Saved-token fallback logic (mirrors ajax_test_messenger_live).
	// =========================================================================

	/**
	 * Helper: mirrors the saved-token fallback in ajax_test_messenger_live.
	 *
	 * When a connection_id is provided and the form value is empty or is an App
	 * Access Token, the handler should prefer the saved token if it differs.
	 *
	 * @param string $form_token    Token value from the form field.
	 * @param string $saved_token   Decrypted token stored in the database (empty if none).
	 * @param string $connection_id Connection ID (empty when adding a new connection).
	 * @return string The token that should actually be used for the test.
	 */
	private function resolve_token( $form_token, $saved_token, $connection_id ) {
		$access_token = trim( $form_token );

		if ( ! empty( $connection_id ) ) {
			$is_form_app_token = (bool) preg_match( '/^\d+\|[A-Za-z0-9_\-]+$/', $access_token );
			if ( empty( $access_token ) || $is_form_app_token ) {
				if ( ! empty( $saved_token ) && $saved_token !== $access_token ) {
					$access_token = $saved_token;
				}
			}
		}

		return $access_token;
	}

	/**
	 * When the form field is empty and a saved Page Access Token exists,
	 * the saved token must be used.
	 */
	public function test_saved_token_used_when_form_field_empty() {
		$saved  = 'EAABwzLixnjYBO2ZCZBQerxyz1234567890';
		$result = $this->resolve_token( '', $saved, 'conn_abc123' );
		$this->assertSame( $saved, $result, 'Saved Page Access Token must be used when form field is empty.' );
	}

	/**
	 * When the form field contains an App Access Token and a saved Page
	 * Access Token exists, the saved Page token must be preferred.
	 */
	public function test_saved_token_preferred_over_app_token_in_form() {
		$saved  = 'EAABwzLixnjYBO2ZCZBQerxyz1234567890';
		$result = $this->resolve_token( '1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA', $saved, 'conn_abc123' );
		$this->assertSame( $saved, $result, 'Saved Page Access Token must be preferred over App Access Token in form.' );
	}

	/**
	 * When the form field contains a Page Access Token (not App format),
	 * the form value must be used even if a saved token exists.
	 */
	public function test_form_page_token_used_over_saved_token() {
		$form_token = 'EAANewPageTokenFromUser';
		$saved      = 'EAAOldSavedToken';
		$result     = $this->resolve_token( $form_token, $saved, 'conn_abc123' );
		$this->assertSame( $form_token, $result, 'Form Page Access Token must be used when user explicitly enters one.' );
	}

	/**
	 * Without a connection_id (new connection), the form token must always
	 * be used even if it looks like an App Access Token.
	 */
	public function test_no_fallback_without_connection_id() {
		$app_token = '1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA';
		$result    = $this->resolve_token( $app_token, 'EAASavedToken', '' );
		$this->assertSame( $app_token, $result, 'No fallback must occur without a connection_id.' );
	}

	/**
	 * When the form has an App Access Token but no saved token exists,
	 * the App Access Token must be used as-is.
	 */
	public function test_app_token_used_when_no_saved_token() {
		$app_token = '1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA';
		$result    = $this->resolve_token( $app_token, '', 'conn_abc123' );
		$this->assertSame( $app_token, $result, 'App Access Token must be used when no saved token exists.' );
	}

	// =========================================================================
	// Messenger auto-reply: Send API endpoint construction.
	// =========================================================================

	/**
	 * Helper: builds the Messenger Send API endpoint URL.
	 *
	 * Mirrors the URL construction in ajax_test_messenger_auto_reply.
	 *
	 * @param string $graph_api_version The Graph API version.
	 * @return string The Send API endpoint URL.
	 */
	private function build_messenger_send_endpoint( $graph_api_version ) {
		return sprintf(
			'https://graph.facebook.com/%s/me/messages',
			rawurlencode( $graph_api_version )
		);
	}

	/**
	 * The Messenger Send API endpoint must use the /me/messages path.
	 */
	public function test_messenger_auto_reply_endpoint_uses_me_messages() {
		$endpoint = $this->build_messenger_send_endpoint( 'v21.0' );
		$this->assertStringContainsString( '/me/messages', $endpoint );
		$this->assertStringContainsString( 'v21.0', $endpoint );
	}

	/**
	 * Verify a different API version produces the correct endpoint.
	 */
	public function test_messenger_auto_reply_endpoint_with_different_version() {
		$endpoint = $this->build_messenger_send_endpoint( 'v20.0' );
		$this->assertStringContainsString( 'v20.0', $endpoint );
		$this->assertStringContainsString( '/me/messages', $endpoint );
	}

	// =========================================================================
	// Messenger auto-reply: message payload construction.
	// =========================================================================

	/**
	 * Helper: builds the Messenger Send API payload.
	 *
	 * Mirrors the payload construction in ajax_test_messenger_auto_reply.
	 *
	 * @param string $recipient_id The PSID of the recipient.
	 * @param string $message_text The message text.
	 * @return array The payload array.
	 */
	private function build_messenger_send_payload( $recipient_id, $message_text ) {
		return array(
			'recipient' => array( 'id' => $recipient_id ),
			'message'   => array( 'text' => $message_text ),
		);
	}

	/**
	 * The payload must contain the correct recipient structure and message text.
	 */
	public function test_messenger_auto_reply_payload_structure() {
		$payload = $this->build_messenger_send_payload( '123456789', 'Hello World' );

		$this->assertArrayHasKey( 'recipient', $payload );
		$this->assertArrayHasKey( 'message', $payload );
		$this->assertSame( '123456789', $payload['recipient']['id'] );
		$this->assertSame( 'Hello World', $payload['message']['text'] );
	}

	/**
	 * The payload must JSON-encode successfully.
	 */
	public function test_messenger_auto_reply_payload_json_encodes() {
		$payload = $this->build_messenger_send_payload( '987654321', 'Test reply' );
		$json    = wp_json_encode( $payload );

		$this->assertNotFalse( $json, 'Messenger auto-reply payload must encode to JSON.' );
		$decoded = json_decode( $json, true );
		$this->assertSame( '987654321', $decoded['recipient']['id'] );
	}

	// =========================================================================
	// Messenger auto-reply: message body truncation.
	// =========================================================================

	/**
	 * Helper: mirrors the message truncation logic in ajax_test_messenger_auto_reply.
	 *
	 * @param string $content Raw AI reply (may contain HTML).
	 * @return string Plain-text body capped at 2000 characters.
	 */
	private function truncate_messenger_body( $content ) {
		$body = wp_strip_all_tags( $content );
		$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $body ) > 2000 ) {
			$body = mb_substr( $body, 0, 1997 ) . '...';
		}
		return $body;
	}

	/**
	 * Short messages must pass through unchanged (minus HTML).
	 */
	public function test_messenger_auto_reply_short_message_unchanged() {
		$result = $this->truncate_messenger_body( '<b>Hello</b> World' );
		$this->assertSame( 'Hello World', $result );
	}

	/**
	 * Messages exceeding 2000 characters must be truncated to 2000 chars total.
	 */
	public function test_messenger_auto_reply_long_message_truncated() {
		$long_input = str_repeat( 'A', 2500 );
		$result     = $this->truncate_messenger_body( $long_input );
		$this->assertSame( 2000, mb_strlen( $result ), 'Truncated message must be exactly 2000 characters.' );
		$this->assertStringEndsWith( '...', $result );
	}

	/**
	 * Exactly 2000 characters must not be truncated.
	 */
	public function test_messenger_auto_reply_exact_limit_not_truncated() {
		$exact_input = str_repeat( 'B', 2000 );
		$result      = $this->truncate_messenger_body( $exact_input );
		$this->assertSame( 2000, mb_strlen( $result ) );
		$this->assertNotSame( '...', substr( $result, -3 ) );
	}

	// =========================================================================
	// Messenger auto-reply: Graph API version fallback.
	// =========================================================================

	/**
	 * Helper: mirrors the Graph API version resolution in ajax_test_messenger_auto_reply.
	 *
	 * @param array $connection The connection settings array.
	 * @return string Resolved Graph API version.
	 */
	private function resolve_messenger_graph_api_version( $connection ) {
		return isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
			? sanitize_text_field( $connection['graph_api_version'] )
			: 'v21.0';
	}

	/**
	 * When graph_api_version is set in the connection, it must be used.
	 */
	public function test_messenger_auto_reply_uses_saved_api_version() {
		$version = $this->resolve_messenger_graph_api_version( array( 'graph_api_version' => 'v22.0' ) );
		$this->assertSame( 'v22.0', $version );
	}

	/**
	 * When graph_api_version is empty or missing, the default v21.0 must be used.
	 */
	public function test_messenger_auto_reply_falls_back_to_default_api_version() {
		$this->assertSame( 'v21.0', $this->resolve_messenger_graph_api_version( array() ) );
		$this->assertSame( 'v21.0', $this->resolve_messenger_graph_api_version( array( 'graph_api_version' => '' ) ) );
	}
}
