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
	 * Forms: master enable flag (1/0). Disabled = the submit route 404s.
	 */
	public const OPTION_FORMS_ENABLED = 'nvoos_forge_bridge_forms_enabled';

	/**
	 * Forms: CORS allowlist — one static origin per line.
	 */
	public const OPTION_FORMS_ORIGINS = 'nvoos_forge_bridge_forms_origins';

	/**
	 * Forms: optional captcha provider ('' or 'turnstile').
	 */
	public const OPTION_FORMS_CAPTCHA_PROVIDER = 'nvoos_forge_bridge_forms_captcha_provider';

	/**
	 * Forms: captcha secret (write-only, never echoed).
	 */
	public const OPTION_FORMS_CAPTCHA_SECRET = 'nvoos_forge_bridge_forms_captcha_secret';

	/**
	 * Forms: captcha site key (public; copied into the static site's config).
	 */
	public const OPTION_FORMS_CAPTCHA_SITE_KEY = 'nvoos_forge_bridge_forms_captcha_site_key';

	/**
	 * Redirects: export the redirect plugins' stored rules (1/0). Off by
	 * default — an opt-in, read-only build input.
	 */
	public const OPTION_REDIRECTS_ENABLED = 'nvoos_forge_bridge_redirects_enabled';

	/**
	 * Redirects: optional shared token. Empty = public (the operator opted in);
	 * non-empty = the caller must send X-Forge-Redirects-Token or a Bearer token.
	 */
	public const OPTION_REDIRECTS_TOKEN = 'nvoos_forge_bridge_redirects_token';

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
			self::OPTION_FORMS_ENABLED,
			self::OPTION_FORMS_ORIGINS,
			self::OPTION_FORMS_CAPTCHA_PROVIDER,
			self::OPTION_FORMS_CAPTCHA_SECRET,
			self::OPTION_FORMS_CAPTCHA_SITE_KEY,
			self::OPTION_REDIRECTS_ENABLED,
			self::OPTION_REDIRECTS_TOKEN,
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
	 * Whether the form bridge is enabled.
	 *
	 * @return bool
	 */
	public static function forms_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_FORMS_ENABLED, '0' );
	}

	/**
	 * Normalize an origin to the comparable shape (scheme://host[:port],
	 * lowercase, no trailing slash).
	 *
	 * @param string $origin Origin.
	 * @return string
	 */
	public static function normalize_origin( $origin ) {
		$origin = untrailingslashit( strtolower( trim( (string) $origin ) ) );
		if ( '' === $origin ) {
			return '';
		}
		$parsed = wp_parse_url( $origin );
		if ( empty( $parsed['host'] ) ) {
			return $origin;
		}
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		return $scheme . '://' . $parsed['host'] . $port;
	}

	/**
	 * The configured static origins (normalized), one per entry.
	 *
	 * @return string[]
	 */
	public static function forms_origins(): array {
		$raw     = (string) get_option( self::OPTION_FORMS_ORIGINS, '' );
		$origins = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$normalized = self::normalize_origin( $line );
			if ( '' !== $normalized ) {
				$origins[] = $normalized;
			}
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * Whether an origin is allowlisted.
	 *
	 * @param string $origin Origin.
	 * @return bool
	 */
	public static function forms_origin_allowed( $origin ): bool {
		$normalized = self::normalize_origin( $origin );
		return '' !== $normalized && in_array( $normalized, self::forms_origins(), true );
	}

	/**
	 * The captcha provider ('' or 'turnstile').
	 *
	 * @return string
	 */
	public static function forms_captcha_provider(): string {
		$provider = (string) get_option( self::OPTION_FORMS_CAPTCHA_PROVIDER, '' );
		return 'turnstile' === $provider ? $provider : '';
	}

	/**
	 * The captcha secret (write-only in the UI).
	 *
	 * @return string
	 */
	public static function forms_captcha_secret(): string {
		return (string) get_option( self::OPTION_FORMS_CAPTCHA_SECRET, '' );
	}

	/**
	 * The captcha site key.
	 *
	 * @return string
	 */
	public static function forms_captcha_site_key(): string {
		return sanitize_text_field( (string) get_option( self::OPTION_FORMS_CAPTCHA_SITE_KEY, '' ) );
	}

	/**
	 * Whether captcha verification is active (provider + secret set).
	 *
	 * @return bool
	 */
	public static function forms_captcha_enabled(): bool {
		return 'turnstile' === self::forms_captcha_provider() && '' !== self::forms_captcha_secret();
	}

	/**
	 * Whether the stored-redirects export is enabled.
	 *
	 * @return bool
	 */
	public static function redirects_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_REDIRECTS_ENABLED, '0' );
	}

	/**
	 * The optional shared token for the redirects export ('' = public).
	 *
	 * @return string
	 */
	public static function redirects_token(): string {
		return (string) get_option( self::OPTION_REDIRECTS_TOKEN, '' );
	}

	/**
	 * Whether the forms bridge is enabled AND has an origin allowlist.
	 *
	 * @return bool
	 */
	public static function forms_configured(): bool {
		return self::forms_enabled() && count( self::forms_origins() ) > 0;
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

		// Forms: master switch, origin allowlist (absolute origins only),
		// and the optional captcha.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$forms_enabled = isset( $_POST['forms_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['forms_enabled'] ) ) ? '1' : '0';
		update_option( self::OPTION_FORMS_ENABLED, $forms_enabled );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$origins_raw = isset( $_POST['forms_origins'] ) ? sanitize_textarea_field( wp_unslash( $_POST['forms_origins'] ) ) : '';
		$origins     = array();
		foreach ( preg_split( '/[\r\n]+/', $origins_raw ) as $line ) {
			$origin = trim( (string) $line );
			if ( '' === $origin || 1 !== preg_match( '#^https?://#i', $origin ) ) {
				continue;
			}
			$normalized = self::normalize_origin( $origin );
			if ( '' !== $normalized ) {
				$origins[] = $normalized;
			}
		}
		update_option( self::OPTION_FORMS_ORIGINS, implode( "\n", array_unique( $origins ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$provider = isset( $_POST['forms_captcha_provider'] ) ? sanitize_key( wp_unslash( $_POST['forms_captcha_provider'] ) ) : '';
		update_option( self::OPTION_FORMS_CAPTCHA_PROVIDER, in_array( $provider, array( '', 'turnstile' ), true ) ? $provider : '' );

		// The captcha secret is write-only: an empty field keeps the stored value.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$captcha_secret = isset( $_POST['forms_captcha_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['forms_captcha_secret'] ) ) : '';
		if ( '' !== $captcha_secret ) {
			update_option( self::OPTION_FORMS_CAPTCHA_SECRET, $captcha_secret );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$captcha_site_key = isset( $_POST['forms_captcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['forms_captcha_site_key'] ) ) : '';
		update_option( self::OPTION_FORMS_CAPTCHA_SITE_KEY, $captcha_site_key );

		// Redirects export: opt-in switch + optional write-only token.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$redirects_enabled = isset( $_POST['redirects_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['redirects_enabled'] ) ) ? '1' : '0';
		update_option( self::OPTION_REDIRECTS_ENABLED, $redirects_enabled );

		// The token is write-only: an empty field keeps the stored value.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$redirects_token = isset( $_POST['redirects_token'] ) ? sanitize_text_field( wp_unslash( $_POST['redirects_token'] ) ) : '';
		if ( '' !== $redirects_token ) {
			update_option( self::OPTION_REDIRECTS_TOKEN, $redirects_token );
		}

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
		$configured         = self::rebuild_configured();
		$forms_configured   = self::forms_configured();
		$redirect_providers = \NvoosForgeBridge\Rest\Redirects::active_providers();
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
			<?php if ( ! $forms_configured ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Form submissions are not enabled — enable the bridge and allowlist your static origin before the forged forms go live.', 'nvoos-forge-bridge' ); ?></p></div>
			<?php endif; ?>
			<?php if ( self::redirects_enabled() && array() === $redirect_providers ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Redirect export is enabled but no supported redirect plugin (Redirection or Rank Math) was detected — the endpoint will return an empty list.', 'nvoos-forge-bridge' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nvoos_forge_bridge_save" />
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<h2 class="title"><?php esc_html_e( 'Rebuild webhook', 'nvoos-forge-bridge' ); ?></h2>
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

				<h2 class="title"><?php esc_html_e( 'Forms (forged frontend submissions)', 'nvoos-forge-bridge' ); ?></h2>
				<p>
					<?php esc_html_e( 'The forged static site rebuilds your JetFormBuilder / Elementor Pro forms and posts submissions to this plugin, which dispatches them to the native handler — validation, storage, mail, and post-submit actions run exactly as on WordPress. The static origin must be allowlisted below (one per line).', 'nvoos-forge-bridge' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="forms_enabled"><?php esc_html_e( 'Enable form submissions', 'nvoos-forge-bridge' ); ?></label></th>
						<td><input type="checkbox" id="forms_enabled" name="forms_enabled" value="1" <?php checked( self::forms_enabled() ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="forms_origins"><?php esc_html_e( 'Static origins (allowlist)', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<textarea class="large-text code" id="forms_origins" name="forms_origins" rows="3" placeholder="https://www.example.com"><?php echo esc_textarea( implode( "\n", self::forms_origins() ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'The static site origin(s) allowed to submit — one full origin per line. Requests from any other origin are rejected.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="forms_captcha_provider"><?php esc_html_e( 'Captcha (optional)', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<select id="forms_captcha_provider" name="forms_captcha_provider">
								<option value="" <?php selected( '', self::forms_captcha_provider() ); ?>><?php esc_html_e( 'None', 'nvoos-forge-bridge' ); ?></option>
								<option value="turnstile" <?php selected( 'turnstile', self::forms_captcha_provider() ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'nvoos-forge-bridge' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When set, every submission must carry a fresh Turnstile token (action and hostname are pinned to your allowlisted origin).', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="forms_captcha_site_key"><?php esc_html_e( 'Turnstile site key', 'nvoos-forge-bridge' ); ?></label></th>
						<td><input type="text" class="regular-text" id="forms_captcha_site_key" name="forms_captcha_site_key" value="<?php echo esc_attr( self::forms_captcha_site_key() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="forms_captcha_secret"><?php esc_html_e( 'Turnstile secret', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="forms_captcha_secret" name="forms_captcha_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::forms_captcha_enabled() ? '••••••••' : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the stored value.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Stored redirects (static site export)', 'nvoos-forge-bridge' ); ?></h2>
				<p>
					<?php esc_html_e( 'Exports the redirect rules your redirect plugin already stores — Redirection (redirection_items) and Rank Math (rank_math_redirections) — so the forged static site keeps every legacy URL alive on cutover day. Only enabled, exact URL matches are exported: regex, query-scoped, and non-URL match types cannot be expressed portably in a static _redirects file and are reported as skipped counts, never silently widened.', 'nvoos-forge-bridge' ); ?>
					<?php if ( array() !== $redirect_providers ) : ?>
						<?php echo esc_html( sprintf( /* translators: %s: comma-separated provider names. */ __( 'Detected: %s.', 'nvoos-forge-bridge' ), implode( ', ', $redirect_providers ) ) ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No supported redirect plugin detected.', 'nvoos-forge-bridge' ); ?>
					<?php endif; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="redirects_enabled"><?php esc_html_e( 'Enable redirect export', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="checkbox" id="redirects_enabled" name="redirects_enabled" value="1" <?php checked( self::redirects_enabled() ); ?> />
							<p class="description"><?php esc_html_e( 'Publishes GET /wp-json/nvoos-forge/v1/redirects — read-only, bounded to 500 rules, and safe to leave off when you are not building a static frontend.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="redirects_token"><?php esc_html_e( 'Export token (optional)', 'nvoos-forge-bridge' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="redirects_token" name="redirects_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== self::redirects_token() ? '••••••••' : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'When set, callers must send the same value in the X-Forge-Redirects-Token header (or as a Bearer token). Leave blank to keep the stored value; set it to protect your URL structure from public discovery.', 'nvoos-forge-bridge' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
