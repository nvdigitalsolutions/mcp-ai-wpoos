<?php
/**
 * Tool for scanning orphaned media files in the WordPress media library.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Media_Toolkit
 * @since 2.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans the WordPress media library for orphaned media files.
 *
 * Identifies:
 * - Attachments with no parent post and not used in any post/page content.
 * - Attachment records where the underlying physical file is missing.
 * - Physical files in the uploads directory with no corresponding attachment record.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Tool_Scan_Orphaned_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scan_orphaned_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scan Orphaned Media', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scans the WordPress media library for orphaned files. Identifies attachments that are not referenced in any post or page content, attachment records where the underlying physical file no longer exists, and files in the uploads directory that have no corresponding attachment record. Returns counts and details by category.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'scan_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of scan to perform. "all" runs every check; "unreferenced" finds attachments not used in content; "missing_files" finds attachment records with no physical file; "unregistered" finds files with no attachment record.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'unreferenced', 'missing_files', 'unregistered' ),
					'default'     => 'all',
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return per category. Default: 500.', 'mcp-ai-wpoos-pro' ),
					'default'     => 500,
					'minimum'     => 1,
					'maximum'     => 5000,
				),
				'year_month' => array(
					'type'        => 'string',
					'description' => __( 'Optional uploads year/month subdirectory to restrict the scan (e.g. "2024/01").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_content',
			'post_type'             => 'attachment',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'content_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Media Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.7.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_media_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.7.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Scan Orphaned Media tool requires the Media Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Maximum number of posts to check for content references in a single query.
	 *
	 * @since 2.7.0
	 * @var int
	 */
	const CONTENT_CHECK_CHUNK = 200;

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Scan results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Media Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Parse arguments.
		$scan_type  = isset( $arguments['scan_type'] ) ? sanitize_text_field( $arguments['scan_type'] ) : 'all';
		$limit      = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 500;
		$year_month = isset( $arguments['year_month'] ) ? sanitize_text_field( $arguments['year_month'] ) : '';

		$results = array(
			'success'           => true,
			'scan_type'         => $scan_type,
			'message'           => __( 'Orphaned media scan completed.', 'mcp-ai-wpoos-pro' ),
			'total_attachments' => $this->get_total_attachment_count(),
			'unreferenced'      => array(
				'count' => 0,
				'items' => array(),
			),
			'missing_files'     => array(
				'count' => 0,
				'items' => array(),
			),
			'unregistered'      => array(
				'count' => 0,
				'items' => array(),
			),
		);

		if ( 'all' === $scan_type || 'unreferenced' === $scan_type ) {
			$unreferenced            = $this->scan_unreferenced_attachments( $limit, $year_month );
			$results['unreferenced'] = $unreferenced;
		}

		if ( 'all' === $scan_type || 'missing_files' === $scan_type ) {
			$missing_files            = $this->scan_missing_files( $limit, $year_month );
			$results['missing_files'] = $missing_files;
		}

		if ( 'all' === $scan_type || 'unregistered' === $scan_type ) {
			$unregistered            = $this->scan_unregistered_files( $limit, $year_month );
			$results['unregistered'] = $unregistered;
		}

		$results['total_orphaned'] = $results['unreferenced']['count'] + $results['missing_files']['count'] + $results['unregistered']['count'];

		return $results;
	}

	/**
	 * Get the total number of attachment posts in the media library.
	 *
	 * @since 2.7.0
	 * @return int
	 */
	private function get_total_attachment_count() {
		$counts = wp_count_posts( 'attachment' );
		$total  = 0;
		foreach ( $counts as $status => $count ) {
			if ( 'inherit' === $status ) {
				$total += (int) $count;
			}
		}
		return $total;
	}

	/**
	 * Scan for attachments not referenced in any post or page content.
	 *
	 * An attachment is considered referenced when its URL appears in the
	 * post_content of any published post, page, or custom post type.
	 *
	 * @since 2.7.0
	 * @param int    $limit      Maximum results.
	 * @param string $year_month Optional uploads subdirectory filter.
	 * @return array Count and items.
	 */
	private function scan_unreferenced_attachments( $limit, $year_month = '' ) {
		$result = array(
			'count' => 0,
			'items' => array(),
		);

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		// Filter by upload date if year_month is provided.
		if ( '' !== $year_month ) {
			$parts = explode( '/', $year_month );
			if ( 2 === count( $parts ) ) {
				$query_args['date_query'] = array(
					array(
						'year'  => absint( $parts[0] ),
						'month' => absint( $parts[1] ),
					),
				);
			}
		}

		$attachments = get_posts( $query_args );

		if ( empty( $attachments ) ) {
			return $result;
		}

		// Build a map of attachment ID → URL fragments to search for.
		$url_map = array();
		foreach ( $attachments as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$file          = get_attached_file( $attachment_id );
			$urls          = array();

			if ( $file ) {
				// Get the base filename (without path) for content searching.
				$urls[] = basename( $file );

				// Also include the WP-generated sizes filenames.
				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( ! empty( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size_data ) {
						if ( ! empty( $size_data['file'] ) ) {
							$urls[] = $size_data['file'];
						}
					}
				}
			}

			$url_map[ $attachment_id ] = array_unique( $urls );
		}

		// Check each attachment against post content.
		$unreferenced = array();
		foreach ( $url_map as $attachment_id => $filenames ) {
			if ( empty( $filenames ) ) {
				// No file — already captured by missing_files scan. Skip here.
				continue;
			}

			$is_referenced = $this->is_attachment_referenced_in_content( $attachment_id, $filenames );

			if ( ! $is_referenced ) {
				$attachment_post = get_post( $attachment_id );
				$file_path       = get_attached_file( $attachment_id );

				$unreferenced[] = array(
					'id'          => $attachment_id,
					'title'       => $attachment_post ? $attachment_post->post_title : '',
					'file'        => $file_path ? basename( $file_path ) : '',
					'file_size'   => $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : '',
					'upload_date' => $attachment_post ? $attachment_post->post_date : '',
					'mime_type'   => $attachment_post ? $attachment_post->post_mime_type : '',
				);
			}
		}

		$result['count'] = count( $unreferenced );
		$result['items'] = $unreferenced;

		return $result;
	}

	/**
	 * Check whether an attachment is referenced in any published post content.
	 *
	 * Searches post_content of all public post types for the attachment's
	 * filename. Also checks the attachment's own post_parent and whether
	 * it appears as a featured image.
	 *
	 * @since 2.7.0
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $filenames    Filenames to search for in content.
	 * @return bool True if referenced anywhere.
	 */
	private function is_attachment_referenced_in_content( $attachment_id, $filenames ) {
		// Check 1: Does it have a post_parent? (attached to a post).
		$parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $parent_id && 'trash' !== get_post_status( $parent_id ) ) {
			return true;
		}

		// Check 2: Is it used as a featured image anywhere?
		$featured_query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_thumbnail_id',
						'value' => $attachment_id,
					),
				),
			)
		);
		if ( $featured_query->have_posts() ) {
			return true;
		}

		// Check 3: Does its filename appear in any post content?
		$search_terms = array();
		foreach ( $filenames as $filename ) {
			// Escape for LIKE query and ensure we search for the bare filename.
			$search_terms[] = $filename;
		}

		$search_terms = array_unique( $search_terms );
		$term_count   = count( $search_terms );

		// Search in chunks to avoid extremely long queries.
		$chunk_size = 5;

		for ( $i = 0; $i < $term_count; $i += $chunk_size ) {
			$chunk   = array_slice( $search_terms, $i, $chunk_size );
			$clauses = array();

			foreach ( $chunk as $term ) {
				$clauses[] = $GLOBALS['wpdb']->prepare(
					'post_content LIKE %s',
					'%' . $GLOBALS['wpdb']->esc_like( $term ) . '%'
				);
			}

			$where = implode( ' OR ', $clauses );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where consists of prepare()d clauses above.
			$sql = $GLOBALS['wpdb']->prepare(
				"SELECT 1 FROM {$GLOBALS['wpdb']->posts} WHERE post_status = %s AND ({$where}) LIMIT 1",
				'publish'
			);
			// phpcs:enable

			$found = $GLOBALS['wpdb']->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $found ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scan for attachment records whose underlying physical file is missing.
	 *
	 * @since 2.7.0
	 * @param int    $limit      Maximum results.
	 * @param string $year_month Optional uploads subdirectory filter.
	 * @return array Count and items.
	 */
	private function scan_missing_files( $limit, $year_month = '' ) {
		$result = array(
			'count' => 0,
			'items' => array(),
		);

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( '' !== $year_month ) {
			$parts = explode( '/', $year_month );
			if ( 2 === count( $parts ) ) {
				$query_args['date_query'] = array(
					array(
						'year'  => absint( $parts[0] ),
						'month' => absint( $parts[1] ),
					),
				);
			}
		}

		$attachments = get_posts( $query_args );

		if ( empty( $attachments ) ) {
			return $result;
		}

		$missing = array();
		foreach ( $attachments as $attachment ) {
			$attachment_id = $attachment->ID;
			$file          = get_attached_file( $attachment_id );

			if ( ! $file || ! file_exists( $file ) ) {
				$attachment_post = get_post( $attachment_id );
				$missing[]       = array(
					'id'            => $attachment_id,
					'title'         => $attachment_post ? $attachment_post->post_title : '',
					'expected_file' => $file ? basename( $file ) : '',
					'upload_date'   => $attachment_post ? $attachment_post->post_date : '',
					'mime_type'     => $attachment_post ? $attachment_post->post_mime_type : '',
				);
			}
		}

		$result['count'] = count( $missing );
		$result['items'] = $missing;

		return $result;
	}

	/**
	 * Scan for physical files in the uploads directory with no attachment record.
	 *
	 * @since 2.7.0
	 * @param int    $limit      Maximum results.
	 * @param string $year_month Optional uploads subdirectory filter.
	 * @return array Count and items.
	 */
	private function scan_unregistered_files( $limit, $year_month = '' ) {
		$result = array(
			'count' => 0,
			'items' => array(),
		);

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return $result;
		}

		// Get all registered attachment file paths.
		$registered = $this->get_all_registered_files();

		// Determine which directories to scan.
		$scan_dirs = array();
		if ( '' !== $year_month ) {
			$target_dir = $base_dir . '/' . trim( $year_month, '/' );
			if ( is_dir( $target_dir ) ) {
				$scan_dirs[] = $target_dir;
			}
		} else {
			// Scan year/month subdirectories in the uploads root.
			$years = glob( $base_dir . '/*', GLOB_ONLYDIR );
			foreach ( $years as $year_dir ) {
				if ( ! is_numeric( basename( $year_dir ) ) ) {
					continue;
				}
				$months = glob( $year_dir . '/*', GLOB_ONLYDIR );
				foreach ( $months as $month_dir ) {
					if ( is_numeric( basename( $month_dir ) ) ) {
						$scan_dirs[] = $month_dir;
					}
				}
			}
		}

		$unregistered = array();
		$count        = 0;

		foreach ( $scan_dirs as $scan_dir ) {
			if ( $count >= $limit ) {
				break;
			}

			$files = glob( $scan_dir . '/*' );
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file_path ) {
				if ( $count >= $limit ) {
					break;
				}

				// Skip directories and non-files.
				if ( ! is_file( $file_path ) ) {
					continue;
				}

				// Skip scaled/rotated intermediate images (e.g. -scaled, -rotated suffix).
				$basename = basename( $file_path );
				if ( preg_match( '/-\d+x\d+\./', $basename ) ) {
					continue;
				}

				// Check if this file is registered.
				$normalized = wp_normalize_path( $file_path );
				if ( ! in_array( $normalized, $registered, true ) ) {
					$unregistered[] = array(
						'file'      => str_replace( $base_dir . '/', '', $normalized ),
						'file_size' => size_format( filesize( $file_path ) ),
					);
					++$count;
				}
			}
		}

		$result['count'] = $count;
		$result['items'] = $unregistered;

		return $result;
	}

	/**
	 * Build a set of all registered attachment file paths (main + all sizes).
	 *
	 * @since 2.7.0
	 * @return string[] Normalized file paths.
	 */
	private function get_all_registered_files() {
		$registered = array();

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) ) {
				$registered[] = wp_normalize_path( $file );
			}

			// Also include size variants.
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) ) {
				$base_dir = dirname( $file );
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$size_path    = $base_dir . '/' . $size_data['file'];
						$registered[] = wp_normalize_path( $size_path );
					}
				}
			}
		}

		return array_unique( $registered );
	}
}
