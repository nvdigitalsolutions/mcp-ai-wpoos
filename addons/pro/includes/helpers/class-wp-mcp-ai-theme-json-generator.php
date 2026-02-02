<?php
/**
 * Theme JSON Generator Helper
 *
 * Generates comprehensive theme.json files following WordPress 2025 best practices
 * and industry standards.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.3.0
 *
 * @see https://developer.wordpress.org/themes/global-settings-and-styles/
 * @see https://developer.wordpress.org/themes/core-concepts/global-settings-and-styles/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme JSON Generator Helper Class
 *
 * Provides comprehensive theme.json generation following WordPress 2025 standards.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Theme_JSON_Generator {

	/**
	 * Current theme.json schema version.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Schema URL for JSON validation.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const SCHEMA_URL = 'https://schemas.wp.org/trunk/theme.json';

	/**
	 * Generate a complete theme.json structure.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Configuration arguments.
	 * @return array Complete theme.json structure.
	 */
	public static function generate( $args = array() ) {
		$defaults = array(
			'theme_name'       => 'Custom Theme',
			'theme_type'       => 'block', // 'classic', 'block', 'hybrid'.
			'color_palette'    => self::get_default_color_palette(),
			'typography'       => self::get_default_typography(),
			'spacing'          => self::get_default_spacing(),
			'custom_templates' => array(),
			'template_parts'   => self::get_default_template_parts(),
			'patterns'         => array(),
			'enable_features'  => array(),
			'disable_features' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$theme_json = array(
			'$schema' => self::SCHEMA_URL,
			'version' => self::SCHEMA_VERSION,
		);

		// Add settings section.
		$theme_json['settings'] = self::generate_settings( $args );

		// Add styles section.
		$theme_json['styles'] = self::generate_styles( $args );

		// Add custom templates if provided.
		if ( ! empty( $args['custom_templates'] ) ) {
			$theme_json['customTemplates'] = self::generate_custom_templates( $args['custom_templates'] );
		}

		// Add template parts if provided.
		if ( ! empty( $args['template_parts'] ) ) {
			$theme_json['templateParts'] = self::generate_template_parts( $args['template_parts'] );
		}

		// Add patterns if provided.
		if ( ! empty( $args['patterns'] ) ) {
			$theme_json['patterns'] = $args['patterns'];
		}

		return $theme_json;
	}

	/**
	 * Generate settings section.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Configuration arguments.
	 * @return array Settings section.
	 */
	private static function generate_settings( $args ) {
		$settings = array(
			'appearanceTools'               => true,
			'useRootPaddingAwareAlignments' => true,
		);

		// Color settings.
		$settings['color'] = array(
			'palette'          => $args['color_palette'],
			'custom'           => ! in_array( 'custom-colors', $args['disable_features'], true ),
			'customGradient'   => ! in_array( 'custom-gradients', $args['disable_features'], true ),
			'defaultPalette'   => false,
			'defaultGradients' => false,
		);

		// Typography settings.
		$settings['typography'] = array(
			'fontFamilies'   => $args['typography']['font_families'],
			'fontSizes'      => $args['typography']['font_sizes'],
			'customFontSize' => ! in_array( 'custom-font-sizes', $args['disable_features'], true ),
			'fluid'          => true,
			'lineHeight'     => true,
			'letterSpacing'  => true,
			'textDecoration' => true,
			'textTransform'  => true,
			'dropCap'        => false,
		);

		// Spacing settings.
		$settings['spacing'] = array(
			'spacingScale'      => $args['spacing']['scale'],
			'units'             => array( 'px', 'em', 'rem', '%', 'vh', 'vw' ),
			'customSpacingSize' => true,
			'padding'           => true,
			'margin'            => true,
			'blockGap'          => true,
		);

		// Layout settings.
		$settings['layout'] = array(
			'contentSize' => '640px',
			'wideSize'    => '1280px',
		);

		// Border settings.
		$settings['border'] = array(
			'color'  => true,
			'radius' => true,
			'style'  => true,
			'width'  => true,
		);

		// Dimensions settings.
		$settings['dimensions'] = array(
			'minHeight' => true,
		);

		// Shadow settings.
		$settings['shadow'] = array(
			'defaultPresets' => true,
			'presets'        => self::get_default_shadow_presets(),
		);

		return $settings;
	}

	/**
	 * Generate styles section.
	 *
	 * @since 1.3.0
	 *
	 * @param array $args Configuration arguments.
	 * @return array Styles section.
	 */
	private static function generate_styles( $args ) {
		$styles = array(
			'color'      => array(
				'background' => 'var(--wp--preset--color--base)',
				'text'       => 'var(--wp--preset--color--contrast)',
			),
			'typography' => array(
				'fontSize'   => 'var(--wp--preset--font-size--medium)',
				'fontFamily' => 'var(--wp--preset--font-family--body)',
				'lineHeight' => '1.6',
			),
			'spacing'    => array(
				'blockGap' => 'var(--wp--preset--spacing--medium)',
			),
		);

		// Element styles.
		$styles['elements'] = array(
			'link'    => array(
				'color'  => array(
					'text' => 'var(--wp--preset--color--primary)',
				),
				':hover' => array(
					'color' => array(
						'text' => 'var(--wp--preset--color--primary-hover)',
					),
				),
			),
			'heading' => array(
				'typography' => array(
					'fontFamily' => 'var(--wp--preset--font-family--heading)',
					'fontWeight' => '700',
					'lineHeight' => '1.2',
				),
			),
			'button'  => array(
				'color'   => array(
					'background' => 'var(--wp--preset--color--primary)',
					'text'       => 'var(--wp--preset--color--base)',
				),
				'border'  => array(
					'radius' => '4px',
				),
				'spacing' => array(
					'padding' => array(
						'top'    => 'var(--wp--preset--spacing--small)',
						'right'  => 'var(--wp--preset--spacing--medium)',
						'bottom' => 'var(--wp--preset--spacing--small)',
						'left'   => 'var(--wp--preset--spacing--medium)',
					),
				),
				':hover'  => array(
					'color' => array(
						'background' => 'var(--wp--preset--color--primary-hover)',
					),
				),
			),
		);

		// Block-specific styles.
		$styles['blocks'] = array(
			'core/paragraph' => array(
				'spacing' => array(
					'margin' => array(
						'bottom' => 'var(--wp--preset--spacing--small)',
					),
				),
			),
			'core/heading'   => array(
				'spacing' => array(
					'margin' => array(
						'top'    => 'var(--wp--preset--spacing--large)',
						'bottom' => 'var(--wp--preset--spacing--small)',
					),
				),
			),
			'core/image'     => array(
				'border' => array(
					'radius' => '8px',
				),
			),
		);

		return $styles;
	}

	/**
	 * Generate custom templates section.
	 *
	 * @since 1.3.0
	 *
	 * @param array $templates Template definitions.
	 * @return array Custom templates section.
	 */
	private static function generate_custom_templates( $templates ) {
		$custom_templates = array();

		foreach ( $templates as $template ) {
			$custom_templates[] = array(
				'name'      => isset( $template['name'] ) ? sanitize_title( $template['name'] ) : '',
				'title'     => isset( $template['title'] ) ? sanitize_text_field( $template['title'] ) : '',
				'postTypes' => isset( $template['post_types'] ) ? array_map( 'sanitize_key', (array) $template['post_types'] ) : array( 'page' ),
			);
		}

		return $custom_templates;
	}

	/**
	 * Generate template parts section.
	 *
	 * @since 1.3.0
	 *
	 * @param array $parts Template part definitions.
	 * @return array Template parts section.
	 */
	private static function generate_template_parts( $parts ) {
		$template_parts = array();

		foreach ( $parts as $part ) {
			$template_parts[] = array(
				'name'  => isset( $part['name'] ) ? sanitize_title( $part['name'] ) : '',
				'title' => isset( $part['title'] ) ? sanitize_text_field( $part['title'] ) : '',
				'area'  => isset( $part['area'] ) ? sanitize_key( $part['area'] ) : 'uncategorized',
			);
		}

		return $template_parts;
	}

	/**
	 * Get default color palette.
	 *
	 * Following 2025 best practices with semantic naming.
	 *
	 * @since 1.3.0
	 *
	 * @return array Default color palette.
	 */
	private static function get_default_color_palette() {
		return array(
			array(
				'name'  => 'Base',
				'slug'  => 'base',
				'color' => '#ffffff',
			),
			array(
				'name'  => 'Contrast',
				'slug'  => 'contrast',
				'color' => '#000000',
			),
			array(
				'name'  => 'Primary',
				'slug'  => 'primary',
				'color' => '#007cba',
			),
			array(
				'name'  => 'Primary Hover',
				'slug'  => 'primary-hover',
				'color' => '#005a87',
			),
			array(
				'name'  => 'Secondary',
				'slug'  => 'secondary',
				'color' => '#005a87',
			),
			array(
				'name'  => 'Accent',
				'slug'  => 'accent',
				'color' => '#d63638',
			),
			array(
				'name'  => 'Neutral',
				'slug'  => 'neutral',
				'color' => '#f0f0f1',
			),
			array(
				'name'  => 'Neutral Dark',
				'slug'  => 'neutral-dark',
				'color' => '#50575e',
			),
		);
	}

	/**
	 * Get default typography settings.
	 *
	 * Following 2025 best practices with fluid typography.
	 *
	 * @since 1.3.0
	 *
	 * @return array Default typography settings.
	 */
	private static function get_default_typography() {
		return array(
			'font_families' => array(
				array(
					'name'       => 'Body',
					'slug'       => 'body',
					'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
				),
				array(
					'name'       => 'Heading',
					'slug'       => 'heading',
					'fontFamily' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
				),
				array(
					'name'       => 'Monospace',
					'slug'       => 'monospace',
					'fontFamily' => 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace',
				),
			),
			'font_sizes'    => array(
				array(
					'name'  => 'Small',
					'slug'  => 'small',
					'size'  => '0.875rem',
					'fluid' => array(
						'min' => '0.875rem',
						'max' => '1rem',
					),
				),
				array(
					'name'  => 'Medium',
					'slug'  => 'medium',
					'size'  => '1rem',
					'fluid' => false,
				),
				array(
					'name'  => 'Large',
					'slug'  => 'large',
					'size'  => '1.25rem',
					'fluid' => array(
						'min' => '1.125rem',
						'max' => '1.5rem',
					),
				),
				array(
					'name'  => 'Extra Large',
					'slug'  => 'x-large',
					'size'  => '1.75rem',
					'fluid' => array(
						'min' => '1.5rem',
						'max' => '2rem',
					),
				),
				array(
					'name'  => '2X Large',
					'slug'  => 'xx-large',
					'size'  => '2.5rem',
					'fluid' => array(
						'min' => '2rem',
						'max' => '3rem',
					),
				),
			),
		);
	}

	/**
	 * Get default spacing scale.
	 *
	 * Following 2025 best practices with consistent spacing.
	 *
	 * @since 1.3.0
	 *
	 * @return array Default spacing scale.
	 */
	private static function get_default_spacing() {
		return array(
			'scale' => array(
				'operator'   => '*',
				'increment'  => 1.5,
				'steps'      => 7,
				'mediumStep' => 1.5,
				'unit'       => 'rem',
			),
		);
	}

	/**
	 * Get default shadow presets.
	 *
	 * Following 2025 best practices for depth and elevation.
	 *
	 * @since 1.3.0
	 *
	 * @return array Default shadow presets.
	 */
	private static function get_default_shadow_presets() {
		return array(
			array(
				'name'   => 'Small',
				'slug'   => 'small',
				'shadow' => '0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24)',
			),
			array(
				'name'   => 'Medium',
				'slug'   => 'medium',
				'shadow' => '0 3px 6px rgba(0, 0, 0, 0.15), 0 2px 4px rgba(0, 0, 0, 0.12)',
			),
			array(
				'name'   => 'Large',
				'slug'   => 'large',
				'shadow' => '0 10px 20px rgba(0, 0, 0, 0.15), 0 3px 6px rgba(0, 0, 0, 0.10)',
			),
			array(
				'name'   => 'Extra Large',
				'slug'   => 'x-large',
				'shadow' => '0 15px 25px rgba(0, 0, 0, 0.15), 0 5px 10px rgba(0, 0, 0, 0.05)',
			),
		);
	}

	/**
	 * Get default template parts.
	 *
	 * Standard template parts following WordPress conventions.
	 *
	 * @since 1.3.0
	 *
	 * @return array Default template parts.
	 */
	private static function get_default_template_parts() {
		return array(
			array(
				'name'  => 'header',
				'title' => 'Header',
				'area'  => 'header',
			),
			array(
				'name'  => 'footer',
				'title' => 'Footer',
				'area'  => 'footer',
			),
		);
	}

	/**
	 * Validate theme.json structure.
	 *
	 * @since 1.3.0
	 *
	 * @param array $theme_json Theme JSON data.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate( $theme_json ) {
		// Check required fields.
		if ( ! isset( $theme_json['version'] ) ) {
			return new WP_Error( 'missing_version', __( 'theme.json must include a version field.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_int( $theme_json['version'] ) || $theme_json['version'] < 1 ) {
			return new WP_Error( 'invalid_version', __( 'theme.json version must be an integer >= 1.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate settings structure if present.
		if ( isset( $theme_json['settings'] ) && ! is_array( $theme_json['settings'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'theme.json settings must be an object.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate styles structure if present.
		if ( isset( $theme_json['styles'] ) && ! is_array( $theme_json['styles'] ) ) {
			return new WP_Error( 'invalid_styles', __( 'theme.json styles must be an object.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate custom templates if present.
		if ( isset( $theme_json['customTemplates'] ) ) {
			if ( ! is_array( $theme_json['customTemplates'] ) ) {
				return new WP_Error( 'invalid_custom_templates', __( 'theme.json customTemplates must be an array.', 'mcp-ai-wpoos-pro' ) );
			}

			foreach ( $theme_json['customTemplates'] as $template ) {
				if ( ! isset( $template['name'] ) || ! isset( $template['title'] ) ) {
					return new WP_Error( 'invalid_template', __( 'Each custom template must have name and title fields.', 'mcp-ai-wpoos-pro' ) );
				}
			}
		}

		// Validate template parts if present.
		if ( isset( $theme_json['templateParts'] ) ) {
			if ( ! is_array( $theme_json['templateParts'] ) ) {
				return new WP_Error( 'invalid_template_parts', __( 'theme.json templateParts must be an array.', 'mcp-ai-wpoos-pro' ) );
			}

			foreach ( $theme_json['templateParts'] as $part ) {
				if ( ! isset( $part['name'] ) || ! isset( $part['title'] ) ) {
					return new WP_Error( 'invalid_template_part', __( 'Each template part must have name and title fields.', 'mcp-ai-wpoos-pro' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Convert theme.json array to JSON string.
	 *
	 * @since 1.3.0
	 *
	 * @param array $theme_json Theme JSON data.
	 * @param bool  $pretty     Whether to pretty-print the JSON.
	 * @return string JSON string.
	 */
	public static function to_json( $theme_json, $pretty = true ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		return wp_json_encode( $theme_json, $flags );
	}

	/**
	 * Get industry-specific color palette.
	 *
	 * @since 1.3.0
	 *
	 * @param string $industry Industry type.
	 * @return array Color palette for industry.
	 */
	public static function get_industry_color_palette( $industry ) {
		$palettes = array(
			'technology' => array(
				array(
					'name'  => 'Base',
					'slug'  => 'base',
					'color' => '#ffffff',
				),
				array(
					'name'  => 'Contrast',
					'slug'  => 'contrast',
					'color' => '#0a0a0a',
				),
				array(
					'name'  => 'Primary',
					'slug'  => 'primary',
					'color' => '#0066cc',
				),
				array(
					'name'  => 'Primary Hover',
					'slug'  => 'primary-hover',
					'color' => '#0052a3',
				),
				array(
					'name'  => 'Secondary',
					'slug'  => 'secondary',
					'color' => '#00cc99',
				),
				array(
					'name'  => 'Accent',
					'slug'  => 'accent',
					'color' => '#ff6600',
				),
			),
			'healthcare' => array(
				array(
					'name'  => 'Base',
					'slug'  => 'base',
					'color' => '#ffffff',
				),
				array(
					'name'  => 'Contrast',
					'slug'  => 'contrast',
					'color' => '#1a1a1a',
				),
				array(
					'name'  => 'Primary',
					'slug'  => 'primary',
					'color' => '#0077be',
				),
				array(
					'name'  => 'Primary Hover',
					'slug'  => 'primary-hover',
					'color' => '#005a8f',
				),
				array(
					'name'  => 'Secondary',
					'slug'  => 'secondary',
					'color' => '#00a86b',
				),
				array(
					'name'  => 'Accent',
					'slug'  => 'accent',
					'color' => '#e74c3c',
				),
			),
			'finance'    => array(
				array(
					'name'  => 'Base',
					'slug'  => 'base',
					'color' => '#ffffff',
				),
				array(
					'name'  => 'Contrast',
					'slug'  => 'contrast',
					'color' => '#1c1c1c',
				),
				array(
					'name'  => 'Primary',
					'slug'  => 'primary',
					'color' => '#003366',
				),
				array(
					'name'  => 'Primary Hover',
					'slug'  => 'primary-hover',
					'color' => '#002244',
				),
				array(
					'name'  => 'Secondary',
					'slug'  => 'secondary',
					'color' => '#006699',
				),
				array(
					'name'  => 'Accent',
					'slug'  => 'accent',
					'color' => '#d4af37',
				),
			),
			'ecommerce'  => array(
				array(
					'name'  => 'Base',
					'slug'  => 'base',
					'color' => '#ffffff',
				),
				array(
					'name'  => 'Contrast',
					'slug'  => 'contrast',
					'color' => '#000000',
				),
				array(
					'name'  => 'Primary',
					'slug'  => 'primary',
					'color' => '#e91e63',
				),
				array(
					'name'  => 'Primary Hover',
					'slug'  => 'primary-hover',
					'color' => '#c2185b',
				),
				array(
					'name'  => 'Secondary',
					'slug'  => 'secondary',
					'color' => '#ff9800',
				),
				array(
					'name'  => 'Accent',
					'slug'  => 'accent',
					'color' => '#4caf50',
				),
			),
		);

		$industry = sanitize_key( $industry );

		return isset( $palettes[ $industry ] ) ? $palettes[ $industry ] : self::get_default_color_palette();
	}
}
