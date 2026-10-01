<?php
/**
 * NV oOS Design System — WooCommerce Email Rebrand Integration
 *
 * Rebrands WooCommerce transactional emails (order confirmations, invoices…)
 * using WooCommerce's own hooks — no template overrides, no double-wrapping:
 *   - woocommerce_email_styles      → inject token-resolved palette CSS
 *   - woocommerce_email_header      → branded header fragment
 *   - woocommerce_email_footer      → branded footer fragment
 *   - woocommerce_email_get_option  → sync colour options from email tokens
 *
 * Opt-in via the "WooCommerce Rebrand" toggle on the Emails tab
 * (nvoos_nds_wc_rebrand option, default off). The nds_email_wc_rebrand
 * filter allows per-email overrides.
 *
 * WC emails are complete HTML documents, so the global wp_mail wrapper's
 * full-document guard leaves them alone — this hook-based path is the
 * correct (and safe) way to rebrand them.
 *
 * @package NV_oOS_Design_System
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce email rebrand integration.
 *
 * @since 0.3.0
 */
class NV_oOS_Design_System_Integration_WooCommerce {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks if WooCommerce is active and rebranding is enabled.
	 *
	 * Real hook binding happens on `woocommerce_init` so the WC_Emails
	 * default header/footer handlers (bound in its constructor) can be
	 * removed by exact callback identity.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::$registered = true;

