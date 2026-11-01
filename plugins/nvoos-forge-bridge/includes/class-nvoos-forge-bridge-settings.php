<?php
/**
 * Settings — options, sanitization, and the admin page.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge;

/**
 * Option access + the Settings → Forge Bridge page.
 */
final class Settings {

	/**
	 * GitHub dispatches URL (empty = webhook disabled).
	 */
	public const OPTION_REBUILD_URL = 'nvoos_forge_bridge_rebuild_url';

	/**
	 * GitHub token for the dispatch POST (never echoed back).
	 */
	public const OPTION_REBUILD_TOKEN = 'nvoos_forge_bridge_rebuild_token';

	/**
	 * Shared HMAC secret (never echoed back; verifies inbound triggers and
	 * signs outbound dispatch payloads).
	 */
	public const OPTION_REBUILD_SECRET = 'nvoos_forge_bridge_rebuild_secret';

	/**
	 * Event type sent in the dispatch payload.
	 */
	public const OPTION_REBUILD_EVENT = 'nvoos_forge_bridge_rebuild_event';

	/**
	 * Master enable flag (1/0).
	 */
	public const OPTION_REBUILD_ENABLED = 'nvoos_forge_bridge_rebuild_enabled';

	/**
	 * Default event type.
	 */
	public const DEFAULT_EVENT = 'nvoos-forge-content-changed';

	/**
	 * All option keys (used by uninstall.php).
	 *
	 * @return string[]
	 */
	public static function option_keys(): array {
		return array(
			self::OPTION_REBUILD_URL,
			self::OPTION_REBUILD_TOKEN,
			self::OPTION_REBUILD_SECRET,
			self::OPTION_REBUILD_EVENT,
			self::OPTION_REBUILD_ENABLED,
		);
	}

	/**
	 * The dispatch URL (trailing slashes trimmed) or '' when unset.
	 *
	 * @return string
	 */
	public static function rebuild_url(): string {
		return untrailingslashit( (string) get_option( self::OPTION_REBUILD_URL, '' ) );
	}

	/**
	 * The GitHub token or ''.
	 *
	 * @return string
	 */
	public static function rebuild_token(): string {
		return (string) get_option( self::OPTION_REBUILD_TOKEN, '' );
	}

	/**
	 * The shared HMAC secret or ''.
	 *
	 * @return string
	 */
	public static function rebuild_secret(): string {
		return (string) get_option( self::OPTION_REBUILD_SECRET, '' );
	}

	/**
	 * The event type (sanitized to a slug).
	 *
	 * @return string
	 */
	public static function rebuild_event(): string {
		$event = (string) get_option( self::OPTION_REBUILD_EVENT, self::DEFAULT_EVENT );
		return '' === $event ? self::DEFAULT_EVENT : $event;
	}

	/**
	 * Whether the webhook is enabled AND configured.
	 *
	 * @return bool
	 */
	public static function rebuild_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_REBUILD_ENABLED, '0' );
	}

	/**
	 * Whether the webhook can fire (enabled + URL + token + secret present).
	 *
	 * @return bool
	 */
	public static function rebuild_configured(): bool {
		return self::rebuild_enabled()
			&& '' !== self::rebuild_url()
			&& '' !== self::rebuild_token()
			&& '' !== self::rebuild_secret();
	}

	/**
	 * Nonce action for the settings form.
	 *
	 * @return string
	 */
	public static function nonce_action(): string {
		return 'nvoos_forge_bridge_save_settings';
	}

	/**
	 * Handle the settings POST. Fires on admin_post_ so the nonce gate and
	 * capability check live in one auditable place.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'nvoos-forge-bridge' ) );
		}
		check_admin_referer( self::nonce_action() );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$url = isset( $_POST['rebuild_url'] ) ? sanitize_text_field( wp_unslash( $_POST['rebuild_url'] ) ) : '';
		if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
			$url = '';
		}
		update_option( self::OPTION_REBUILD_URL, $url );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$token = isset( $_POST['rebuild_token'] ) ? sanitize_text_field( wp_unslash( $_POST['rebuild_token'] ) ) : '';
		if ( '' !== $token ) {
			update_option( self::OPTION_REBUILD_TOKEN, $token );
		}

		// The secret is write-only: an empty field keeps the stored value.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$secret = isset( $_POST['rebuild_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['rebuild_secret'] ) ) : '';
		if ( '' !== $secret ) {
			update_option( self::OPTION_REBUILD_SECRET, $secret );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$event = isset( $_POST['rebuild_event'] ) ? sanitize_title( wp_unslash( $_POST['rebuild_event'] ) ) : '';
		update_option( self::OPTION_REBUILD_EVENT, '' === $event ? self::DEFAULT_EVENT : $event );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$enabled = isset( $_POST['rebuild_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rebuild_enabled'] ) ) ? '1' : '0';
		update_option( self::OPTION_REBUILD_ENABLED, $enabled );

		$fallback = admin_url( 'options-general.php?page=nvoos-forge-bridge' );
		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ? wp_get_referer() : $fallback ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$configured = self::rebuild_configured();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Forge Bridge', 'nvoos-forge-bridge' ); ?></h1>
			<p>
				<?php esc_html_e( 'Fires a signed GitHub repository_dispatch when content changes, so the Astro Forge rebuilds the generated frontend. Configure the target repository, a token with dispatch rights, and a shared HMAC secret.', 'nvoos-forge-bridge' ); ?>
			</p>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'nvoos-forge-bridge' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The rebuild webhook is not fully configured — content changes will not trigger rebuilds yet.', 'nvoos-forge-bridge' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nvoos_forge_bridge_save" />
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rebuild_enabled"><?php esc_html_e( 'Enable rebuild webhook', 'nvoos-forge-bridge' ); ?></label></th>
						<td><input type="checkbox" id="rebuild_enabled" name="rebuild_enabled" value="1" <?php checked( self::rebuild_enabled() ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rebuild_url"><?php esc_html_e( 'GitHub dispatches URL', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="rebuild_url" name="rebuild_url" value="<?php echo esc_attr( self::rebuild_url() ); ?>" placeholder="https://api.github.com/repos/OWNER/REPO/dispatches" />
							<p class="description"><?php esc_html_e( 'Empty disables the webhook.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rebuild_token"><?php esc_html_e( 'GitHub token', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="rebuild_token" name="rebuild_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $configured ? '••••••••' : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'A repo-scoped token with dispatch rights. Leave blank to keep the stored value.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rebuild_secret"><?php esc_html_e( 'HMAC secret', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="rebuild_secret" name="rebuild_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $configured ? '••••••••' : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Signs outbound dispatch payloads and verifies inbound rebuild triggers. Leave blank to keep the stored value.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rebuild_event"><?php esc_html_e( 'Dispatch event type', 'nvoos-forge-bridge' ); ?></label></th>
						<td><input type="text" class="regular-text" id="rebuild_event" name="rebuild_event" value="<?php echo esc_attr( self::rebuild_event() ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
