<?php
/**
 * Token Usage Service for WP oOS.
 *
 * Provides centralized token usage management and statistics extracted from the admin layer:
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
 * Token Usage Service class.
 *
 * This service provides business logic for token usage calculations and statistics,
 * separated from the admin UI presentation layer.
 */
class WP_MCP_AI_Token_Usage_Service {

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
			'total_cost'        => 0.0,
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

				$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
				$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;

				$totals['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
				$totals['prompt_tokens']     += $prompt_tokens;
				$totals['completion_tokens'] += $completion_tokens;
				$totals['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
				$totals['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;

				// Calculate cost for this model.
				if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
					$totals['total_cost'] += WP_MCP_AI_Usage_Tracker::calculate_cost(
						$provider,
						$model,
						$prompt_tokens,
						$completion_tokens
					);
				}
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

		if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			return array(
				'total_users'    => 0,
				'total_requests' => 0,
				'total_tokens'   => 0,
				'total_cost'     => 0.0,
				'by_provider'    => array(),
				'top_models'     => array(),
				'top_tools'      => array(),
				'tools_used'     => 0,
			);
		}

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
			'total_cost'     => 0.0,
			'by_provider'    => array(),
			'top_models'     => array(),
			'top_tools'      => array(),
			'tools_used'     => 0,
		);

		$all_models = array();
		$all_tools  = array();

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
						'total_cost'        => 0.0,
					);
				}

				foreach ( $models as $model => $data ) {
					$prompt_tokens     = isset( $data['prompt_tokens'] ) ? (int) $data['prompt_tokens'] : 0;
					$completion_tokens = isset( $data['completion_tokens'] ) ? (int) $data['completion_tokens'] : 0;
					$cost              = WP_MCP_AI_Usage_Tracker::calculate_cost( $provider, $model, $prompt_tokens, $completion_tokens );

					$stats['total_requests'] += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['total_tokens']   += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$stats['total_cost']     += $cost;

					$stats['by_provider'][ $provider ]['requests']          += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$stats['by_provider'][ $provider ]['prompt_tokens']     += $prompt_tokens;
					$stats['by_provider'][ $provider ]['completion_tokens'] += $completion_tokens;
					$stats['by_provider'][ $provider ]['total_tokens']      += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$stats['by_provider'][ $provider ]['cached_tokens']     += isset( $data['cached_tokens'] ) ? (int) $data['cached_tokens'] : 0;
					$stats['by_provider'][ $provider ]['total_cost']        += $cost;

					$model_key = $provider . '|' . $model;
					if ( ! isset( $all_models[ $model_key ] ) ) {
						$all_models[ $model_key ] = array(
							'provider'     => $provider,
							'model'        => $model,
							'requests'     => 0,
							'total_tokens' => 0,
							'total_cost'   => 0.0,
						);
					}

					$all_models[ $model_key ]['requests']     += isset( $data['requests'] ) ? (int) $data['requests'] : 0;
					$all_models[ $model_key ]['total_tokens'] += isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0;
					$all_models[ $model_key ]['total_cost']   += $cost;
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

		// Collect tool usage statistics.
		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			$tool_meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
			$tool_users    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$tool_meta_key
				)
			);

			$tools_set       = array();
			$available_tools = self::get_all_available_tools();

			foreach ( $tool_users as $user_id ) {
				$tool_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

				foreach ( $tool_usage as $tool_slug => $tool_data ) {
					$tools_set[ $tool_slug ] = true;

					// Initialize tool stats if not exists.
					if ( ! isset( $all_tools[ $tool_slug ] ) ) {
						$all_tools[ $tool_slug ] = array(
							'tool_slug'    => $tool_slug,
							'tool_name'    => isset( $available_tools[ $tool_slug ] ) ? $available_tools[ $tool_slug ] : ucwords( str_replace( '_', ' ', $tool_slug ) ),
							'users'        => array(), // Track unique users.
							'requests'     => 0,
							'total_tokens' => 0,
						);
					}

					// Track unique user for this tool.
					if ( ! in_array( $user_id, $all_tools[ $tool_slug ]['users'], true ) ) {
						$all_tools[ $tool_slug ]['users'][] = $user_id;
					}

					// Add requests.
					if ( isset( $tool_data['requests'] ) ) {
						$all_tools[ $tool_slug ]['requests'] += (int) $tool_data['requests'];
					}

					// Add total tokens.
					if ( isset( $tool_data['total_tokens'] ) ) {
						$all_tools[ $tool_slug ]['total_tokens'] += (int) $tool_data['total_tokens'];
					}
				}
			}

			$stats['tools_used'] = count( $tools_set );

			// Convert user arrays to counts and prepare for output.
			foreach ( $all_tools as $tool_slug => $tool_data ) {
				$all_tools[ $tool_slug ]['total_users'] = count( $tool_data['users'] );
				unset( $all_tools[ $tool_slug ]['users'] ); // Remove the array, keep only count.
			}

			// Sort tools by total tokens and get top 10.
			uasort(
				$all_tools,
				function ( $a, $b ) {
					return $b['total_tokens'] - $a['total_tokens'];
				}
			);

			$stats['top_tools'] = array_slice( $all_tools, 0, 10 );
		}

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
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return 1.0;
		}

		// Get multipliers from the WP_MCP_AI_Tool_Token_Limits class.
		$multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();

		if ( isset( $multipliers[ $tool_slug ] ) ) {
			return (float) $multipliers[ $tool_slug ];
		}

		return 1.0; // Default multiplier.
	}
}
