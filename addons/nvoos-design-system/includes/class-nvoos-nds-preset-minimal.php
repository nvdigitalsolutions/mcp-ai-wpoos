<?php
/**
 * NV oOS Design System — Minimal Preset
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bare-minimum design token set (~70 tokens).
 *
 * This is the default preset applied on plugin activation. It provides neutral
 * dark-theme defaults that work well as a starting point for most Crocoblock
 * sites, plus a matching email palette for the email template module.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Preset_Minimal implements NV_oOS_Design_System_Data_Preset {

	/**
	 * Optional. Preset name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Minimal (Default)', 'nvoos-design-system' );
	}

	/**
	 * Optional. Preset description.
	 *
	 * @return string
	 */
	public function description() {
		return __(
			'Neutral dark-theme tokens. A clean starting point for any site.',
			'nvoos-design-system'
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
				'label'       => __( 'Surface', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#1a1a1a',
				'description' => __( 'Background colour for cards, filter buttons, and form inputs.', 'nvoos-design-system' ),
			),
			array(
				'id'      => 'color_surface_hover',
				'label'   => __( 'Surface Hover', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#2a2a2a',
			),
			array(
				'id'      => 'color_text_primary',
				'label'   => __( 'Text Primary', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#f5f0e8',
			),
			array(
				'id'      => 'color_text_secondary',
				'label'   => __( 'Text Secondary', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#9a9488',
			),
			array(
				'id'          => 'color_accent',
				'label'       => __( 'Accent', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#8b9f48',
				'description' => __( 'Active filter button background, primary button, selected state.', 'nvoos-design-system' ),
			),
			array(
				'id'      => 'color_accent_hover',
				'label'   => __( 'Accent Hover', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#a3b85a',
			),
			array(
				'id'      => 'color_border',
				'label'   => __( 'Border', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#333333',
			),
			array(
				'id'      => 'color_success',
				'label'   => __( 'Success', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#4caf50',
			),
			array(
				'id'      => 'color_warning',
				'label'   => __( 'Warning', 'nvoos-design-system' ),
				'group'   => 'colors',
				'type'    => 'color',
				'default' => '#ff9800',
			),

			// ── Typography ─────────────────────────────────────────
			array(
				'id'      => 'font_family',
				'label'   => __( 'Font Family', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
			),
			array(
				'id'      => 'font_size_xs',
				'label'   => __( 'Font Size XS', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '12px',
			),
			array(
				'id'      => 'font_size_sm',
				'label'   => __( 'Font Size SM', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '14px',
			),
			array(
				'id'      => 'font_size_base',
				'label'   => __( 'Font Size Base', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '16px',
			),
			array(
				'id'      => 'font_size_lg',
				'label'   => __( 'Font Size LG', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '20px',
			),
			array(
				'id'      => 'font_size_xl',
				'label'   => __( 'Font Size XL', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '26px',
			),
			array(
				'id'      => 'font_weight_normal',
				'label'   => __( 'Font Weight Normal', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '400',
			),
			array(
				'id'      => 'font_weight_bold',
				'label'   => __( 'Font Weight Bold', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'font',
				'default' => '700',
			),
			array(
				'id'      => 'line_height',
				'label'   => __( 'Line Height', 'nvoos-design-system' ),
				'group'   => 'typography',
				'type'    => 'size',
				'default' => '1.5',
			),

			// ── Spacing ────────────────────────────────────────────
			array(
				'id'      => 'space_xs',
				'label'   => __( 'Space XS', 'nvoos-design-system' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '4px',
			),
			array(
				'id'      => 'space_sm',
				'label'   => __( 'Space SM', 'nvoos-design-system' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '8px',
			),
			array(
				'id'      => 'space_md',
				'label'   => __( 'Space MD', 'nvoos-design-system' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '16px',
			),
			array(
				'id'      => 'space_lg',
				'label'   => __( 'Space LG', 'nvoos-design-system' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '24px',
			),
			array(
				'id'      => 'space_xl',
				'label'   => __( 'Space XL', 'nvoos-design-system' ),
				'group'   => 'spacing',
				'type'    => 'size',
				'default' => '40px',
			),
			array(
				'id'          => 'gap_grid',
				'label'       => __( 'Grid Gap', 'nvoos-design-system' ),
				'group'       => 'spacing',
				'type'        => 'size',
				'default'     => '20px',
				'description' => __( 'Gap between listing grid items.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'gap_filter',
				'label'       => __( 'Filter Gap', 'nvoos-design-system' ),
				'group'       => 'spacing',
				'type'        => 'size',
				'default'     => '30px',
				'description' => __( 'Horizontal gap between filter buttons/pills.', 'nvoos-design-system' ),
			),

			// ── Borders ────────────────────────────────────────────
			array(
				'id'      => 'radius_sm',
				'label'   => __( 'Radius SM', 'nvoos-design-system' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '3px',
			),
			array(
				'id'      => 'radius_md',
				'label'   => __( 'Radius MD', 'nvoos-design-system' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '6px',
			),
			array(
				'id'      => 'radius_lg',
				'label'   => __( 'Radius LG', 'nvoos-design-system' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '12px',
			),
			array(
				'id'      => 'border_width',
				'label'   => __( 'Border Width', 'nvoos-design-system' ),
				'group'   => 'borders',
				'type'    => 'size',
				'default' => '1px',
			),

			// ── Shadows ────────────────────────────────────────────
			array(
				'id'      => 'shadow_card',
				'label'   => __( 'Card Shadow', 'nvoos-design-system' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 2px 8px rgba(0, 0, 0, 0.15)',
			),
			array(
				'id'      => 'shadow_card_hover',
				'label'   => __( 'Card Shadow (Hover)', 'nvoos-design-system' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 4px 16px rgba(0, 0, 0, 0.25)',
			),
			array(
				'id'      => 'shadow_dropdown',
				'label'   => __( 'Dropdown Shadow', 'nvoos-design-system' ),
				'group'   => 'shadows',
				'type'    => 'shadow',
				'default' => '0 4px 12px rgba(0, 0, 0, 0.3)',
			),

			// ── Sizing ─────────────────────────────────────────────
			array(
				'id'      => 'filter_button_min_width',
				'label'   => __( 'Filter Button Min Width', 'nvoos-design-system' ),
				'group'   => 'sizing',
				'type'    => 'size',
				'default' => '80px',
			),
			array(
				'id'          => 'card_image_height',
				'label'       => __( 'Card Image Height', 'nvoos-design-system' ),
				'group'       => 'sizing',
				'type'        => 'size',
				'default'     => '200px',
				'description' => __( 'Default height for listing card images.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'input_height',
				'label'       => __( 'Input Height', 'nvoos-design-system' ),
				'group'       => 'sizing',
				'type'        => 'size',
				'default'     => '44px',
				'description' => __( 'Height for form inputs and search fields.', 'nvoos-design-system' ),
			),

			// ── Transitions ────────────────────────────────────────
			array(
				'id'      => 'transition_fast',
				'label'   => __( 'Transition Fast', 'nvoos-design-system' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '150ms ease',
			),
			array(
				'id'      => 'transition_normal',
				'label'   => __( 'Transition Normal', 'nvoos-design-system' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '300ms ease',
			),

			// ── Animations / Easing ────────────────────────────
			array(
				'id'          => 'easing_standard',
				'label'       => __( 'Easing Standard', 'nvoos-design-system' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0.2, 0, 0, 1)',
				'description' => __( 'Material Design standard easing. Use for most UI transitions.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'easing_decelerate',
				'label'       => __( 'Easing Decelerate', 'nvoos-design-system' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0, 0, 0.2, 1)',
				'description' => __( 'For elements entering the screen (fade in, slide up).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'easing_accelerate',
				'label'       => __( 'Easing Accelerate', 'nvoos-design-system' ),
				'group'       => 'transitions',
				'type'        => 'transition',
				'default'     => 'cubic-bezier(0.3, 0, 1, 1)',
				'description' => __( 'For elements exiting the screen.', 'nvoos-design-system' ),
			),
			array(
				'id'      => 'duration_instant',
				'label'   => __( 'Duration Instant', 'nvoos-design-system' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '100ms',
			),
			array(
				'id'      => 'duration_short',
				'label'   => __( 'Duration Short', 'nvoos-design-system' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '200ms',
			),
			array(
				'id'      => 'duration_medium',
				'label'   => __( 'Duration Medium', 'nvoos-design-system' ),
				'group'   => 'transitions',
				'type'    => 'transition',
				'default' => '300ms',
			),
			array(
				'id'      => 'duration_long',
				'label'   => __( 'Duration Long', 'nvoos-design-system' ),
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
				'label'       => __( 'Surface (Dark)', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#121212',
				'description' => __( 'Dark-mode surface override. Applied inside @media (prefers-color-scheme: dark).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'color_text_primary_dark',
				'label'       => __( 'Text Primary (Dark)', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#e0e0e0',
				'description' => __( 'Dark-mode text color. Overrides --nds-color-text-primary.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'color_border_hc',
				'label'       => __( 'Border (High Contrast)', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#ffffff',
				'description' => __( 'High-contrast border override for @media (prefers-contrast: high).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'color_text_secondary_hc',
				'label'       => __( 'Text Secondary (HC)', 'nvoos-design-system' ),
				'group'       => 'colors',
				'type'        => 'color',
				'default'     => '#ffffff',
				'description' => __( 'High-contrast text override for @media (prefers-contrast: high).', 'nvoos-design-system' ),
			),

			// ── Email palette (email template module) ──────────────
			// These drive the built-in email templates and are resolved
			// inline at render time (email clients do not support CSS
			// custom properties, so the renderer substitutes values).
			array(
				'id'          => 'email_page_bg',
				'label'       => __( 'Email Page Background', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#f0ede8',
				'description' => __( 'Outer background of outgoing emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_card_bg',
				'label'       => __( 'Email Card Background', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#ffffff',
				'description' => __( 'Content card background of outgoing emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_header_bg',
				'label'       => __( 'Email Header Background', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#0f1e18',
				'description' => __( 'Letterhead / header band background.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_accent',
				'label'       => __( 'Email Accent', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#c9b96e',
				'description' => __( 'Primary email accent (gold rule, logo text).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_accent_2',
				'label'       => __( 'Email Accent 2', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#4d8a7b',
				'description' => __( 'Secondary email accent (links, sub-brand).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_body_text',
				'label'       => __( 'Email Body Text', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#1a2420',
				'description' => __( 'Primary body text colour in emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_divider',
				'label'       => __( 'Email Divider', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#e2ddd6',
				'description' => __( 'Divider rule colour in emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_muted',
				'label'       => __( 'Email Muted', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#2e4a3e',
				'description' => __( 'Muted footer text colour in emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_font',
				'label'       => __( 'Email Font', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'font',
				'default'     => "Georgia, 'Times New Roman', serif",
				'description' => __( 'Font stack used in outgoing emails (system-safe only).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_body_size',
				'label'       => __( 'Email Body Size', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'size',
				'default'     => '16px',
				'description' => __( 'Body font size in emails (WCAG recommends ≥16px).', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_body_height',
				'label'       => __( 'Email Line Height', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'size',
				'default'     => '1.8',
				'description' => __( 'Body line height in emails.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_page_bg_dark',
				'label'       => __( 'Email Page Background (Dark)', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#101010',
				'description' => __( 'Dark-mode page background override.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_card_bg_dark',
				'label'       => __( 'Email Card Background (Dark)', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#1c1c1c',
				'description' => __( 'Dark-mode card background override.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_body_text_dark',
				'label'       => __( 'Email Body Text (Dark)', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#e8e4dc',
				'description' => __( 'Dark-mode body text override.', 'nvoos-design-system' ),
			),
			array(
				'id'          => 'email_divider_dark',
				'label'       => __( 'Email Divider (Dark)', 'nvoos-design-system' ),
				'group'       => 'emails',
				'type'        => 'color',
				'default'     => '#333333',
				'description' => __( 'Dark-mode divider override.', 'nvoos-design-system' ),
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
