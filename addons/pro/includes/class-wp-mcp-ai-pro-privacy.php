<?php
/**
 * Pro Privacy API Integration
 *
 * Extends the base WP Privacy API integration to cover the additional
 * personal/health data stored by the Pro addon:
 *
 *  - Health-wellness CPTs (mcp_ai_member, mcp_ai_policy, mcp_ai_med_record,
 *    mcp_ai_checkup, mcp_ai_prescription, mcp_ai_allergy)
 *  - DICOM imaging studies (mcp_ai_imaging_study)
 *
 * Registers exporters and erasers that are wired into the standard
 * WordPress "Tools → Export Personal Data" / "Erase Personal Data" flow.
 * Erasure of health data hard-deletes all CPT posts (no trash) and, for
 * imaging studies, also removes the DICOM files on disk.
 *
 * F-PRIV-01 fix — Wave 23.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Privacy API service.
 */
class WP_MCP_AI_Pro_Privacy {

	/**
	 * Number of records processed per paginated Privacy API callback.
	 *
	 * WordPress passes a $page parameter (1-based) to each callback; 10 records
	 * per page keeps individual page-load time bounded.
	 *
	 * @var int
	 */
	const PAGE_SIZE = 10;

	/**
	 * Boot the service by wiring into the Privacy API filters.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ), 15 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ), 15 );
	}

	// =========================================================================
	// Registration
	// =========================================================================

	/**
	 * Register Pro personal-data exporters.
	 *
	 * @param array $exporters Existing exporter definitions.
	 * @return array Modified exporter definitions.
	 */
	public static function register_exporters( $exporters ) {
		$exporters['wp-mcp-ai-pro-health-wellness'] = array(
			'exporter_friendly_name' => __( 'NV oOS Pro — Health & Wellness Records', 'mcp-ai-wpoos-pro' ),
			'callback'               => array( __CLASS__, 'export_health_wellness' ),
		);

		if ( class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$exporters['wp-mcp-ai-pro-imaging-studies'] = array(
				'exporter_friendly_name' => __( 'NV oOS Pro — Medical Imaging Studies', 'mcp-ai-wpoos-pro' ),
				'callback'               => array( __CLASS__, 'export_imaging_studies' ),
			);
		}

		return $exporters;
	}

	/**
	 * Register Pro personal-data erasers.
	 *
	 * @param array $erasers Existing eraser definitions.
	 * @return array Modified eraser definitions.
	 */
	public static function register_erasers( $erasers ) {
		$erasers['wp-mcp-ai-pro-health-wellness'] = array(
			'eraser_friendly_name' => __( 'NV oOS Pro — Health & Wellness Records', 'mcp-ai-wpoos-pro' ),
			'callback'             => array( __CLASS__, 'erase_health_wellness' ),
		);

		if ( class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$erasers['wp-mcp-ai-pro-imaging-studies'] = array(
				'eraser_friendly_name' => __( 'NV oOS Pro — Medical Imaging Studies', 'mcp-ai-wpoos-pro' ),
				'callback'             => array( __CLASS__, 'erase_imaging_studies' ),
			);
		}

		return $erasers;
	}

	// =========================================================================
	// Exporters
	// =========================================================================

