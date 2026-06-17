<?php
/**
 * Admin page tests.
 *
 * @package NV_oOS_Chat_Spa
 */
class Test_Chat_Spa_Admin_Page extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CHAT_SPA_VERSION' ) ) {
			define( 'NVOOS_CHAT_SPA_VERSION', '0.6.0' );
		}
		if ( ! defined( 'NVOOS_CHAT_SPA_PATH' ) ) {
			define( 'NVOOS_CHAT_SPA_PATH', dirname( __DIR__ ) . '/' );
		}
		require_once NVOOS_CHAT_SPA_PATH . 'includes/admin/class-nvoos-chat-spa-admin-page.php';
	}

	/**
	 * Test that register hooks into admin_menu action.
	 */
	public function test_register_hooks_admin_menu() {
		// Re-register against a clean global state.
		remove_all_actions( 'admin_menu' );
		NV_oOS_Chat_Spa_Admin_Page::register();
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'NV_oOS_Chat_Spa_Admin_Page', 'add_menu' ) )
		);
	}

	/**
	 * Test that render requires manage_options capability.
	 */
	public function test_render_requires_capability() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		// Render must call wp_die() for unprivileged users.
		add_filter( 'wp_die_handler', array( $this, 'capture_wp_die' ) );
		try {
			NV_oOS_Chat_Spa_Admin_Page::render();
			$this->fail( 'Expected wp_die() for users without manage_options.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'permission', strtolower( $e->getMessage() ) );
		}
		remove_filter( 'wp_die_handler', array( $this, 'capture_wp_die' ) );
	}

	/**
	 * Capture wp_die calls for testing.
	 */
	public function capture_wp_die() {
		return function ( $message ) {
			throw new Exception( is_string( $message ) ? wp_kses_post( $message ) : 'wp_die' );
		};
	}
}
