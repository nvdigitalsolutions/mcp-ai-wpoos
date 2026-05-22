<?php
/**
 * JetEngine MCP Resources
 *
 * Fetches and normalizes JetEngine MCP resources for AI context enrichment.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine MCP Resources integration.
 *
 * Fetches resources from JetEngine's MCP server and provides normalized
 * access to post types, taxonomies, meta boxes, glossaries, macros, and
 * relations. Integrates with the assistant system context for AI grounding.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_JetEngine_MCP_Resources {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached resources data.
	 *
	 * @var array|null
	 */
	private $resources = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Hook into system context building if setting enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['jetengine_mcp_context_injection'] ) ) {
			add_filter( 'wp_mcp_ai_build_system_context', array( $this, 'inject_site_context' ), 20 );
		}
	}

	/**
	 * Fetch all MCP resources.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Resources data or error.
	 */
	public function fetch_resources( $use_cache = true ) {
		if ( null !== $this->resources && $use_cache ) {
			return $this->resources;
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->resources_list( $use_cache );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->resources = isset( $result['resources'] ) ? $result['resources'] : $result;

		return $this->resources;
	}

	/**
	 * Get post types from MCP resources.
	 *
	 * @return array Array of post type definitions.
	 */
	public function get_post_types() {
		return $this->get_resource_section( 'post_types' );
	}

	/**
	 * Get taxonomies from MCP resources.
	 *
	 * @return array Array of taxonomy definitions.
	 */
	public function get_taxonomies() {
		return $this->get_resource_section( 'taxonomies' );
	}

	/**
	 * Get meta boxes from MCP resources.
	 *
	 * @return array Array of meta box definitions.
	 */
	public function get_meta_boxes() {
		return $this->get_resource_section( 'meta_boxes' );
	}

	/**
	 * Get glossaries from MCP resources.
	 *
	 * @return array Array of glossary definitions.
	 */
	public function get_glossaries() {
		return $this->get_resource_section( 'glossaries' );
	}

	/**
	 * Get macros from MCP resources.
	 *
	 * @return array Array of macro definitions.
	 */
	public function get_macros() {
		return $this->get_resource_section( 'macros' );
	}

	/**
	 * Get relations from MCP resources.
	 *
	 * @return array Array of relation definitions.
	 */
	public function get_relations() {
		return $this->get_resource_section( 'relations' );
	}

	/**
	 * Inject JetEngine site context into AI system prompts.
	 *
	 * @param string $context Current system context.
	 * @return string Modified context with JetEngine site structure.
	 */
	public function inject_site_context( $context ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) || ! WP_MCP_AI_JetEngine_Compat::has_mcp_server() ) {
			return $context;
		}

		$resources = $this->fetch_resources();

		if ( is_wp_error( $resources ) || empty( $resources ) ) {
			return $context;
		}

		$site_info = "\n\n## JetEngine Site Structure\n";

		$post_types = $this->get_post_types();
		if ( ! empty( $post_types ) ) {
			$site_info .= "\n### Custom Post Types\n";
			foreach ( $post_types as $pt ) {
				$name = isset( $pt['name'] ) ? $pt['name'] : ( isset( $pt['slug'] ) ? $pt['slug'] : 'Unknown' );
				$slug = isset( $pt['slug'] ) ? $pt['slug'] : '';
				if ( ! empty( $slug ) ) {
					$site_info .= "- {$name} (`{$slug}`)\n";
				}
			}
		}

		$taxonomies = $this->get_taxonomies();
		if ( ! empty( $taxonomies ) ) {
			$site_info .= "\n### Custom Taxonomies\n";
			foreach ( $taxonomies as $tax ) {
				$name = isset( $tax['name'] ) ? $tax['name'] : ( isset( $tax['slug'] ) ? $tax['slug'] : 'Unknown' );
				$slug = isset( $tax['slug'] ) ? $tax['slug'] : '';
				if ( ! empty( $slug ) ) {
					$site_info .= "- {$name} (`{$slug}`)\n";
				}
			}
		}

		$relations = $this->get_relations();
		if ( ! empty( $relations ) ) {
			$site_info .= "\n### Relations\n";
			foreach ( $relations as $rel ) {
				$name       = isset( $rel['name'] ) ? $rel['name'] : 'Unknown';
				$site_info .= "- {$name}\n";
			}
		}

		return $context . $site_info;
	}

	/**
	 * Get a specific section from resources.
	 *
	 * @param string $section Section key.
	 * @return array Section data or empty array.
	 */
	private function get_resource_section( $section ) {
		$resources = $this->fetch_resources();

		if ( is_wp_error( $resources ) ) {
			return array();
		}

		if ( is_array( $resources ) && isset( $resources[ $section ] ) ) {
			return (array) $resources[ $section ];
		}

		// Search in flat array of resources.
		if ( is_array( $resources ) ) {
			foreach ( $resources as $resource ) {
				if ( isset( $resource['type'] ) && $resource['type'] === $section ) {
					return isset( $resource['data'] ) ? (array) $resource['data'] : array( $resource );
				}
			}
		}

		return array();
	}

	/**
	 * Get MCP client instance.
	 *
	 * @return WP_MCP_AI_JetEngine_MCP_Client|WP_Error Client or error.
	 */
	private function get_client() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			$client_file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
				: '';
			if ( ! empty( $client_file ) && file_exists( $client_file ) ) {
				require_once $client_file;
			} else {
				return new WP_Error( 'mcp_client_missing', __( 'MCP client class is not available.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		return new WP_MCP_AI_JetEngine_MCP_Client();
	}
}
