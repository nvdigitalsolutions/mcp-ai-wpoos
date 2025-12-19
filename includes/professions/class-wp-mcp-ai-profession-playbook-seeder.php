<?php
/**
 * Profession Playbook Seeder.
 *
 * Seeds profession playbook attachments from authorable txt files.
 * Runs incrementally after profession seeding, processing batches to avoid timeouts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds profession playbook attachments.
 */
class WP_MCP_AI_Profession_Playbook_Seeder {
	/**
	 * Option key to track if playbooks have been seeded.
	 */
	const SEEDED_OPTION = 'wp_mcp_ai_playbooks_seeded';

	/**
	 * Option key for tracking batch progress offset.
	 */
	const OFFSET_OPTION = 'wp_mcp_ai_playbook_seed_offset';

	/**
	 * Admin init priority for seeding.
	 * Must run after profession seeding (priority 20).
	 */
	const ADMIN_INIT_PRIORITY = 30;

	/**
	 * Number of professions to process per admin_init.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Initialize the seeder.
	 * Runs once after profession seeding completes.
	 */
	public static function init() {
		// Check if already seeded.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Seed playbooks after professions are seeded.
		add_action( 'admin_init', array( __CLASS__, 'seed_playbooks_incremental' ), self::ADMIN_INIT_PRIORITY );
	}

	/**
	 * Seed playbooks incrementally (batch processing).
	 *
	 * Processes BATCH_SIZE professions per admin_init to avoid timeouts.
	 */
	public static function seed_playbooks_incremental() {
		// Bail if already seeded.
		if ( get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		// Ensure professions exist before seeding playbooks.
		if ( ! get_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, false ) ) {
			// Professions not seeded yet, bail.
			return;
		}

		// Load repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}

		// Load playbook loader if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Loader' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-playbook-loader.php';
		}

		// Get current offset.
		$offset = absint( get_option( self::OFFSET_OPTION, 0 ) );

		// Get batch of professions.
		$repository  = new WP_MCP_AI_Profession_Repository();
		$professions = $repository->find_all(
			array(
				'posts_per_page' => self::BATCH_SIZE,
				'offset'         => $offset,
			)
		);

		if ( empty( $professions ) ) {
			// No more professions to process - mark as complete.
			update_option( self::SEEDED_OPTION, true, false );
			delete_option( self::OFFSET_OPTION );
			return;
		}

		// Process this batch.
		$loader = new WP_MCP_AI_Profession_Playbook_Loader();

		foreach ( $professions as $profession ) {
			self::sync_profession_playbook( $profession, $loader, false );
		}

