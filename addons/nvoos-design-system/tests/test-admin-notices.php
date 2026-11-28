<?php
/**
 * Test: Admin notice carrier across admin-post redirects.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for NV_oOS_Design_System_Plugin::consume_admin_notices().
 *
 * `add_settings_error()` only appends to the in-request global, and core only
 * replays the core `settings_errors` transient when the URL carries
 * `settings-updated`. The plugin therefore stores its notices under a
 * user-scoped transient and drains them on the next page load.
 *
 * @covers NV_oOS_Design_System_Plugin::consume_admin_notices
 */
class Test_Admin_Notices extends WP_UnitTestCase {

	/**
	 * User-scoped key for a given user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function key_for( $user_id ) {
		return NV_oOS_Design_System_Plugin::ADMIN_NOTICE_KEY . '_' . $user_id;
	}

	/**
	 * Clean up transients between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array( 1, 2 ) as $user_id ) {
			delete_transient( $this->key_for( $user_id ) );
		}
		parent::tearDown();
	}

	/**
	 * The storage key is prefixed for the WordPress.org guidelines.
	 *
	 * @return void
	 */
	public function test_notice_key_is_prefixed() {
		$this->assertStringStartsWith( 'nvoos_nds_', NV_oOS_Design_System_Plugin::ADMIN_NOTICE_KEY );
	}

	/**
	 * Notices are returned once and the transient is cleared.
	 *
	 * @return void
	 */
	public function test_consume_returns_and_clears() {
		wp_set_current_user( 1 );

		set_transient(
			$this->key_for( 1 ),
			array(
				array(
					'setting' => 'nvoos_nds_email',
					'code'    => 'import_failed',
					'message' => 'Email template contains active content.',
					'type'    => 'error',
				),
			),
			60
		);

		$notices = NV_oOS_Design_System_Plugin::consume_admin_notices();

		$this->assertCount( 1, $notices );
		$this->assertSame( 'Email template contains active content.', $notices[0]['message'] );
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertFalse( get_transient( $this->key_for( 1 ) ) );
		$this->assertSame( array(), NV_oOS_Design_System_Plugin::consume_admin_notices() );
	}

	/**
	 * One admin never sees another admin's notices.
	 *
	 * @return void
	 */
	public function test_notices_are_user_scoped() {
		set_transient(
			$this->key_for( 1 ),
			array(
				array(
					'setting' => 'nvoos_nds_email',
					'message' => 'For user one only.',
					'type'    => 'success',
				),
			),
			60
		);

		wp_set_current_user( 2 );
		$this->assertSame( array(), NV_oOS_Design_System_Plugin::consume_admin_notices() );

		wp_set_current_user( 1 );
		$this->assertCount( 1, NV_oOS_Design_System_Plugin::consume_admin_notices() );
	}

	/**
	 * A missing transient yields an empty array, not a warning.
	 *
	 * @return void
	 */
	public function test_missing_transient_is_empty_array() {
		wp_set_current_user( 1 );
		$this->assertSame( array(), NV_oOS_Design_System_Plugin::consume_admin_notices() );
	}
}
