<?php
/**
 * NV oOS Chat SPA — Admin embed page.
 *
 * Adds a `Tools → NV oOS Chat` page that mounts the SPA against the
 * default assistant for quick smoke-testing without needing to publish a
 * shortcode on a public post. All capability checks are inherited from
 * `manage_options`; the page itself just renders the existing shortcode.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin embed page for the Chat SPA.
 *
 * @since 0.2.0
 */
class NV_oOS_Chat_Spa_Admin_Page {

	/**
	 * Capability required to view the embed.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Menu slug for the admin page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'nvoos-chat-spa';

	/**
	 * Register the admin menu hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Register the Tools sub-menu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_management_page(
			__( 'NV oOS Chat', 'nvoos-chat-spa' ),
			__( 'NV oOS Chat', 'nvoos-chat-spa' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the admin page — a thin wrapper around the shortcode so the
	 * SPA loads the same way it does on the front-end.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-chat-spa' ) );
		}

		$assistant_id = isset( $_GET['assistant_id'] ) ? absint( wp_unslash( $_GET['assistant_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display, no state change.

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'NV oOS Chat', 'nvoos-chat-spa' ) );
		printf(
			'<p>%s <code>[nvoos_chat_spa]</code></p>',
			esc_html__( 'Embedded preview of the React chat SPA. This page renders the same shortcode used on the front-end:', 'nvoos-chat-spa' )
		);

		echo '<form method="get" style="margin:1em 0;">';
		printf(
			'<input type="hidden" name="page" value="%s" />',
			esc_attr( self::MENU_SLUG )
		);
		printf(
			'<label for="nvoos-chat-spa-assistant-id">%s</label> ',
			esc_html__( 'Assistant ID:', 'nvoos-chat-spa' )
		);
		printf(
			'<input id="nvoos-chat-spa-assistant-id" type="number" min="0" name="assistant_id" value="%d" />',
			esc_attr( $assistant_id )
		);
		printf(
			' <button type="submit" class="button">%s</button>',
			esc_html__( 'Reload', 'nvoos-chat-spa' )
		);
		echo '</form>';

		// Render the shortcode directly so we exercise the same enqueue +
		// localize path as the front-end. Output of the shortcode is
		// already escaped via esc_attr() in
		// NV_oOS_Chat_Spa_Shortcode::render().
		echo do_shortcode(
			sprintf(
				'[nvoos_chat_spa assistant_id="%d" theme="auto" height="600px"]',
				absint( $assistant_id )
			)
		);
		echo '</div>';
	}
}
