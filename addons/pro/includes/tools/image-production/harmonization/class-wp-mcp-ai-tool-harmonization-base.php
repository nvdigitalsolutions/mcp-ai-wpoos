<?php
/**
 * Abstract base class for harmonization tools.
 *
 * Provides shared interface implementations and helper methods. Concrete tools
 * supply slug, name, description, schema, and an `execute_harmonization()` body.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_MCP_AI_PATH' ) ) {
	$wp_mcp_ai_iface = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $wp_mcp_ai_iface ) ) {
		require_once $wp_mcp_ai_iface;
	}
}
require_once __DIR__ . '/trait-wp-mcp-ai-tool-harmonization.php';
require_once __DIR__ . '/class-wp-mcp-ai-harmonization-compositor.php';
require_once __DIR__ . '/class-wp-mcp-ai-lighting-analyzer.php';

/**
 * Abstract base for harmonization tools.
 *
 * @since 1.1.0
 */
abstract class WP_MCP_AI_Tool_Harmonization_Base implements
	WP_MCP_AI_Tool_Interface,
	WP_MCP_AI_Tool_LLM_Sanitizer_Interface,
	WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Harmonization;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Compositor instance.
	 *
	 * @var WP_MCP_AI_Harmonization_Compositor|null
	 */
	protected $compositor = null;

	/**
	 * Lighting analyzer instance.
	 *
	 * @var WP_MCP_AI_Lighting_Analyzer|null
	 */
	protected $lighting = null;

	/**
	 * Whether the tool is available on this server.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return extension_loaded( 'gd' ) || extension_loaded( 'imagick' );
	}

	/**
	 * Lazy compositor accessor.
	 *
	 * @return WP_MCP_AI_Harmonization_Compositor
	 */
	protected function compositor() {
		if ( null === $this->compositor ) {
			$this->compositor = new WP_MCP_AI_Harmonization_Compositor();
		}
		return $this->compositor;
	}

	/**
	 * Lazy lighting analyzer accessor.
	 *
	 * @return WP_MCP_AI_Lighting_Analyzer
	 */
	protected function lighting() {
		if ( null === $this->lighting ) {
			$this->lighting = new WP_MCP_AI_Lighting_Analyzer();
		}
		return $this->lighting;
	}

	/**
	 * Default capability flags. Override in `harmonize_image_into_background` for async.
	 *
	 * @return array
	 */
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return $this->harmonization_capability_flags();
	}

	/**
	 * Default schema is empty; concrete tools must override.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			// Empty stdClass encodes as `{}`; an empty PHP array would encode
			// as `[]`, which strict providers (DeepSeek) reject.
			'properties'           => new stdClass(),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute entrypoint with auth + dependency gating wrapped around the body.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 *
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! static::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_unavailable',
				__( 'Harmonization tools require Imagick or GD.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$user = $this->harmonization_authorize( $context );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		/**
		 * Action: before a harmonization stage runs.
		 *
		 * @param string $stage     Tool slug.
		 * @param array  $arguments Tool arguments.
		 * @param array  $context   Execution context.
		 */
		do_action( 'wp_mcp_ai_before_harmonization_stage', $this->get_slug(), $arguments, $context );

		$result = $this->execute_harmonization( $arguments, $context, (int) $user );

		/**
		 * Action: after a harmonization stage runs.
		 *
		 * @param string         $stage     Tool slug.
		 * @param array          $arguments Tool arguments.
		 * @param array          $context   Execution context.
		 * @param array|WP_Error $result    Tool result.
		 */
		do_action( 'wp_mcp_ai_after_harmonization_stage', $this->get_slug(), $arguments, $context, $result );

		return $result;
	}

	/**
	 * Run an AI edit on an image and return raw bytes.
	 *
	 * Centralised so all harmonization tools share one implementation.
	 *
	 * @param string $path     Image path.
	 * @param string $prompt   Edit prompt.
	 * @param string $provider 'gemini' or 'openai'.
	 *
	 * @return string|WP_Error Raw image bytes or error.
	 */
	protected function ai_edit_image( $path, $prompt, $provider ) {
		if ( 'gemini' === $provider && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$image_data = file_get_contents( $path );
			if ( false === $image_data || '' === $image_data ) {
				return new WP_Error( 'wp_mcp_ai_read_failed', __( 'Failed to read image.', 'mcp-ai-wpoos-pro' ) );
			}
			$mime   = wp_check_filetype( $path );
			$mtype  = isset( $mime['type'] ) && '' !== $mime['type'] ? $mime['type'] : 'image/png';
			$client = new WP_MCP_AI_Gemini_Client();
			$result = $client->edit_image(
				$prompt,
				array(
					'source_image' => array(
						'data'      => base64_encode( $image_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by Gemini image API.
						'mime_type' => $mtype,
					),
					'mime_type'    => 'image/png',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['image'] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'Gemini edit returned empty.', 'mcp-ai-wpoos-pro' ) );
			}
			return $result['image'];
		}

		if ( 'openai' === $provider && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new WP_MCP_AI_OpenAI_Client();
			$result = $client->edit_image( $path, $prompt, array( 'model' => 'gpt-image-2' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['data'][0] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'OpenAI edit returned empty.', 'mcp-ai-wpoos-pro' ) );
			}
			$first = $result['data'][0];
			if ( ! empty( $first['b64_json'] ) ) {
				$cleaned = str_replace( array( "\r", "\n", ' ' ), '', $first['b64_json'] );
				$decoded = base64_decode( $cleaned, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $decoded ) {
					return new WP_Error( 'wp_mcp_ai_decode_failed', __( 'Failed to decode OpenAI image.', 'mcp-ai-wpoos-pro' ) );
				}
				return $decoded;
			}
			if ( ! empty( $first['url'] ) ) {
				$resp = wp_safe_remote_get( $first['url'], array( 'timeout' => 60 ) );
				if ( is_wp_error( $resp ) ) {
					return $resp;
				}
				return (string) wp_remote_retrieve_body( $resp );
			}
		}

		return new WP_Error( 'wp_mcp_ai_no_provider', __( 'No supported AI provider for editing.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Concrete tools implement the actual work here.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   Authorized user id (0 for token auth).
	 *
	 * @return array|WP_Error
	 */
	abstract protected function execute_harmonization( array $arguments, array $context, $user_id );

	/**
	 * LLM-friendly sanitization of the tool result.
	 *
	 * @param mixed $result Tool result.
	 *
	 * @return array
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}
		if ( ! is_array( $result ) ) {
			return array(
				'success' => true,
				'result'  => $result,
			);
		}
		return $result;
	}
}
