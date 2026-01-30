<?php
/**
 * Tool for getting profession statistics and analytics.
 *
 * Provides insights about professions and their usage.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets profession statistics.
 */
class WP_MCP_AI_Tool_Profession_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_profession_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Profession Statistics', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves statistics about professions including counts by category, total professions, and category distribution.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user permissions - stats viewing requires read capability.
		$user_id             = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$required_capability = apply_filters( 'wp_mcp_ai_profession_stats_capability', 'read', $context, $arguments );

		if ( $required_capability && $user_id && ! user_can( $user_id, $required_capability ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to view profession statistics.', 'mcp-ai-wpoos' ),
			);
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
			);
		}

		// Get profession service.
		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Profession system not available. The professions module may not be loaded.', 'mcp-ai-wpoos' ),
				'code'    => 'profession_system_unavailable',
			);
		}

		try {
			$profession_service = wp_mcp_ai_get_profession_service();

			if ( ! $profession_service ) {
				return array(
					'success' => false,
					'message' => __( 'Profession service could not be initialized.', 'mcp-ai-wpoos' ),
					'code'    => 'profession_service_initialization_failed',
				);
			}

			// Get all professions.
			$all_professions = $profession_service->get_all_professions();

			// Get category counts.
			$category_counts = $profession_service->get_category_counts();
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'profession_stats_error',
				'Error retrieving profession statistics',
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error retrieving profession statistics: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				'code'    => 'profession_stats_exception',
			);
		} catch ( Error $e ) {
			WP_MCP_AI_Logger::log_error(
				'profession_stats_fatal_error',
				'Fatal error retrieving profession statistics',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Fatal error retrieving profession statistics: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				'code'    => 'profession_stats_fatal_error',
			);
		}

		// Calculate category percentages.
		$total                = count( $all_professions );
		$category_percentages = array();

		foreach ( $category_counts as $category => $count ) {
			$category_percentages[ $category ] = $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
		}

		// Category labels.
		$category_labels = array(
			'advisory'   => __( 'Advisory/Consulting', 'mcp-ai-wpoos' ),
			'creative'   => __( 'Creative Services', 'mcp-ai-wpoos' ),
			'technical'  => __( 'Technical/STEM', 'mcp-ai-wpoos' ),
			'healthcare' => __( 'Healthcare/Medical', 'mcp-ai-wpoos' ),
			'legal'      => __( 'Legal', 'mcp-ai-wpoos' ),
			'financial'  => __( 'Financial', 'mcp-ai-wpoos' ),
			'other'      => __( 'Other', 'mcp-ai-wpoos' ),
		);

		return array(
			'success'        => true,
			'total'          => $total,
			'by_category'    => array(
				'counts'      => $category_counts,
				'percentages' => $category_percentages,
				'labels'      => $category_labels,
			),
			'top_categories' => $this->get_top_categories( $category_counts, $category_labels ),
		);
	}

	/**
	 * Get top categories by count.
	 *
	 * @param array $counts Category counts.
	 * @param array $labels Category labels.
	 * @return array Top categories.
	 */
	protected function get_top_categories( $counts, $labels ) {
		arsort( $counts );
		$top = array();
		$i   = 0;

		foreach ( $counts as $category => $count ) {
			if ( $i >= 5 ) {
				break;
			}

			$top[] = array(
				'category' => $category,
				'label'    => isset( $labels[ $category ] ) ? $labels[ $category ] : $category,
				'count'    => $count,
			);

			++$i;
		}

		return $top;
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'peer_to_peer' ),

			'profession_tags'       => array( 'business_analyst', 'data_scientist' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',         // Reads profession data.
			'local-only',   // No external API calls.
			'safe',         // Read-only operation.
		);
	}
}
