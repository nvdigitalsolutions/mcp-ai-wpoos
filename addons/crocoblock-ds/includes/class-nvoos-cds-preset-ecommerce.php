<?php
/**
 * NV oOS Crocoblock DS — Ecommerce Preset
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-tuned token set for product grids and shop pages.
 *
 * Brighter surfaces, tighter spacing, and product-focused typography compared
 * to the Minimal preset.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Preset_Ecommerce extends NV_oOS_Crocoblock_DS_Preset_Minimal {

	/**
	 * Optional. Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Ecommerce', 'nvoos-crocoblock-ds' );
	}

		/**
		 * Optional. Preset description.
		 *
		 * @return string
		 */
	public function description() {
		return __(
			'Optimised for product grids and shop pages. Brighter surfaces and tighter spacing.',
			'nvoos-crocoblock-ds'
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
		);
	}
}
