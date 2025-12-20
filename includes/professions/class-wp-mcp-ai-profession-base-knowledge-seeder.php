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
	 * Populates the META_KNOWLEDGE_BASE field from profession-documents/*.txt files.
	 *
	 * @param WP_Post $profession Profession post object.
	 * @param bool    $force      Force update even if knowledge base already has content.
	 */
	protected static function seed_profession_base_knowledge( $profession, $force = false ) {
		$slug     = $profession->post_name;
		$category = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );

		// Check if knowledge base already has content.
		$existing_knowledge = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );

		if ( ! empty( $existing_knowledge ) && ! $force ) {
			// Already has knowledge base content, skip.
			self::ensure_supported_mime_types( $profession->ID, $category, $force );
			return;
		}

		// Load knowledge document content from profession-documents/*.txt.
		$content = self::load_knowledge_document_from_file( $slug );

		if ( empty( $content ) ) {
			// No document file found, skip (don't overwrite existing content with empty).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI: No profession-documents file found for ' . $slug );
			}
			return;
		}

		// Update profession's META_KNOWLEDGE_BASE field.
		update_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, $content );

		// Populate META_SUPPORTED_MIME_TYPES.
		self::ensure_supported_mime_types( $profession->ID, $category, $force );
	}

	/**
	 * Load knowledge document content from profession-documents/*.txt file.
	 *
	 * @param string $slug Profession slug.
	 * @return string Knowledge document content, or empty string if file not found.
	 */
	protected static function load_knowledge_document_from_file( $slug ) {
		$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-documents/' . sanitize_file_name( $slug ) . '.txt';

		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		return false !== $content ? $content : '';
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
