<?php
/**
 * Media Consolidate & Add Page
 *
 * Enhanced media import with template and collection management.
 * Handles images, videos, audio, and design templates with metadata validation.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-consolidate-add-base.php';

/**
 * Media Consolidation Admin Page
 */
class WP_MCP_AI_Media_Consolidate_Page extends WP_MCP_AI_Consolidate_Add_Base {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'design-media';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self( 'media' );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Media menu (upload.php).
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'upload.php', // Media Library menu.
			__( 'Consolidate & Add Media', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'upload_files',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page() {
		$instance = new self( 'media' );
		$instance->render();
	}

	/**
	 * Enqueue assets for the consolidation page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our consolidation page.
		// Note: Media submenu pages use 'media_page_' prefix, not 'upload_page_'.
		if ( 'media_page_' . self::PAGE_SLUG !== $hook ) {
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
			'wp-mcp-ai-media-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/media-consolidate.js',
			array( 'jquery', 'media-upload', 'media-views' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-media-consolidate',
			'wpMcpAiMediaConsolidate',
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
	 * Get entity types for media toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'templates'   => __( 'Design Templates', 'mcp-ai-wpoos-pro' ),
			'collections' => __( 'Media Collections', 'mcp-ai-wpoos-pro' ),
			'assets'      => __( 'Media Assets', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get import formats supported for media.
	 *
	 * @return array Import formats.
	 */
	protected function get_import_formats() {
		return array(
			'zip'  => 'ZIP Archive',
			'csv'  => 'CSV (Metadata)',
			'json' => 'JSON (Metadata)',
			'jpg'  => 'JPEG Image',
			'png'  => 'PNG Image',
			'gif'  => 'GIF Image',
			'svg'  => 'SVG Vector',
			'pdf'  => 'PDF Document',
			'mp4'  => 'MP4 Video',
			'mp3'  => 'MP3 Audio',
			'psd'  => 'Photoshop (PSD)',
			'ai'   => 'Adobe Illustrator',
			'eps'  => 'EPS Vector',
		);
	}

	/**
	 * Get validation schema for media based on industry standards.
	 *
	 * @return array Validation rules.
	 */
	protected function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title' => __( 'Media Title', 'mcp-ai-wpoos-pro' ),
				'file'  => __( 'File/URL', 'mcp-ai-wpoos-pro' ),
				'type'  => __( 'Media Type', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'alt_text'    => __( 'Alt Text (Accessibility)', 'mcp-ai-wpoos-pro' ),
				'caption'     => __( 'Caption', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'collection'  => __( 'Collection/Category', 'mcp-ai-wpoos-pro' ),
				'tags'        => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'license'     => __( 'License/Copyright', 'mcp-ai-wpoos-pro' ),
				'dimensions'  => __( 'Dimensions/Resolution', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'file'       => array(
					'type'          => 'file',
					'allowed_types' => array( 'image', 'video', 'audio', 'application' ),
					'max_size'      => wp_max_upload_size(),
				),
				'alt_text'   => array(
					'type'         => 'string',
					'max_length'   => 125, // SEO best practice.
					'required_for' => array( 'image' ),
				),
				'dimensions' => array(
					'type'   => 'string',
					'format' => 'widthxheight (e.g., 1920x1080)',
				),
			),
			'quality_dimensions' => array(
				'accessibility' => __( 'Alt text for images, captions for videos', 'mcp-ai-wpoos-pro' ),
				'organization'  => __( 'Proper categorization and tagging', 'mcp-ai-wpoos-pro' ),
				'metadata'      => __( 'Complete EXIF and custom metadata', 'mcp-ai-wpoos-pro' ),
				'licensing'     => __( 'Copyright and usage rights documented', 'mcp-ai-wpoos-pro' ),
				'optimization'  => __( 'Appropriate file sizes and formats', 'mcp-ai-wpoos-pro' ),
			),
			'template_fields'    => array(
				'template_name'   => __( 'Template Name', 'mcp-ai-wpoos-pro' ),
				'template_type'   => __( 'Type (Social, Print, Web, etc.)', 'mcp-ai-wpoos-pro' ),
				'dimensions'      => __( 'Canvas Dimensions', 'mcp-ai-wpoos-pro' ),
				'color_mode'      => __( 'Color Mode (RGB/CMYK)', 'mcp-ai-wpoos-pro' ),
				'layers'          => __( 'Layer Information', 'mcp-ai-wpoos-pro' ),
				'fonts_used'      => __( 'Required Fonts', 'mcp-ai-wpoos-pro' ),
				'design_software' => __( 'Source Software', 'mcp-ai-wpoos-pro' ),
			),
			'collection_fields'  => array(
				'collection_name' => __( 'Collection Name', 'mcp-ai-wpoos-pro' ),
				'collection_type' => __( 'Type (Brand, Campaign, Project)', 'mcp-ai-wpoos-pro' ),
				'client'          => __( 'Client/Brand', 'mcp-ai-wpoos-pro' ),
				'date_created'    => __( 'Creation Date', 'mcp-ai-wpoos-pro' ),
				'status'          => __( 'Status (Active, Archived, Draft)', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Parse imported media data.
	 *
	 * @param string $data   Raw import data.
	 * @param string $format Import format.
	 * @return array|WP_Error Parsed data or error.
	 */
	protected function parse_import_data( $data, $format ) {
		switch ( $format ) {
			case 'zip':
				return $this->parse_zip_archive( $data );
			case 'csv':
				return $this->parse_csv_metadata( $data );
			case 'json':
				return $this->parse_json_metadata( $data );
			default:
				// For single media files, create a simple entry.
				return array(
					array(
						'title' => __( 'Imported Media', 'mcp-ai-wpoos-pro' ),
						'type'  => $this->get_mime_type_from_extension( $format ),
						'data'  => $data,
					),
				);
		}
	}

	/**
	 * Parse ZIP archive containing media files.
	 *
	 * @param string $file_path Path to ZIP file.
	 * @return array|WP_Error Parsed media items or error.
	 */
	protected function parse_zip_archive( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_not_supported', __( 'ZIP extraction not supported on this server', 'mcp-ai-wpoos-pro' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'invalid_zip', __( 'Invalid ZIP archive', 'mcp-ai-wpoos-pro' ) );
		}

		$media_items = array();
		$temp_dir    = wp_upload_dir()['basedir'] . '/temp-media-import-' . uniqid();
		wp_mkdir_p( $temp_dir );

		for ( $i = 0; $i < $zip->num_files; $i++ ) {
			$filename = $zip->getNameIndex( $i );

			// Skip directories and hidden files.
			if ( substr( $filename, -1 ) === '/' || strpos( basename( $filename ), '.' ) === 0 ) {
				continue;
			}

			$file_info     = pathinfo( $filename );
			$media_items[] = array(
				'title'     => $file_info['filename'],
				'filename'  => $filename,
				'extension' => $file_info['extension'] ?? '',
				'type'      => $this->get_mime_type_from_extension( $file_info['extension'] ?? '' ),
			);
		}

		$zip->close();
		return $media_items;
	}

	/**
	 * Parse CSV metadata file.
	 *
	 * @param string $data CSV data.
	 * @return array|WP_Error Parsed media items or error.
	 */
	protected function parse_csv_metadata( $data ) {
		$lines = str_getcsv( $data, "\n" );
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_csv', __( 'CSV file is empty', 'mcp-ai-wpoos-pro' ) );
		}

		$headers     = str_getcsv( array_shift( $lines ) );
		$media_items = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $headers ) ) {
				continue;
			}

			$item          = array_combine( $headers, $values );
			$media_items[] = $this->normalize_media_data( $item );
		}

		return $media_items;
	}

