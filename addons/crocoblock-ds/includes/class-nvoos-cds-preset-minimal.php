<?php
/**
 * NV oOS Crocoblock DS — Minimal Preset
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bare-minimum design token set (~55 tokens).
 *
 * This is the default preset applied on plugin activation. It provides neutral
 * dark-theme defaults that work well as a starting point for most Crocoblock
 * sites.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Preset_Minimal implements NV_oOS_Crocoblock_DS_Data_Preset {

	/**
	 * Optional. Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Minimal (Default)', 'nvoos-crocoblock-ds' );
	}

	/**
	 * Optional. Preset description.
	 *
	 * @return string
	 */
	public function description() {
		return __(
			'Neutral dark-theme tokens. A clean starting point for any Crocoblock site.',
			'nvoos-crocoblock-ds'
		);
	}

	/**
	 * Token definitions — the canonical list of all tokens in this preset.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function definitions() {
		return array(
			// ── Colors ──────────────────────────────────────────────
			array(
				'id'          => 'color_surface',
				'label'       => __( 'Surface', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#1a1a1a',
				'description' => __( 'Background colour for cards, filter buttons, and form inputs.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'      => 'color_surface_hover',
				'label'   => __( 'Surface Hover', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#2a2a2a',
			),
			array(
				'id'      => 'color_text_primary',
				'label'   => __( 'Text Primary', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#f5f0e8',
			),
			array(
				'id'      => 'color_text_secondary',
				'label'   => __( 'Text Secondary', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#9a9488',
			),
			array(
				'id'          => 'color_accent',
				'label'       => __( 'Accent', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#8b9f48',
				'description' => __( 'Active filter button background, primary button, selected state.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'      => 'color_accent_hover',
				'label'   => __( 'Accent Hover', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#a3b85a',
			),
			array(
				'id'      => 'color_border',
				'label'   => __( 'Border', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#333333',
			),
			array(
				'id'      => 'color_success',
				'label'   => __( 'Success', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#4caf50',
			),
			array(
				'id'      => 'color_warning',
				'label'   => __( 'Warning', 'nvoos-crocoblock-ds' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#ff9800',
			),

			// ── Typography ─────────────────────────────────────────
			array(
				'id'      => 'font_family',
				'label'   => __( 'Font Family', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
			),
			array(
				'id'      => 'font_size_xs',
				'label'   => __( 'Font Size XS', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '12px',
			),
			array(
				'id'      => 'font_size_sm',
				'label'   => __( 'Font Size SM', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '14px',
			),
			array(
				'id'      => 'font_size_base',
				'label'   => __( 'Font Size Base', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '16px',
			),
			array(
				'id'      => 'font_size_lg',
				'label'   => __( 'Font Size LG', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '20px',
			),
			array(
				'id'      => 'font_size_xl',
				'label'   => __( 'Font Size XL', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '26px',
			),
			array(
				'id'      => 'font_weight_normal',
				'label'   => __( 'Font Weight Normal', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '400',
			),
			array(
				'id'      => 'font_weight_bold',
				'label'   => __( 'Font Weight Bold', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '700',
			),
			array(
				'id'      => 'line_height',
				'label'   => __( 'Line Height', 'nvoos-crocoblock-ds' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '1.5',
			),

			// ── Spacing ────────────────────────────────────────────
			array(
				'id'      => 'space_xs',
				'label'   => __( 'Space XS', 'nvoos-crocoblock-ds' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '4px',
			),
			array(
				'id'      => 'space_sm',
				'label'   => __( 'Space SM', 'nvoos-crocoblock-ds' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '8px',
			),
			array(
				'id'      => 'space_md',
				'label'   => __( 'Space MD', 'nvoos-crocoblock-ds' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '16px',
			),
			array(
				'id'      => 'space_lg',
				'label'   => __( 'Space LG', 'nvoos-crocoblock-ds' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '24px',
			),
			array(
				'id'      => 'space_xl',
				'label'   => __( 'Space XL', 'nvoos-crocoblock-ds' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '40px',
			),
			array(
				'id'          => 'gap_grid',
				'label'       => __( 'Grid Gap', 'nvoos-crocoblock-ds' ),
				'group'       => 'spacing',
				'type'        => 'size',
				'default'     => '20px',
				'description' => __( 'Gap between listing grid items.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'gap_filter',
				'label'       => __( 'Filter Gap', 'nvoos-crocoblock-ds' ),
				'group'       => 'spacing',
				'type'        => 'size',
				'default'     => '30px',
				'description' => __( 'Horizontal gap between filter buttons/pills.', 'nvoos-crocoblock-ds' ),
			),

			// ── Borders ────────────────────────────────────────────
			array(
				'id'      => 'radius_sm',
				'label'   => __( 'Radius SM', 'nvoos-crocoblock-ds' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '3px',
			),
			array(
				'id'      => 'radius_md',
				'label'   => __( 'Radius MD', 'nvoos-crocoblock-ds' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '6px',
			),
			array(
				'id'      => 'radius_lg',
				'label'   => __( 'Radius LG', 'nvoos-crocoblock-ds' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '12px',
			),
			array(
				'id'      => 'border_width',
				'label'   => __( 'Border Width', 'nvoos-crocoblock-ds' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '1px',
			),

			// ── Shadows ────────────────────────────────────────────
			array(
				'id'      => 'shadow_card',
				'label'   => __( 'Card Shadow', 'nvoos-crocoblock-ds' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 2px 8px rgba(0, 0, 0, 0.15)',
			),
			array(
				'id'      => 'shadow_card_hover',
				'label'   => __( 'Card Shadow (Hover)', 'nvoos-crocoblock-ds' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 4px 16px rgba(0, 0, 0, 0.25)',
			),
			array(
				'id'      => 'shadow_dropdown',
				'label'   => __( 'Dropdown Shadow', 'nvoos-crocoblock-ds' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 4px 12px rgba(0, 0, 0, 0.3)',
			),

			// ── Sizing ─────────────────────────────────────────────
			array(
				'id'      => 'filter_button_min_width',
				'label'   => __( 'Filter Button Min Width', 'nvoos-crocoblock-ds' ),
				'group'   => 'sizing',
				'type'    => 'size',
				'default' => '80px',
			),
			array(
				'id'          => 'card_image_height',
				'label'       => __( 'Card Image Height', 'nvoos-crocoblock-ds' ),
				'group'       => 'sizing',
				'type'        => 'size',
				'default'     => '200px',
				'description' => __( 'Default height for listing card images.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'input_height',
				'label'       => __( 'Input Height', 'nvoos-crocoblock-ds' ),
				'group'       => 'sizing',
				'type'        => 'size',
				'default'     => '44px',
				'description' => __( 'Height for form inputs and search fields.', 'nvoos-crocoblock-ds' ),
			),

			// ── Transitions ────────────────────────────────────────
			array(
				'id'      => 'transition_fast',
				'label'   => __( 'Transition Fast', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '150ms ease',
			),
			array(
				'id'      => 'transition_normal',
				'label'   => __( 'Transition Normal', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '300ms ease',
			),

			// ── Animations / Easing ────────────────────────────
			array(
				'id'          => 'easing_standard',
				'label'       => __( 'Easing Standard', 'nvoos-crocoblock-ds' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0.2, 0, 0, 1)',
				'description' => __( 'Material Design standard easing. Use for most UI transitions.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'easing_decelerate',
				'label'       => __( 'Easing Decelerate', 'nvoos-crocoblock-ds' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0, 0, 0.2, 1)',
				'description' => __( 'For elements entering the screen (fade in, slide up).', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'easing_accelerate',
				'label'       => __( 'Easing Accelerate', 'nvoos-crocoblock-ds' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0.3, 0, 1, 1)',
				'description' => __( 'For elements exiting the screen.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'      => 'duration_instant',
				'label'   => __( 'Duration Instant', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '100ms',
			),
			array(
				'id'      => 'duration_short',
				'label'   => __( 'Duration Short', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '200ms',
			),
			array(
				'id'      => 'duration_medium',
				'label'   => __( 'Duration Medium', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '300ms',
			),
			array(
				'id'      => 'duration_long',
				'label'   => __( 'Duration Long', 'nvoos-crocoblock-ds' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '500ms',
			),

			// ── Accessibility (dark mode + high contrast variants) ──
			// These override their base token when the corresponding
			// @media query matches. They only take effect when their
			// value differs from the default.
			array(
				'id'          => 'color_surface_dark',
				'label'       => __( 'Surface (Dark)', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#121212',
				'description' => __( 'Dark-mode surface override. Applied inside @media (prefers-color-scheme: dark).', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'color_text_primary_dark',
				'label'       => __( 'Text Primary (Dark)', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#e0e0e0',
				'description' => __( 'Dark-mode text color. Overrides --cds-color-text-primary.', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'color_border_hc',
				'label'       => __( 'Border (High Contrast)', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#ffffff',
				'description' => __( 'High-contrast border override for @media (prefers-contrast: high).', 'nvoos-crocoblock-ds' ),
			),
			array(
				'id'          => 'color_text_secondary_hc',
				'label'       => __( 'Text Secondary (HC)', 'nvoos-crocoblock-ds' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#ffffff',
				'description' => __( 'High-contrast text override for @media (prefers-contrast: high).', 'nvoos-crocoblock-ds' ),
			),
		);
	}

	/**
	 * Minimal preset uses factory defaults for all tokens — no overrides.
	 *
	 * @return array<string, string>
	 */
	public function token_values() {
		return array();
	}
}
