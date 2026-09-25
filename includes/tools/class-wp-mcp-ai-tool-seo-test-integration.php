<?php
/**
 * Tool: seo_test_integration — Tests active SEO plugins and metadata access.
 *
 * Port of mcp-wordpress wp_seo_test_integration tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Test Integration — detects active SEO plugins and tests metadata access.
 *
 * Checks for Yoast, Rank Math, AIOSEO, and SEOPress, then verifies (read-only)
 * whether each plugin's focus-keyword meta key can be read on the latest post.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Test_Integration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_test_integration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Integration Tester', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects which SEO plugins are active (Yoast, Rank Math, AIOSEO, SEOPress) and tests, read-only, whether their focus-keyword metadata keys can be read on the latest post. Reports metadata_keys_readable per plugin.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Verifying which SEO plugin owns metadata on the site before bulk updates or before reading live SEO data.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Reading actual SEO values; use seo_get_live_data or get_rankmath_seo.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_rankmath_seo', 'seo_meta_optimizer', 'seo_get_live_data' ),
			'notes'           => __( 'The metadata test only reads — it never writes. metadata_keys_readable reports whether the plugin\'s focus-keyword key exists on the latest post (null when no post exists or the test was skipped).', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'check_plugins'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to detect active SEO plugins.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'test_metadata_access' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to test reading each plugin\'s focus-keyword meta key on the latest post.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run SEO integration tests.', 'mcp-ai-wpoos' ) );
		}

		$check_plugins = isset( $arguments['check_plugins'] ) ? ! empty( $arguments['check_plugins'] ) : true;
		$test_metadata = isset( $arguments['test_metadata_access'] ) ? ! empty( $arguments['test_metadata_access'] ) : true;

		$plugins         = array();
		$metadata_access = false;
		$latest          = null;

		if ( $test_metadata ) {
			$latest_posts = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'any',
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
			$latest       = ! empty( $latest_posts ) ? $latest_posts[0] : null;
		}

		if ( $check_plugins ) {
			$detected = $this->detect_seo_plugins();

			$definitions = array(
				'yoast'    => array(
					'name' => __( 'Yoast SEO', 'mcp-ai-wpoos' ),
					'key'  => '_yoast_wpseo_focuskw',
				),
				'rankmath' => array(
					'name' => __( 'Rank Math', 'mcp-ai-wpoos' ),
					'key'  => 'rank_math_focus_keyword',
				),
				'aioseo'   => array(
					'name' => __( 'All in One SEO', 'mcp-ai-wpoos' ),
					'key'  => '_aioseo_keywords',
				),
				'seopress' => array(
					'name' => __( 'SEOPress', 'mcp-ai-wpoos' ),
					'key'  => '_seopress_analysis_target_kw',
				),
			);

			foreach ( $definitions as $slug => $definition ) {
				$readable = null;
				if ( $test_metadata && $latest ) {
					$readable = metadata_exists( $latest->ID, $definition['key'] );
					if ( $readable ) {
						$metadata_access = true;
					}
				}

				$plugins[] = array(
					'slug'                   => $slug,
					'name'                   => $definition['name'],
					'active'                 => ! empty( $detected[ $slug ] ),
					'metadata_keys_readable' => $readable,
				);
			}
		}

		$active_count = 0;
		foreach ( $plugins as $plugin ) {
			if ( $plugin['active'] ) {
				++$active_count;
			}
		}

		if ( 0 === $active_count ) {
			$message = __( 'SEO integration test completed: no supported SEO plugins were detected.', 'mcp-ai-wpoos' );
		} else {
			$message = sprintf(
				/* translators: %d: number of active SEO plugins */
				__( 'SEO integration test completed: %d SEO plugin(s) active.', 'mcp-ai-wpoos' ),
				$active_count
			);
		}

		$response = array(
			'message'         => $message,
			'plugins'         => $plugins,
			'metadata_access' => $metadata_access,
		);

		if ( $latest ) {
			$response['tested_post_id'] = (int) $latest->ID;
		}

		return $response;
	}

	/**
	 * Detects active SEO plugins.
	 *
	 * @since 1.1.87
	 *
	 * @return array Detection map keyed by plugin slug.
	 */
	private function detect_seo_plugins() {
		$yoast = defined( 'WPSEO_VERSION' );
		if ( ! $yoast && function_exists( 'is_plugin_active' ) ) {
			$yoast = is_plugin_active( 'wordpress-seo/wp-seo.php' );
		}

		return array(
			'rankmath' => class_exists( 'RankMath' ) || function_exists( 'rank_math' ),
			'yoast'    => $yoast,
			'aioseo'   => function_exists( 'aioseo' ),
			'seopress' => function_exists( 'seopress_get_service' ) || defined( 'SEOPRESS_VERSION' ),
		);
	}
}
