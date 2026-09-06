<?php
/**
 * Comic Consolidate & Add Page
 *
 * Enhanced comic import with script, panel, and character management.
 * Handles CBZ archives, CSV scripts, and JSON data with metadata validation.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-consolidate-add-base.php';

/**
 * Comic Consolidation Admin Page
 */
class WP_MCP_AI_Comic_Consolidate_Page extends WP_MCP_AI_Consolidate_Add_Base {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'consolidate-comic';

	/**
	 * Singleton instance — prevents double hook registration between init() and render_page().
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Initialize the page.
	 */
	public static function init() {
		self::$instance = new self( 'comic_creation' );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Comics menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_comic',
			__( 'Consolidate & Add Comic', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page() {
		if ( null === self::$instance ) {
			self::$instance = new self( 'comic_creation' );
		}
		self::$instance->render();
	}

	/**
	 * Enqueue assets for the consolidation page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our consolidation page.
		if ( 'mcp_ai_comic_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets if available.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue WordPress media uploader.
		wp_enqueue_media();

		// Enqueue consolidation page specific script.
		wp_enqueue_script(
			'wp-mcp-ai-comic-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/comic-consolidate.js',
			array( 'jquery', 'media-upload', 'media-views' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-comic-consolidate',
			'wpMcpAiComicConsolidate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'bulk_import'        => wp_create_nonce( 'wp_mcp_ai_bulk_import' ),
					'upload_document'    => wp_create_nonce( 'wp_mcp_ai_upload_document' ),
					'validate_data'      => wp_create_nonce( 'wp_mcp_ai_validate_data' ),
					'check_completeness' => wp_create_nonce( 'wp_mcp_ai_check_completeness' ),
				),
			)
		);
	}

	/**
	 * Get entity types for comic toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'comics'     => __( 'Comics', 'mcp-ai-wpoos-pro' ),
			'panels'     => __( 'Panels', 'mcp-ai-wpoos-pro' ),
			'characters' => __( 'Characters', 'mcp-ai-wpoos-pro' ),
			'scripts'    => __( 'Scripts', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get import formats supported for comics.
	 *
	 * @return array Import formats.
	 */
	protected function get_import_formats() {
		return array(
			'cbz'  => __( 'CBZ Archive', 'mcp-ai-wpoos-pro' ),
			'csv'  => __( 'CSV Script', 'mcp-ai-wpoos-pro' ),
			'json' => __( 'JSON Data', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get validation schema for comics based on industry standards.
	 *
	 * @return array Validation rules.
	 */
	protected function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title' => __( 'Comic Title', 'mcp-ai-wpoos-pro' ),
				'style' => __( 'Art Style', 'mcp-ai-wpoos-pro' ),
				'pages' => __( 'Number of Pages', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'series'       => __( 'Series Name', 'mcp-ai-wpoos-pro' ),
				'issue'        => __( 'Issue Number', 'mcp-ai-wpoos-pro' ),
				'direction'    => __( 'Reading Direction', 'mcp-ai-wpoos-pro' ),
				'layout'       => __( 'Page Layout', 'mcp-ai-wpoos-pro' ),
				'description'  => __( 'Description / Synopsis', 'mcp-ai-wpoos-pro' ),
				'characters'   => __( 'Characters List', 'mcp-ai-wpoos-pro' ),
				'panels_count' => __( 'Panels Per Page', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'style'        => array(
					'type'           => 'string',
					'allowed_values' => array(
						'manga',
						'western',
						'noir',
						'webtoon',
						'american',
						'european',
						'underground',
						'custom',
					),
				),
				'pages'        => array(
					'type' => 'integer',
					'min'  => 1,
					'max'  => 500,
				),
				'issue'        => array(
					'type' => 'integer_or_text',
				),
				'panels_count' => array(
					'type' => 'integer',
					'min'  => 1,
					'max'  => 12,
				),
			),
			'quality_dimensions' => array(
				'art_quality'  => __( 'Art style consistency and resolution', 'mcp-ai-wpoos-pro' ),
				'storytelling' => __( 'Narrative coherence and pacing', 'mcp-ai-wpoos-pro' ),
				'lettering'    => __( 'Clear and readable speech bubbles', 'mcp-ai-wpoos-pro' ),
				'formatting'   => __( 'Proper page layout and panel flow', 'mcp-ai-wpoos-pro' ),
				'metadata'     => __( 'Complete series and issue tracking', 'mcp-ai-wpoos-pro' ),
			),
			'comic_fields'       => array(
				'comic_title'       => __( 'Comic Title', 'mcp-ai-wpoos-pro' ),
				'comic_style'       => __( 'Art Style', 'mcp-ai-wpoos-pro' ),
				'series_name'       => __( 'Series Name', 'mcp-ai-wpoos-pro' ),
				'issue_number'      => __( 'Issue Number', 'mcp-ai-wpoos-pro' ),
				'reading_direction' => __( 'Reading Direction', 'mcp-ai-wpoos-pro' ),
				'page_layout'       => __( 'Page Layout', 'mcp-ai-wpoos-pro' ),
				'description'       => __( 'Synopsis', 'mcp-ai-wpoos-pro' ),
			),
			'panel_fields'       => array(
				'panel_number'   => __( 'Panel Number', 'mcp-ai-wpoos-pro' ),
				'panel_content'  => __( 'Panel Content / Image', 'mcp-ai-wpoos-pro' ),
				'speech_bubbles' => __( 'Speech Bubbles', 'mcp-ai-wpoos-pro' ),
				'panel_layout'   => __( 'Panel Size & Position', 'mcp-ai-wpoos-pro' ),
				'panel_style'    => __( 'Panel Style (Ink/Color/Pencil)', 'mcp-ai-wpoos-pro' ),
			),
			'character_fields'   => array(
				'character_name'         => __( 'Character Name', 'mcp-ai-wpoos-pro' ),
				'character_role'         => __( 'Role (Hero, Villain, Sidekick)', 'mcp-ai-wpoos-pro' ),
				'appearance_description' => __( 'Appearance Description', 'mcp-ai-wpoos-pro' ),
				'personality_traits'     => __( 'Personality Traits', 'mcp-ai-wpoos-pro' ),
				'reference_image'        => __( 'Reference Image', 'mcp-ai-wpoos-pro' ),
			),
			'script_fields'      => array(
				'script_title'    => __( 'Script Title', 'mcp-ai-wpoos-pro' ),
				'script_content'  => __( 'Full Script', 'mcp-ai-wpoos-pro' ),
				'page_count'      => __( 'Page Count', 'mcp-ai-wpoos-pro' ),
				'panel_breakdown' => __( 'Panel Breakdown', 'mcp-ai-wpoos-pro' ),
				'author'          => __( 'Author / Writer', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Parse imported comic data.
	 *
	 * @param string $data   Raw import data.
	 * @param string $format Import format.
	 * @return array|WP_Error Parsed data or error.
	 */
	protected function parse_import_data( $data, $format ) {
		switch ( $format ) {
			case 'cbz':
				return $this->parse_cbz_archive( $data );
			case 'csv':
				return $this->parse_csv_script( $data );
			case 'json':
				return $this->parse_json_data( $data );
			default:
				return new WP_Error( 'invalid_format', __( 'Unsupported format', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Parse CBZ archive containing comic pages.
	 *
	 * @param string $file_path Path to CBZ file.
	 * @return array|WP_Error Parsed comic items or error.
	 */
	protected function parse_cbz_archive( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_not_supported', __( 'CBZ extraction not supported on this server', 'mcp-ai-wpoos-pro' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'invalid_cbz', __( 'Invalid CBZ archive', 'mcp-ai-wpoos-pro' ) );
		}

		$comic_pages      = array();
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' );

		// `num_files` is not a ZipArchive property on PHP 8.x; count the archive.
		$entry_count = count( $zip );
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$filename = $zip->getNameIndex( $i );

			// Skip directories and hidden files.
			if ( substr( $filename, -1 ) === '/' || strpos( basename( $filename ), '.' ) === 0 ) {
				continue;
			}

			$file_info = pathinfo( $filename );
			$extension = strtolower( $file_info['extension'] ?? '' );

			// Only include image files.
			if ( ! in_array( $extension, $image_extensions, true ) ) {
				continue;
			}

			$comic_pages[] = array(
				'page_name'  => $file_info['filename'],
				'filename'   => $filename,
				'extension'  => $extension,
				'page_order' => $i + 1,
				'mime_type'  => 'image/' . ( 'jpg' === $extension ? 'jpeg' : $extension ),
			);
		}

		$zip->close();

		if ( empty( $comic_pages ) ) {
			return new WP_Error( 'empty_cbz', __( 'No valid image pages found in CBZ archive', 'mcp-ai-wpoos-pro' ) );
		}

		return $comic_pages;
	}

	/**
	 * Parse CSV script file.
	 *
	 * @param string $data CSV data.
	 * @return array|WP_Error Parsed script items or error.
	 */
	protected function parse_csv_script( $data ) {
		$lines = str_getcsv( $data, "\n" );
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_csv', __( 'CSV file is empty', 'mcp-ai-wpoos-pro' ) );
		}

		$headers = str_getcsv( array_shift( $lines ) );
		$scripts = array();
		$panels  = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $headers ) ) {
				continue;
			}

			$item = array_combine( $headers, $values );
			$item = $this->normalize_comic_data( $item );

			// Separate into scripts and panels based on data.
			if ( isset( $item['panel_number'] ) && ! empty( $item['panel_number'] ) ) {
				$panels[] = $item;
			} else {
				$scripts[] = $item;
			}
		}

		return array(
			'scripts' => $scripts,
			'panels'  => $panels,
		);
	}

	/**
	 * Parse JSON data file.
	 *
	 * @param string $data JSON data.
	 * @return array|WP_Error Parsed comic items or error.
	 */
	protected function parse_json_data( $data ) {
		$comic_data = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_array( $comic_data ) ) {
			return new WP_Error( 'invalid_json_structure', __( 'JSON must be an object or array of comic data', 'mcp-ai-wpoos-pro' ) );
		}

		// Normalize the data structure.
		$normalized = array(
			'comics'     => array(),
			'panels'     => array(),
			'characters' => array(),
			'scripts'    => array(),
		);

		foreach ( $comic_data as $key => $value ) {
			if ( in_array( $key, array( 'comics', 'panels', 'characters', 'scripts' ), true ) && is_array( $value ) ) {
				$normalized[ $key ] = array_map( array( $this, 'normalize_comic_data' ), $value );
			} elseif ( is_array( $value ) ) {
				$normalized['comics'][] = $this->normalize_comic_data( $value );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize comic data to standard format.
	 *
	 * @param array $comic Raw comic data.
	 * @return array Normalized comic data.
	 */
	protected function normalize_comic_data( $comic ) {
		$normalized = array();

		foreach ( $comic as $key => $value ) {
			$key_lower                = strtolower( trim( $key ) );
			$normalized[ $key_lower ] = trim( $value );
		}

		// Map common field variations for comics.
		$field_map = array(
			'name'          => 'title',
			'art_style'     => 'style',
			'comic_style'   => 'style',
			'genre'         => 'style',
			'pages'         => 'page_count',
			'panels'        => 'panels_count',
			'direction'     => 'reading_direction',
			'synopsis'      => 'description',
			'desc'          => 'description',
			'author'        => 'writer',
			'issue'         => 'issue_number',
			'issue_no'      => 'issue_number',
			'number'        => 'issue_number',
			'collection'    => 'series_name',
			'series'        => 'series_name',
			'character'     => 'character_name',
			'role'          => 'character_role',
			'speech'        => 'dialogue',
			'dialogue_text' => 'dialogue',
		);

		foreach ( $field_map as $old_key => $new_key ) {
			if ( isset( $normalized[ $old_key ] ) && ! isset( $normalized[ $new_key ] ) ) {
				$normalized[ $new_key ] = $normalized[ $old_key ];
			}
		}

		return $normalized;
	}

	/**
	 * Calculate comic data completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_completeness() {
		$entity = $this->current_entity;

		switch ( $entity ) {
			case 'comics':
				return $this->calculate_comics_completeness();
			case 'panels':
				return $this->calculate_panels_completeness();
			case 'characters':
				return $this->calculate_characters_completeness();
			case 'scripts':
				return $this->calculate_scripts_completeness();
			default:
				return array(
					'percentage' => 0,
					'missing'    => array( __( 'Unknown entity type', 'mcp-ai-wpoos-pro' ) ),
				);
		}
	}

	/**
	 * Calculate comics completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_comics_completeness() {
		$args = array(
			'post_type'      => 'mcp_ai_comic',
			'posts_per_page' => 20,
			'post_status'    => 'any',
		);

		$comics = get_posts( $args );
		$total  = count( $comics );

		if ( 0 === $total ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No comics found. Start by creating or importing comics.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$schema       = $this->get_validation_schema();
		$comic_fields = array_keys( $schema['comic_fields'] );
		$total_fields = count( $comic_fields );
		$filled_count = 0;

		foreach ( $comics as $comic ) {
			$comic_filled = 0;

			if ( ! empty( $comic->post_title ) ) {
				++$comic_filled;
			}
			if ( get_post_meta( $comic->ID, '_comic_style', true ) ) {
				++$comic_filled;
			}
			if ( get_post_meta( $comic->ID, '_series_name', true ) ) {
				++$comic_filled;
			}
			if ( get_post_meta( $comic->ID, '_issue_number', true ) ) {
				++$comic_filled;
			}
			if ( get_post_meta( $comic->ID, '_reading_direction', true ) ) {
				++$comic_filled;
			}
			if ( get_post_meta( $comic->ID, '_page_layout', true ) ) {
				++$comic_filled;
			}
			if ( ! empty( $comic->post_content ) ) {
				++$comic_filled;
			}

			$filled_count += $comic_filled;
		}

		$average_filled = $total > 0 ? $filled_count / $total : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		$missing = array();
		if ( $percentage < 70 ) {
			$missing[] = sprintf(
				/* translators: %d: Current percentage */
				__( 'Comic metadata completeness is %d%%. Add style, series, and page layout for better organization.', 'mcp-ai-wpoos-pro' ),
				$percentage
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing,
		);
	}

	/**
	 * Calculate panels completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_panels_completeness() {
		// Panels could be stored as post meta or custom post type.
		$args = array(
			'post_type'      => 'mcp_ai_comic',
			'posts_per_page' => 20,
			'post_status'    => 'any',
		);

		$comics              = get_posts( $args );
		$total               = count( $comics );
		$panel_count         = 0;
		$panels_with_content = 0;

		foreach ( $comics as $comic ) {
			$panels = get_post_meta( $comic->ID, '_comic_panels', true );
			if ( ! empty( $panels ) && is_array( $panels ) ) {
				$panel_count += count( $panels );
				foreach ( $panels as $panel ) {
					if ( ! empty( $panel['content'] ) || ! empty( $panel['image'] ) ) {
						++$panels_with_content;
					}
				}
			}
		}

		if ( 0 === $panel_count ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No comic panels found. Import or create panels for your comics.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$percentage = round( ( $panels_with_content / $panel_count ) * 100 );

		return array(
			'percentage' => $percentage,
			'missing'    => $percentage < 80 ? array(
				sprintf(
					/* translators: %d: Current percentage */
					__( 'Panel content completeness is %d%%. Add panel images or content for better results.', 'mcp-ai-wpoos-pro' ),
					$percentage
				),
			) : array(),
		);
	}

	/**
	 * Calculate characters completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_characters_completeness() {
		$args = array(
			'post_type'      => 'mcp_ai_comic',
			'posts_per_page' => 20,
			'post_status'    => 'any',
		);

		$comics                = get_posts( $args );
		$char_count            = 0;
		$characters_with_role  = 0;
		$characters_with_image = 0;

		foreach ( $comics as $comic ) {
			$characters = get_post_meta( $comic->ID, '_comic_characters', true );
			if ( ! empty( $characters ) && is_array( $characters ) ) {
				$char_count += count( $characters );
				foreach ( $characters as $character ) {
					if ( ! empty( $character['role'] ) ) {
						++$characters_with_role;
					}
					if ( ! empty( $character['image'] ) ) {
						++$characters_with_image;
					}
				}
			}
		}

		if ( 0 === $char_count ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No characters found. Add character sheets to your comics.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$role_weight  = 0.6;
		$image_weight = 0.4;
		$percentage   = round(
			( ( $characters_with_role / $char_count ) * $role_weight + ( $characters_with_image / $char_count ) * $image_weight ) * 100
		);

		return array(
			'percentage' => $percentage,
			'missing'    => $percentage < 70 ? array(
				sprintf(
					/* translators: %d: Current percentage */
					__( 'Character data completeness is %d%%. Add roles and reference images for each character.', 'mcp-ai-wpoos-pro' ),
					$percentage
				),
			) : array(),
		);
	}

	/**
	 * Calculate scripts completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_scripts_completeness() {
		$args = array(
			'post_type'      => 'mcp_ai_comic',
			'posts_per_page' => 20,
			'post_status'    => 'any',
		);

		$comics         = get_posts( $args );
		$total          = count( $comics );
		$with_script    = 0;
		$with_breakdown = 0;

		foreach ( $comics as $comic ) {
			$script = get_post_meta( $comic->ID, '_comic_script', true );
			if ( ! empty( $script ) ) {
				++$with_script;
			}
			$breakdown = get_post_meta( $comic->ID, '_panel_breakdown', true );
			if ( ! empty( $breakdown ) ) {
				++$with_breakdown;
			}
		}

		if ( 0 === $total ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No comics found. Create comics to add scripts.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$script_weight    = 0.5;
		$breakdown_weight = 0.5;
		$percentage       = round(
			( ( $with_script / $total ) * $script_weight + ( $with_breakdown / $total ) * $breakdown_weight ) * 100
		);

		return array(
			'percentage' => $percentage,
			'missing'    => $percentage < 60 ? array(
				sprintf(
					/* translators: %d: Current percentage */
					__( 'Script completeness is %d%%. Add scripts and panel breakdowns for your comics.', 'mcp-ai-wpoos-pro' ),
					$percentage
				),
			) : array(),
		);
	}

	/**
	 * Calculate quality score for a comic item.
	 *
	 * @param array $item Comic data.
	 * @return array Quality score data.
	 */
	protected function calculate_item_quality_score( $item ) {
		$score  = 100;
		$issues = array();

		$comic_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( ! $comic_id ) {
			return array(
				'score'  => 0,
				'level'  => 'low',
				'status' => __( 'Not Found', 'mcp-ai-wpoos-pro' ),
			);
		}

		$comic = get_post( $comic_id );
		if ( ! $comic ) {
			return array(
				'score'  => 0,
				'level'  => 'low',
				'status' => __( 'Not Found', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check title.
		if ( empty( $comic->post_title ) ) {
			$score   -= 15;
			$issues[] = 'missing_title';
		}

		// Check comic style.
		if ( ! get_post_meta( $comic_id, '_comic_style', true ) ) {
			$score   -= 20;
			$issues[] = 'missing_style';
		}

		// Check series name.
		if ( ! get_post_meta( $comic_id, '_series_name', true ) ) {
			$score   -= 15;
			$issues[] = 'missing_series';
		}

		// Check reading direction.
		if ( ! get_post_meta( $comic_id, '_reading_direction', true ) ) {
			$score -= 10;
		}

		// Check page layout.
		if ( ! get_post_meta( $comic_id, '_page_layout', true ) ) {
			$score -= 10;
		}

		// Check panels count.
		$panels_count = get_post_meta( $comic_id, '_panels_count', true );
		if ( empty( $panels_count ) || (int) $panels_count < 1 ) {
			$score   -= 15;
			$issues[] = 'missing_panels';
		}

		// Check content/script.
		if ( empty( $comic->post_content ) ) {
			$score   -= 15;
			$issues[] = 'missing_content';
		}

		// Determine quality level.
		if ( $score >= 80 ) {
			$level  = 'high';
			$status = __( 'Excellent', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 50 ) {
			$level  = 'medium';
			$status = __( 'Good', 'mcp-ai-wpoos-pro' );
		} else {
			$level  = 'low';
			$status = __( 'Needs Improvement', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'score'  => max( 0, $score ),
			'level'  => $level,
			'status' => $status,
			'issues' => $issues,
		);
	}

	/**
	 * Validate item data before saving.
	 *
	 * @param array $item_data Item data to validate.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	protected function validate_item_data( $item_data ) {
		$schema = $this->get_validation_schema();

		// Check required fields.
		if ( empty( $item_data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Comic title is required', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $item_data['style'] ) ) {
			return new WP_Error( 'missing_style', __( 'Art style is required', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate style against allowed values.
		if ( ! empty( $item_data['style'] ) ) {
			$allowed_styles = $schema['validation_rules']['style']['allowed_values'];
			if ( ! in_array( strtolower( $item_data['style'] ), $allowed_styles, true ) ) {
				return new WP_Error(
					'invalid_style',
					sprintf(
						/* translators: %s: Comma-separated list of allowed styles */
						__( 'Invalid art style. Allowed styles: %s', 'mcp-ai-wpoos-pro' ),
						implode( ', ', $allowed_styles )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Render comic-specific form fields.
	 */
	protected function render_entity_form_fields() {
		$entity = $this->current_entity;

		switch ( $entity ) {
			case 'comics':
				$this->render_comic_form_fields();
				break;
			case 'panels':
				$this->render_panel_form_fields();
				break;
			case 'characters':
				$this->render_character_form_fields();
				break;
			case 'scripts':
				$this->render_script_form_fields();
				break;
			default:
				parent::render_entity_form_fields();
		}
	}

	/**
	 * Render comic form fields.
	 */
	protected function render_comic_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="comic_title"><?php esc_html_e( 'Comic Title', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="comic_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="comic_style"><?php esc_html_e( 'Art Style', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<select id="comic_style" name="item_data[style]" class="regular-text" required>
						<option value=""><?php esc_html_e( 'Select Style', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="manga"><?php esc_html_e( 'Manga', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="western"><?php esc_html_e( 'Western', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="noir"><?php esc_html_e( 'Noir', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="webtoon"><?php esc_html_e( 'Webtoon', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="american"><?php esc_html_e( 'American', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="european"><?php esc_html_e( 'European', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="underground"><?php esc_html_e( 'Underground', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="custom"><?php esc_html_e( 'Custom', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="series_name"><?php esc_html_e( 'Series Name', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="series_name" name="item_data[series_name]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="issue_number"><?php esc_html_e( 'Issue Number', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="issue_number" name="item_data[issue_number]" class="small-text" placeholder="1">
					<p class="description"><?php esc_html_e( 'Issue number or volume (e.g., "1", "Special", "Annual")', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="reading_direction"><?php esc_html_e( 'Reading Direction', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="reading_direction" name="item_data[reading_direction]" class="regular-text">
						<option value="LTR"><?php esc_html_e( 'Left to Right (Western)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="RTL"><?php esc_html_e( 'Right to Left (Manga)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="page_layout"><?php esc_html_e( 'Page Layout', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="page_layout" name="item_data[page_layout]" class="regular-text">
						<option value="standard"><?php esc_html_e( 'Standard (6-9 panels)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="splash"><?php esc_html_e( 'Splash Page (1 panel)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="grid"><?php esc_html_e( 'Grid (equal panels)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="dynamic"><?php esc_html_e( 'Dynamic (varied sizes)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="webtoon_scroll"><?php esc_html_e( 'Webtoon Scroll', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="strip"><?php esc_html_e( 'Comic Strip (3-4 panels)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="comic_description"><?php esc_html_e( 'Synopsis / Description', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="comic_description" name="item_data[description]" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Brief synopsis of the comic...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render panel form fields.
	 */
	protected function render_panel_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="panel_number"><?php esc_html_e( 'Panel Number', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="number" id="panel_number" name="item_data[panel_number]" class="small-text" min="1" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="panel_image"><?php esc_html_e( 'Panel Image', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="file" id="panel_image" name="item_data[image]" accept="image/*" required>
					<p class="description"><?php esc_html_e( 'Upload panel artwork (JPG, PNG, WebP)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="speech_bubbles"><?php esc_html_e( 'Speech Bubbles', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="speech_bubbles" name="item_data[speech_bubbles]" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Character 1: "Hello!"\nCharacter 2: "What\'s that?"', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="panel_layout"><?php esc_html_e( 'Panel Size & Position', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="panel_layout" name="item_data[panel_layout]" class="regular-text">
						<option value="full_width"><?php esc_html_e( 'Full Width', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="half_width"><?php esc_html_e( 'Half Width', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="third_width"><?php esc_html_e( 'One Third Width', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="quarter"><?php esc_html_e( 'Quarter', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="inset"><?php esc_html_e( 'Inset (overlapping)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="panel_style"><?php esc_html_e( 'Panel Style', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="panel_style" name="item_data[panel_style]" class="regular-text">
						<option value="ink"><?php esc_html_e( 'Ink (line art)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="color"><?php esc_html_e( 'Color', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="pencil"><?php esc_html_e( 'Pencil / Sketch', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="greyscale"><?php esc_html_e( 'Greyscale', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render character form fields.
	 */
	protected function render_character_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="character_name"><?php esc_html_e( 'Character Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="character_name" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="character_role"><?php esc_html_e( 'Role', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="character_role" name="item_data[character_role]" class="regular-text">
						<option value="hero"><?php esc_html_e( 'Hero / Protagonist', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="villain"><?php esc_html_e( 'Villain / Antagonist', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="sidekick"><?php esc_html_e( 'Sidekick', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="supporting"><?php esc_html_e( 'Supporting', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="cameo"><?php esc_html_e( 'Cameo / Minor', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="narrator"><?php esc_html_e( 'Narrator', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="appearance_description"><?php esc_html_e( 'Appearance Description', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="appearance_description" name="item_data[appearance_description]" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Height, build, hair color, eye color, distinguishing features...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="personality_traits"><?php esc_html_e( 'Personality Traits', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="personality_traits" name="item_data[personality_traits]" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Brave, Impulsive, Wise-cracking', 'mcp-ai-wpoos-pro' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="reference_image"><?php esc_html_e( 'Reference Image', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="file" id="reference_image" name="item_data[reference_image]" accept="image/*">
					<p class="description"><?php esc_html_e( 'Upload character reference / model sheet', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render script form fields.
	 */
	protected function render_script_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="script_title"><?php esc_html_e( 'Script Title', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="script_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="script_author"><?php esc_html_e( 'Author / Writer', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="script_author" name="item_data[author]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="page_count"><?php esc_html_e( 'Page Count', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="number" id="page_count" name="item_data[page_count]" class="small-text" min="1">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="script_content"><?php esc_html_e( 'Full Script', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<textarea id="script_content" name="item_data[script_content]" rows="12" class="large-text" placeholder="<?php esc_attr_e( 'PAGE 1\n\nPanel 1:\nDescription: [Scene description]\nDialogue: [Character]: "[Speech]"\n\nPanel 2: ...', 'mcp-ai-wpoos-pro' ); ?>" required></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="panel_breakdown"><?php esc_html_e( 'Panel Breakdown', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="panel_breakdown" name="item_data[panel_breakdown]" rows="8" class="large-text" placeholder="<?php esc_attr_e( 'Page 1: 6 panels (full-width opener, 2x3 grid)\nPage 2: 4 panels (dynamic layout)\nPage 3: Splash page...', 'mcp-ai-wpoos-pro' ); ?>"></textarea>
				</td>
			</tr>
		</table>
		<?php
	}
}
