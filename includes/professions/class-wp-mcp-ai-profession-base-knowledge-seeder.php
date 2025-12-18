<?php
/**
 * Profession Base Knowledge Seeder.
 *
 * Seeds base knowledge documents and supported MIME types for professions.
 * Runs once after profession seeding on plugin activation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds base knowledge documents for professions.
 */
class WP_MCP_AI_Profession_Base_Knowledge_Seeder {
	/**
	 * Option key to track if base knowledge has been seeded.
	 */
	const SEEDED_OPTION = 'wp_mcp_ai_profession_base_knowledge_seeded';

	/**
	 * Admin init priority for seeding.
	 * Must run after profession seeding (priority 20).
	 */
	const ADMIN_INIT_PRIORITY = 30;

	/**
	 * Initialize the seeder.
	 * Runs once after profession seeding completes.
	 */
	public static function init() {
		// Check if already seeded.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Seed base knowledge after professions are seeded.
		add_action( 'admin_init', array( __CLASS__, 'seed_base_knowledge' ), self::ADMIN_INIT_PRIORITY );
	}

	/**
	 * Seed base knowledge documents for all professions.
	 *
	 * @param bool $force Force re-seeding even if already seeded.
	 */
	public static function seed_base_knowledge( $force = false ) {
		// Bail if already seeded and not forced.
		if ( ! $force && get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Ensure professions exist before seeding base knowledge.
		if ( ! get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false ) ) {
			// Professions not seeded yet, bail.
			return;
		}

		// Load repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}

		$repository  = new WP_MCP_AI_Profession_Repository();
		$professions = $repository->find_all();

		if ( empty( $professions ) ) {
			// No professions to seed base knowledge for.
			return;
		}

		foreach ( $professions as $profession ) {
			self::seed_profession_base_knowledge( $profession, $force );
		}

		// Mark as seeded.
		if ( ! $force ) {
			update_option( self::SEEDED_OPTION, true, false );
		}
	}

	/**
	 * Seed base knowledge for a single profession.
	 *
	 * @param WP_Post $profession Profession post object.
	 * @param bool    $force      Force re-creation of knowledge documents.
	 */
	protected static function seed_profession_base_knowledge( $profession, $force = false ) {
		$slug     = $profession->post_name;
		$title    = $profession->post_title;
		$category = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );

		// Check if attachment already exists (idempotency).
		$existing_attachment = self::find_existing_attachment( $slug );

		if ( $existing_attachment && ! $force ) {
			// Already seeded, skip.
			self::ensure_attachment_in_memory_files( $profession->ID, $existing_attachment->ID );
			self::ensure_supported_mime_types( $profession->ID, $category, $force );
			return;
		}

		// Build knowledge document content.
		$content = self::build_knowledge_document( $profession );

		// Create attachment file.
		$attachment_id = self::create_knowledge_attachment( $profession, $content );

