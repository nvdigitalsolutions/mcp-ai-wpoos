<?php
/**
 * Tool Settings Manager
 *
 * Manages custom settings for tools including capability flags overrides and force-sync settings.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Settings_Manager' ) ) {
	/**
	 * Manages custom tool settings and overrides.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Tool_Settings_Manager {

		/**
		 * Option name for storing tool capability flags overrides.
		 *
		 * @var string
		 */
		const CAPABILITY_FLAGS_OPTION = 'wp_mcp_ai_tool_capability_flags';

		/**
		 * Option name for storing force-sync settings per tool.
		 *
		 * @var string
		 */
		const FORCE_SYNC_OPTION = 'wp_mcp_ai_tool_force_sync';

		/**
		 * Get capability flags for a tool, including custom overrides.
		 *
		 * @param string                   $tool_slug Tool slug.
		 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
		 * @return array Array of capability flags.
		 */
		public static function get_capability_flags( $tool_slug, $tool = null ) {
			// Get base flags from tool if available.
			$base_flags = array();
			if ( $tool && $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$base_flags = $tool->get_capability_flags();
			}

			// Get custom overrides.
			$custom_flags = self::get_custom_capability_flags( $tool_slug );

			// Merge: custom flags override base flags.
			if ( ! empty( $custom_flags ) ) {
				return $custom_flags;
			}

			return $base_flags;
		}

		/**
		 * Get custom capability flags for a tool.
		 *
		 * @param string $tool_slug Tool slug.
		 * @return array Array of custom capability flags, or empty array if none set.
		 */
		public static function get_custom_capability_flags( $tool_slug ) {
			$all_custom_flags = get_option( self::CAPABILITY_FLAGS_OPTION, array() );

			if ( isset( $all_custom_flags[ $tool_slug ] ) && is_array( $all_custom_flags[ $tool_slug ] ) ) {
				return $all_custom_flags[ $tool_slug ];
			}

			return array();
		}

		/**
		 * Update custom capability flags for a tool.
		 *
		 * @param string $tool_slug Tool slug.
		 * @param array  $flags     Array of capability flags.
		 * @return bool True on success, false on failure.
		 */
		public static function update_capability_flags( $tool_slug, $flags ) {
			$all_custom_flags = get_option( self::CAPABILITY_FLAGS_OPTION, array() );
			$old_custom_flags = $all_custom_flags;

			if ( empty( $flags ) ) {
				// Remove custom flags if empty (revert to default).
				unset( $all_custom_flags[ $tool_slug ] );
			} else {
				// Sanitize and store flags.
				$all_custom_flags[ $tool_slug ] = array_map( 'sanitize_key', $flags );
			}

			// Check if the value actually changed.
			// If it didn't change, consider it a success (no need to update).
			if ( $all_custom_flags === $old_custom_flags ) {
				return true;
			}

			return update_option( self::CAPABILITY_FLAGS_OPTION, $all_custom_flags );
		}

		/**
		 * Check if a tool has force-sync enabled.
		 *
		 * @param string $tool_slug Tool slug.
		 * @return bool True if force-sync is enabled, false otherwise.
		 */
		public static function is_force_sync_enabled( $tool_slug ) {
			$force_sync_tools = get_option( self::FORCE_SYNC_OPTION, array() );

			return isset( $force_sync_tools[ $tool_slug ] ) && $force_sync_tools[ $tool_slug ];
		}

		/**
		 * Enable or disable force-sync for a tool.
		 *
		 * @param string $tool_slug    Tool slug.
		 * @param bool   $force_sync   Whether to enable force-sync.
		 * @return bool True on success, false on failure.
		 */
		public static function set_force_sync( $tool_slug, $force_sync ) {
			$force_sync_tools = get_option( self::FORCE_SYNC_OPTION, array() );
			$old_force_sync_tools = $force_sync_tools;

			if ( $force_sync ) {
				$force_sync_tools[ $tool_slug ] = true;
			} else {
				unset( $force_sync_tools[ $tool_slug ] );
			}

			// Check if the value actually changed.
			// If it didn't change, consider it a success (no need to update).
			if ( $force_sync_tools === $old_force_sync_tools ) {
				return true;
			}

			return update_option( self::FORCE_SYNC_OPTION, $force_sync_tools );
		}

		/**
		 * Get all available capability flags.
		 *
		 * Returns a grouped list of all standard capability flags for UI display.
		 *
		 * @return array Grouped capability flags.
		 */
		public static function get_available_capability_flags() {
			return array(
				'tier'         => array(
					'label' => __( 'Tier', 'wp-mcp-ai' ),
					'flags' => array(
						'pro' => __( 'Pro', 'wp-mcp-ai' ),
					),
				),
				'requirements' => array(
					'label' => __( 'Requirements', 'wp-mcp-ai' ),
					'flags' => array(
						'requires-credentials'      => __( 'Requires Credentials', 'wp-mcp-ai' ),
						'requires-plugin'           => __( 'Requires Plugin', 'wp-mcp-ai' ),
						'requires-capability'       => __( 'Requires Capability', 'wp-mcp-ai' ),
						'requires-model'            => __( 'Requires Model', 'wp-mcp-ai' ),
						'requires-vision-model'     => __( 'Requires Vision Model', 'wp-mcp-ai' ),
						'requires-multimodal-model' => __( 'Requires Multimodal Model', 'wp-mcp-ai' ),
						'requires-video-model'      => __( 'Requires Video Model', 'wp-mcp-ai' ),
					),
				),
				'operational'  => array(
					'label' => __( 'Operational', 'wp-mcp-ai' ),
					'flags' => array(
						'read-only'          => __( 'Read-only', 'wp-mcp-ai' ),
						'write'              => __( 'Write', 'wp-mcp-ai' ),
						'state-changing'     => __( 'State-changing', 'wp-mcp-ai' ),
						'reversible'         => __( 'Reversible', 'wp-mcp-ai' ),
						'idempotent'         => __( 'Idempotent', 'wp-mcp-ai' ),
						'performance-impact' => __( 'Performance Impact', 'wp-mcp-ai' ),
						'consumes-tokens'    => __( 'Consumes Tokens', 'wp-mcp-ai' ),
						'model-dependent'    => __( 'Model Dependent', 'wp-mcp-ai' ),
					),
				),
				'network'      => array(
					'label' => __( 'Network & Performance', 'wp-mcp-ai' ),
					'flags' => array(
						'local-only'          => __( 'Local Only', 'wp-mcp-ai' ),
						'external-api'        => __( 'External API', 'wp-mcp-ai' ),
						'network-dependent'   => __( 'Network Dependent', 'wp-mcp-ai' ),
						'async'               => __( 'Async', 'wp-mcp-ai' ),
						'rate-limited'        => __( 'Rate Limited', 'wp-mcp-ai' ),
						'deferred-result'     => __( 'Deferred Result', 'wp-mcp-ai' ),
						'requires-polling'    => __( 'Requires Polling', 'wp-mcp-ai' ),
						'supports-webhook'    => __( 'Supports Webhook', 'wp-mcp-ai' ),
						'requires-callback'   => __( 'Requires Callback', 'wp-mcp-ai' ),
						'long-running'        => __( 'Long-running', 'wp-mcp-ai' ),
						'may-timeout'         => __( 'May Timeout', 'wp-mcp-ai' ),
						'background-only'     => __( 'Background Only', 'wp-mcp-ai' ),
						'streaming-capable'   => __( 'Streaming Capable', 'wp-mcp-ai' ),
					),
				),
				'data'         => array(
					'label' => __( 'Data Characteristics', 'wp-mcp-ai' ),
					'flags' => array(
						'cacheable'            => __( 'Cacheable', 'wp-mcp-ai' ),
						'non-deterministic'    => __( 'Non-deterministic', 'wp-mcp-ai' ),
						'pii-data'             => __( 'PII Data', 'wp-mcp-ai' ),
						'large-response'       => __( 'Large Response', 'wp-mcp-ai' ),
						'paginated'            => __( 'Paginated', 'wp-mcp-ai' ),
						'supports-compression' => __( 'Supports Compression', 'wp-mcp-ai' ),
					),
				),
			);
		}

		/**
		 * Reset tool settings to defaults.
		 *
		 * @param string $tool_slug Tool slug.
		 * @return bool True on success, false on failure.
		 */
		public static function reset_tool_settings( $tool_slug ) {
			$success = true;

			// Remove custom capability flags.
			$all_custom_flags = get_option( self::CAPABILITY_FLAGS_OPTION, array() );
			unset( $all_custom_flags[ $tool_slug ] );
			$success = $success && update_option( self::CAPABILITY_FLAGS_OPTION, $all_custom_flags );

			// Remove force-sync setting.
			$force_sync_tools = get_option( self::FORCE_SYNC_OPTION, array() );
			unset( $force_sync_tools[ $tool_slug ] );
			$success = $success && update_option( self::FORCE_SYNC_OPTION, $force_sync_tools );

			return $success;
		}
	}
}
