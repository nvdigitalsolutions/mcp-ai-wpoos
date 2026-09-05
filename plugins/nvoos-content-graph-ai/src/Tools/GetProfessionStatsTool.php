<?php
/**
 * Get Profession Statistics tool (D8 Cluster 2c-5 port of the base
 * plugin's WP_MCP_AI_Tool_Profession_Stats — byte-identical slug,
 * schema, error codes, envelope, and category math; per-mode
 * profession-service seam).
 *
 * The base tool consumes the plugin's profession service via
 * wp_mcp_ai_get_profession_service(). Standalone installs have no
 * profession module, so the port keeps the base's function_exists()
 * guard and returns the same documented degradation envelope
 * ("Profession system not available…") when the module is absent.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Gets profession statistics.
 */
class GetProfessionStatsTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'get_profession_stats';
	}

	public function getName(): string {
		return __( 'Get Profession Statistics', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Retrieves statistics about professions including counts by category, total professions, and category distribution.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array(
			'read',        // Reads profession data.
			'local-only',  // No external API calls.
			'safe',        // Read-only operation.
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Check user permissions - stats viewing requires read capability.
		$user_id             = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$required_capability = apply_filters( 'wp_mcp_ai_profession_stats_capability', 'read', $context, $arguments );

		if ( $required_capability && $user_id && ! user_can( $user_id, $required_capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability resolved from the wp_mcp_ai_profession_stats_capability filter (base-identical contract).
			return new \WP_Error( 'wp_mcp_ai_error', __( 'You do not have permission to view profession statistics.', 'nvoos-content-graph-ai' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		// Get profession service.
		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Profession system not available. The professions module may not be loaded.', 'nvoos-content-graph-ai' ) );
		}

		try {
			$profession_service = wp_mcp_ai_get_profession_service();

			if ( ! $profession_service ) {
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Profession service could not be initialized.', 'nvoos-content-graph-ai' ) );
			}

			// Get all professions.
			$all_professions = $profession_service->get_all_professions();

			// Get category counts.
			$category_counts = $profession_service->get_category_counts();
		} catch ( \Exception $e ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'profession_stats_error',
					'Error retrieving profession statistics',
					array(
						'exception' => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
			}

			return new \WP_Error(
				'wp_mcp_ai_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Error retrieving profession statistics: %s', 'nvoos-content-graph-ai' ),
					$e->getMessage()
				)
			);
		} catch ( \Error $e ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'profession_stats_fatal_error',
					'Fatal error retrieving profession statistics',
					array(
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					)
				);
			}

			return new \WP_Error(
				'wp_mcp_ai_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Fatal error retrieving profession statistics: %s', 'nvoos-content-graph-ai' ),
					$e->getMessage()
				)
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
			'advisory'   => __( 'Advisory/Consulting', 'nvoos-content-graph-ai' ),
			'creative'   => __( 'Creative Services', 'nvoos-content-graph-ai' ),
			'technical'  => __( 'Technical/STEM', 'nvoos-content-graph-ai' ),
			'healthcare' => __( 'Healthcare/Medical', 'nvoos-content-graph-ai' ),
			'legal'      => __( 'Legal', 'nvoos-content-graph-ai' ),
			'financial'  => __( 'Financial', 'nvoos-content-graph-ai' ),
			'other'      => __( 'Other', 'nvoos-content-graph-ai' ),
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
	private function get_top_categories( $counts, $labels ) {
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
}
