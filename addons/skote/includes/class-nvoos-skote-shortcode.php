<?php
/**
 * NV oOS Skote — Shortcode
 *
 * Renders `[nvoos_skote app="dashboard"]` on the front end. The `app`
 * attribute is forwarded into the React HashRouter as the initial route,
 * letting site authors deep-link to a specific Skote app (Tasks, Calendar,
 * etc.) on a customer-facing page.
 *
 * Capability gating is intentionally strict: by default the shortcode does
 * NOT render for logged-out visitors. Site admins can lower the bar via the
 * `nvoos_skote_shortcode_capability` filter.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend shortcode renderer.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_Shortcode {

	/**
	 * Render the shortcode.
	 *
	 * @since 0.1.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'app'    => 'dashboard',
				'height' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'nvoos_skote'
		);

		$app    = sanitize_key( (string) $atts['app'] );
		$height = preg_replace( '/[^0-9a-z%px\s]/i', '', (string) $atts['height'] );

		/**
		 * Filters the capability required to render the Skote shortcode.
		 *
		 * Default `read` (any logged-in user). Sites that expose Skote on
		 * customer-facing pages should typically tighten this.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Default: `read`.
		 * @param string $app        Requested app slug.
		 */
		$capability = (string) apply_filters( 'nvoos_skote_shortcode_capability', 'read', $app );

		if ( $capability && ! current_user_can( $capability ) ) {
			return '';
		}

		// Make sure assets are enqueued, even if the host theme renders
		// shortcodes after `wp_enqueue_scripts` has already fired.
		NVOOS_Skote_Assets::enqueue(
			array(
				'surface' => 'shortcode',
				'app'     => $app,
			)
		);

		$style = '';
		if ( '' !== $height ) {
			$style = ' style="' . esc_attr( 'min-height:' . $height . ';' ) . '"';
		}

		return sprintf(
			'<div id="%1$s" class="nvoos-skote-root nvoos-skote-shortcode" data-surface="shortcode" data-app="%2$s"%3$s></div>',
			esc_attr( 'nvoos-skote-root' ),
			esc_attr( $app ),
			$style // Already escaped above. phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
