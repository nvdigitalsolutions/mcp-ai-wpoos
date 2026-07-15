<?php
/**
 * Shared helper for content format detection, text extraction, and SEO plugin awareness.
 *
 * Tools that read or write post content need to know which content format a post
 * uses (blocks, Elementor, classic HTML) and which SEO plugin is active
 * (Rank Math, Yoast, SEOPress). This static utility class provides those
 * answers in one place so every tool doesn't duplicate the detection logic.
 *
 * @package    WP_MCP_AI
 * @subpackage Helpers
 * @since      1.10.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Format Helper
 *
 * All methods are static — no instance state, no constructor side effects.
 * This follows the helpers/ convention: dependency-free at load time,
 * no persistence, pure utilities.
 *
 * @since 1.10.0
 */
class WP_MCP_AI_Content_Format_Helper {

	/**
	 * Content format constants.
	 *
	 * @since 1.10.0
	 * @var string
	 */
	const FORMAT_BLOCK_EDITOR  = 'block-editor';
	const FORMAT_CLASSIC       = 'classic-editor';
	const FORMAT_ELEMENTOR     = 'elementor';
	const FORMAT_AUTO          = 'auto';

	/**
	 * SEO plugin constants.
	 *
	 * @since 1.10.0
	 * @var string
	 */
	const SEO_RANK_MATH = 'rank_math';
	const SEO_YOAST     = 'yoast';
	const SEO_SEOPRESS  = 'seopress';
	const SEO_NONE      = 'none';

	/**
	 * Cached result of detect_seo_plugin() — only computed once per request.
	 *
	 * @since 1.10.0
	 * @var string|null
	 */
	private static $seo_plugin = null;

	/**
	 * Detect the content format used by a post.
	 *
	 * Checks post meta for page-builder fingerprints before falling back
	 * to the block-editor capability of the post type.
	 *
	 * @since 1.10.0
	 *
	 * @param int $post_id Post ID.
	 * @return string One of the FORMAT_* constants.
	 */
	public static function detect_post_format( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return self::FORMAT_BLOCK_EDITOR;
		}