	/**
	 * Parse JSON metadata file.
	 *
	 * @param string $data JSON data.
	 * @return array|WP_Error Parsed media items or error.
	 */
	protected function parse_json_metadata( $data ) {
		$media_items = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_array( $media_items ) ) {
			return new WP_Error( 'invalid_json_structure', __( 'JSON must be an array of media items', 'mcp-ai-wpoos-pro' ) );
		}

		return array_map( array( $this, 'normalize_media_data' ), $media_items );
	}

	/**
	 * Normalize media data to standard format.
	 *
	 * @param array $media Raw media data.
	 * @return array Normalized media data.
	 */
	protected function normalize_media_data( $media ) {
		$normalized = array();

		foreach ( $media as $key => $value ) {
			$key_lower                = strtolower( trim( $key ) );
			$normalized[ $key_lower ] = trim( $value );
		}

		// Map common field variations.
		$field_map = array(
			'name'        => 'title',
			'filename'    => 'file',
			'url'         => 'file',
			'alt'         => 'alt_text',
			'alternative' => 'alt_text',
			'desc'        => 'description',
			'category'    => 'collection',
			'folder'      => 'collection',
		);

		foreach ( $field_map as $old_key => $new_key ) {
			if ( isset( $normalized[ $old_key ] ) && ! isset( $normalized[ $new_key ] ) ) {
				$normalized[ $new_key ] = $normalized[ $old_key ];
			}
		}

		return $normalized;
	}

	/**
	 * Get MIME type from file extension.
	 *
	 * @param string $extension File extension.
	 * @return string MIME type.
	 */
	protected function get_mime_type_from_extension( $extension ) {
		$extension = strtolower( $extension );

		$mime_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
			'pdf'  => 'application/pdf',
			'mp4'  => 'video/mp4',
			'mp3'  => 'audio/mpeg',
			'psd'  => 'image/vnd.adobe.photoshop',
			'ai'   => 'application/illustrator',
			'eps'  => 'application/postscript',
		);

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Calculate media data completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_completeness() {
		$entity = $this->current_entity;

		if ( 'templates' === $entity ) {
			return $this->calculate_templates_completeness();
		} elseif ( 'collections' === $entity ) {
			return $this->calculate_collections_completeness();
		} else {
			return $this->calculate_assets_completeness();
		}
	}

	/**
	 * Calculate templates completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_templates_completeness() {
		// Query for design template posts/metadata.
		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => 10,
			'meta_query'     => array(
				array(
					'key'     => '_wp_mcp_ai_is_template',
					'value'   => '1',
					'compare' => '=',
				),
			),
		);

		$templates = get_posts( $args );
		$total     = count( $templates );

		if ( 0 === $total ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No design templates found. Start by importing template files.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$schema          = $this->get_validation_schema();
		$template_fields = array_keys( $schema['template_fields'] );
		$total_fields    = count( $template_fields );
		$filled_count    = 0;

		foreach ( $templates as $template ) {
			$template_filled = 0;

			if ( get_post_meta( $template->ID, '_wp_mcp_ai_template_name', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_template_type', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_dimensions', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_color_mode', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_layers', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_fonts', true ) ) {
				++$template_filled;
			}
			if ( get_post_meta( $template->ID, '_wp_mcp_ai_software', true ) ) {
				++$template_filled;
			}

			$filled_count += $template_filled;
		}

		$average_filled = $total > 0 ? $filled_count / $total : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		$missing = array();
		if ( $percentage < 80 ) {
			$missing[] = sprintf(
				/* translators: %d: Current percentage */
				__( 'Template metadata completeness is %d%%. Add more details for better organization.', 'mcp-ai-wpoos-pro' ),
				$percentage
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing,
		);
	}

	/**
	 * Calculate collections completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_collections_completeness() {
		// Query for collection records (could be custom taxonomy or post type).
		$collections = get_terms(
			array(
				'taxonomy'   => 'media_collection',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $collections ) ) {
			$collections = array();
		}

		$total = count( $collections );

		if ( 0 === $total ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No media collections found. Create collections to organize your media.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$schema            = $this->get_validation_schema();
		$collection_fields = array_keys( $schema['collection_fields'] );
		$total_fields      = count( $collection_fields );
		$filled_count      = 0;

		foreach ( $collections as $collection ) {
			$collection_filled = 0;

			if ( ! empty( $collection->name ) ) {
				++$collection_filled;
			}
			if ( get_term_meta( $collection->term_id, 'collection_type', true ) ) {
				++$collection_filled;
			}
			if ( get_term_meta( $collection->term_id, 'client', true ) ) {
				++$collection_filled;
			}
			if ( get_term_meta( $collection->term_id, 'date_created', true ) ) {
				++$collection_filled;
			}
			if ( get_term_meta( $collection->term_id, 'status', true ) ) {
				++$collection_filled;
			}

			$filled_count += $collection_filled;
		}

		$average_filled = $total > 0 ? $filled_count / $total : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		$missing = array();
		if ( $percentage < 70 ) {
			$missing[] = sprintf(
				/* translators: %d: Current percentage */
				__( 'Collection metadata completeness is %d%%. Add collection details for better tracking.', 'mcp-ai-wpoos-pro' ),
				$percentage
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing,
		);
	}

	/**
	 * Calculate media assets completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_assets_completeness() {
		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => 20,
			'post_status'    => 'inherit',
		);

		$attachments = get_posts( $args );
		$total       = count( $attachments );

		if ( 0 === $total ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No media assets found. Upload images, videos, or documents.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$required_count    = 3; // Title, alt_text (for images), file.
		$recommended_count = 4; // Caption, description, tags, license.
		$total_fields      = $required_count + $recommended_count;
		$filled_count      = 0;
		$missing_alt_text  = 0;

		foreach ( $attachments as $attachment ) {
			$attachment_filled = 0;

			// Required fields.
			if ( ! empty( $attachment->post_title ) ) {
				++$attachment_filled;
			}
			if ( ! empty( $attachment->guid ) ) {
				++$attachment_filled;
			}

			// Alt text (critical for images).
			if ( wp_attachment_is_image( $attachment->ID ) ) {
				$alt_text = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
				if ( ! empty( $alt_text ) ) {
					++$attachment_filled;
				} else {
					++$missing_alt_text;
				}
			} else {
				++$attachment_filled; // Not applicable to non-images.
			}

			// Recommended fields.
			if ( ! empty( $attachment->post_excerpt ) ) { // Caption.
				++$attachment_filled;
			}
			if ( ! empty( $attachment->post_content ) ) { // Description.
				++$attachment_filled;
			}
			if ( get_post_meta( $attachment->ID, '_wp_mcp_ai_tags', true ) ) {
				++$attachment_filled;
			}
			if ( get_post_meta( $attachment->ID, '_wp_mcp_ai_license', true ) ) {
				++$attachment_filled;
			}

			$filled_count += $attachment_filled;
		}

		$average_filled = $total > 0 ? $filled_count / $total : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		$missing = array();
		if ( $missing_alt_text > 0 ) {
			$missing[] = sprintf(
				/* translators: %d: Number of images */
				_n(
					'%d image missing alt text (critical for accessibility)',
					'%d images missing alt text (critical for accessibility)',
					$missing_alt_text,
					'mcp-ai-wpoos-pro'
				),
				$missing_alt_text
			);
		}

		if ( $percentage < 60 ) {
			$missing[] = sprintf(
				/* translators: %d: Current percentage */
				__( 'Media metadata completeness is %d%%. Add descriptions and tags for better searchability.', 'mcp-ai-wpoos-pro' ),
				$percentage
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing,
		);
	}

	/**
	 * Calculate quality score for a media item.
	 *
	 * @param array $item Media data.
	 * @return array Quality score data.
	 */
	protected function calculate_item_quality_score( $item ) {
		$score  = 100;
		$issues = array();

		$attachment_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( ! $attachment_id ) {
			return array(
				'score'  => 0,
				'level'  => 'low',
				'status' => __( 'Not Found', 'mcp-ai-wpoos-pro' ),
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return array(
				'score'  => 0,
				'level'  => 'low',
				'status' => __( 'Not Found', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check title.
		if ( empty( $attachment->post_title ) ) {
			$score   -= 15;
			$issues[] = 'missing_title';
		}

		// Check alt text for images (critical for accessibility).
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( empty( $alt_text ) ) {
				$score   -= 25; // Heavy penalty for missing alt text.
				$issues[] = 'missing_alt_text';
			} elseif ( strlen( $alt_text ) > 125 ) {
				$score   -= 5; // Minor penalty for too-long alt text.
				$issues[] = 'alt_text_too_long';
			}
		}

		// Check description.
		if ( empty( $attachment->post_content ) ) {
			$score   -= 15;
			$issues[] = 'missing_description';
		}

		// Check caption.
		if ( empty( $attachment->post_excerpt ) ) {
			$score -= 10;
		}

		// Check licensing info.
		if ( ! get_post_meta( $attachment_id, '_wp_mcp_ai_license', true ) ) {
			$score -= 10;
		}

		// Check file size optimization (images should be < 2MB for web).
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
				if ( $file_size > 2 * 1024 * 1024 ) { // 2MB.
					$score   -= 10;
					$issues[] = 'large_file_size';
				}
			}
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
			return new WP_Error( 'missing_title', __( 'Media title is required', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $item_data['file'] ) ) {
			return new WP_Error( 'missing_file', __( 'File or URL is required', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate alt text length for images.
		if ( ! empty( $item_data['alt_text'] ) && strlen( $item_data['alt_text'] ) > 125 ) {
			return new WP_Error( 'alt_text_too_long', __( 'Alt text should be 125 characters or less for SEO best practices', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Render media-specific form fields.
	 */
	protected function render_entity_form_fields() {
		$entity = $this->current_entity;

		if ( 'templates' === $entity ) {
			$this->render_template_form_fields();
		} elseif ( 'collections' === $entity ) {
			$this->render_collection_form_fields();
		} else {
			$this->render_asset_form_fields();
		}
	}

	/**
	 * Render template form fields.
	 */
	protected function render_template_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="template_name"><?php esc_html_e( 'Template Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="template_name" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="template_file"><?php esc_html_e( 'Template File', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="file" id="template_file" name="item_data[file]" accept=".psd,.ai,.eps,.pdf,.svg" required>
					<p class="description"><?php esc_html_e( 'PSD, AI, EPS, PDF, or SVG files', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="template_type"><?php esc_html_e( 'Template Type', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="template_type" name="item_data[template_type]" class="regular-text">
						<option value=""><?php esc_html_e( 'Select Type', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="social"><?php esc_html_e( 'Social Media', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="print"><?php esc_html_e( 'Print', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="web"><?php esc_html_e( 'Web', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="presentation"><?php esc_html_e( 'Presentation', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="email"><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="template_dimensions"><?php esc_html_e( 'Canvas Dimensions', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="template_dimensions" name="item_data[dimensions]" class="regular-text" placeholder="1920x1080">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="color_mode"><?php esc_html_e( 'Color Mode', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="color_mode" name="item_data[color_mode]" class="regular-text">
						<option value="RGB">RGB</option>
						<option value="CMYK">CMYK</option>
						<option value="Grayscale">Grayscale</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="fonts_used"><?php esc_html_e( 'Required Fonts', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="fonts_used" name="item_data[fonts]" rows="3" class="large-text" placeholder="Arial, Helvetica, Custom Font Name"></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render collection form fields.
	 */
	protected function render_collection_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="collection_name"><?php esc_html_e( 'Collection Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="collection_name" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="collection_type"><?php esc_html_e( 'Collection Type', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="collection_type" name="item_data[collection_type]" class="regular-text">
						<option value="brand"><?php esc_html_e( 'Brand Assets', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="campaign"><?php esc_html_e( 'Campaign', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="project"><?php esc_html_e( 'Project', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="archive"><?php esc_html_e( 'Archive', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="client_name"><?php esc_html_e( 'Client/Brand', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="client_name" name="item_data[client]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="collection_status"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select id="collection_status" name="item_data[status]" class="regular-text">
						<option value="active"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="archived"><?php esc_html_e( 'Archived', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="collection_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="collection_description" name="item_data[description]" rows="4" class="large-text"></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render asset form fields.
	 */
	protected function render_asset_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="media_title"><?php esc_html_e( 'Media Title', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="media_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="media_file"><?php esc_html_e( 'Media File', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="file" id="media_file" name="item_data[file]" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="alt_text"><?php esc_html_e( 'Alt Text', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="alt_text" name="item_data[alt_text]" class="regular-text" maxlength="125">
					<p class="description"><?php esc_html_e( 'Required for images. Max 125 characters for SEO.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="media_caption"><?php esc_html_e( 'Caption', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="media_caption" name="item_data[caption]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="media_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="media_description" name="item_data[description]" rows="4" class="large-text"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="media_collection"><?php esc_html_e( 'Collection', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="media_collection" name="item_data[collection]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="media_license"><?php esc_html_e( 'License/Copyright', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="media_license" name="item_data[license]" class="regular-text" placeholder="© 2024 Company Name">
				</td>
			</tr>
		</table>
		<?php
	}
}
