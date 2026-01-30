<?php
/**
 * Shortcut Recommendations Helper.
 *
 * Provides industry-standard prompt shortcut recommendations for tools
 * that don't explicitly define their own shortcuts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for providing shortcut recommendations based on tool patterns.
 */
class WP_MCP_AI_Shortcut_Recommendations {

	/**
	 * Get recommended shortcuts for a tool based on its slug and metadata.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
	 * @return array|null Array of shortcut recommendations or null if no recommendations.
	 */
	public static function get_recommendations_for_tool( $tool ) {
		if ( ! $tool || ! method_exists( $tool, 'get_slug' ) ) {
			return null;
		}

		$slug = $tool->get_slug();
		$name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug;

		// Check for pattern-based recommendations.
		$recommendations = self::get_pattern_based_recommendations( $slug, $name );

		if ( ! empty( $recommendations ) ) {
			return $recommendations;
		}

		// Return null to indicate no recommendations (allows fallback shortcuts).
		return null;
	}

	/**
	 * Get recommendations based on tool slug patterns.
	 *
	 * @param string $slug Tool slug.
	 * @param string $name Tool name.
	 * @return array Array of recommendations.
	 */
	protected static function get_pattern_based_recommendations( $slug, $name ) {
		$recommendations = array();

		// Content & Publishing Tools.
		if ( preg_match( '/^(get|search|list).*posts?$/i', $slug ) ) {
			$recommendations[] = array(
				/* translators: %s: tool name in lowercase */
				'label'       => sprintf( __( 'Show recent %s', 'mcp-ai-wpoos' ), strtolower( $name ) ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to retrieve and display recent content', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Quick access to latest published content', 'mcp-ai-wpoos' ),
			);
		}

		// Search Tools.
		if ( preg_match( '/^search/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => sprintf( __( 'Search for content', 'mcp-ai-wpoos' ) ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to find specific content. Ask what to search for, then execute the search.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Find specific content or data', 'mcp-ai-wpoos' ),
			);
		}

