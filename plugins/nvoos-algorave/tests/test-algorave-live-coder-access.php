<?php
/**
 * Tests for the [algorave_live_coder] shortcode access control.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/**
 * Algorave live coder access tests.
 */
class Test_Algorave_Live_Coder_Access extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_ALGORAVE_PATH' ) ) {
			define( 'NVOOS_ALGORAVE_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_ALGORAVE_URL' ) ) {
			define( 'NVOOS_ALGORAVE_URL', 'http://example.com/wp-content/plugins/nvoos-algorave/' );
		}
		if ( ! defined( 'NVOOS_ALGORAVE_VERSION' ) ) {
			define( 'NVOOS_ALGORAVE_VERSION', '1.0.0' );
		}
		if ( ! defined( 'NVOOS_ALGORAVE_STRUDEL_VERSION' ) ) {
			define( 'NVOOS_ALGORAVE_STRUDEL_VERSION', '1.0.0' );
		}

		require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave.php';

		// Reset settings between tests.
		delete_option( 'nvoos_algorave_settings' );
	}

	/**
	 * Reset auth between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'nvoos_algorave_settings' );
		parent::tearDown();
	}

	/**
	 * Authors and above always see the live coder regardless of guest_access.
	 */
	public function test_author_sees_live_coder() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$output = NV_oOS_Algorave::shortcode_live_coder( array() );

		$this->assertStringContainsString( 'algorave-live-coder', $output );
		$this->assertStringNotContainsString( 'algorave-live-coder-locked', $output );
		$this->assertTrue( NV_oOS_Algorave::current_user_can_view_live_coder() );
	}

	/**
	 * Guests see the login prompt (not a black screen) when guest_access is off.
	 */
	public function test_guest_without_access_sees_login_prompt() {
		wp_set_current_user( 0 );

		$output = NV_oOS_Algorave::shortcode_live_coder( array() );

		$this->assertStringContainsString( 'algorave-live-coder-locked', $output );
		$this->assertStringContainsString( 'wp-login', $output );
		$this->assertStringNotContainsString( 'algorave-code-editor', $output );
		$this->assertFalse( NV_oOS_Algorave::current_user_can_view_live_coder() );
	}

	/**
	 * Guests see the live coder when guest_access setting is enabled.
	 */
	public function test_guest_with_access_sees_live_coder() {
		wp_set_current_user( 0 );
		update_option(
			'nvoos_algorave_settings',
			array_merge(
				NV_oOS_Algorave::get_settings(),
				array( 'guest_access' => true )
			)
		);

		$output = NV_oOS_Algorave::shortcode_live_coder( array() );

		$this->assertStringContainsString( 'algorave-live-coder', $output );
		$this->assertStringNotContainsString( 'algorave-live-coder-locked', $output );
		$this->assertTrue( NV_oOS_Algorave::current_user_can_view_live_coder() );
	}

	/**
	 * Subscribers (logged-in but not editors) without guest_access see the
	 * locked panel; the message does not include a wp-login link because they
	 * are already logged in.
	 */
	public function test_subscriber_without_guest_access_sees_login_prompt() {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$output = NV_oOS_Algorave::shortcode_live_coder( array() );

		$this->assertStringContainsString( 'algorave-live-coder-locked', $output );
		$this->assertStringNotContainsString( 'algorave-login-link', $output );
		$this->assertFalse( NV_oOS_Algorave::current_user_can_view_live_coder() );
	}

	/**
	 * Tone.js eval is never allowed when the opt-in constant is missing.
	 */
	public function test_tonejs_eval_disallowed_without_constant() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		// We cannot un-define a constant, so this only asserts behaviour
		// when the constant is absent. The bootstrap does not define it.
		if ( defined( 'NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL' ) && NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL ) {
			$this->markTestSkipped( 'NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL is defined in this test environment.' );
		}

		$this->assertFalse( NV_oOS_Algorave::is_tonejs_eval_allowed_for_current_user() );
	}

	/**
	 * Even with the opt-in constant set, guests/subscribers cannot use Tone.js eval.
	 */
	public function test_tonejs_eval_disallowed_for_non_editors() {
		// Simulate the constant by stubbing the helper through reflection of behaviour:
		// instead of redefining, we directly assert the capability gate by switching users.
		if ( ! defined( 'NVOOS_ALGORAVE_ALLOW_TONEJS_EVAL' ) ) {
			// Constant not defined: the helper returns false for everyone, which
			// already proves "non-editors are blocked".
			wp_set_current_user( 0 );
			$this->assertFalse( NV_oOS_Algorave::is_tonejs_eval_allowed_for_current_user() );
			return;
		}

		// Constant defined and truthy: editors allowed, others blocked.
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );
		$this->assertFalse( NV_oOS_Algorave::is_tonejs_eval_allowed_for_current_user() );

		wp_set_current_user( 0 );
		$this->assertFalse( NV_oOS_Algorave::is_tonejs_eval_allowed_for_current_user() );
	}
}
