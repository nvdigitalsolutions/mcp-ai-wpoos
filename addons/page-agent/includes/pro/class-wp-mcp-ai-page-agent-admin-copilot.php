<?php
/**
 * Admin Dashboard Copilot (Pro Feature — Phase 2)
 *
 * Adds a floating copilot button to the WordPress admin bar that
 * launches the Page Agent for controlling the admin dashboard via
 * natural language.
 *
 * Gated by: defined( 'WP_MCP_AI_PRO_ACTIVE' ) && WP_MCP_AI_PRO_ACTIVE
 *
 * @package NV_oOS_Page_Agent
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin copilot for Page Agent.
 *
 * @since 0.2.0
 */
class WP_MCP_AI_Page_Agent_Admin_Copilot {

	/**
	 * Option key for admin copilot settings.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_page_agent_admin_settings';

	/**
	 * Constructor — registers WordPress hooks.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_copilot' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_copilot_button' ), 100 );
	}

	/**
	 * Check if the admin copilot is enabled.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	private function is_enabled() {
		if ( ! WP_MCP_AI_Page_Agent::is_enabled() ) {
			return false;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['admin_enabled'] ) || ! empty( $settings['admin_enabled'] );
	}

	/**
	 * Check if the current user has an allowed role.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	private function is_allowed_role() {
		$settings      = get_option( self::OPTION_KEY, array() );
		$allowed_roles = isset( $settings['allowed_roles'] )
			? (array) $settings['allowed_roles']
			: array( 'administrator' );

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return (bool) array_intersect( $allowed_roles, (array) $user->roles );
	}

	/**
	 * Enqueue the admin copilot script.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_copilot( $hook ) {
		if ( ! $this->is_allowed_role() ) {
			return;
		}

		wp_enqueue_script(
			'nvoos-page-agent-admin',
			NVOOS_PAGE_AGENT_URL . 'assets/js/page-agent-admin-copilot.min.js',
			array( WP_MCP_AI_Page_Agent::SCRIPT_HANDLE_BRIDGE ),
			NVOOS_PAGE_AGENT_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-page-agent-admin',
			'wpMcpAiPageAgentAdmin',
			array(
				'currentPage'         => $hook,
				'pageTitle'           => get_admin_page_title(),
				'allowedActions'      => $this->get_allowed_actions( $hook ),
				'confirmDestructive'  => (bool) get_option( 'nvoos_page_agent_confirm_destructive', true ),
				'restUrl'             => rest_url( 'nvoos-page-agent/v1' ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Get allowed actions for the current admin page.
	 *
	 * Maps admin page hooks to safe action categories.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook The admin page hook.
	 * @return array
	 */
	private function get_allowed_actions( $hook ) {
		// By default, allow read-only actions everywhere.
		$allowed = array( 'read', 'navigate' );

		// Allow write actions on appropriate pages.
		$write_pages = array(
			'post.php',
			'post-new.php',
			'edit.php',
			'upload.php',
			'user-edit.php',
			'profile.php',
			'options-general.php',
		);

		foreach ( $write_pages as $write_page ) {
			if ( false !== strpos( $hook, $write_page ) ) {
				$allowed[] = 'write';
				break;
			}
		}

		return $allowed;
	}

	/**
	 * Add the copilot button to the admin bar.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar instance.
	 * @return void
	 */
	public function add_copilot_button( $admin_bar ) {
		if ( ! $this->is_allowed_role() ) {
			return;
		}

		$admin_bar->add_menu(
			array(
				'id'    => 'nvoos-page-agent-copilot',
				'title' => '<span class="ab-icon dashicons dashicons-admin-generic" style="top:2px;"></span> '
					. esc_html__( 'Page Agent', 'nvoos-page-agent' ),
				'href'  => '#',
				'meta'  => array(
					'class' => 'nvoos-page-agent-copilot-button',
					'title' => esc_attr__( 'Open Page Agent Copilot', 'nvoos-page-agent' ),
				),
			)
		);
	}
}