		// Create/Add Tools.
		if ( preg_match( '/^(create|add|new)/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => sprintf( __( 'Create new item', 'mcp-ai-wpoos' ) ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to create a new item. Gather required information, then execute creation.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Add new content or data', 'mcp-ai-wpoos' ),
			);
		}

		// Update/Edit Tools.
		if ( preg_match( '/^(update|edit|modify|save)/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => sprintf( __( 'Update existing item', 'mcp-ai-wpoos' ) ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to modify existing content. Identify what to update, then apply changes.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Modify existing content or settings', 'mcp-ai-wpoos' ),
			);
		}

		// Delete/Remove Tools.
		if ( preg_match( '/^(delete|remove)/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => sprintf( __( 'Delete item', 'mcp-ai-wpoos' ) ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to remove content. Confirm what to delete, then execute removal.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Remove content or data', 'mcp-ai-wpoos' ),
			);
		}

		// Image Generation Tools.
		if ( preg_match( '/generate.*image/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Generate image', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to create an image. Gather description details, style preferences, and size requirements, then generate the image.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Create AI-generated images', 'mcp-ai-wpoos' ),
			);
		}

		// Image Editing Tools.
		if ( preg_match( '/(edit|modify).*image|image.*(edit|modify)/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Edit image', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to modify an existing image. Specify what changes to make, then apply edits.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Modify or enhance images', 'mcp-ai-wpoos' ),
			);
		}

		// Video Tools.
		if ( preg_match( '/video/i', $slug ) ) {
			if ( preg_match( '/generate|create/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'Generate video', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to create a video. Provide scene descriptions, duration, and style preferences.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Create AI-generated videos', 'mcp-ai-wpoos' ),
				);
			} elseif ( preg_match( '/analyze|check|status/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'Analyze video', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to analyze or check video status.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Get video information or processing status', 'mcp-ai-wpoos' ),
				);
			}
		}

		// Email/Communication Tools.
		if ( preg_match( '/send.*(email|message)/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Send message', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to send a communication. Gather recipient, subject, and message content.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Send emails or messages', 'mcp-ai-wpoos' ),
			);
		}

		// WooCommerce/Product Tools.
		if ( preg_match( '/woo|product/i', $slug ) ) {
			if ( preg_match( '/order/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'View orders', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to retrieve order information. Specify date range or other filters.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Access e-commerce order data', 'mcp-ai-wpoos' ),
				);
			} elseif ( preg_match( '/product/i', $slug ) && preg_match( '/create|add/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'Add product', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to create a new product. Gather name, description, price, and other details.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Create new product listings', 'mcp-ai-wpoos' ),
				);
			} elseif ( preg_match( '/product/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'List products', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to retrieve product information.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Browse product catalog', 'mcp-ai-wpoos' ),
				);
			}
		}

		// Analytics/Reporting Tools.
		if ( preg_match( '/analytics|report|stats|insights/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'View analytics', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to retrieve analytics data. Specify date range and metrics.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Access performance metrics', 'mcp-ai-wpoos' ),
			);
		}

		// Chart/Visualization Tools.
		if ( preg_match( '/chart|visualiz/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Create chart', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to create a data visualization. Provide data and specify chart type.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Generate visual representations of data', 'mcp-ai-wpoos' ),
			);
		}

		// Cache/Performance Tools.
		if ( preg_match( '/cache|purge|clear/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Clear cache', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to clear cached data and improve performance.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Purge cache for fresh content', 'mcp-ai-wpoos' ),
			);
		}

		// SEO Tools.
		if ( preg_match( '/seo|rankmath/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'SEO analysis', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to analyze SEO performance and get optimization recommendations.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Check search engine optimization', 'mcp-ai-wpoos' ),
			);
		}

		// Site Health/Status Tools.
		if ( preg_match( '/health|status|check/i', $slug ) && preg_match( '/site|system|environment/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Check system status', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to check system health and identify any issues.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Monitor site health and performance', 'mcp-ai-wpoos' ),
			);
		}

		// Cron/Scheduled Job Tools.
		if ( preg_match( '/cron|schedule/i', $slug ) ) {
			if ( preg_match( '/create|add/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'Schedule task', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to create a scheduled task. Specify timing and action.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Set up automated tasks', 'mcp-ai-wpoos' ),
				);
			} elseif ( preg_match( '/list|get/i', $slug ) ) {
				$recommendations[] = array(
					'label'       => __( 'View scheduled tasks', 'mcp-ai-wpoos' ),
					/* translators: %s: tool slug */
					'payload'     => sprintf( __( 'Use the `%s` tool to list all scheduled tasks and their status.', 'mcp-ai-wpoos' ), $slug ),
					'description' => __( 'Check scheduled jobs', 'mcp-ai-wpoos' ),
				);
			}
		}

		// AI/ML Embedding Tools.
		if ( preg_match( '/embed|embedding/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Generate embeddings', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to create vector embeddings for semantic analysis.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Create AI embeddings for content', 'mcp-ai-wpoos' ),
			);
		}

		// Moderation Tools.
		if ( preg_match( '/moderat|filter|check.*content/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Moderate content', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to check content for policy violations or inappropriate material.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Content moderation and filtering', 'mcp-ai-wpoos' ),
			);
		}

		// Location/Places Tools.
		if ( preg_match( '/place|location|map|geo/i', $slug ) ) {
			$recommendations[] = array(
				'label'       => __( 'Find location', 'mcp-ai-wpoos' ),
				/* translators: %s: tool slug */
				'payload'     => sprintf( __( 'Use the `%s` tool to search for locations or places. Specify type and area.', 'mcp-ai-wpoos' ), $slug ),
				'description' => __( 'Search for places and locations', 'mcp-ai-wpoos' ),
			);
		}

		return $recommendations;
	}
}
