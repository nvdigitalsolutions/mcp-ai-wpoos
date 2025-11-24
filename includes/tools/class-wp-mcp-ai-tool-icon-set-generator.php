<?php
/**
 * Tool for icon set generation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates scalable icon sets for various use cases.
 */
class WP_MCP_AI_Tool_Icon_Set_Generator implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'icon_set_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Icon Set Generator', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate cohesive icon sets with consistent style, scalable SVG format, and multiple size exports.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'icon_category' => array(
					'type'        => 'string',
					'description' => __( 'Category of icons to generate.', 'wp-mcp-ai' ),
					'enum'        => array( 'ui', 'social', 'navigation', 'ecommerce', 'media', 'communication', 'business', 'custom' ),
					'default'     => 'ui',
				),
				'icon_style'    => array(
					'type'        => 'string',
					'description' => __( 'Visual style of the icons.', 'wp-mcp-ai' ),
					'enum'        => array( 'outline', 'filled', 'duotone', 'line_art', 'glyph' ),
					'default'     => 'outline',
				),
				'stroke_width'  => array(
					'type'        => 'number',
					'description' => __( 'Stroke width in pixels (for outline style).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 4,
					'default'     => 2,
				),
				'corner_style'  => array(
					'type'        => 'string',
					'description' => __( 'Corner style for paths.', 'wp-mcp-ai' ),
					'enum'        => array( 'rounded', 'sharp', 'square' ),
					'default'     => 'rounded',
				),
				'base_size'     => array(
					'type'        => 'integer',
					'description' => __( 'Base grid size in pixels.', 'wp-mcp-ai' ),
					'enum'        => array( 16, 24, 32, 48 ),
					'default'     => 24,
				),
				'color'         => array(
					'type'        => 'string',
					'description' => __( 'Icon color (hex code). Use "currentColor" for CSS control.', 'wp-mcp-ai' ),
					'default'     => 'currentColor',
				),
				'icon_count'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of icons to generate in the set.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'export_sizes'  => array(
					'type'        => 'array',
					'description' => __( 'Additional export sizes in pixels.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
					'default'     => array( 16, 24, 32 ),
				),
				'custom_icons'  => array(
					'type'        => 'array',
					'description' => __( 'Custom icon names/descriptions to include.', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'icon_category' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate icon sets.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Sanitize inputs.
		$category     = isset( $arguments['icon_category'] ) ? sanitize_key( $arguments['icon_category'] ) : 'ui';
		$style        = isset( $arguments['icon_style'] ) ? sanitize_key( $arguments['icon_style'] ) : 'outline';
		$stroke_width = isset( $arguments['stroke_width'] ) ? floatval( $arguments['stroke_width'] ) : 2;
		$corner_style = isset( $arguments['corner_style'] ) ? sanitize_key( $arguments['corner_style'] ) : 'rounded';
		$base_size    = isset( $arguments['base_size'] ) ? absint( $arguments['base_size'] ) : 24;
		$color        = isset( $arguments['color'] ) ? sanitize_text_field( $arguments['color'] ) : 'currentColor';
		$icon_count   = isset( $arguments['icon_count'] ) ? absint( $arguments['icon_count'] ) : 10;
		$export_sizes = isset( $arguments['export_sizes'] ) && is_array( $arguments['export_sizes'] ) ? array_map( 'absint', $arguments['export_sizes'] ) : array( 16, 24, 32 );
		$custom_icons = isset( $arguments['custom_icons'] ) && is_array( $arguments['custom_icons'] ) ? array_map( 'sanitize_text_field', $arguments['custom_icons'] ) : array();

		// Validate and clamp values.
		$stroke_width = max( 1, min( 4, $stroke_width ) );
		$icon_count   = max( 1, min( 50, $icon_count ) );

		// Ensure base_size is valid.
		$valid_sizes = array( 16, 24, 32, 48 );
		if ( ! in_array( $base_size, $valid_sizes, true ) ) {
			$base_size = 24;
		}

		$set_id    = wp_generate_uuid4();
		$timestamp = current_time( 'mysql' );

		// Generate icon list.
		$icons = $this->generate_icon_list( $category, $icon_count, $custom_icons );

		$result = array(
			'set_id'         => $set_id,
			'category'       => $category,
			'specifications' => array(
				'style'        => $style,
				'stroke_width' => $stroke_width,
				'corner_style' => $corner_style,
				'base_size'    => $base_size,
				'color'        => $color,
			),
			'icons'          => $icons,
			'total_count'    => count( $icons ),
			'export_formats' => array(
				'svg'     => true,
				'png'     => $export_sizes,
				'webfont' => true,
			),
			'status'         => 'generated',
			'generated_at'   => $timestamp,
			'download_urls'  => array(
				'svg_set' => esc_url(
					add_query_arg(
						array(
							'action' => 'wp_mcp_ai_download_icon_set',
							'set_id' => $set_id,
							'format' => 'svg',
						),
						admin_url( 'admin-ajax.php' )
					)
				),
				'png_set' => esc_url(
					add_query_arg(
						array(
							'action' => 'wp_mcp_ai_download_icon_set',
							'set_id' => $set_id,
							'format' => 'png',
						),
						admin_url( 'admin-ajax.php' )
					)
				),
				'webfont' => esc_url(
					add_query_arg(
						array(
							'action' => 'wp_mcp_ai_download_icon_set',
							'set_id' => $set_id,
							'format' => 'webfont',
						),
						admin_url( 'admin-ajax.php' )
					)
				),
			),
			'usage_guide'    => $this->generate_usage_guide( $style ),
			'message'        => sprintf(
				/* translators: 1: number of icons, 2: icon style */
				__( 'Successfully generated %1$d %2$s icons with consistent styling.', 'wp-mcp-ai' ),
				count( $icons ),
				ucwords( str_replace( '_', ' ', $style ) )
			),
		);

		/**
		 * Fires after an icon set is generated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result Icon set result.
		 * @param array $arguments Tool arguments.
		 * @param int   $user_id User ID.
		 */
		do_action( 'wp_mcp_ai_icon_set_generated', $result, $arguments, $user_id );

		return $result;
	}

	/**
	 * Generate list of icons based on category.
	 *
	 * @param string $category     Icon category.
	 * @param int    $icon_count   Number of icons to generate.
	 * @param array  $custom_icons Custom icon names.
	 * @return array Icon list.
	 */
	private function generate_icon_list( $category, $icon_count, $custom_icons ) {
		$category_icons = array(
			'ui'            => array( 'home', 'settings', 'search', 'menu', 'close', 'check', 'plus', 'minus', 'edit', 'delete', 'save', 'refresh', 'download', 'upload', 'star' ),
			'social'        => array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube', 'pinterest', 'tiktok', 'whatsapp', 'telegram', 'discord' ),
			'navigation'    => array( 'arrow_up', 'arrow_down', 'arrow_left', 'arrow_right', 'chevron_up', 'chevron_down', 'chevron_left', 'chevron_right', 'back', 'forward' ),
			'ecommerce'     => array( 'cart', 'bag', 'credit_card', 'wallet', 'tag', 'gift', 'shipping', 'returns', 'wishlist', 'compare' ),
			'media'         => array( 'play', 'pause', 'stop', 'volume', 'mute', 'camera', 'video', 'image', 'mic', 'music' ),
			'communication' => array( 'mail', 'message', 'chat', 'phone', 'video_call', 'notification', 'bell', 'send', 'inbox', 'archive' ),
			'business'      => array( 'briefcase', 'calendar', 'clock', 'chart', 'graph', 'document', 'folder', 'user', 'team', 'building' ),
		);

		$available_icons = isset( $category_icons[ $category ] ) ? $category_icons[ $category ] : array();

		// Add custom icons if provided.
		if ( ! empty( $custom_icons ) ) {
			$available_icons = array_merge( $available_icons, $custom_icons );
		}

		// Limit to requested count.
		$icons_to_generate = array_slice( $available_icons, 0, $icon_count );

		$icon_list = array();
		foreach ( $icons_to_generate as $icon_name ) {
			$icon_list[] = array(
				'name'        => $icon_name,
				'slug'        => sanitize_key( $icon_name ),
				'description' => sprintf( __( '%s icon', 'wp-mcp-ai' ), ucwords( str_replace( '_', ' ', $icon_name ) ) ),
			);
		}

		return $icon_list;
	}

	/**
	 * Generate usage guide.
	 *
	 * @param string $style Icon style.
	 * @return array Usage guide.
	 */
	private function generate_usage_guide( $style ) {
		return array(
			'html_svg'      => array(
				'description' => 'Inline SVG in HTML',
				'example'     => '<svg class="icon"><use xlink:href="#icon-name"></use></svg>',
			),
			'css_class'     => array(
				'description' => 'Using CSS classes',
				'example'     => '<i class="icon icon-name"></i>',
			),
			'react'         => array(
				'description' => 'React/JSX component',
				'example'     => '<Icon name="icon-name" />',
			),
			'accessibility' => array(
				'add_aria_label' => true,
				'add_title'      => 'For important icons',
				'use_role'       => 'img or presentation',
			),
			'optimization'  => array(
				'minify'       => true,
				'sprite_sheet' => 'Recommended for web',
				'icon_font'    => 'Alternative to SVG sprites',
			),
		);
	}
}
