<?php
/**
 * Token Management Service for WP oOS.
 *
 * Provides centralized token usage management and statistics:
 * - User token usage tracking and totals
 * - Tool-specific token limits and multipliers
 * - Site-wide token statistics
 * - Provider and model usage aggregation
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Management Service class.
 */
class WP_MCP_AI_Token_Management_Service {

	/**
	 * Calculate total usage from usage array.
	 *
	 * @param array $usage Usage data.
	 * @return array Totals.
	 */
	public static function calculate_usage_totals( $usage ) {
		$totals = array(
			'requests'          => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'cached_tokens'     => 0,
		);

		if ( ! is_array( $usage ) ) {
			return $totals;
		}

		foreach ( $usage as $provider => $models ) {
			if ( ! is_array( $models ) ) {
				continue;
			}

			foreach ( $models as $model => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}

				$totals['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
				$totals['prompt_tokens']     += isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
				$totals['completion_tokens'] += isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
				$totals['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
				$totals['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;
			}
		}

		return $totals;
	}

	/**
	 * Get all available tools.
	 *
	 * @return array Tool slug => Tool name pairs.
	 */
	public static function get_all_available_tools() {
		$tools = array();

		// Get all registered tools from the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( ! $registry ) {
			// Fallback to hardcoded tools if registry is not available.
			$tools = array(
				'run_crawl4ai_job' => __( 'Crawl4AI Web Scraper', 'wp-mcp-ai' ),
				'general_tools'    => __( 'General Tools (Default)', 'wp-mcp-ai' ),
			);
		} else {
			// Ensure registry is initialized.
			$registry->init();

			$registered_tools = $registry->get_tools();

			// Build array of tool slug => name pairs.
			foreach ( $registered_tools as $tool ) {
				if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
					$slug = $tool->get_slug();
					$name = $tool->get_name();

					if ( ! empty( $slug ) && ! empty( $name ) ) {
						$tools[ $slug ] = $name;
					}
				}
			}

			// Sort tools by name for better UI experience.
			asort( $tools );
		}

		/**
		 * Filter available tools for token limit configuration.
		 *
		 * @param array $tools Tool slug => Tool name pairs.
		 */
		return apply_filters( 'wp_mcp_ai_token_manager_tools', $tools );
	}

	/**
	 * Get site-wide statistics.
	 *
	 * @return array Site statistics.
	 */
	public static function get_site_wide_statistics() {
		global $wpdb;

		$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;

		// Get all user IDs with usage data.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		$stats = array(
			'total_users'    => count( $user_ids ),
			'total_requests' => 0,
			'total_tokens'   => 0,
			'by_provider'    => array(),
			'top_models'     => array(),
			'tools_used'     => 0,
		);

		$all_models = array();

		foreach ( $user_ids as $user_id ) {
			$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

			foreach ( $usage as $provider => $models ) {
				if ( ! isset( $stats['by_provider'][ $provider ] ) ) {
					$stats['by_provider'][ $provider ] = array(
						'requests'          => 0,
						'prompt_tokens'     => 0,
						'completion_tokens' => 0,
						'total_tokens'      => 0,
						'cached_tokens'     => 0,
					);
				}

				foreach ( $models as $model => $data ) {
					$stats['total_requests'] += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['total_tokens']   += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;

					$stats['by_provider'][ $provider ]['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['by_provider'][ $provider ]['prompt_tokens']     += isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
					$stats['by_provider'][ $provider ]['completion_tokens'] += isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
					$stats['by_provider'][ $provider ]['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$stats['by_provider'][ $provider ]['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;

					$model_key = $provider . '|' . $model;
					if ( ! isset( $all_models[ $model_key ] ) ) {
						$all_models[ $model_key ] = array(
							'provider'     => $provider,
							'model'        => $model,
							'requests'     => 0,
							'total_tokens' => 0,
						);
					}

					$all_models[ $model_key ]['requests']     += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$all_models[ $model_key ]['total_tokens'] += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
				}
			}
		}

		// Sort models by total tokens and get top 10.
		uasort(
			$all_models,
			function ( $a, $b ) {
				return $b['total_tokens'] - $a['total_tokens'];
			}
		);

		$stats['top_models'] = array_slice( $all_models, 0, 10 );

		// Count tools used.
		$tool_meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
		$tool_users    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$tool_meta_key
			)
		);

		$tools_set = array();
		foreach ( $tool_users as $user_id ) {
			$tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
			foreach ( array_keys( $tool_usage ) as $tool_slug ) {
				$tools_set[ $tool_slug ] = true;
			}
		}

		$stats['tools_used'] = count( $tools_set );

		return $stats;
	}

	/**
	 * Get formatted provider display name.
	 *
	 * @param string $provider Provider key.
	 * @return string Formatted provider name.
	 */
	public static function get_provider_display_name( $provider ) {
		$provider = sanitize_key( $provider );

		$provider_labels = array(
			'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
			'anthropic' => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
			'gemini'    => __( 'Gemini', 'wp-mcp-ai' ),
			'ollama'    => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
			'lm_studio' => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
		);

		if ( isset( $provider_labels[ $provider ] ) ) {
			return $provider_labels[ $provider ];
		}

		// Fallback: capitalize and replace underscores/hyphens with spaces.
		return ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );
	}

	/**
	 * Get tool multiplier for token limits.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return float Multiplier value.
	 */
	public static function get_tool_multiplier( $tool_slug ) {
		// Get multipliers from the WP_MCP_AI_Tool_Token_Limits class.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

		if ( isset( $multipliers[ $tool_slug ] ) ) {
			return (float) $multipliers[ $tool_slug ];
		}

		return 1.0; // Default multiplier.
	}
}
