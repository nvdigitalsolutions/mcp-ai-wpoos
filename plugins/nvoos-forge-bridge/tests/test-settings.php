<?php
/**
 * Settings tests — option plumbing, sanitization seams, and configuration
 * gating.
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Settings;

/**
 * Settings suite.
 */
class Test_Forge_Bridge_Settings extends WP_UnitTestCase {

	/**
	 * Reset options + the webhook debounce between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( Settings::option_keys() as $option ) {
			delete_option( $option );
		}
		delete_transient( 'nvoos_forge_bridge_webhook_sent' );
		\NvoosForgeBridge\Rebuild_Webhook::reset_pending();
	}

	/**
	 * The webhook is disabled and unconfigured out of the box.
	 */
	public function test_defaults_are_off(): void {
		$this->assertFalse( Settings::rebuild_enabled() );
		$this->assertFalse( Settings::rebuild_configured() );
		$this->assertSame( '', Settings::rebuild_url() );
		$this->assertSame( 'nvoos-forge-content-changed', Settings::rebuild_event() );
	}

	/**
	 * All four pieces are required for the configured state.
	 */
	public function test_configured_requires_all_pieces(): void {
		update_option( Settings::OPTION_REBUILD_ENABLED, '1' );
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'token' );
		$this->assertFalse( Settings::rebuild_configured() ); // Secret missing.

		update_option( Settings::OPTION_REBUILD_SECRET, 'secret' );
		$this->assertTrue( Settings::rebuild_configured() );
	}

	/**
	 * URLs are trimmed of trailing slashes on read.
	 */
	public function test_url_is_untrailingslashed(): void {
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches/' );
		$this->assertSame( 'https://api.github.com/repos/o/r/dispatches', Settings::rebuild_url() );
	}

	/**
	 * The event type falls back to the default when empty.
	 */
	public function test_event_default_when_empty(): void {
		update_option( Settings::OPTION_REBUILD_EVENT, '' );
		$this->assertSame( Settings::DEFAULT_EVENT, Settings::rebuild_event() );
	}

	/**
	 * Helper — turn the save redirect into a catchable exception BEFORE any
	 * header output (the wp_redirect filter runs before the header() call).
	 *
	 * @return void
	 */
	private function expect_redirect(): void {
		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new \WPDieException( (string) $location );
			}
		);
	}

	/**
	 * Saving with an invalid URL stores the empty string, never garbage.
	 */
	public function test_save_rejects_invalid_url(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['rebuild_url']   = 'not a url';
		$_POST['rebuild_event'] = 'My Event Name!';
		$_POST['rebuild_enabled'] = '1';
		$_REQUEST['_wpnonce']   = wp_create_nonce( Settings::nonce_action() );
		$_POST['_wpnonce']      = $_REQUEST['_wpnonce'];
		$this->expect_redirect();

		try {
			Settings::handle_save();
		} catch ( \WPDieException $e ) {
			// handle_save redirects via wp_safe_redirect + exit — the redirect
			// is the success path; assert the stored state below.
		}

		$this->assertSame( '', Settings::rebuild_url() );
		$this->assertSame( 'my-event-name', Settings::rebuild_event() );
		$this->assertTrue( Settings::rebuild_enabled() );
	}

	/**
	 * The secret and token are write-only — empty POST fields keep stored
	 * values (the form never echoes them).
	 */
	public function test_secret_and_token_are_write_only(): void {
		update_option( Settings::OPTION_REBUILD_SECRET, 'stored-secret' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'stored-token' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['rebuild_url']    = 'https://api.github.com/repos/o/r/dispatches';
		$_POST['rebuild_secret'] = '';
		$_POST['rebuild_token']  = '';
		$_REQUEST['_wpnonce']    = wp_create_nonce( Settings::nonce_action() );
		$_POST['_wpnonce']       = $_REQUEST['_wpnonce'];
		$this->expect_redirect();

		try {
			Settings::handle_save();
		} catch ( \WPDieException $e ) {
			// Redirect = success path.
		}

		$this->assertSame( 'stored-secret', Settings::rebuild_secret() );
		$this->assertSame( 'stored-token', Settings::rebuild_token() );
	}

	/**
	 * The save handler refuses users without manage_options.
	 */
	public function test_save_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings::nonce_action() );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];

		$this->expectException( \WPDieException::class );
		Settings::handle_save();
	}
}
