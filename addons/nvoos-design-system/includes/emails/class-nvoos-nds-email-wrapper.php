<?php
/**
 * NV oOS Design System — Email Wrapper
 *
 * Wraps all outgoing WordPress emails in the active branded template via the
 * `wp_mail` filter. No plugin dependencies; works on any WordPress site.
 *
 * Safety rules (see the industry research doc):
 *   - sentinel guard — never double-wrap (our own marker, other wrappers'
 *     markers, or already-complete HTML documents are skipped)
 *   - content type — the HTML content-type filter is added inside wrap()
 *     and removes itself on first use, so it only affects this one email
 *     (the wp.org-documented reset-after-send pattern)
 *   - plain text — emails explicitly sent as text/plain are never wrapped
 *   - opt-out — nvoos_nds_email_skip filter for plugin authors
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global wp_mail wrapper.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Wrapper {

	/**
	 * Pending plain-text body for the in-flight send.
	 *
	 * Set by wrap() and consumed by the one-shot phpmailer_init callback so
	 * PHPMailer can emit a proper multipart/alternative message.
	 *
	 * @var string|null
	 */
	private static $pending_alt_body = null;

	/**
	 * Wrap an outgoing email in the active template.
	 *
	 * @param array $atts wp_mail() arguments: to, subject, message, headers, attachments.
	 * @return array Modified arguments.
	 */
	public static function wrap( $atts ) {
		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		$mode = self::get_wrap_mode();
		if ( 'off' === $mode ) {
			return $atts;
		}

		/**
		 * Skip wrapping for a specific message.
		 *
		 * @param bool  $skip Whether to skip wrapping (default false).
		 * @param array $atts wp_mail() arguments.
		 */
		if ( apply_filters( 'nvoos_nds_email_skip', false, $atts ) ) {
			return $atts;
		}

		if ( empty( $atts['message'] ) ) {
			return $atts;
		}

		// `text_only` is the safest non-off mode: it leaves HTML fragments
		// (forms, WooCommerce, other template plugins) untouched.
		if ( 'text_only' === $mode && self::is_html( $atts['message'] ) ) {
			self::log_send( 'skipped', 'html_body', '', strlen( (string) $atts['message'] ), $atts );
			return $atts;
		}

		/**
		 * Filter whether this specific message should be wrapped.
		 *
		 * @param bool   $wrap Whether to wrap.
		 * @param array  $atts wp_mail() arguments.
		 * @param string $mode Wrap mode.
		 */
		if ( ! apply_filters( 'nvoos_nds_email_should_wrap', true, $atts, $mode ) ) {
			self::log_send( 'skipped', 'filter', '', strlen( (string) $atts['message'] ), $atts );
			return $atts;
		}

		$message = $atts['message'];

		// 1. Sentinel guard — never double-wrap (ours or any other wrapper's).
		if ( false !== strpos( $message, NV_oOS_Design_System_Email_Renderer::SENTINEL_PREFIX ) ) {
			self::log_send( 'skipped', 'sentinel', '', strlen( (string) $message ), $atts );
			return $atts;
		}

		// 2. Full-document guard — another plugin (WooCommerce, an SMTP
		// template plugin) already produced a complete HTML email.
		if ( false !== stripos( $message, '<!doctype' ) || false !== stripos( $message, '<html' ) ) {
			self::log_send( 'skipped', 'full_document', '', strlen( (string) $message ), $atts );
			return $atts;
		}

		// 3. Explicit text/plain sends are never wrapped.
		if ( self::is_text_plain( isset( $atts['headers'] ) ? $atts['headers'] : array() ) ) {
			self::log_send( 'skipped', 'text_plain', '', strlen( (string) $message ), $atts );
			return $atts;
		}

		$template = NV_oOS_Design_System_Email_Template_Registry::get_active();
		if ( empty( $template['html'] ) ) {
			self::log_send( 'skipped', 'no_template', '', strlen( (string) $message ), $atts );
			return $atts;
		}

		// Convert plain-text bodies to HTML; harden HTML bodies.
		$body = $message;
		if ( ! self::is_html( $body ) ) {
			$body = wpautop( esc_html( $body ) );
		}
		$body = wp_kses_post( $body );

		$context = self::build_context( $atts, $template['settings'], $body );

		$renderer        = new NV_oOS_Design_System_Email_Renderer();
		$atts['message'] = $renderer->render( $template['html'], $context, $template['slug'] );

		self::log_send( 'wrapped', '', $template['slug'], strlen( (string) $atts['message'] ), $atts );

		// Force HTML for this send only; the filter removes itself on first use.
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'force_html_content_type_once' ), 99 );

		// Pair a plain-text alternative via PHPMailer's AltBody so the send
		// goes out as multipart/alternative (deliverability best practice).
		self::pair_plain_text( $atts['message'], $atts );

		return $atts;
	}

	/**
	 * Derive a plain-text version of the rendered email and attach it to the
	 * in-flight send via a one-shot phpmailer_init callback.
	 *
	 * @param string $html Rendered HTML email.
	 * @param array  $atts Original wp_mail() arguments (for the filter).
	 * @return void
	 */
	private static function pair_plain_text( $html, $atts ) {
		$plain = self::plain_text_from_html( $html );

		/**
		 * Filter the plain-text alternative paired with a wrapped email.
		 *
		 * @param string $plain Plain-text body.
		 * @param array  $atts  wp_mail() arguments.
		 */
		$plain = apply_filters( 'nvoos_nds_email_plain_text', $plain, $atts );

		if ( '' === trim( (string) $plain ) ) {
			return;
		}

		self::$pending_alt_body = (string) $plain;
		add_action( 'phpmailer_init', array( __CLASS__, 'attach_alt_body' ), 20 );
	}

	/**
	 * Attach the pending plain-text body to PHPMailer as AltBody.
	 *
	 * One-shot, guarded: only applies when the message is being sent as
	 * HTML and no other plugin has already set AltBody. PHPMailer converts
	 * the message to multipart/alternative automatically when both Body and
	 * AltBody are set.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public static function attach_alt_body( $phpmailer ) {
		remove_action( 'phpmailer_init', array( __CLASS__, 'attach_alt_body' ), 20 );

		if ( null === self::$pending_alt_body ) {
			return;
		}

		$plain                  = self::$pending_alt_body;
		self::$pending_alt_body = null;

		if ( ! is_object( $phpmailer ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public property.
		if ( 'text/html' !== $phpmailer->ContentType ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public property.
		if ( ! empty( $phpmailer->AltBody ) ) {
			return; // Another plugin already provided a plain-text part.
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public property.
		$phpmailer->AltBody = $plain;
	}

	/**
	 * Convert rendered HTML email into a clean plain-text body.
	 *
	 * Pipeline (industry guidance): strip <style> blocks first, map block
	 * elements and <br> to newlines (cells to tabs) before stripping tags,
	 * decode entities, then collapse trailing whitespace and 3+ blank lines.
	 *
	 * @param string $html Rendered HTML.
	 * @return string Plain text.
	 */
	public static function plain_text_from_html( $html ) {
		$text = (string) $html;

		// Remove style blocks and conditional comments first.
		$text = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $text );
		$text = preg_replace( '/<!--.*?-->/s', '', $text );

		// Structural newlines before tags are stripped.
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
		$text = preg_replace( '/<\/(p|div|tr|h[1-6]|li|table|blockquote)>/i', "\n", $text );
		$text = preg_replace( '/<\/t[dh]>/i', "\t", $text );
		$text = preg_replace( '/<hr\b[^>]*>/i', "\n---\n", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Collapse spaces before newlines and blank-line runs.
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * One-shot wp_mail_content_type override.
	 *
	 * Removes itself before returning so subsequent sends are unaffected
	 * (the wp.org-documented pattern).
	 *
	 * @param string $content_type Current content type.
	 * @return string 'text/html'.
	 */
	public static function force_html_content_type_once( $content_type ) {
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'force_html_content_type_once' ), 99 );
		return 'text/html';
	}

	/**
	 * Build the merge context for the active template.
	 *
	 * Logo priority chain (matches the Aerlinn reference):
	 *   nvoos_nds_email_logo_url filter → WooCommerce header image → theme custom
	 *   logo → template setting → empty.
	 *
	 * @param array  $atts     wp_mail() arguments.
	 * @param array  $settings Per-template settings.
	 * @param string $body     Sanitised body HTML.
	 * @return array<string, string> Merge context.
	 */
	private static function build_context( $atts, $settings, $body ) {
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_domain = $site_domain ? $site_domain : '';

		$admin_email = ! empty( $settings['admin_email'] ) ? $settings['admin_email'] : (string) get_option( 'admin_email', '' );

		$context = array(
			'subject'      => isset( $atts['subject'] ) ? $atts['subject'] : '',
			'body'         => $body,
			'preheader'    => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'to_name'      => self::get_recipient_name( $atts['to'] ),
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'site_domain'  => $site_domain,
			'logo_url'     => self::resolve_logo_url( $settings ),
			'admin_email'  => $admin_email,
			'sub_brand'    => isset( $settings['sub_brand'] ) ? $settings['sub_brand'] : '',
			'confidential' => isset( $settings['confidential'] ) ? $settings['confidential'] : '',
			'year'         => gmdate( 'Y' ),
		);

		/**
		 * Filter the email merge context before rendering.
		 *
		 * @param array $context Merge context.
		 * @param array $atts    wp_mail() arguments.
		 */
		return apply_filters( 'nvoos_nds_email_context', $context, $atts );
	}

	/**
	 * Resolve the logo URL with the standard priority chain.
	 *
	 * @param array $settings Per-template settings.
	 * @return string Logo URL (may be empty).
	 */
	public static function resolve_logo_url( $settings ) {
		/**
		 * Filter the email logo URL. Return a non-empty string to override
		 * every fallback.
		 *
		 * @param string $logo_url Logo URL (default empty = use fallbacks).
		 */
		$url = apply_filters( 'nvoos_nds_email_logo_url', '' );
		if ( $url ) {
			return esc_url_raw( $url );
		}

		// WooCommerce header image (if WC is installed).
		if ( function_exists( 'WC' ) ) {
			$wc_logo = get_option( 'woocommerce_email_header_image' );
			if ( $wc_logo ) {
				return esc_url_raw( $wc_logo );
			}
		}

		// Theme custom logo.
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo = wp_get_attachment_image_url( $custom_logo_id, 'thumbnail' );
			if ( $logo ) {
				return esc_url_raw( $logo );
			}
		}

		// Per-template setting.
		if ( ! empty( $settings['logo_url'] ) ) {
			return esc_url_raw( $settings['logo_url'] );
		}

		return '';
	}

	/**
	 * Extract a display name from a recipient field.
	 *
	 * Handles "Name <email>" strings, bare addresses, and arrays of
	 * recipients (JetFormBuilder and other plugins may pass arrays).
	 *
	 * @param string|array $to Recipient field.
	 * @return string Display name (may be empty).
	 */
	public static function get_recipient_name( $to ) {
		if ( is_array( $to ) ) {
			$to = reset( $to );
			if ( empty( $to ) ) {
				return '';
			}
		}

		if ( preg_match( '/^(.+?)\s*<[^>]+>$/', $to, $m ) ) {
			return trim( $m[1] );
		}

		$email = trim( $to, '<> ' );
		if ( is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return $user->display_name ? $user->display_name : $user->user_login;
			}
		}

		return '';
	}

	/**
	 * Detect whether headers explicitly request text/plain.
	 *
	 * @param string|array $headers wp_mail() headers.
	 * @return bool
	 */
	private static function is_text_plain( $headers ) {
		if ( is_string( $headers ) ) {
			return false !== stripos( $headers, 'text/plain' );
		}

		if ( is_array( $headers ) ) {
			foreach ( $headers as $header ) {
				if ( is_string( $header ) && false !== stripos( $header, 'text/plain' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a message already contains HTML.
	 *
	 * @param string $text Message body.
	 * @return bool
	 */
	private static function is_html( $text ) {
		return wp_strip_all_tags( $text ) !== $text;
	}

	/**
	 * Current wrap mode.
	 *
	 * @return string `off`, `text_only`, `fragments`, or `all`.
	 */
	public static function get_wrap_mode() {
		$mode = (string) get_option( NV_oOS_Design_System_Plugin::WRAP_MODE_KEY, 'off' );
		return in_array( $mode, array( 'off', 'text_only', 'fragments', 'all' ), true ) ? $mode : 'off';
	}

	/**
	 * Read the recent send log (newest first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_log() {
		$log = get_option( NV_oOS_Design_System_Plugin::EMAIL_LOG_KEY, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the send log.
	 *
	 * @return void
	 */
	public static function clear_log() {
		delete_option( NV_oOS_Design_System_Plugin::EMAIL_LOG_KEY );
	}

	/**
	 * Record a send (subject only; never the body).
	 *
	 * @param string $status  `wrapped` or `skipped`.
	 * @param string $reason  Skip reason.
	 * @param string $slug    Template slug.
	 * @param int    $bytes   Rendered size.
	 * @param array  $atts    wp_mail() arguments.
	 * @return void
	 */
	private static function log_send( $status, $reason, $slug, $bytes, $atts ) {
		$log = self::get_log();

		array_unshift(
			$log,
			array(
				'time'     => time(),
				'status'   => (string) $status,
				'reason'   => (string) $reason,
				'template' => (string) $slug,
				'bytes'    => (int) $bytes,
				'subject'  => isset( $atts['subject'] ) ? substr( sanitize_text_field( (string) $atts['subject'] ), 0, 120 ) : '',
			)
		);

		update_option( NV_oOS_Design_System_Plugin::EMAIL_LOG_KEY, array_slice( $log, 0, 50 ), false );
	}
}