		// Update offset for next batch.
		$new_offset = $offset + self::BATCH_SIZE;
		update_option( self::OFFSET_OPTION, $new_offset, false );
	}

	/**
	 * Sync playbook for a single profession.
	 *
	 * Creates or updates playbook attachment based on content hash.
	 * Automatically removes duplicate playbook attachments.
	 *
	 * @param WP_Post                              $profession Profession post object.
	 * @param WP_MCP_AI_Profession_Playbook_Loader $loader     Playbook loader instance.
	 * @param bool                                 $force      Force recreation even if hash matches.
	 */
	protected static function sync_profession_playbook( $profession, $loader, $force = false ) {
		$slug = $profession->post_name;

		// First, remove any duplicate playbook attachments for this profession.
		self::remove_duplicate_playbooks( $profession->ID );

		// Build playbook content.
		$content = $loader->build_playbook( $profession->ID );

		if ( empty( $content ) ) {
			// No content to create attachment for.
			return;
		}

		// Calculate content hash.
		$content_hash = hash( 'sha256', $content );

		// Check if attachment already exists.
		$existing_attachment = self::find_existing_playbook_attachment( $profession->ID );

		if ( $existing_attachment && ! $force ) {
			// Check if content has changed.
			$existing_hash = get_post_meta( $existing_attachment->ID, '_wp_mcp_ai_playbook_hash', true );

			if ( $existing_hash === $content_hash ) {
				// Content unchanged, ensure it's in memory files and MIME types are set.
				self::ensure_attachment_in_memory_files( $profession->ID, $existing_attachment->ID );
				self::ensure_supported_mime_types( $profession->ID );
				return;
			}

			// Content changed - update the existing attachment file.
			self::update_playbook_attachment( $existing_attachment->ID, $content, $content_hash );
			self::ensure_attachment_in_memory_files( $profession->ID, $existing_attachment->ID );
			self::ensure_supported_mime_types( $profession->ID );
			return;
		}

		// Create new attachment.
		$attachment_id = self::create_playbook_attachment( $profession, $content, $content_hash );

		if ( is_wp_error( $attachment_id ) ) {
			// Log error but don't fail the entire seeding.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP_MCP_AI: Failed to create playbook attachment for profession ' . $slug . ': ' . $attachment_id->get_error_message() );
			}
			return;
		}

		// Update profession META_MEMORY_FILES and MIME types.
		self::ensure_attachment_in_memory_files( $profession->ID, $attachment_id );
		self::ensure_supported_mime_types( $profession->ID );
	}

	/**
	 * Find existing playbook attachment for a profession.
	 *
	 * Returns the most recent attachment if duplicates exist.
	 *
	 * @param int $profession_id Profession post ID.
	 * @return WP_Post|null Attachment post or null if not found.
	 */
	protected static function find_existing_playbook_attachment( $profession_id ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_wp_mcp_ai_playbook_profession_id',
					'value'   => $profession_id,
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
	 * Find all existing playbook attachments for a profession.
	 *
	 * @param int $profession_id Profession post ID.
	 * @return array Array of WP_Post objects.
	 */
	protected static function find_all_playbook_attachments( $profession_id ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_wp_mcp_ai_playbook_profession_id',
					'value'   => $profession_id,
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Remove duplicate playbook attachments for a profession.
	 *
	 * Keeps only the most recent attachment associated with the profession.
	 * Older duplicate attachments are removed from the profession's memory files
	 * but remain in the media library for reference.
	 *
	 * @param int $profession_id Profession post ID.
	 * @return int Number of duplicates removed from profession.
	 */
	protected static function remove_duplicate_playbooks( $profession_id ) {
		$attachments = self::find_all_playbook_attachments( $profession_id );

		if ( count( $attachments ) <= 1 ) {
			// No duplicates to remove.
			return 0;
		}

		$removed_count = 0;

		// Keep the first (most recent) attachment, remove the rest from profession.
		$keep_attachment_id = $attachments[0]->ID;

		for ( $i = 1; $i < count( $attachments ); $i++ ) {
			$attachment_id = $attachments[ $i ]->ID;

			// Remove from profession's memory files.
			self::remove_attachment_from_memory_files( $profession_id, $attachment_id );

			// Remove the profession association meta, but keep the attachment in media library.
			delete_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id' );

			$removed_count++;
		}

		// Ensure the kept attachment is in memory files.
		self::ensure_attachment_in_memory_files( $profession_id, $keep_attachment_id );

		return $removed_count;
	}

	/**
	 * Remove attachment ID from profession's memory files meta.
	 *
	 * @param int $profession_id Profession post ID.
	 * @param int $attachment_id Attachment post ID.
	 */
	protected static function remove_attachment_from_memory_files( $profession_id, $attachment_id ) {
		$memory_files = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, true );

		if ( ! is_array( $memory_files ) ) {
			return;
		}

		$key = array_search( $attachment_id, $memory_files, true );
		if ( false !== $key ) {
			unset( $memory_files[ $key ] );
			// Re-index array to maintain sequential keys.
			$memory_files = array_values( $memory_files );
			update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );
		}
	}

	/**
	 * Create playbook attachment file.
	 *
	 * @param WP_Post $profession    Profession post object.
	 * @param string  $content       Playbook content.
	 * @param string  $content_hash  SHA256 hash of content.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected static function create_playbook_attachment( $profession, $content, $content_hash ) {
		$slug     = $profession->post_name;
		$filename = "profession-{$profession->ID}-{$slug}-playbook.txt";

		// Get upload directory.
		$upload_dir = wp_upload_dir();

		// Create subdirectory if it doesn't exist.
		$subdir     = 'wp-mcp-ai/profession-playbooks';
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;
		$target_url = trailingslashit( $upload_dir['baseurl'] ) . $subdir;

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		// Target file path.
		$target_file = trailingslashit( $target_dir ) . $filename;

		// Write content to file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $target_file, $content );

		if ( false === $result ) {
			return new WP_Error(
				'file_write_error',
				sprintf( 'Failed to write playbook file: %s', $target_file )
			);
		}

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => 'text/plain',
			'post_title'     => $profession->post_title . ' - Playbook',
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

		// Add playbook metadata.
		update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $profession->ID );
		update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', $content_hash );

		return $attachment_id;
	}

	/**
	 * Update existing playbook attachment with new content.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $content       New playbook content.
	 * @param string $content_hash  SHA256 hash of new content.
	 */
	protected static function update_playbook_attachment( $attachment_id, $content, $content_hash ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		// Update file content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file_path, $content );

		// Update hash meta.
		update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', $content_hash );
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

		// Deduplicate existing array to clean up any existing duplicates.
		$memory_files = array_values( array_unique( array_map( 'absint', $memory_files ) ) );

		// Add attachment if not already present (idempotent).
		if ( ! in_array( $attachment_id, $memory_files, true ) ) {
			$memory_files[] = $attachment_id;
		}

		// Always update to ensure deduplication is saved.
		update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_MEMORY_FILES, $memory_files );
	}

	/**
	 * Ensure profession has text/plain in supported MIME types.
	 *
	 * @param int $profession_id Profession post ID.
	 */
	protected static function ensure_supported_mime_types( $profession_id ) {
		$existing_mimes = get_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, true );

		if ( ! is_array( $existing_mimes ) ) {
			$existing_mimes = array();
		}

		// Ensure text/plain is included.
		if ( ! in_array( 'text/plain', $existing_mimes, true ) ) {
			$existing_mimes[] = 'text/plain';
			update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_SUPPORTED_MIME_TYPES, $existing_mimes );
		}
	}

	/**
	 * Sync all profession playbooks.
	 *
	 * Can be called manually to regenerate all playbooks from current txt files.
	 *
	 * @param bool $force Force regeneration even if content hash matches.
	 */
	public static function sync_all( $force = false ) {
		// Load repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}

		// Load playbook loader if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Loader' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-playbook-loader.php';
		}

		$repository  = new WP_MCP_AI_Profession_Repository();
		$professions = $repository->find_all();

		if ( empty( $professions ) ) {
			return;
		}

		$loader = new WP_MCP_AI_Profession_Playbook_Loader();

		foreach ( $professions as $profession ) {
			self::sync_profession_playbook( $profession, $loader, $force );
		}
	}

	/**
	 * Clean up all duplicate playbook attachments.
	 *
	 * Removes duplicate playbook attachments across all professions,
	 * keeping only the most recent attachment for each profession.
	 *
	 * @return array Statistics about the cleanup operation.
	 */
	public static function cleanup_all_duplicates() {
		// Load repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
		}

		$repository  = new WP_MCP_AI_Profession_Repository();
		$professions = $repository->find_all();

		if ( empty( $professions ) ) {
			return array(
				'professions_processed' => 0,
				'duplicates_removed'    => 0,
			);
		}

		$total_removed         = 0;
		$professions_processed = 0;

		foreach ( $professions as $profession ) {
			$removed = self::remove_duplicate_playbooks( $profession->ID );
			$total_removed += $removed;

			if ( $removed > 0 ) {
				$professions_processed++;
			}
		}

		return array(
			'professions_processed' => $professions_processed,
			'duplicates_removed'    => $total_removed,
		);
	}
}
