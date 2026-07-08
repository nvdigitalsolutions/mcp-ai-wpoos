<?php
/**
 * NV oOS Crocoblock DS — Directory Preset
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token set tuned for listing/directory-style sites.
 *
 * Neutral, professional palette with generous spacing for readability.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Preset_Directory extends NV_oOS_Crocoblock_DS_Preset_Minimal {

	/**
	 * Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Directory', 'nvoos-crocoblock-ds' );
	}

	/**
	 * Preset description.
	 *
	 * @return string
	 */
	public function description() {
		return __(
			'Neutral professional palette with generous spacing. Ideal for directories, team pages, and knowledge bases.',
			'nvoos-crocoblock-ds'
		);
	}

	/**
	 * Override token values for a directory feel.
	 *
	 * @return array<string, string>
	 */
	public function token_values() {
		return array(
			'color_surface'        => '#fafafa',
			'color_surface_hover'  => '#f0f0f0',
			'color_text_primary'   => '#1a1a1a',
			'color_text_secondary' => '#555555',
			'color_accent'         => '#6366f1',
			'color_accent_hover'   => '#4f46e5',
			'color_border'         => '#e0e0e0',
			'shadow_card'          => '0 1px 2px rgba(0, 0, 0, 0.06)',
			'shadow_card_hover'    => '0 2px 8px rgba(0, 0, 0, 0.1)',
			'radius_md'            => '4px',
			'gap_grid'             => '24px',
			'gap_filter'           => '40px',
			'card_image_height'    => '180px',
		);
	}
}