		// Elementor: check for the canonical meta key.
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return self::FORMAT_ELEMENTOR;
		}

		// Fallback: does the post type support the block editor?
		$post_type = get_post_type( $post_id );
		if ( $post_type
			&& function_exists( 'use_block_editor_for_post_type' )
			&& use_block_editor_for_post_type( $post_type )
		) {
			return self::FORMAT_BLOCK_EDITOR;
		}

		return self::FORMAT_CLASSIC;
	}

	/**
	 * Whether a post was built with Elementor.
	 *
	 * @since 1.10.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor_post( $post_id ) {
		return self::FORMAT_ELEMENTOR === self::detect_post_format( $post_id );
	}

	/**
	 * Whether a post uses the block editor.
	 *
	 * @since 1.10.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_block_editor_post( $post_id ) {
		return self::FORMAT_BLOCK_EDITOR === self::detect_post_format( $post_id );
	}

	/**
	 * Extract human-readable plain text from a post regardless of its format.
	 *
	 * For blocks / classic posts this strips HTML tags from post_content.
	 * For Elementor posts this recursively walks the _elementor_data JSON
	 * and collects text from known widget settings keys.
	 *
	 * @since 1.10.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Plain-text content suitable for AI analysis.
	 */
	public static function extract_readable_text( $post_id ) {
		$format = self::detect_post_format( $post_id );

		if ( self::FORMAT_ELEMENTOR === $format ) {
			$data = get_post_meta( $post_id, '_elementor_data', true );
			return self::extract_text_from_elementor_json( $data );
		}

		$content = get_post_field( 'post_content', $post_id );
		return wp_strip_all_tags( $content, true );
	}

	/**
	 * Recursively extract visible text from Elementor JSON.
	 *
	 * Walks the content tree (containers → widgets) and picks up text from
	 * every widget whose settings contain known text-bearing keys.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $data Decoded Elementor JSON (array, object, or empty).
	 * @return string Concatenated plain text.
	 */
	public static function extract_text_from_elementor_json( $data ) {
		$text   = '';
		$parsed = $data;

		if ( is_string( $parsed ) && '' !== $parsed ) {
			$decoded = json_decode( $parsed, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$parsed = $decoded;
			}
		}

		if ( ! is_array( $parsed ) && ! is_object( $parsed ) ) {
			return '';
		}

		$parsed = (array) $parsed;

		// If this is a widget, pull its text.
		if ( isset( $parsed['elType'] ) && 'widget' === $parsed['elType'] ) {
			$text .= self::extract_widget_text( $parsed );
		}

		// Recurse into nested elements (both 'elements' and 'content' keys).
		foreach ( array( 'elements', 'content' ) as $children_key ) {
			if ( ! empty( $parsed[ $children_key ] ) && is_array( $parsed[ $children_key ] ) ) {
				foreach ( $parsed[ $children_key ] as $child ) {
					$text .= self::extract_text_from_elementor_json( $child );
				}
			}
		}

		return $text;
	}

	/**
	 * Extract text from a single Elementor widget's settings.
	 *
	 * Maps known widgetType values to the settings keys that carry
	 * user-visible text. Unknown widget types are still scanned for
	 * common text-bearing keys.
	 *
	 * @since 1.10.0
	 *
	 * @param array $widget Widget element from Elementor JSON.
	 * @return string Extracted text.
	 */
	private static function extract_widget_text( $widget ) {
		if ( empty( $widget['settings'] ) || ! is_array( $widget['settings'] ) ) {
			return '';
		}

		$settings   = $widget['settings'];
		$widget_type = isset( $widget['widgetType'] ) ? $widget['widgetType'] : '';
		$text        = '';

		// Widget-type-specific extraction.
		switch ( $widget_type ) {
			case 'heading':
				$text .= self::pick_text( $settings, 'title' );
				break;

			case 'text-editor':
				$text .= self::pick_stripped_text( $settings, 'editor' );
				break;

			case 'button':
				$text .= self::pick_text( $settings, 'text' );
				break;

			case 'icon-box':
				$text .= self::pick_text( $settings, 'title_text' );
				$text .= self::pick_stripped_text( $settings, 'description_text' );
				break;

			case 'image-box':
				$text .= self::pick_text( $settings, 'title_text' );
				$text .= self::pick_stripped_text( $settings, 'description_text' );
				break;

			case 'testimonial':
				$text .= self::pick_stripped_text( $settings, 'testimonial_content' );
				$text .= self::pick_text( $settings, 'testimonial_name' );
				$text .= self::pick_text( $settings, 'testimonial_job' );
				break;

			case 'icon-list':
				if ( ! empty( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
					foreach ( $settings['icon_list'] as $item ) {
						$text .= self::pick_text( $item, 'text' );
					}
				}
				break;

			case 'call-to-action':
				$text .= self::pick_text( $settings, 'title' );
				$text .= self::pick_stripped_text( $settings, 'description' );
				$text .= self::pick_text( $settings, 'button' );
				break;

			case 'counter':
				$text .= self::pick_text( $settings, 'prefix' );
				$text .= self::pick_text( $settings, 'title' );
				$text .= self::pick_text( $settings, 'suffix' );
				break;

			case 'progress':
				$text .= self::pick_text( $settings, 'title' );
				$text .= self::pick_text( $settings, 'inner_text' );
				break;

			case 'toggle':
				$text .= self::pick_text( $settings, 'tab_title' );
				$text .= self::pick_stripped_text( $settings, 'tab_content' );
				break;

			case 'accordion':
				if ( ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( $settings['tabs'] as $tab ) {
						$text .= self::pick_text( $tab, 'tab_title' );
						$text .= self::pick_stripped_text( $tab, 'tab_content' );
					}
				}
				break;

			case 'tabs':
				if ( ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( $settings['tabs'] as $tab ) {
						$text .= self::pick_text( $tab, 'tab_title' );
						$text .= self::pick_stripped_text( $tab, 'tab_content' );
					}
				}
				break;

			case 'alert':
				$text .= self::pick_text( $settings, 'alert_title' );
				$text .= self::pick_stripped_text( $settings, 'alert_description' );
				break;

			case 'blockquote':
				$text .= self::pick_stripped_text( $settings, 'blockquote_content' );
				$text .= self::pick_text( $settings, 'tweet_button_label' );
				break;

			case 'price-list':
				if ( ! empty( $settings['price_list'] ) && is_array( $settings['price_list'] ) ) {
					foreach ( $settings['price_list'] as $item ) {
						$text .= self::pick_text( $item, 'title' );
						$text .= self::pick_stripped_text( $item, 'item_description' );
					}
				}
				break;

			case 'price-table':
				$text .= self::pick_text( $settings, 'header_title' );
				$text .= self::pick_stripped_text( $settings, 'header_description' );
				if ( ! empty( $settings['features_list'] ) && is_array( $settings['features_list'] ) ) {
					foreach ( $settings['features_list'] as $feature ) {
						$text .= self::pick_text( $feature, 'text' );
					}
				}
				break;

			case 'flip-box':
				$text .= self::pick_text( $settings, 'title_text_front' );
				$text .= self::pick_stripped_text( $settings, 'description_text_front' );
				$text .= self::pick_text( $settings, 'title_text_back' );
				$text .= self::pick_stripped_text( $settings, 'description_text_back' );
				break;

			case 'slides':
				if ( ! empty( $settings['slides'] ) && is_array( $settings['slides'] ) ) {
					foreach ( $settings['slides'] as $slide ) {
						$text .= self::pick_text( $slide, 'heading' );
						$text .= self::pick_stripped_text( $slide, 'description' );
					}
				}
				break;

			case 'form':
				if ( ! empty( $settings['form_fields'] ) && is_array( $settings['form_fields'] ) ) {
					foreach ( $settings['form_fields'] as $field ) {
						$text .= self::pick_text( $field, 'field_label' );
					}
				}
				break;

			default:
				// Fallback: scan common text keys for unknown widget types.
				$text .= self::scan_common_text_keys( $settings );
				break;
		}

		// Always check generic text-bearing keys that might appear regardless
		// of widgetType.
		$text .= self::pick_text( $settings, 'title' );
		$text .= self::pick_text( $settings, 'title_text' );
		$text .= self::pick_stripped_text( $settings, 'description' );
		$text .= self::pick_stripped_text( $settings, 'content' );

		return $text;
	}

	/**
	 * Safely pick a plain-text value from an array, appending a space.
	 *
	 * @since 1.10.0
	 *
	 * @param array  $data Array to read from.
	 * @param string $key  Array key.
	 * @return string Text followed by a space, or empty string.
	 */
	private static function pick_text( $data, $key ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
			return '';
		}

		$value = $data[ $key ];

		if ( is_string( $value ) ) {
			return trim( wp_strip_all_tags( $value, true ) ) . ' ';
		}

		return '';
	}

	/**
	 * Safely pick an HTML-containing value, strip tags, and append a space.
	 *
	 * @since 1.10.0
	 *
	 * @param array  $data Array to read from.
	 * @param string $key  Array key.
	 * @return string Text followed by a space, or empty string.
	 */
	private static function pick_stripped_text( $data, $key ) {
		return self::pick_text( $data, $key );
	}

	/**
	 * Scan an unknown widget's settings for common text-bearing keys.
	 *
	 * @since 1.10.0
	 *
	 * @param array $settings Widget settings.
	 * @return string Extracted text.
	 */
	private static function scan_common_text_keys( $settings ) {
		$text       = '';
		$text_keys  = array(
			'title',
			'heading',
			'text',
			'content',
			'description',
			'caption',
			'label',
		);

		foreach ( $text_keys as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$text .= trim( wp_strip_all_tags( $settings[ $key ], true ) ) . ' ';
			}
		}

		return $text;
	}

	/**
	 * Detect the active SEO plugin on the site.
	 *
	 * Checks for the three most common WordPress SEO plugins. The result
	 * is cached per-request since the answer can't change mid-request.
	 *
	 * @since 1.10.0
	 *
	 * @return string One of the SEO_* constants.
	 */
	public static function detect_seo_plugin() {
		if ( null !== self::$seo_plugin ) {
			return self::$seo_plugin;
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			self::$seo_plugin = self::SEO_RANK_MATH;
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			self::$seo_plugin = self::SEO_YOAST;
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			self::$seo_plugin = self::SEO_SEOPRESS;
		} else {
			self::$seo_plugin = self::SEO_NONE;
		}

		return self::$seo_plugin;
	}

	/**
	 * Get the correct meta keys for the active SEO plugin.
	 *
	 * Returns an associative array with 'title', 'description', and 'focus_keyword'
	 * keys pointing to the correct post-meta keys for the active plugin.
	 *
	 * @since 1.10.0
	 *
	 * @param string|null $plugin Optional. Force a specific plugin slug.
	 *                            If null, auto-detects.
	 * @return array{title: string, description: string, focus_keyword: string}
	 */
	public static function get_seo_meta_keys( $plugin = null ) {
		if ( null === $plugin ) {
			$plugin = self::detect_seo_plugin();
		}

		switch ( $plugin ) {
			case self::SEO_RANK_MATH:
				return array(
					'title'          => 'rank_math_title',
					'description'    => 'rank_math_description',
					'focus_keyword'  => 'rank_math_focus_keyword',
				);

			case self::SEO_YOAST:
				return array(
					'title'          => '_yoast_wpseo_title',
					'description'    => '_yoast_wpseo_metadesc',
					'focus_keyword'  => '_yoast_wpseo_focuskw',
				);

			case self::SEO_SEOPRESS:
				return array(
					'title'          => '_seopress_titles_title',
					'description'    => '_seopress_titles_desc',
					'focus_keyword'  => '_seopress_analysis_target_kw',
				);

			default:
				// Fallback to custom meta keys when no SEO plugin is active.
				return array(
					'title'          => '_wp_mcp_ai_seo_title',
					'description'    => '_wp_mcp_ai_meta_description',
					'focus_keyword'  => '_wp_mcp_ai_focus_keyword',
				);
		}
	}

	/**
	 * Get a human-readable name for the active SEO plugin.
	 *
	 * @since 1.10.0
	 *
	 * @return string Plugin name or 'None'.
	 */
	public static function get_seo_plugin_name() {
		switch ( self::detect_seo_plugin() ) {
			case self::SEO_RANK_MATH:
				return 'Rank Math SEO';
			case self::SEO_YOAST:
				return 'Yoast SEO';
			case self::SEO_SEOPRESS:
				return 'SEOPress';
			default:
				return 'None';
		}
	}

	/**
	 * Check whether Elementor is active on the site.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public static function is_elementor_active() {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false );
	}

	/**
	 * Validate a requested format string.
	 *
	 * @since 1.10.0
	 *
	 * @param string $format The format to validate.
	 * @return string A valid format constant.
	 */
	public static function validate_format( $format ) {
		$valid = array(
			self::FORMAT_BLOCK_EDITOR,
			self::FORMAT_CLASSIC,
			self::FORMAT_ELEMENTOR,
			self::FORMAT_AUTO,
		);

		if ( in_array( $format, $valid, true ) ) {
			return $format;
		}

		return self::FORMAT_BLOCK_EDITOR;
	}

	/**
	 * Resolve the effective format for a post operation.
	 *
	 * When 'auto' is requested, detects the existing format (for updates)
	 * or falls back to block-editor (for new posts).
	 *
	 * @since 1.10.0
	 *
	 * @param string   $requested_format The requested format.
	 * @param int|null $post_id          Post ID for existing format detection. Null for new posts.
	 * @return string A resolved FORMAT_* constant.
	 */
	public static function resolve_format( $requested_format, $post_id = null ) {
		$format = self::validate_format( $requested_format );

		if ( self::FORMAT_AUTO !== $format ) {
			return $format;
		}

		// Auto: detect existing format, or default to block-editor.
		if ( $post_id ) {
			return self::detect_post_format( $post_id );
		}

		return self::FORMAT_BLOCK_EDITOR;
	}
}
