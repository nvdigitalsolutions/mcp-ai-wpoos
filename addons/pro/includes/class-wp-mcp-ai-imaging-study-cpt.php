<?php
/**
 * Imaging Study Custom Post Type
 *
 * Registers the `mcp_ai_imaging_study` CPT that stores DICOM study
 * metadata (UIDs, modality, dates, etc.) as post meta.  Actual DICOM
 * files are stored **outside** the public uploads folder in a
 * protected directory served only through signed REST API URLs.
 *
 * Post meta schema (all prefixed with `_imaging_`):
 *  _imaging_study_instance_uid   — DICOM StudyInstanceUID
 *  _imaging_patient_id           — de-identified patient reference
 *  _imaging_modality             — CT / PT / MR / etc.
 *  _imaging_study_date           — YYYYMMDD
 *  _imaging_study_description    — optional free-text
 *  _imaging_series               — JSON array of series objects
 *  _imaging_storage_path         — absolute path to study directory
 *  _imaging_status               — 'pending' | 'active' | 'archived'
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Imaging Study CPT.
 */
class WP_MCP_AI_Imaging_Study_CPT {

	/**
	 * CPT slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_imaging_study';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Imaging Studies', 'post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'      => _x( 'Imaging Study', 'post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'          => __( 'Imaging Studies', 'mcp-ai-wpoos-pro' ),
			'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'       => __( 'Add New Imaging Study', 'mcp-ai-wpoos-pro' ),
			'edit_item'          => __( 'Edit Imaging Study', 'mcp-ai-wpoos-pro' ),
			'new_item'           => __( 'New Imaging Study', 'mcp-ai-wpoos-pro' ),
			'view_item'          => __( 'View Imaging Study', 'mcp-ai-wpoos-pro' ),
			'search_items'       => __( 'Search Imaging Studies', 'mcp-ai-wpoos-pro' ),
			'not_found'          => __( 'No imaging studies found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash' => __( 'No imaging studies found in trash.', 'mcp-ai-wpoos-pro' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'capabilities'        => array(
					'create_posts' => 'upload_medical_imaging',
					'edit_posts'   => 'view_medical_imaging',
					'delete_posts' => 'delete_medical_imaging',
				),
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Create a new imaging study record.
	 *
	 * @param array $data { // phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop -- Inline array specification.
	 *     @type string $study_instance_uid  DICOM StudyInstanceUID (required).
	 *     @type string $patient_id          De-identified patient reference.
	 *     @type string $modality            DICOM modality (CT, PT, MR, …).
	 *     @type string $study_date          YYYYMMDD formatted date.
	 *     @type string $study_description   Free-text description.
	 *     @type string $storage_path        Absolute filesystem path to study directory.
	 * }
	 * @return int|WP_Error  Post ID on success, WP_Error on failure.
	 */
	public static function create( array $data ) {
		$study_uid = isset( $data['study_instance_uid'] ) ? sanitize_text_field( $data['study_instance_uid'] ) : '';
		if ( '' === $study_uid ) {
			return new WP_Error( 'imaging_missing_uid', __( 'StudyInstanceUID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$title = isset( $data['study_description'] ) && '' !== $data['study_description']
			? sanitize_text_field( $data['study_description'] )
			: $study_uid;

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store meta.
		$meta_fields = array(
			'_imaging_study_instance_uid' => $study_uid,
			'_imaging_patient_id'         => isset( $data['patient_id'] ) ? sanitize_text_field( $data['patient_id'] ) : '',
			'_imaging_modality'           => isset( $data['modality'] ) ? sanitize_text_field( $data['modality'] ) : '',
			'_imaging_study_date'         => isset( $data['study_date'] ) ? sanitize_text_field( $data['study_date'] ) : '',
			'_imaging_study_description'  => $title,
			'_imaging_series'             => '[]',
			'_imaging_storage_path'       => isset( $data['storage_path'] ) ? sanitize_text_field( $data['storage_path'] ) : '',
			'_imaging_status'             => 'active',
		);

		foreach ( $meta_fields as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Retrieve a study by its DICOM StudyInstanceUID.
	 *
	 * @param string $study_uid DICOM StudyInstanceUID.
	 * @return WP_Post|null
	 */
	public static function get_by_uid( $study_uid ) {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'posts_per_page'   => 1,
				'post_status'      => 'publish',
				// Suppress external SQL-level filter hooks so third-party plugins
				// cannot narrow the meta query to a subset of posts.
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => '_imaging_study_instance_uid',
						'value' => sanitize_text_field( $study_uid ),
					),
				),
			)
		);
		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Add a series to an existing study.
	 *
	 * @param int   $post_id    Study post ID.
	 * @param array $series_data Series metadata (seriesInstanceUID, modality, instances).
	 */
	public static function add_series( $post_id, array $series_data ) {
		$series = json_decode( get_post_meta( $post_id, '_imaging_series', true ), true );
		if ( ! is_array( $series ) ) {
			$series = array();
		}

		// Check for duplicate series UID.
		$series_uid = isset( $series_data['series_instance_uid'] ) ? sanitize_text_field( $series_data['series_instance_uid'] ) : '';
		foreach ( $series as &$existing ) {
			if ( isset( $existing['series_instance_uid'] ) && $series_uid === $existing['series_instance_uid'] ) {
				// Merge new instances into existing series.
				if ( ! empty( $series_data['instances'] ) ) {
					$existing['instances'] = array_merge(
						isset( $existing['instances'] ) ? $existing['instances'] : array(),
						$series_data['instances']
					);
					// Deduplicate by sop_instance_uid.
					$seen    = array();
					$deduped = array();
					foreach ( $existing['instances'] as $inst ) {
						$iuid = isset( $inst['sop_instance_uid'] ) ? $inst['sop_instance_uid'] : '';
						if ( '' === $iuid || ! isset( $seen[ $iuid ] ) ) {
							$seen[ $iuid ] = true;
							$deduped[]     = $inst;
						}
					}
					$existing['instances'] = $deduped;
				}
				update_post_meta( $post_id, '_imaging_series', wp_json_encode( $series ) );
				return;
			}
		}
		unset( $existing );

		$series[] = $series_data;
		update_post_meta( $post_id, '_imaging_series', wp_json_encode( $series ) );
	}

	/**
	 * Get all studies (for admin listing).
	 *
	 * @param int   $per_page Results per page. Use -1 to return all studies.
	 * @param int   $page     Page number (1-based).
	 * @param array $filters  Optional filter criteria:
	 *                        - modality   (string) Filter by _imaging_modality meta value.
	 *                        - date_from  (string) YYYY-MM-DD lower bound on _imaging_study_date.
	 *                        - date_to    (string) YYYY-MM-DD upper bound on _imaging_study_date.
	 *                        - search     (string) LIKE match against _imaging_study_instance_uid.
	 * @return array {posts, total, pages}
	 */
	public static function get_all( $per_page = 20, $page = 1, $filters = array() ) {
		$per_page_val = (int) $per_page;
		$page_val     = max( 1, absint( $page ) );

		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => -1 === $per_page_val ? -1 : max( 1, $per_page_val ),
			'paged'            => $page_val,
			'orderby'          => 'date',
			'order'            => 'DESC',
			// Suppress external post-query filters so that third-party plugins
			// cannot inadvertently limit the result set (e.g., by modifying
			// posts_clauses or posts_where on a secondary WP_Query).
			'suppress_filters' => true,
			'no_found_rows'    => false,
		);

		$meta_query = array();

		if ( ! empty( $filters['modality'] ) ) {
			$meta_query[] = array(
				'key'   => '_imaging_modality',
				'value' => sanitize_text_field( $filters['modality'] ),
			);
		}

		if ( ! empty( $filters['date_from'] ) || ! empty( $filters['date_to'] ) ) {
			// _imaging_study_date is stored as YYYYMMDD; convert from YYYY-MM-DD input.
			$date_clause = array(
				'key'  => '_imaging_study_date',
				'type' => 'CHAR',
			);
			$date_from   = ! empty( $filters['date_from'] )
				? str_replace( '-', '', sanitize_text_field( $filters['date_from'] ) )
				: '';
			$date_to     = ! empty( $filters['date_to'] )
				? str_replace( '-', '', sanitize_text_field( $filters['date_to'] ) )
				: '';

			if ( $date_from && $date_to ) {
				$date_clause['compare'] = 'BETWEEN';
				$date_clause['value']   = array( $date_from, $date_to );
			} elseif ( $date_from ) {
				$date_clause['compare'] = '>=';
				$date_clause['value']   = $date_from;
			} else {
				$date_clause['compare'] = '<=';
				$date_clause['value']   = $date_to;
			}

			$meta_query[] = $date_clause;
		}

		if ( ! empty( $filters['search'] ) ) {
			$meta_query[] = array(
				'key'     => '_imaging_study_instance_uid',
				'value'   => sanitize_text_field( $filters['search'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $args );

		$total            = (int) $query->found_posts;
		$effective_per_pg = -1 === $per_page_val ? -1 : max( 1, $per_page_val );
		$pages            = ( -1 === $effective_per_pg )
			? 1
			: (int) ceil( $total / $effective_per_pg );

		return array(
			'posts' => $query->posts,
			'total' => $total,
			'pages' => max( 1, $pages ),
		);
	}
}
