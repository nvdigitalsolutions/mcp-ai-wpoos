<?php
/**
 * Pro SPA Loader — Registers the React Single Page Application admin page
 * and enqueues its assets. The SPA replaces the traditional PHP admin UI
 * with a Zed-inspired React interface (ThreadsSidebar, AgentPanel, Command Palette).
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Loader
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_SPA_Loader {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.7.0
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Register the SPA admin menu page and enqueue hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 20 );
	}

	/**
	 * Add the SPA admin page as a top-level menu item.
	 *
	 * Registered with a different slug than the base plugin's admin page
	 * so both can coexist. The old page remains at 'wp-mcp-ai'.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function add_admin_page() {
		$this->hook_suffix = add_menu_page(
			__( 'NV oOS', 'mcp-ai-wpoos' ),
			__( 'NV oOS', 'mcp-ai-wpoos' ),
			'read',
			'wp-mcp-ai-spa',
			array( $this, 'render' ),
			'dashicons-superhero',
			30
		);

		add_action( 'load-' . $this->hook_suffix, array( $this, 'enqueue' ) );
	}

	/**
	 * Render the SPA root div.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function render() {
		echo '<div id="wp-mcp-ai-spa-root"></div>';
	}

	/**
	 * Enqueue SPA JavaScript and CSS assets.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function enqueue() {
		$dist_dir = WP_MCP_AI_PRO_PATH . 'assets/spa/dist/';
		$dist_url = WP_MCP_AI_PRO_URL . 'assets/spa/dist/';

		// Check if built assets exist.
		$asset_file = $dist_dir . 'index.asset.php';
		$js_file    = $dist_dir . 'spa-bundle.js';
		$css_file   = $dist_dir . 'spa-bundle.css';

		if ( ! file_exists( $asset_file ) || ! file_exists( $js_file ) ) {
			// Show a notice if built assets are missing (development mode).
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'NV oOS Pro SPA assets not found. Run `npm run build` in addons/pro/assets/spa/.', 'mcp-ai-wpoos' )
					);
				}
			);
			return;
		}

		$asset_data = include $asset_file;

		wp_enqueue_script(
			'wp-mcp-ai-spa',
			$js_file,
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-spa',
				$css_file,
				array( 'wp-components' ),
				$asset_data['version']
			);
		}

		// Pass data to the SPA.
		wp_localize_script(
			'wp-mcp-ai-spa',
			'wpMcpAiPro',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'restUrl'      => rest_url(),
				'sseUrl'       => rest_url( 'mcp-ai/v1/sse' ),
				'bootstrapUrl' => rest_url( 'mcp-ai-pro/v1/spa/bootstrap' ),
				'userId'       => get_current_user_id(),
				'isAdmin'      => current_user_can( 'manage_options' ),
				'i18n'         => array(
					'send'              => __( 'Send', 'mcp-ai-wpoos' ),
					'sending'           => __( 'Sending…', 'mcp-ai-wpoos' ),
					'stop'              => __( 'Stop', 'mcp-ai-wpoos' ),
					'newThread'         => __( 'New Thread', 'mcp-ai-wpoos' ),
					'archiveThread'     => __( 'Archive', 'mcp-ai-wpoos' ),
					'restoreThread'     => __( 'Restore', 'mcp-ai-wpoos' ),
					'compactThread'     => __( 'Compact', 'mcp-ai-wpoos' ),
					'reviewChanges'     => __( 'Review Changes', 'mcp-ai-wpoos' ),
					'restoreCheckpoint' => __( 'Restore Checkpoint', 'mcp-ai-wpoos' ),
					'switchModel'       => __( 'Switch Model', 'mcp-ai-wpoos' ),
					'switchProfile'     => __( 'Switch Profile', 'mcp-ai-wpoos' ),
					'manageProfiles'    => __( 'Manage Profiles', 'mcp-ai-wpoos' ),
					'threadHistory'     => __( 'Thread History', 'mcp-ai-wpoos' ),
					'error'             => __( 'An error occurred.', 'mcp-ai-wpoos' ),
					'typeMessage'       => __( 'Type a message…', 'mcp-ai-wpoos' ),
					'typeCommand'       => __( 'Type a command…', 'mcp-ai-wpoos' ),
					'noResults'         => __( 'No results found.', 'mcp-ai-wpoos' ),
					'agentWriting'      => __( 'Agent is writing…', 'mcp-ai-wpoos' ),
					'agentDone'         => __( 'Agent finished', 'mcp-ai-wpoos' ),
					'tokenCount'        => __( 'Tokens', 'mcp-ai-wpoos' ),
					'contextWarning'    => __( 'Approaching context limit. Consider compacting.', 'mcp-ai-wpoos' ),
				),
			)
		);

		// Set script type to module for ES module support.
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( 'wp-mcp-ai-spa' === $handle ) {
					return str_replace( '<script ', '<script type="module" ', $tag );
				}
				return $tag;
			},
			10,
			2
		);
	}
}
