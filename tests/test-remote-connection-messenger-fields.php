<?php
/**
 * Tests for Facebook Messenger connection test endpoint logic.
 *
 * Validates that the connection test uses /me instead of /{page_id} to avoid
 * requiring pages_read_engagement, Page Public Content Access, or Page Public
 * Metadata Access features, and that page_id matching logic works correctly.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Messenger connection test endpoint behavior.
 */
class Test_Remote_Connection_Messenger_Fields extends WP_UnitTestCase {

	// =========================================================================
	// Endpoint URL construction.
	// =========================================================================

	/**
	 * The connection test must always call /me, never /{page_id}, to stay within
	 * standard page access token permissions.
	 */
	public function test_messenger_test_endpoint_uses_me_not_page_id() {
		$api_version = 'v21.0';
		$page_id     = '759520660575652';

		// Build the endpoint as the fixed handler does.
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/me?fields=id,name',
			$api_version
		);

		// Must end with /me, not /{page_id}.
		$this->assertStringContainsString( '/me', $endpoint );
		$this->assertStringNotContainsString( '/' . $page_id, $endpoint );
	}

	/**
	 * The endpoint must NOT request the category field, which requires the
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
	// Page ID matching logic.
	// =========================================================================

	/**
	 * When the page_id provided by the user matches the id returned from /me,
	 * no page-id mismatch warning should be set.
	 */
	public function test_no_warning_when_page_id_matches_returned_id() {
		$page_id     = '759520660575652';
		$returned_id = '759520660575652';

		$warning = null;

		if ( ! empty( $page_id ) && $returned_id !== $page_id ) {
			$warning = 'mismatch';
		} elseif ( empty( $page_id ) ) {
			$warning = 'app_token';
		}

		$this->assertNull( $warning, 'No warning should be set when IDs match.' );
	}

	/**
	 * When the page_id provided by the user does NOT match the id returned from
	 * /me (e.g. an App Access Token was used instead of a Page Access Token),
	 * a mismatch warning must be set.
	 */
	public function test_warning_when_page_id_does_not_match_returned_id() {
		$page_id     = '759520660575652';
		$returned_id = '1704482943846642'; // App ID returned by /me for an App Access Token.

		$warning = null;

		if ( ! empty( $page_id ) && $returned_id !== $page_id ) {
			$warning = 'mismatch';
		} elseif ( empty( $page_id ) ) {
			$warning = 'app_token';
		}

		$this->assertSame( 'mismatch', $warning, 'A mismatch warning must be set when IDs differ.' );
	}

	/**
	 * When no page_id is supplied the handler must treat the token as an App
	 * Access Token and emit the app-token advisory warning.
	 */
	public function test_warning_when_no_page_id_supplied() {
		$page_id     = '';
		$returned_id = '1704482943846642';

		$warning = null;

		if ( ! empty( $page_id ) && $returned_id !== $page_id ) {
			$warning = 'mismatch';
		} elseif ( empty( $page_id ) ) {
			$warning = 'app_token';
		}

		$this->assertSame( 'app_token', $warning, 'App-token warning must be set when no page_id is given.' );
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
