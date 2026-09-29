<?php
/**
 * NV oOS Design System — Directory Preset
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token set tuned for listing/directory-style sites.
 *
 * Neutral, professional palette with generous spacing for readability.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Preset_Directory extends NV_oOS_Design_System_Preset_Minimal {

	/**
	 * Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Directory', 'nvoos-design-system' );
	}

	/**
	 * Preset description.
	 *
	 * @return string
	 */
	public function description() {
		return __(
			'Neutral professional palette with generous spacing. Ideal for directories, team pages, and knowledge bases.',
			'nvoos-design-system'
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

			// Email palette — editorial, generous.
			'email_page_bg'        => '#eef0f3',
			'email_card_bg'        => '#ffffff',
			'email_header_bg'      => '#1f2937',
			'email_accent'         => '#6366f1',
			'email_accent_2'       => '#4f46e5',
			'email_body_text'      => '#111827',
			'email_divider'        => '#d1d5db',
			'email_muted'          => '#6b7280',
			'email_body_size'      => '16px',
			'email_body_height'    => '1.8',
		);
	}
}
