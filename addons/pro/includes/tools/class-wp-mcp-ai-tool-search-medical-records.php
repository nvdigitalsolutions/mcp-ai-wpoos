<?php
/**
 * Tool for searching and researching medical records (procedures, diagnoses, etc.).
 *
 * Allows AI assistants to search medical records with advanced filtering.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search and research medical records.
 */
class WP_MCP_AI_Tool_Search_Medical_Records implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Search and research medical records including procedures, diagnoses, lab results, treatments, vaccinations, imaging, and hospitalizations. Filter by member, record type, provider, date ranges, and keywords. Essential for medical history review and care coordination.', 'mcp-ai-wpoos-pro' );
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

		// Validate per_page.
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

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

		return array(
			'success'    => true,
			'records'    => $records,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
