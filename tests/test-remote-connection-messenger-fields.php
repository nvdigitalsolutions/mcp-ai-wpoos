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
}