		add_action( 'woocommerce_init', array( __CLASS__, 'bind_hooks' ) );
	}

	/**
	 * Bind the rebrand hooks on woocommerce_init.
	 *
	 * @return void
	 */
	public static function bind_hooks() {
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;

		// Replace WC's default header/footer handlers with NDS fragments.
		if ( $mailer instanceof WC_Emails ) {
			remove_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) );
			remove_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) );
		}

		add_action( 'woocommerce_email_header', array( __CLASS__, 'render_header' ), 10, 2 );
		add_action( 'woocommerce_email_footer', array( __CLASS__, 'render_footer' ), 10, 1 );

		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'inject_styles' ), 10, 2 );
		add_filter( 'woocommerce_email_get_option', array( __CLASS__, 'sync_option' ), 10, 3 );
	}

	/**
	 * Check whether rebranding is enabled (globally or for a specific email).
	 *
	 * @param WC_Email|null $email WC email instance (optional).
	 * @return bool
	 */
	public static function is_rebrand_enabled( $email = null ) {
		if ( ! (bool) get_option( NV_oOS_Design_System_Plugin::WC_REBRAND_KEY, false ) ) {
			return false;
		}

		/**
		 * Filter whether a specific WooCommerce email should be rebranded.
		 *
		 * @param bool          $enabled Whether rebranding applies (default true).
		 * @param WC_Email|null $email   The WC email instance, or null.
		 */
		return (bool) apply_filters( 'nds_email_wc_rebrand', true, $email );
	}

	/**
	 * Inject token-resolved palette CSS into WC's email styles.
	 *
	 * @param string       $css   WC-generated CSS.
	 * @param WC_Email|bool $email WC email instance.
	 * @return string
	 */
	public static function inject_styles( $css, $email = false ) {
		if ( ! self::is_rebrand_enabled( $email instanceof WC_Email ? $email : null ) ) {
			return $css;
		}

		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		$header_bg = isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18';
		$accent    = isset( $values['email_accent'] ) ? $values['email_accent'] : '#c9b96e';
		$accent_2  = isset( $values['email_accent_2'] ) ? $values['email_accent_2'] : '#4d8a7b';
		$body_text = isset( $values['email_body_text'] ) ? $values['email_body_text'] : '#1a2420';
		$muted     = isset( $values['email_muted'] ) ? $values['email_muted'] : '#2e4a3e';
		$page_bg   = isset( $values['email_page_bg'] ) ? $values['email_page_bg'] : '#f0ede8';
		$card_bg   = isset( $values['email_card_bg'] ) ? $values['email_card_bg'] : '#ffffff';
		$font      = isset( $values['email_font'] ) ? $values['email_font'] : 'Georgia, serif';

		$css .= "\n/* NV oOS Design System — token-driven rebrand */\n";
		$css .= '#wrapper, body { background-color: ' . esc_html( $page_bg ) . ' !important; font-family: ' . esc_html( $font ) . '; }' . "\n";
		$css .= '#template_container { background-color: ' . esc_html( $card_bg ) . ' !important; border-radius: 0; }' . "\n";
		$css .= '#template_header { background-color: ' . esc_html( $header_bg ) . ' !important; color: ' . esc_html( $accent ) . ' !important; }' . "\n";
		$css .= '#template_header h1 { color: ' . esc_html( $accent ) . ' !important; font-family: ' . esc_html( $font ) . '; letter-spacing: 2px; }' . "\n";
		$css .= '#template_footer_html, #template_footer td { background-color: ' . esc_html( $header_bg ) . ' !important; color: ' . esc_html( $muted ) . ' !important; }' . "\n";
		$css .= '#body_content, #body_content table, #body_content td { color: ' . esc_html( $body_text ) . ' !important; font-family: ' . esc_html( $font ) . '; }' . "\n";
		$css .= 'a { color: ' . esc_html( $accent_2 ) . ' !important; }' . "\n";
		$css .= '#body_content table td { border-color: ' . esc_html( $accent_2 ) . '; }' . "\n";

		return $css;
	}

	/**
	 * Render the NDS-branded email header fragment.
	 *
	 * Replaces WC_Emails::email_header() output.
	 *
	 * @param string   $email_heading Heading text.
	 * @param WC_Email $email         WC email instance.
	 * @return void
	 */
	public static function render_header( $email_heading, $email ) {
		if ( ! self::is_rebrand_enabled( $email instanceof WC_Email ? $email : null ) ) {
			return;
		}

		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		$header_bg = isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18';
		$accent    = isset( $values['email_accent'] ) ? $values['email_accent'] : '#c9b96e';
		$accent_2  = isset( $values['email_accent_2'] ) ? $values['email_accent_2'] : '#4d8a7b';
		$font      = isset( $values['email_font'] ) ? $values['email_font'] : 'Georgia, serif';
		$logo_url  = NV_oOS_Design_System_Email_Wrapper::resolve_logo_url( array() );

		echo '<div id="template_header" style="background-color:' . esc_attr( $header_bg ) . '; padding:36px 48px 32px;">';
		echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>';
		if ( $logo_url ) {
			echo '<td style="padding-right:16px; vertical-align:middle;" width="48">';
			echo '<img src="' . esc_url( $logo_url ) . '" width="48" alt="" style="display:block; border:0;">';
			echo '</td>';
		}
		echo '<td style="vertical-align:middle;">';
		echo '<h1 style="margin:0; font-family:' . esc_attr( $font ) . '; font-size:24px; letter-spacing:4px; color:' . esc_attr( $accent ) . '; font-weight:normal; line-height:1;">' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
		echo '</td></tr>';
		if ( ! empty( $email_heading ) ) {
			echo '<tr><td colspan="2" style="padding-top:14px;"><span style="font-family:' . esc_attr( $font ) . '; font-size:12px; letter-spacing:2px; color:' . esc_attr( $accent_2 ) . '; line-height:1;">' . esc_html( $email_heading ) . '</span></td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * Render the NDS-branded email footer fragment.
	 *
	 * Replaces WC_Emails::email_footer() output.
	 *
	 * @param WC_Email $email WC email instance.
	 * @return void
	 */
	public static function render_footer( $email = null ) {
		if ( ! self::is_rebrand_enabled( $email instanceof WC_Email ? $email : null ) ) {
			return;
		}

		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		$header_bg = isset( $values['email_header_bg'] ) ? $values['email_header_bg'] : '#0f1e18';
		$accent_2  = isset( $values['email_accent_2'] ) ? $values['email_accent_2'] : '#4d8a7b';
		$muted     = isset( $values['email_muted'] ) ? $values['email_muted'] : '#2e4a3e';
		$font      = isset( $values['email_font'] ) ? $values['email_font'] : 'Georgia, serif';

		$domain      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$admin_email = (string) get_option( 'admin_email', '' );

		echo '<div id="template_footer_html" style="background-color:' . esc_attr( $header_bg ) . '; padding:24px 48px;">';
		echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>';
		echo '<td style="font-family:' . esc_attr( $font ) . '; font-size:12px; letter-spacing:1px; color:' . esc_attr( $accent_2 ) . '; line-height:1.8;">';
		echo '<a href="' . esc_url( home_url() ) . '" style="color:' . esc_attr( $accent_2 ) . '; text-decoration:none;">' . esc_html( $domain ) . '</a>';
		if ( $admin_email ) {
			echo '&nbsp;&nbsp;·&nbsp;&nbsp;<a href="mailto:' . esc_attr( $admin_email ) . '" style="color:' . esc_attr( $accent_2 ) . '; text-decoration:none;">' . esc_html( $admin_email ) . '</a>';
		}
		echo '</td>';
		echo '<td align="right" style="font-family:' . esc_attr( $font ) . '; font-size:11px; letter-spacing:1px; color:' . esc_attr( $muted ) . '; line-height:1.8;">© ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( get_bloginfo( 'name' ) ) . '</td>';
		echo '</tr></table></div>';
	}

	/**
	 * Sync WC colour options from the email token group.
	 *
	 * @param string   $value Current option value.
	 * @param WC_Email $email WC email instance.
	 * @param string   $key   Option key (may be absent on older WC).
	 * @return string
	 */
	public static function sync_option( $value, $email, $key = '' ) {
		if ( ! self::is_rebrand_enabled( $email instanceof WC_Email ? $email : null ) || '' === $key ) {
			return $value;
		}

		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_group_values( 'emails' );

		$map = array(
			'base_color'           => 'email_header_bg',
			'bg_color'             => 'email_card_bg',
			'body_background_color' => 'email_page_bg',
			'body_text_color'      => 'email_body_text',
			'footer_text_color'    => 'email_muted',
		);

		if ( isset( $map[ $key ], $values[ $map[ $key ] ] ) && '' !== $values[ $map[ $key ] ] ) {
			return $values[ $map[ $key ] ];
		}

		return $value;
	}
}