		if ( is_wp_error( $attachment_id ) ) {
			// Log error but don't fail the entire seeding.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI: Failed to create base knowledge attachment for profession ' . $slug . ': ' . $attachment_id->get_error_message() );
			}
			return;
		}

		// Update profession META_MEMORY_FILES.
		self::ensure_attachment_in_memory_files( $profession->ID, $attachment_id );

		// Populate META_SUPPORTED_MIME_TYPES.
		self::ensure_supported_mime_types( $profession->ID, $category, $force );
	}

	/**
	 * Find existing base knowledge attachment for a profession.
	 *
	 * @param string $profession_slug Profession slug.
	 * @return WP_Post|null Attachment post or null if not found.
	 */
	protected static function find_existing_attachment( $profession_slug ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_wp_mcp_ai_seeded_profession_slug',
					'value'   => $profession_slug,
					'compare' => '=',
				),
				array(
					'key'     => '_wp_mcp_ai_seeded_profession_doc_type',
					'value'   => 'base_knowledge',
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return null;
	}

	/**
	 * Build knowledge document content from profession data.
	 *
	 * @param WP_Post $profession Profession post object.
	 * @return string Knowledge document content in Markdown format.
	 */
	protected static function build_knowledge_document( $profession ) {
		$title            = $profession->post_title;
		$overview         = $profession->post_content;
		$role_description = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, true );
		$expertise        = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$warnings         = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_WARNINGS, true );
		$knowledge_base   = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );

		// Build structured Markdown content.
		$content = "# {$title} - Base Knowledge\n\n";

		if ( ! empty( $overview ) ) {
			$content .= "## Overview\n\n";
			$content .= wp_strip_all_tags( $overview ) . "\n\n";
		}

		if ( ! empty( $role_description ) ) {
			$content .= "## Role Description\n\n";
			$content .= wp_strip_all_tags( $role_description ) . "\n\n";
		}

		if ( ! empty( $expertise ) && is_array( $expertise ) ) {
			$content .= "## Expertise Areas\n\n";
			foreach ( $expertise as $area ) {
				$content .= "- " . wp_strip_all_tags( $area ) . "\n";
			}
			$content .= "\n";
		}

		if ( ! empty( $warnings ) && is_array( $warnings ) ) {
			$content .= "## Important Warnings/Disclaimers\n\n";
			foreach ( $warnings as $warning ) {
				$content .= "- " . wp_strip_all_tags( $warning ) . "\n";
			}
			$content .= "\n";
		}

		if ( ! empty( $knowledge_base ) ) {
			$content .= "## Knowledge Base\n\n";
			$content .= wp_strip_all_tags( $knowledge_base ) . "\n\n";
		}

		$content .= "---\n\n";
		$content .= "This document was automatically generated by WP oOS as base knowledge for the {$title} profession.\n";
		$content .= "Last updated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

		return $content;
	}

	/**
	 * Create knowledge attachment file.
	 *
	 * @param WP_Post $profession Profession post object.
	 * @param string  $content    Knowledge document content.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected static function create_knowledge_attachment( $profession, $content ) {
		$slug     = $profession->post_name;
		$filename = "profession-{$slug}-base-knowledge.txt";

		// Get upload directory.
		$upload_dir = wp_upload_dir();

		// Create subdirectory if it doesn't exist.
		$subdir      = 'wp-mcp-ai/profession-knowledge';
		$target_dir  = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		$target_url  = trailingslashit( $upload_dir['baseurl'] ) . $subdir;

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		// Use wp_upload_bits to create the file (without time subdirectory).
		$upload = wp_upload_bits( $filename, null, $content, null );

		if ( $upload['error'] ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		// Move file to our subdirectory.
		$source_file = $upload['file'];
		$target_file = trailingslashit( $target_dir ) . $filename;

		// If file already exists in target, remove it first.
		if ( file_exists( $target_file ) ) {
			wp_delete_file( $target_file );
		}

		// Copy to target directory.
		if ( ! copy( $source_file, $target_file ) ) {
			return new WP_Error(
				'copy_error',
				sprintf( 'Failed to copy file from %s to %s', $source_file, $target_file )
			);
		}

		// Remove source file.
		wp_delete_file( $source_file );

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => 'text/plain',
			'post_title'     => $profession->post_title . ' - Base Knowledge',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $profession->ID,
			'guid'           => trailingslashit( $target_url ) . $filename,
		);

		// Insert attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $target_file, $profession->ID );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $target_file );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		// Add idempotency markers.
		update_post_meta( $attachment_id, '_wp_mcp_ai_seeded_profession_slug', $slug );
		update_post_meta( $attachment_id, '_wp_mcp_ai_seeded_profession_doc_type', 'base_knowledge' );

		return $attachment_id;
	}

	/**
	 * Ensure attachment ID is in profession's memory files meta.
	 *
	 * @param int $profession_id Profession post ID.
	 * @param int $attachment_id Attachment post ID.
	 */
	protected static function ensure_attachment_in_memory_files( $profession_id, $attachment_id ) {
		$memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );

		if ( ! is_array( $memory_files ) ) {
			$memory_files = array();
		}

		// Add attachment if not already present.
		if ( ! in_array( $attachment_id, $memory_files, true ) ) {
			$memory_files[] = $attachment_id;
			update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );
		}
	}

	/**
	 * Ensure profession has supported MIME types set.
	 *
	 * @param int    $profession_id Profession post ID.
	 * @param string $category      Profession category.
	 * @param bool   $force         Force update even if already set.
	 */
	protected static function ensure_supported_mime_types( $profession_id, $category, $force = false ) {
		$existing_mimes = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );

		// Skip if already set and not forced.
		if ( ! $force && ! empty( $existing_mimes ) && is_array( $existing_mimes ) ) {
			return;
		}

		$mimes = self::get_supported_mimes_for_category( $category );
		update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $mimes );
	}

	/**
	 * Get supported MIME types for a profession category.
	 *
	 * @param string $category Profession category.
	 * @return array Array of MIME type strings.
	 */
	protected static function get_supported_mimes_for_category( $category ) {
		$base_mimes = array( 'text/plain' );

		switch ( $category ) {
			case 'advisory':
			case 'financial':
			case 'legal':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					)
				);

			case 'creative':
				return array_merge(
					$base_mimes,
					array(
						'image/jpeg',
						'image/png',
						'image/webp',
						'application/pdf',
					)
				);

			case 'technical':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'text/csv',
					)
				);

			case 'healthcare':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'image/jpeg',
						'image/png',
					)
				);

			default:
				return array_merge(
					$base_mimes,
					array( 'application/pdf' )
				);
		}
	}
}
