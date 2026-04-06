<?php
/**
 * JetEngine MCP Prompts
 *
 * Discovers and caches JetEngine MCP prompt templates.
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
 * JetEngine MCP Prompts integration.
 *
 * Discovers and caches JetEngine's prompt templates via the MCP server.
 * Allows rendering prompts with arguments for AI-powered workflows.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_JetEngine_MCP_Prompts {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached prompts list.
	 *
	 * @var array|null
	 */
	private $prompts = null;

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
	private function __construct() {}

	/**
	 * List available prompts.
	 *
	 * @param bool $use_cache Whether to use cached response.
	 * @return array|WP_Error Array of prompt definitions or error.
	 */
	public function list_prompts( $use_cache = true ) {
		if ( null !== $this->prompts && $use_cache ) {
			return $this->prompts;
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$result = $client->prompts_list( $use_cache );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->prompts = isset( $result['prompts'] ) ? $result['prompts'] : $result;

		return $this->prompts;
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $name      Prompt name.
	 * @param array  $arguments Prompt arguments.
	 * @return array|WP_Error Prompt content or error.
	 */
	public function get_prompt( $name, $arguments = array() ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $client->prompts_get( $name, $arguments );
	}

	/**
	 * Render a prompt with arguments and return the message content.
	 *
	 * @param string $name      Prompt name.
	 * @param array  $arguments Prompt arguments.
	 * @return string|WP_Error Rendered prompt text or error.
	 */
	public function render_prompt( $name, $arguments = array() ) {
		$result = $this->get_prompt( $name, $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract message content from the prompt response.
		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			$texts = array();
			foreach ( $result['messages'] as $message ) {
				if ( isset( $message['content'] ) ) {
					if ( is_string( $message['content'] ) ) {
						$texts[] = $message['content'];
					} elseif ( isset( $message['content']['text'] ) ) {
						$texts[] = $message['content']['text'];
					}
				}
			}
			return implode( "\n\n", $texts );
		}

		// Fallback: return as JSON string.
		$encoded = wp_json_encode( $result );

		if ( false === $encoded ) {
			return new WP_Error(
				'mcp_prompt_encode_error',
				__( 'Failed to encode prompt response.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $encoded;
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
