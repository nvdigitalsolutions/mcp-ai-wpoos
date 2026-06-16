<?php
/**
 * Tool for cleaning up orphaned media files from the WordPress media library.
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
 * Removes orphaned media files and broken attachment records.
 *
 * Deletes:
 * - Attachment records where the underlying physical file is missing.
 * - Physical files in the uploads directory with no corresponding attachment.
 * - (Optionally) Unreferenced attachments not used in any post content.
 *
 * Supports a dry_run mode that reports what would be deleted without
 * making changes. Accepts a list of attachment IDs from a prior scan.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Tool_Cleanup_Orphaned_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cleanup_orphaned_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Cleanup Orphaned Media', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Removes orphaned media files and broken attachment records from the WordPress media library. Supports dry_run mode to preview changes before executing. Can delete unreferenced attachments, attachment records with missing files, and physical files with no attachment record. Accepts optional lists of attachment IDs to target specific items.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'dry_run'                  => array(
					'type'        => 'boolean',
					'description' => __( 'If true, previews what would be deleted without making changes. Default: true (safe).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'cleanup_type'             => array(
					'type'        => 'string',
					'description' => __( 'What to clean up. "all" removes everything orphaned; "missing_files" removes attachment records with missing physical files; "unregistered" removes files with no attachment record; "unreferenced" removes attachments not used in any content; "ids" only processes the provided attachment_ids.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'missing_files', 'unregistered', 'unreferenced', 'ids' ),
					'default'     => 'all',
				),
				'attachment_ids'           => array(
					'type'        => 'array',
					'description' => __( 'Optional list of specific attachment IDs to delete. Only used when cleanup_type is "ids".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'delete_unreferenced'      => array(
					'type'        => 'boolean',
					'description' => __( 'When cleanup_type is "all", whether to also delete unreferenced attachments (not used in any content). Default: false (safe — unreferenced files may still be needed).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'limit'                    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of items to process. Default: 100. Use to avoid timeouts on large libraries.', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'delete_posts';
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
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'administrator', 'content_manager' ),
			'risk_level'            => 'moderate',
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
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
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
		return __( 'The Cleanup Orphaned Media tool requires the Media Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Cleanup results.
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
		$dry_run             = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;
		$cleanup_type        = isset( $arguments['cleanup_type'] ) ? sanitize_text_field( $arguments['cleanup_type'] ) : 'all';
		$attachment_ids      = isset( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ? array_map( 'absint', $arguments['attachment_ids'] ) : array();
		$delete_unreferenced = isset( $arguments['delete_unreferenced'] ) ? (bool) $arguments['delete_unreferenced'] : false;
		$limit               = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;

		$results = array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'cleanup_type'  => $cleanup_type,
			'message'       => $dry_run
				? __( 'Dry run completed. No files were actually deleted. Set dry_run to false to execute cleanup.', 'mcp-ai-wpoos-pro' )
				: __( 'Orphaned media cleanup completed.', 'mcp-ai-wpoos-pro' ),
			'deleted'       => array(
				'attachments'     => array(),
				'count_attachments' => 0,
				'files'           => array(),
				'count_files'     => 0,
				'errors'          => array(),
			),
			'bytes_freed'   => 0,
		);

		// Process by cleanup type.
		if ( 'ids' === $cleanup_type ) {
			$this->cleanup_by_ids( $attachment_ids, $dry_run, $limit, $results );
		} else {
			if ( 'all' === $cleanup_type || 'missing_files' === $cleanup_type ) {
				$this->cleanup_missing_files( $dry_run, $limit, $results );
			}

			if ( 'all' === $cleanup_type || 'unregistered' === $cleanup_type ) {
				$this->cleanup_unregistered_files( $dry_run, $limit, $results );
			}

			if ( ( 'all' === $cleanup_type && $delete_unreferenced ) || 'unreferenced' === $cleanup_type ) {
				$this->cleanup_unreferenced_attachments( $dry_run, $limit, $results );
			}
		}

		$results['total_deleted'] = $results['deleted']['count_attachments'] + $results['deleted']['count_files'];
		$results['bytes_freed']   = size_format( $results['bytes_freed'] );

		return $results;
	}

	/**
	 * Clean up specific attachment IDs.
	 *
	 * @since 2.7.0
	 * @param int[] $attachment_ids Attachment IDs to delete.
	 * @param bool  $dry_run        Whether this is a dry run.
	 * @param int   $limit          Maximum items to process.
	 * @param array $results        Results array to update (by reference).
	 */
	private function cleanup_by_ids( $attachment_ids, $dry_run, $limit, &$results ) {
		$attachment_ids = array_slice( $attachment_ids, 0, $limit );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$results['deleted']['errors'][] = sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment ID %d is not a valid attachment.', 'mcp-ai-wpoos-pro' ),
					$attachment_id
				);
				continue;
			}

			$file = get_attached_file( $attachment_id );

			if ( $dry_run ) {
				$results['deleted']['attachments'][] = array(
					'id'    => $attachment_id,
					'title' => $attachment->post_title,
					'file'  => $file ? basename( $file ) : '',
				);
				$results['deleted']['count_attachments']++;
				if ( $file && file_exists( $file ) ) {
					$results['bytes_freed'] += filesize( $file );
				}
			} else {
				// Get file size before deletion.
				if ( $file && file_exists( $file ) ) {
					$results['bytes_freed'] += filesize( $file );
				}

				$deleted = wp_delete_attachment( $attachment_id, true );
				if ( $deleted ) {
					$results['deleted']['attachments'][] = array(
						'id'    => $attachment_id,
						'title' => $attachment->post_title,
						'file'  => $file ? basename( $file ) : '',
					);
					$results['deleted']['count_attachments']++;
				} else {
					$results['deleted']['errors'][] = sprintf(
						/* translators: %d: attachment ID */
						__( 'Failed to delete attachment ID %d.', 'mcp-ai-wpoos-pro' ),
						$attachment_id
					);
				}
			}
		}
	}

	/**
	 * Clean up attachment records with missing physical files.
	 *
	 * @since 2.7.0
	 * @param bool  $dry_run Whether this is a dry run.
	 * @param int   $limit   Maximum items to process.
	 * @param array $results Results array to update (by reference).
	 */
	private function cleanup_missing_files( $dry_run, $limit, &$results ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$processed = 0;
		foreach ( $attachments as $attachment ) {
			if ( $processed >= $limit ) {
				break;
			}

			$file = get_attached_file( $attachment->ID );

			// Only process attachments with missing files.
			if ( $file && file_exists( $file ) ) {
				continue;
			}

			$processed++;

			if ( $dry_run ) {
				$results['deleted']['attachments'][] = array(
					'id'            => $attachment->ID,
					'title'         => $attachment->post_title,
					'expected_file' => $file ? basename( $file ) : '',
				);
				$results['deleted']['count_attachments']++;
			} else {
				$deleted = wp_delete_attachment( $attachment->ID, true );
				if ( $deleted ) {
					$results['deleted']['attachments'][] = array(
						'id'    => $attachment->ID,
						'title' => $attachment->post_title,
					);
					$results['deleted']['count_attachments']++;
				} else {
					$results['deleted']['errors'][] = sprintf(
						/* translators: %d: attachment ID */
						__( 'Failed to delete attachment ID %d (missing file).', 'mcp-ai-wpoos-pro' ),
						$attachment->ID
					);
				}
			}
		}
	}

	/**
	 * Clean up physical files with no corresponding attachment record.
	 *
	 * @since 2.7.0
	 * @param bool  $dry_run Whether this is a dry run.
	 * @param int   $limit   Maximum items to process.
	 * @param array $results Results array to update (by reference).
	 */
	private function cleanup_unregistered_files( $dry_run, $limit, &$results ) {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return;
		}

		// Build set of all registered file paths.
		$registered = $this->get_all_registered_files();

		// Scan year/month subdirectories.
		$processed = 0;
		$years     = glob( $base_dir . '/*', GLOB_ONLYDIR );

		foreach ( $years as $year_dir ) {
			if ( $processed >= $limit ) {
				break;
			}

			if ( ! is_numeric( basename( $year_dir ) ) ) {
				continue;
			}

			$months = glob( $year_dir . '/*', GLOB_ONLYDIR );
			foreach ( $months as $month_dir ) {
				if ( $processed >= $limit ) {
					break;
				}

				if ( ! is_numeric( basename( $month_dir ) ) ) {
					continue;
				}

				$files = glob( $month_dir . '/*' );
				if ( ! is_array( $files ) ) {
					continue;
				}

				foreach ( $files as $file_path ) {
					if ( $processed >= $limit ) {
						break;
					}

					if ( ! is_file( $file_path ) ) {
						continue;
					}

					// Skip intermediate sizes (e.g., file-150x150.jpg).
					$basename = basename( $file_path );
					if ( preg_match( '/-\d+x\d+\./', $basename ) ) {
						continue;
					}

					$normalized = wp_normalize_path( $file_path );
					if ( in_array( $normalized, $registered, true ) ) {
						continue;
					}

					$processed++;

					$relative_path = str_replace( $base_dir . '/', '', $normalized );
					$file_size     = filesize( $file_path );

					if ( $dry_run ) {
						$results['deleted']['files'][] = array(
							'file'      => $relative_path,
							'file_size' => size_format( $file_size ),
						);
						$results['deleted']['count_files']++;
						$results['bytes_freed'] += $file_size;
					} else {
						// Also delete corresponding intermediate sizes.
						$deleted_main = $this->delete_file_safe( $file_path );

						if ( $deleted_main ) {
							$results['deleted']['files'][] = array(
								'file'      => $relative_path,
								'file_size' => size_format( $file_size ),
							);
							$results['deleted']['count_files']++;
							$results['bytes_freed'] += $file_size;

							// Delete all intermediate size variants of this file.
							$bytes_from_sizes = $this->delete_size_variants( $file_path, $dry_run, $results );
							$results['bytes_freed'] += $bytes_from_sizes;
						} else {
							$results['deleted']['errors'][] = sprintf(
								/* translators: %s: file path */
								__( 'Failed to delete file: %s', 'mcp-ai-wpoos-pro' ),
								$relative_path
							);
						}
					}
				}
			}
		}
	}

	/**
	 * Clean up unreferenced attachments (not in content, no parent, not featured).
	 *
	 * @since 2.7.0
	 * @param bool  $dry_run Whether this is a dry run.
	 * @param int   $limit   Maximum items to process.
	 * @param array $results Results array to update (by reference).
	 */
	private function cleanup_unreferenced_attachments( $dry_run, $limit, &$results ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$processed = 0;
		foreach ( $attachments as $attachment ) {
			if ( $processed >= $limit ) {
				break;
			}

			$attachment_id = $attachment->ID;

			// Skip if it has a parent.
			$parent_id = wp_get_post_parent_id( $attachment_id );
			if ( $parent_id && 'trash' !== get_post_status( $parent_id ) ) {
				continue;
			}

			// Skip if used as featured image.
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
				continue;
			}

			// Skip if filename appears in any post content.
			$file = get_attached_file( $attachment_id );
			if ( ! $file ) {
				// File is missing — already handled by missing_files cleanup.
				continue;
			}

			$filenames = array( basename( $file ) );
			$metadata  = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$filenames[] = $size_data['file'];
					}
				}
			}
			$filenames = array_unique( $filenames );

			if ( $this->is_referenced_in_content( $filenames ) ) {
				continue;
			}

			$processed++;

			if ( $dry_run ) {
				$results['deleted']['attachments'][] = array(
					'id'    => $attachment_id,
					'title' => $attachment->post_title,
					'file'  => basename( $file ),
				);
				$results['deleted']['count_attachments']++;
				if ( file_exists( $file ) ) {
					$results['bytes_freed'] += filesize( $file );
				}
			} else {
				if ( file_exists( $file ) ) {
					$results['bytes_freed'] += filesize( $file );
				}

				$deleted = wp_delete_attachment( $attachment_id, true );
				if ( $deleted ) {
					$results['deleted']['attachments'][] = array(
						'id'    => $attachment_id,
						'title' => $attachment->post_title,
						'file'  => basename( $file ),
					);
					$results['deleted']['count_attachments']++;
				} else {
					$results['deleted']['errors'][] = sprintf(
						/* translators: %d: attachment ID */
						__( 'Failed to delete unreferenced attachment ID %d.', 'mcp-ai-wpoos-pro' ),
						$attachment_id
					);
				}
			}
		}
	}

	/**
	 * Check if any of the given filenames appear in published post content.
	 *
	 * @since 2.7.0
	 * @param string[] $filenames Filenames to search for.
	 * @return bool True if any filename is found in post_content.
	 */
	private function is_referenced_in_content( $filenames ) {
		$filenames = array_unique( $filenames );
		$chunk_size = 5;

		for ( $i = 0; $i < count( $filenames ); $i += $chunk_size ) {
			$chunk   = array_slice( $filenames, $i, $chunk_size );
			$clauses = array();

			foreach ( $chunk as $term ) {
				$clauses[] = $GLOBALS['wpdb']->prepare(
					'post_content LIKE %s',
					'%' . $GLOBALS['wpdb']->esc_like( $term ) . '%'
				);
			}

			$where = implode( ' OR ', $clauses );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Safely delete a file and log errors.
	 *
	 * @since 2.7.0
	 * @param string $file_path Absolute path to the file.
	 * @return bool True on success.
	 */
	private function delete_file_safe( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Double-check we're deleting from the uploads directory.
		$upload_dir = wp_upload_dir();
		$base_dir   = wp_normalize_path( $upload_dir['basedir'] );
		$file_path  = wp_normalize_path( $file_path );

		if ( 0 !== strpos( $file_path, $base_dir ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return unlink( $file_path );
	}

	/**
	 * Delete intermediate size variants for a given main file.
	 *
	 * e.g. For photo.jpg, deletes photo-150x150.jpg, photo-300x300.jpg, etc.
	 *
	 * @since 2.7.0
	 * @param string $main_file Absolute path to the main file.
	 * @param bool   $dry_run   Whether this is a dry run.
	 * @param array  $results   Results array to update (by reference).
	 * @return int Total bytes freed from size variants.
	 */
	private function delete_size_variants( $main_file, $dry_run, &$results ) {
		$dir       = dirname( $main_file );
		$basename  = basename( $main_file );
		$ext_pos   = strrpos( $basename, '.' );
		$name_base = false !== $ext_pos ? substr( $basename, 0, $ext_pos ) : $basename;
		$ext       = false !== $ext_pos ? substr( $basename, $ext_pos ) : '';

		$bytes_freed = 0;
		$pattern     = $dir . '/' . $name_base . '-*' . $ext;
		$variants    = glob( $pattern );

		if ( ! is_array( $variants ) ) {
			return $bytes_freed;
		}

		foreach ( $variants as $variant ) {
			// Only match size suffixes like -150x150, -300x300, etc.
			$variant_basename = basename( $variant );
			if ( ! preg_match( '/-\d+x\d+\./', $variant_basename ) ) {
				continue;
			}

			if ( ! $dry_run ) {
				if ( file_exists( $variant ) ) {
					$variant_size = filesize( $variant );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					if ( unlink( $variant ) ) {
						$bytes_freed += $variant_size;
						$results['deleted']['files'][] = array(
							'file'      => str_replace( wp_normalize_path( wp_upload_dir()['basedir'] ) . '/', '', wp_normalize_path( $variant ) ),
							'file_size' => size_format( $variant_size ),
						);
						$results['deleted']['count_files']++;
					}
				}
			}
		}

		return $bytes_freed;
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
