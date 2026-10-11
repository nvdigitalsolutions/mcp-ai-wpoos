<?php
/**
 * User Profile — Chat Profile Selector.
 *
 * Adds a per-user chat profile dropdown to the WordPress user profile and
 * edit-user screens so administrators can manage profiles without the Pro
 * SPA or WP-CLI (proposal 015, "Option C" legacy surface). The dropdown
 * respects the same downgrade/upgrade policy as the REST surface: profiles
 * the edited user is allowed to select are listed; anything else is
 * rejected on save.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers user-profile fields for the chat profile.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_User_Profile_Chat_Profile {

	/**
	 * Nonce action for the profile field.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_save_chat_profile';

	/**
	 * Bootstrap hooks.
	 *
	 * @since 2.2.0
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
	 * Render the chat-profile section on the profile / edit-user screen.
	 *
	 * @since 2.2.0
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}
		if ( ! class_exists( 'WP_MCP_AI_Chat_Profile_Manager' ) || ! WP_MCP_AI_Chat_Profile_Manager::is_enabled() ) {
			return;
		}

		$current = WP_MCP_AI_Chat_Profile_Manager::get_user_slug( $user->ID );
		if ( '' === $current ) {
			$current = WP_MCP_AI_Chat_Profile_Manager::get_site_default_slug();
		}

		// The editing user may see profiles the edited user cannot select
		// (e.g. a downgraded editor's profile page viewed by an admin) —
		// the selectable list below is filtered by the edited user's caps.
		$selectable = WP_MCP_AI_Chat_Profile_Registry::list_selectable_for_user( $user->ID );
		?>
		<h2><?php esc_html_e( 'NV oOS — Chat Profile', 'mcp-ai-wpoos' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wp_mcp_ai_chat_profile">
						<?php esc_html_e( 'Chat profile', 'mcp-ai-wpoos' ); ?>
					</label>
				</th>
				<td>
					<select name="wp_mcp_ai_chat_profile" id="wp_mcp_ai_chat_profile">
						<?php foreach ( $selectable as $profile ) : ?>
							<option
								value="<?php echo esc_attr( $profile->get_slug() ); ?>"
								<?php selected( $profile->get_slug(), $current ); ?>
							>
								<?php echo esc_html( $profile->get_label() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Determines which tools the assistant may call during chat. "Read-only" blocks writes, state changes and destructive actions.', 'mcp-ai-wpoos' ); ?>
					</p>
					<?php wp_nonce_field( self::NONCE_ACTION, 'wp_mcp_ai_chat_profile_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the chat-profile selection.
	 *
	 * @since 2.2.0
	 *
	 * @param int $user_id The user being edited.
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		if ( ! isset( $_POST['wp_mcp_ai_chat_profile_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_chat_profile_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// Only users who may edit this profile may change it (editors may
		// edit their own profile; administrators may edit anyone's).
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$slug = isset( $_POST['wp_mcp_ai_chat_profile'] ) ? sanitize_key( wp_unslash( $_POST['wp_mcp_ai_chat_profile'] ) ) : '';
		if ( '' === $slug ) {
			return;
		}

		WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $user_id, $slug );
	}
}
