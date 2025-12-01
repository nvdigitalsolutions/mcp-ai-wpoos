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
		return __( 'Get Profession Statistics', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves statistics about professions including counts by category, total professions, and category distribution.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
		// Get profession service.
		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Profession system not available.', 'wp-mcp-ai' ),
			);
		}

		$profession_service = wp_mcp_ai_get_profession_service();

		// Get all professions.
		$all_professions = $profession_service->get_all_professions();

		// Get category counts.
		$category_counts = $profession_service->get_category_counts();

		// Calculate category percentages.
		$total                = count( $all_professions );
		$category_percentages = array();

		foreach ( $category_counts as $category => $count ) {
			$category_percentages[ $category ] = $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
		}

		// Category labels.
		$category_labels = array(
			'advisory'   => __( 'Advisory/Consulting', 'wp-mcp-ai' ),
			'creative'   => __( 'Creative Services', 'wp-mcp-ai' ),
			'technical'  => __( 'Technical/STEM', 'wp-mcp-ai' ),
			'healthcare' => __( 'Healthcare/Medical', 'wp-mcp-ai' ),
			'legal'      => __( 'Legal', 'wp-mcp-ai' ),
			'financial'  => __( 'Financial', 'wp-mcp-ai' ),
			'other'      => __( 'Other', 'wp-mcp-ai' ),
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
