<?php
/**
 * NV oOS Design System — Ecommerce Preset
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-tuned token set for product grids and shop pages.
 *
 * Brighter surfaces, tighter spacing, and product-focused typography compared
 * to the Minimal preset, with a matching light email palette.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Preset_Ecommerce extends NV_oOS_Design_System_Preset_Minimal {

	/**
	 * Optional. Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Ecommerce', 'nvoos-design-system' );
	}

	/**
	 * Optional. Preset description.
	 *
	 * @return string
	 */
	public function description() {
		return __(
			'Optimised for product grids and shop pages. Brighter surfaces and tighter spacing.',
			'nvoos-design-system'
		);
	}

	/**
	 * Override token values for an ecommerce feel.
	 *
	 * @return array<string, string>
	 */
	public function token_values() {
		return array(
			'color_surface'        => '#ffffff',
			'color_surface_hover'  => '#f5f5f5',
			'color_text_primary'   => '#1a1a1a',
			'color_text_secondary' => '#666666',
			'color_accent'         => '#2563eb',
			'color_accent_hover'   => '#1d4ed8',
			'color_border'         => '#e5e5e5',
			'shadow_card'          => '0 1px 3px rgba(0, 0, 0, 0.08)',
			'shadow_card_hover'    => '0 4px 12px rgba(0, 0, 0, 0.12)',
			'radius_md'            => '8px',
			'gap_grid'             => '16px',
			'card_image_height'    => '240px',

			// Email palette — bright, product-first.
			'email_page_bg'        => '#f4f6fb',
			'email_card_bg'        => '#ffffff',
			'email_header_bg'      => '#1e3a8a',
			'email_accent'         => '#2563eb',
			'email_accent_2'       => '#1d4ed8',
			'email_body_text'      => '#111827',
			'email_divider'        => '#e5e7eb',
			'email_muted'          => '#6b7280',
			'email_body_size'      => '16px',
			'email_body_height'    => '1.7',
		);
	}
}
