<?php
/**
 * Tool for searching and researching medical records (procedures, diagnoses, etc.).
 *
 * Allows AI assistants to search medical records with advanced filtering.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/traits/trait-wp-mcp-ai-relevance-search.php';

/**
 * Search and research medical records.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_Tool_Search_Medical_Records implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_CRM_Relevance_Search;

	/**
	 * Allowed orderby options.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	const ORDERBY_OPTIONS = array( 'relevance', 'date', 'title', 'provider' );

	/**
	 * Default field weights for medical record TF-IDF scoring.
	 *
	 * Higher weight = field contributes more to relevance score.
	 *
	 * @since 2.4.0
	 * @var array<string,float>
	 */
	protected $default_field_weights = array(
		'title'     => 3.0,
		'diagnosis' => 3.0,
		'provider'  => 2.0,
		'facility'  => 1.5,
		'summary'   => 1.0,
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_medical_records';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Medical Records', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and research medical records including procedures, diagnoses, lab results, treatments, vaccinations, imaging, and hospitalizations. Filter by member, record type, provider, date ranges, and keywords. Supports configurable sort order (date, title, provider) and TF-IDF relevance ranking when searching by keyword. Essential for medical history review and care coordination.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'record_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by record type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization', '' ),
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by records on or after this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by records on or before this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'search'      => array(
					'type'        => 'string',
					'description' => __( 'Search records by diagnosis, procedure name, or keywords (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'orderby'     => array(
					'type'        => 'string',
					'description' => __( 'Sort records by field. Use "relevance" for TF-IDF ranked results when a search keyword is provided (optional, default: date)', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ORDERBY_OPTIONS,
					'default'     => 'date',
				),
				'order'       => array(
					'type'        => 'string',
					'description' => __( 'Sort direction (optional, default: DESC)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of records to return per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_med_record',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'medical_coder' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$record_type = isset( $arguments['record_type'] ) ? sanitize_key( $arguments['record_type'] ) : '';
		$provider    = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$start_date  = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date    = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$search      = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page    = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page        = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$raw_orderby = isset( $arguments['orderby'] ) ? (string) $arguments['orderby'] : 'date';
		$raw_order   = isset( $arguments['order'] ) ? (string) $arguments['order'] : 'DESC';

		$orderby = $this->sanitise_orderby( $raw_orderby, 'date', self::ORDERBY_OPTIONS );
		$order   = strtoupper( $raw_order ) === 'ASC' ? 'ASC' : 'DESC';

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Determine if TF-IDF relevance ranking is active.
		$use_relevance = ( 'relevance' === $orderby && ! empty( $search ) );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'posts_per_page' => $use_relevance ? 500 : $per_page,
		);

		if ( ! $use_relevance ) {
			$query_args['paged'] = $page;
		}

		// Set orderby and order.
		if ( $use_relevance ) {
			// Relevance mode: fetch a broad date-sorted candidate set for re-ranking.
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		} else {
			switch ( $orderby ) {
				case 'title':
					$query_args['orderby'] = 'title';
					break;
				case 'provider':
					$query_args['meta_key'] = '_record_provider';
					$query_args['orderby']  = 'meta_value';
					break;
				case 'date':
				default:
					$query_args['orderby'] = 'date';
					break;
			}
			$query_args['order'] = $order;
		}

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Build meta query.
		$meta_query = array( 'relation' => 'AND' );

		// Filter by member.
		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_record_member_id',
				'value' => $member_id,
			);
		}

		// Filter by provider.
		if ( $provider ) {
			$meta_query[] = array(
				'key'     => '_record_provider',
				'value'   => $provider,
				'compare' => 'LIKE',
			);
		}

		// Filter by date range.
		if ( $start_date && $end_date ) {
			$meta_query[] = array(
				'key'     => '_record_date',
				'value'   => array( $start_date, $end_date ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		} elseif ( $start_date ) {
			$meta_query[] = array(
				'key'     => '_record_date',
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		} elseif ( $end_date ) {
			$meta_query[] = array(
				'key'     => '_record_date',
				'value'   => $end_date,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Add record type filter if provided.
		if ( $record_type ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_record_type',
					'field'    => 'slug',
					'terms'    => $record_type,
				),
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Build response.
		$records = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$record_id = get_the_ID();

				// Get record type.
				$types            = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'names' ) );
				$record_type_name = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

				// Get member info.
				$member_id   = get_post_meta( $record_id, '_record_member_id', true );
				$member_name = '';
				if ( $member_id ) {
					$member      = get_post( $member_id );
					$member_name = $member ? $member->post_title : '';
				}

				$records[] = array(
					'id'          => $record_id,
					'title'       => get_the_title(),
					'type'        => $record_type_name,
					'member_id'   => $member_id,
					'member_name' => $member_name,
					'date'        => get_post_meta( $record_id, '_record_date', true ),
					'provider'    => get_post_meta( $record_id, '_record_provider', true ),
					'facility'    => get_post_meta( $record_id, '_record_facility', true ),
					'diagnosis'   => get_post_meta( $record_id, '_record_diagnosis', true ),
					'summary'     => wp_trim_words( get_the_content(), 30 ),
					'created'     => get_the_date( 'Y-m-d H:i:s' ),
				);
			}
			wp_reset_postdata();
		}

		// Apply TF-IDF relevance ranking when active.
		if ( $use_relevance ) {
			$total_found = count( $records );
			$records     = $this->rank_by_relevance( $records, $search );

			// Paginate the relevance-ranked results.
			$total_pages = $per_page > 0 ? (int) ceil( $total_found / $per_page ) : 1;
			$offset      = ( $page - 1 ) * $per_page;
			$records     = array_slice( $records, $offset, $per_page );
		} else {
			$total_found = $query->found_posts;
			$total_pages = $query->max_num_pages;
		}

		return array(
			'success'    => true,
			'records'    => $records,
			'pagination' => array(
				'total'        => $total_found,
				'total_pages'  => $total_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}

	/**
	 * Extract searchable text for a medical record by post ID.
	 *
	 * Provides medical-record-specific fields for TF-IDF scoring: title,
	 * diagnosis, provider, facility, and full content summary.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $post_id        Medical record post ID.
	 * @param array $field_weights  Map of field => weight (optional, uses defaults).
	 * @return array<string,string>
	 */
	protected function extract_searchable_text( $post_id, $field_weights = array() ) {
		if ( empty( $field_weights ) ) {
			$field_weights = $this->default_field_weights;
		}

		$text = array();

		if ( isset( $field_weights['title'] ) ) {
			$text['title'] = strtolower( get_the_title( $post_id ) );
		}
		if ( isset( $field_weights['diagnosis'] ) ) {
			$text['diagnosis'] = strtolower( (string) get_post_meta( $post_id, '_record_diagnosis', true ) );
		}
		if ( isset( $field_weights['provider'] ) ) {
			$text['provider'] = strtolower( (string) get_post_meta( $post_id, '_record_provider', true ) );
		}
		if ( isset( $field_weights['facility'] ) ) {
			$text['facility'] = strtolower( (string) get_post_meta( $post_id, '_record_facility', true ) );
		}
		if ( isset( $field_weights['summary'] ) ) {
			$text['summary'] = strtolower( wp_strip_all_tags( get_the_content( null, false, $post_id ) ) );
		}

		return $text;
	}
}
