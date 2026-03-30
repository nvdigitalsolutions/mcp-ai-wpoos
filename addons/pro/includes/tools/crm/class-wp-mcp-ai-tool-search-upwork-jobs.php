<?php
/**
 * Tool for searching Upwork job postings via the Upwork GraphQL API.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches the Upwork marketplace for job postings matching specified criteria.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Search_Upwork_Jobs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * GraphQL query used to search job postings.
	 *
	 * @var string
	 */
	const SEARCH_QUERY = '
		query SearchUpworkJobs($marketPlaceJobFilter: MarketplaceJobPostingsSearchFilter, $paging: Paging) {
			marketplaceJobPostingsSearch(
				marketPlaceJobFilter: $marketPlaceJobFilter,
				paging: $paging
			) {
				totalCount
				edges {
					node {
						id
						title
						description
						createdDateTime
						publishedDateTime
						contractorTier
						jobType
						engagement
						duration
						budget { amount currency }
						hourlyBudget { min max currency }
						skills { prettyName }
						client {
							totalFeedback
							totalHires
							totalJobsPosted
							totalSpent { amount currency }
							paymentVerificationStatus
							location { country }
						}
						category { name }
						subcategory { name }
						totalApplicants
						tierText
					}
					cursor
				}
				pageInfo { endCursor hasNextPage }
			}
		}
	';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_upwork_jobs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Upwork Jobs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search Upwork marketplace job postings with filters for keyword, category, skills, budget, job type, experience level, duration, and more. Returns a paginated list of matching jobs.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites Upwork connection ID.', 'mcp-ai-wpoos-pro' ),
				),
				'query'               => array(
					'type'        => 'string',
					'description' => __( 'Keyword search query.', 'mcp-ai-wpoos-pro' ),
				),
				'category2'           => array(
					'type'        => 'string',
					'description' => __( 'Job category name (e.g. "Web, Mobile & Software Dev").', 'mcp-ai-wpoos-pro' ),
				),
				'skills'              => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Required skill names.', 'mcp-ai-wpoos-pro' ),
				),
				'budget_min'          => array(
					'type'        => 'number',
					'description' => __( 'Minimum budget amount.', 'mcp-ai-wpoos-pro' ),
				),
				'budget_max'          => array(
					'type'        => 'number',
					'description' => __( 'Maximum budget amount.', 'mcp-ai-wpoos-pro' ),
				),
				'job_type'            => array(
					'type'        => 'string',
					'enum'        => array( 'hourly', 'fixed' ),
					'description' => __( 'Job type filter.', 'mcp-ai-wpoos-pro' ),
				),
				'experience_level'    => array(
					'type'        => 'string',
					'enum'        => array( 'entry', 'intermediate', 'expert' ),
					'description' => __( 'Required experience level.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_weeks_min'  => array(
					'type'        => 'number',
					'description' => __( 'Minimum engagement duration in weeks.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_weeks_max'  => array(
					'type'        => 'number',
					'description' => __( 'Maximum engagement duration in weeks.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of results to return (1-50, default 10).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'cursor'              => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor from a previous search response.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id' ),
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
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'external-api',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to search Upwork jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['connection_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_connection_id',
				__( 'connection_id is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );

		// Validate connection.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( ! $connection ) {
				return new WP_Error(
					'wp_mcp_ai_connection_not_found',
					__( 'Upwork connection not found.', 'mcp-ai-wpoos-pro' )
				);
			}
			if ( 'upwork' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_connection_type',
					__( 'The specified connection is not an Upwork connection.', 'mcp-ai-wpoos-pro' )
				);
			}
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_connection_disabled',
					__( 'The Upwork connection is disabled.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Build GraphQL variables.
		$filter = array();

		if ( ! empty( $arguments['query'] ) ) {
			$filter['searchExpression'] = sanitize_text_field( $arguments['query'] );
		}

		if ( ! empty( $arguments['category2'] ) ) {
			$filter['category2'] = sanitize_text_field( $arguments['category2'] );
		}

		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			$filter['skills'] = array_map( 'sanitize_text_field', $arguments['skills'] );
		}

		if ( isset( $arguments['budget_min'] ) || isset( $arguments['budget_max'] ) ) {
			$budget = array();
			if ( isset( $arguments['budget_min'] ) ) {
				$budget['min'] = (float) $arguments['budget_min'];
			}
			if ( isset( $arguments['budget_max'] ) ) {
				$budget['max'] = (float) $arguments['budget_max'];
			}
			$filter['budget'] = $budget;
		}

		if ( ! empty( $arguments['job_type'] ) ) {
			// Use the jobType filter for hourly/fixed — distinct from contractorTier (experience level).
			$filter['jobType'] = strtoupper( sanitize_text_field( $arguments['job_type'] ) );
		}

		$exp_map = array(
			'entry'        => 1,
			'intermediate' => 2,
			'expert'       => 3,
		);
		if ( ! empty( $arguments['experience_level'] ) && isset( $exp_map[ $arguments['experience_level'] ] ) ) {
			// contractorTier maps to the experience level tier (1=Entry, 2=Intermediate, 3=Expert).
			$filter['contractorTier'] = $exp_map[ $arguments['experience_level'] ];
		}

		if ( isset( $arguments['duration_weeks_min'] ) || isset( $arguments['duration_weeks_max'] ) ) {
			$duration = array();
			if ( isset( $arguments['duration_weeks_min'] ) ) {
				$duration['min'] = (int) $arguments['duration_weeks_min'];
			}
			if ( isset( $arguments['duration_weeks_max'] ) ) {
				$duration['max'] = (int) $arguments['duration_weeks_max'];
			}
			$filter['durationV3'] = $duration;
		}

		$limit  = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10;
		$paging = array( 'first' => $limit );
		if ( ! empty( $arguments['cursor'] ) ) {
			$paging['after'] = sanitize_text_field( $arguments['cursor'] );
		}

		$variables = array(
			'paging' => $paging,
		);

		if ( ! empty( $filter ) ) {
			$variables['marketPlaceJobFilter'] = $filter;
		}

		// Execute the search.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
		$client = new WP_MCP_AI_Upwork_Client( $connection_id );
		$result = $client->graphql( self::SEARCH_QUERY, $variables );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$search_data = isset( $result['data']['marketplaceJobPostingsSearch'] )
			? $result['data']['marketplaceJobPostingsSearch']
			: array();

		$jobs       = array();
		$page_info  = isset( $search_data['pageInfo'] ) ? $search_data['pageInfo'] : array();
		$total      = isset( $search_data['totalCount'] ) ? (int) $search_data['totalCount'] : 0;
		$edges      = isset( $search_data['edges'] ) ? $search_data['edges'] : array();

		foreach ( $edges as $edge ) {
			$node = isset( $edge['node'] ) ? $edge['node'] : array();
			if ( empty( $node ) ) {
				continue;
			}

			$jobs[] = array(
				'id'                => isset( $node['id'] ) ? $node['id'] : '',
				'title'             => isset( $node['title'] ) ? $node['title'] : '',
				'description'       => isset( $node['description'] ) ? wp_trim_words( $node['description'], 60 ) : '',
				'created'           => isset( $node['createdDateTime'] ) ? $node['createdDateTime'] : '',
				'published'         => isset( $node['publishedDateTime'] ) ? $node['publishedDateTime'] : '',
				'job_type'          => isset( $node['jobType'] ) ? $node['jobType'] : '',
				'engagement'        => isset( $node['engagement'] ) ? $node['engagement'] : '',
				'duration'          => isset( $node['duration'] ) ? $node['duration'] : '',
				'budget'            => isset( $node['budget'] ) ? $node['budget'] : null,
				'hourly_budget'     => isset( $node['hourlyBudget'] ) ? $node['hourlyBudget'] : null,
				'skills'            => isset( $node['skills'] ) ? wp_list_pluck( $node['skills'], 'prettyName' ) : array(),
				'category'          => isset( $node['category']['name'] ) ? $node['category']['name'] : '',
				'subcategory'       => isset( $node['subcategory']['name'] ) ? $node['subcategory']['name'] : '',
				'applicants'        => isset( $node['totalApplicants'] ) ? (int) $node['totalApplicants'] : 0,
				'tier'              => isset( $node['tierText'] ) ? $node['tierText'] : '',
				'client'            => array(
					'feedback'             => isset( $node['client']['totalFeedback'] ) ? (float) $node['client']['totalFeedback'] : null,
					'total_hires'          => isset( $node['client']['totalHires'] ) ? (int) $node['client']['totalHires'] : null,
					'jobs_posted'          => isset( $node['client']['totalJobsPosted'] ) ? (int) $node['client']['totalJobsPosted'] : null,
					'total_spent'          => isset( $node['client']['totalSpent'] ) ? $node['client']['totalSpent'] : null,
					'payment_verified'     => isset( $node['client']['paymentVerificationStatus'] ) ? $node['client']['paymentVerificationStatus'] : null,
					'country'              => isset( $node['client']['location']['country'] ) ? $node['client']['location']['country'] : '',
				),
				'cursor'            => isset( $edge['cursor'] ) ? $edge['cursor'] : '',
			);
		}

		return array(
			'success'       => true,
			'total_count'   => $total,
			'count'         => count( $jobs ),
			'jobs'          => $jobs,
			'page_info'     => $page_info,
			'has_next_page' => isset( $page_info['hasNextPage'] ) ? (bool) $page_info['hasNextPage'] : false,
			'end_cursor'    => isset( $page_info['endCursor'] ) ? $page_info['endCursor'] : null,
		);
	}
}
