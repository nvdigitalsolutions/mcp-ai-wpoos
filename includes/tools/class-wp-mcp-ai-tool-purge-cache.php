<?php
/**
 * Master cache purge tool that coordinates multi-layer cache clearing.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
}

/**
 * Provides a master tool for purging all configured cache layers.
 */
class WP_MCP_AI_Tool_Purge_Cache implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_TIMEOUT = 30;

	const MAX_TIMEOUT = 120;

	const MIN_TIMEOUT = 5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'purge_cache';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Purge Cache', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Purges all configured caching layers (Cloudflare, Varnish, etc.) in the correct order to ensure content updates are properly reflected.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to purge the entire cache for all configured layers.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => __( 'Specific URLs to purge from all configured cache layers. Provide absolute URLs.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
				'timeout'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional timeout in seconds for cache purge requests.', 'wp-mcp-ai' ),
					'minimum'     => self::MIN_TIMEOUT,
					'maximum'     => self::MAX_TIMEOUT,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute cache purge across all configured layers.
	 *
	 * @param array $arguments Parsed tool arguments.
	 * @param array $context   Request context including acting user details.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to purge the cache.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_settings', __( 'The admin settings component is not available.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$purge_everything = ! empty( $arguments['purge_everything'] );
		$urls             = isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ? $arguments['urls'] : array();

		if ( ! $purge_everything && empty( $urls ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_purge', __( 'Provide purge_everything or at least one URL to purge.', 'wp-mcp-ai' ) );
		}

		$layers_purged = array();
		$errors        = array();

		// Purge Varnish first (local cache should be cleared before CDN).
		if ( $this->is_varnish_enabled( $settings ) ) {
			$varnish_result = $this->purge_varnish( $arguments, $context );

			if ( is_wp_error( $varnish_result ) ) {
				$errors['varnish'] = array(
					'layer'   => 'Varnish',
					'message' => $varnish_result->get_error_message(),
					'code'    => $varnish_result->get_error_code(),
				);
			} else {
				$layers_purged['varnish'] = $varnish_result;
			}
		}

		// Purge Cloudflare second (CDN cache).
		if ( $this->is_cloudflare_enabled( $settings ) ) {
			$cloudflare_result = $this->purge_cloudflare( $arguments, $context );

			if ( is_wp_error( $cloudflare_result ) ) {
				$errors['cloudflare'] = array(
					'layer'   => 'Cloudflare',
					'message' => $cloudflare_result->get_error_message(),
					'code'    => $cloudflare_result->get_error_code(),
				);
			} else {
				$layers_purged['cloudflare'] = $cloudflare_result;
			}
		}

		if ( empty( $layers_purged ) && empty( $errors ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_cache_layers',
				__( 'No cache layers are currently configured. Enable Cloudflare or Varnish purge in the plugin settings.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_cache' => __( 'Configure cache settings in WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$summary = array(
			'message'       => __( 'Cache purge operation completed.', 'wp-mcp-ai' ),
			'layers_purged' => array_keys( $layers_purged ),
			'results'       => $layers_purged,
		);

		if ( ! empty( $errors ) ) {
			$summary['errors'] = $errors;

			if ( empty( $layers_purged ) ) {
				$summary['message'] = __( 'Cache purge operation failed for all layers.', 'wp-mcp-ai' );
			} else {
				$summary['message'] = __( 'Cache purge operation completed with some errors.', 'wp-mcp-ai' );
			}
		}

		return $summary;
	}

	/**
	 * Check if Varnish purge is enabled in settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	protected function is_varnish_enabled( $settings ) {
		return ! empty( $settings['enable_varnish_purge'] );
	}

	/**
	 * Check if Cloudflare purge is enabled in settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	protected function is_cloudflare_enabled( $settings ) {
		$api_token = isset( $settings['cloudflare_api_token'] ) ? trim( (string) $settings['cloudflare_api_token'] ) : '';
		$zone_id   = isset( $settings['cloudflare_zone_id'] ) ? trim( (string) $settings['cloudflare_zone_id'] ) : '';

		return '' !== $api_token && '' !== $zone_id;
	}

	/**
	 * Purge Varnish cache layer.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function purge_varnish( $arguments, $context ) {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$varnish_tool  = $tool_registry->get_tool( 'purge_varnish_cache' );

		if ( ! $varnish_tool ) {
			return new WP_Error(
				'wp_mcp_ai_varnish_tool_missing',
				__( 'The Varnish purge tool is not available.', 'wp-mcp-ai' )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'cache_purge_varnish',
			'Purging Varnish cache layer.',
			array(
				'purge_everything' => ! empty( $arguments['purge_everything'] ),
				'url_count'        => isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ? count( $arguments['urls'] ) : 0,
			)
		);

		return $varnish_tool->execute( $arguments, $context );
	}

	/**
	 * Purge Cloudflare cache layer.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function purge_cloudflare( $arguments, $context ) {
		$tool_registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$cloudflare_tool = $tool_registry->get_tool( 'purge_cloudflare_cache' );

		if ( ! $cloudflare_tool ) {
			return new WP_Error(
				'wp_mcp_ai_cloudflare_tool_missing',
				__( 'The Cloudflare purge tool is not available.', 'wp-mcp-ai' )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'cache_purge_cloudflare',
			'Purging Cloudflare cache layer.',
			array(
				'purge_everything' => ! empty( $arguments['purge_everything'] ),
				'url_count'        => isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ? count( $arguments['urls'] ) : 0,
			)
		);

		return $cloudflare_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Clears cache data.
			'state-changing',       // Modifies system state.
			'requires-capability',  // Requires 'manage_options' capability.
			'performance-impact',   // May affect site performance temporarily.
			'idempotent',           // Can be called multiple times safely.
		);
	}
}
