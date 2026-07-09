<?php
/**
 * User Profile — Chat Memory Preferences.
 *
 * Adds per-user "Use long-term memory" and "Auto-summarize" checkboxes
 * to the WordPress user profile page so administrators and users can
 * recover when the chat-side drawer is inaccessible (e.g. because the
 * per-user toggle was accidentally set to off, which blocks the drawer
 * REST endpoints and prevents self-service recovery).
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers user-profile fields for chat-memory preferences.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_User_Profile_Memory {

	/**
	 * Bootstrap hooks.
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_fields' ) );
	}

	/**
	 * Whether the site-wide gate is open.
	 *
	 * @since 1.8.0
	 *
	 * @return bool
	 */
	private static function is_site_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return true;
		}

		return (bool) WP_MCP_AI_Settings_Registry::get_setting( 'enable_chat_memory', true );
	}

	/**
	 * Render the chat-memory section on the user profile / edit-user screen.
	 *
	 * @since 1.8.0
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}

		$site_enabled = self::is_site_enabled();

		// User meta keys (mirrors WP_MCP_AI_REST_Chat_Memory_Controller constants).
		$meta_enabled       = 'wp_mcp_ai_chat_memory_enabled';
		$meta_autosummarize = 'wp_mcp_ai_chat_memory_autosummarize';

		$enabled_raw       = get_user_meta( $user->ID, $meta_enabled, true );
		$autosummarize_raw = get_user_meta( $user->ID, $meta_autosummarize, true );

		// Unset meta means default (true for enabled, false for autosummarize).
		$enabled       = '' === $enabled_raw ? true : (bool) $enabled_raw;
		$autosummarize = '' === $autosummarize_raw ? false : (bool) $autosummarize_raw;
		?>
		<h2><?php esc_html_e( 'NV oOS — Chat Memory', 'mcp-ai-wpoos' ); ?></h2>

		<table class="form-table" role="presentation">
			<?php if ( ! $site_enabled ) : ?>
				<tr>
					<th scope="row">&nbsp;</th>
					<td>
						<p class="description" style="color:#b32d2e;">
							<?php
							printf(
								/* translators: %s: link to the Orchestration settings page */
								esc_html__( 'Long-term chat memory is disabled site-wide. An administrator can enable it on the %s page.', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings#wp_mcp_ai_orchestration' ) ) . '">' . esc_html__( 'Orchestration → Settings', 'mcp-ai-wpoos' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Use long-term memory', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<label for="wp_mcp_ai_chat_memory_enabled">
						<input
							type="checkbox"
							name="wp_mcp_ai_chat_memory_enabled"
							id="wp_mcp_ai_chat_memory_enabled"
							value="1"
							<?php checked( $enabled ); ?>
							<?php disabled( ! $site_enabled ); ?>
						/>
						<?php esc_html_e( 'Allow the AI assistant to store and recall memories across chat sessions.', 'mcp-ai-wpoos' ); ?>
					</label>
					<?php if ( ! $site_enabled ) : ?>
						<p class="description"><?php esc_html_e( 'This setting has no effect while the site-wide feature is disabled.', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-summarize conversations', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<label for="wp_mcp_ai_chat_memory_autosummarize">
						<input
							type="checkbox"
							name="wp_mcp_ai_chat_memory_autosummarize"
							id="wp_mcp_ai_chat_memory_autosummarize"
							value="1"
							<?php checked( $autosummarize ); ?>
							<?php disabled( ! $site_enabled || ! $enabled ); ?>
						/>
						<?php esc_html_e( 'Automatically store a summary of each conversation as a memory.', 'mcp-ai-wpoos' ); ?>
					</label>
					<?php if ( ! $enabled && $site_enabled ) : ?>
						<p class="description"><?php esc_html_e( 'Enable "Use long-term memory" first.', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save chat-memory preferences on user profile update.
	 *
	 * @since 1.8.0
	 *
	 * @param int $user_id The user ID being saved.
	 *
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Do not touch user meta when the site-wide feature is off — the
		// checkboxes are disabled and won't appear in $_POST, which would
		// otherwise be misread as the user unchecking them.
		if ( ! self::is_site_enabled() ) {
			return;
		}

		$meta_enabled       = 'wp_mcp_ai_chat_memory_enabled';
		$meta_autosummarize = 'wp_mcp_ai_chat_memory_autosummarize';

		// The checkbox input only appears in $_POST when checked.
		// We intentionally store an explicit 0/1 so the per-user override is
		// always present, which lets the user recover via the profile even when
		// the drawer REST endpoints are blocked.
		if ( isset( $_POST[ $meta_enabled ] ) ) {
			update_user_meta( $user_id, $meta_enabled, 1 );
		} else {
			update_user_meta( $user_id, $meta_enabled, 0 );
		}

		if ( isset( $_POST[ $meta_autosummarize ] ) ) {
			update_user_meta( $user_id, $meta_autosummarize, 1 );
		} else {
			update_user_meta( $user_id, $meta_autosummarize, 0 );
		}
	}
}