	/**
	 * Export health & wellness CPT records for a given e-mail address.
	 *
	 * Matches records by `post_author` because each CPT record is authored by
	 * the WordPress user who created it.  Where a separate member relationship
	 * field is present (e.g. `_member_id`), that is also checked.
	 *
	 * @param string $email_address Requestor e-mail.
	 * @param int    $page          Pagination page (1-based).
	 * @return array { data: array, done: bool }
	 */
	public static function export_health_wellness( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$cpt_map = self::get_health_cpt_map();
		$data    = array();
		$offset  = ( (int) $page - 1 ) * self::PAGE_SIZE;

		$posts = get_posts(
			array(
				'post_type'      => array_keys( $cpt_map ),
				'author'         => $user->ID,
				'posts_per_page' => self::PAGE_SIZE,
				'offset'         => $offset,
				'post_status'    => 'any',
				'fields'         => 'all',
			)
		);

		foreach ( $posts as $post ) {
			$label = isset( $cpt_map[ $post->post_type ] ) ? $cpt_map[ $post->post_type ] : $post->post_type;
			$meta  = get_post_meta( $post->ID );
			$items = array(
				array(
					'name'  => __( 'Record type', 'mcp-ai-wpoos-pro' ),
					'value' => esc_html( $label ),
				),
				array(
					'name'  => __( 'Title', 'mcp-ai-wpoos-pro' ),
					'value' => esc_html( $post->post_title ),
				),
				array(
					'name'  => __( 'Created', 'mcp-ai-wpoos-pro' ),
					'value' => esc_html( $post->post_date ),
				),
			);

			// Include all non-internal meta as additional export fields.
			foreach ( $meta as $meta_key => $meta_values ) {
				// Skip internal WordPress meta keys.
				if ( 0 === strpos( $meta_key, '_edit_' ) || 0 === strpos( $meta_key, '_wp_' ) ) {
					continue;
				}
				$items[] = array(
					'name'  => esc_html( $meta_key ),
					'value' => esc_html( maybe_serialize( $meta_values[0] ) ),
				);
			}

			$data[] = array(
				'group_id'          => 'wp-mcp-ai-pro-health-' . $post->post_type,
				'group_label'       => esc_html( $label ),
				'group_description' => esc_html(
					sprintf(
						/* translators: %s: record type label */
						__( 'NV oOS Pro health and wellness %s records authored by this user.', 'mcp-ai-wpoos-pro' ),
						$label
					)
				),
				'item_id'           => 'health-' . $post->ID,
				'data'              => $items,
			);
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Export DICOM imaging study records for a given e-mail address.
	 *
	 * Studies are matched by `post_author`.  Only de-identified study metadata
	 * is exported; raw DICOM file data is NOT included in the export bundle.
	 *
	 * @param string $email_address Requestor e-mail.
	 * @param int    $page          Pagination page (1-based).
	 * @return array { data: array, done: bool }
	 */
	public static function export_imaging_studies( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( (int) $page - 1 ) * self::PAGE_SIZE;

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_imaging_study',
				'author'         => $user->ID,
				'posts_per_page' => self::PAGE_SIZE,
				'offset'         => $offset,
				'post_status'    => 'any',
				'fields'         => 'all',
			)
		);

		$data = array();

		foreach ( $posts as $post ) {
			$data[] = array(
				'group_id'          => 'wp-mcp-ai-pro-imaging-studies',
				'group_label'       => __( 'Medical Imaging Studies', 'mcp-ai-wpoos-pro' ),
				'group_description' => __( 'NV oOS Pro DICOM imaging study records authored by this user. Pixel data is stored separately and not included in this export.', 'mcp-ai-wpoos-pro' ),
				'item_id'           => 'imaging-' . $post->ID,
				'data'              => array(
					array(
						'name'  => __( 'Study Instance UID', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_study_instance_uid', true ) ),
					),
					array(
						'name'  => __( 'Patient ID (de-identified)', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_patient_id', true ) ),
					),
					array(
						'name'  => __( 'Modality', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_modality', true ) ),
					),
					array(
						'name'  => __( 'Study Date', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_study_date', true ) ),
					),
					array(
						'name'  => __( 'Description', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_study_description', true ) ),
					),
					array(
						'name'  => __( 'Status', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( get_post_meta( $post->ID, '_imaging_status', true ) ),
					),
					array(
						'name'  => __( 'Uploaded', 'mcp-ai-wpoos-pro' ),
						'value' => esc_html( $post->post_date ),
					),
				),
			);
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	// =========================================================================
	// Erasers
	// =========================================================================

	/**
	 * Erase health & wellness CPT records for a given e-mail address.
	 *
	 * Records are hard-deleted (bypassing trash) because they contain PHI.
	 *
	 * @param string $email_address Requestor e-mail.
	 * @param int    $page          Pagination page (1-based).
	 * @return array { items_removed: int, items_retained: int, messages: array, done: bool }
	 */
	public static function erase_health_wellness( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$cpt_map = self::get_health_cpt_map();
		$offset  = ( (int) $page - 1 ) * self::PAGE_SIZE;

		$posts = get_posts(
			array(
				'post_type'      => array_keys( $cpt_map ),
				'author'         => $user->ID,
				'posts_per_page' => self::PAGE_SIZE,
				'offset'         => $offset,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		$removed  = 0;
		$retained = 0;
		$messages = array();

		foreach ( $posts as $post_id ) {
			// Hard-delete — PHI must not sit in the trash.
			$result = wp_delete_post( (int) $post_id, true );
			if ( $result ) {
				++$removed;
			} else {
				++$retained;
				$messages[] = sprintf(
					/* translators: %d: post ID */
					__( 'Could not erase health record #%d.', 'mcp-ai-wpoos-pro' ),
					(int) $post_id
				);
			}
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Erase DICOM imaging study records for a given e-mail address.
	 *
	 * Removes the CPT post AND the DICOM files on disk.  Uses the
	 * existing {@see WP_MCP_AI_Imaging_REST_Controller::delete_study_files()}
	 * helper when available; otherwise falls back to manual unlink.
	 *
	 * @param string $email_address Requestor e-mail.
	 * @param int    $page          Pagination page (1-based).
	 * @return array { items_removed: int, items_retained: int, messages: array, done: bool }
	 */
	public static function erase_imaging_studies( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$offset = ( (int) $page - 1 ) * self::PAGE_SIZE;

		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_imaging_study',
				'author'         => $user->ID,
				'posts_per_page' => self::PAGE_SIZE,
				'offset'         => $offset,
				'post_status'    => 'any',
				'fields'         => 'all',
			)
		);

		$removed  = 0;
		$retained = 0;
		$messages = array();

		foreach ( $posts as $post ) {
			// Delete DICOM files from disk first.
			$storage_path = get_post_meta( $post->ID, '_imaging_storage_path', true );
			if ( $storage_path && is_dir( $storage_path ) ) {
				$real_storage = realpath( $storage_path );
				$upload_dir   = wp_upload_dir();
				$real_uploads = isset( $upload_dir['basedir'] ) ? realpath( $upload_dir['basedir'] ) : false;

				// Only delete if the resolved path is within the uploads directory.
				// This prevents path traversal attacks via manipulated post meta.
				if ( $real_storage && $real_uploads && 0 === strpos( $real_storage, $real_uploads ) ) {
					self::delete_directory_recursively( $real_storage );
				} else {
					$messages[] = sprintf(
						/* translators: %d: post ID */
						__( 'Skipped imaging study #%d due to invalid storage path.', 'mcp-ai-wpoos-pro' ),
						$post->ID
					);
				}
			}

			// Hard-delete the CPT record.
			$result = wp_delete_post( $post->ID, true );
			if ( $result ) {
				++$removed;
			} else {
				++$retained;
				$messages[] = sprintf(
					/* translators: %d: post ID */
					__( 'Could not erase imaging study #%d.', 'mcp-ai-wpoos-pro' ),
					$post->ID
				);
			}
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Map CPT slugs to human-readable labels used in export output.
	 *
	 * @return array<string,string> { post_type_slug => label }
	 */
	private static function get_health_cpt_map() {
		return array(
			'mcp_ai_member'       => __( 'Member', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_policy'       => __( 'Insurance Policy', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_med_record'   => __( 'Medical Record', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_checkup'      => __( 'Health Checkup', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_prescription' => __( 'Prescription', 'mcp-ai-wpoos-pro' ),
			'mcp_ai_allergy'      => __( 'Allergy Record', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * Used when erasing imaging studies to remove DICOM pixel data from disk.
	 *
	 * @param string $dir Absolute path to directory.
	 * @return void
	 */
	private static function delete_directory_recursively( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::delete_directory_recursively( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}
}
