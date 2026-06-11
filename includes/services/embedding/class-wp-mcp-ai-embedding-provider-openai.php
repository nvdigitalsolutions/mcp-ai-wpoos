<?php
/**
 * OpenAI embedding provider.
 *
 * Default embedding backend used by {@see WP_MCP_AI_Vector_Context_Service}.
 * Wraps the existing {@see WP_MCP_AI_OpenAI_Client::create_embeddings()} call so
 * the vector service can stay provider-agnostic.
 *
 * Phase 3 enhancement inspired by the MemPalace project
 * (https://github.com/MemPalace/mempalace).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI embedding provider.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Embedding_Provider_OpenAI implements WP_MCP_AI_Embedding_Provider_Interface {

	/**
	 * Default embedding model id.
	 *
	 * Mirrors the previous hard-coded constant in the vector service so the
	 * default embedding behaviour is byte-for-byte identical to the
	 * pre-Phase-3 implementation.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Cached OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client|null
	 */
	private $client = null;

	/**
	 * Override model (when caller passes one to the constructor).
	 *
	 * @var string|null
	 */
	private $model_override;

	/**
	 * Constructor.
	 *
	 * @param string|null $model Optional model id to override the default.
	 */
	public function __construct( $model = null ) {
		$this->model_override = is_string( $model ) && '' !== $model ? $model : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model() {
		if ( null !== $this->model_override ) {
			return $this->model_override;
		}

		/**
		 * Filter the OpenAI embedding model.
		 *
		 * @since 1.1.0
		 *
		 * @param string $model Default OpenAI embedding model.
		 */
		$model = (string) apply_filters( 'wp_mcp_ai_embedding_provider_openai_model', self::DEFAULT_MODEL );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		return ! empty( $api_key ) && class_exists( 'WP_MCP_AI_OpenAI_Client' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $text Text to embed.
	 * @return array<int,float>|WP_Error
	 */
	public function embed( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return new WP_Error( 'empty_text', __( 'Embedding input cannot be empty.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->is_available() ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' ) );
		}

		try {
			$response = $this->get_client()->create_embeddings(
				$text,
				array( 'model' => $this->get_model() )
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'embedding_error', $e->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['data'][0]['embedding'] ) && is_array( $response['data'][0]['embedding'] ) ) {
			return array_values( array_map( 'floatval', $response['data'][0]['embedding'] ) );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid embedding response from OpenAI.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Lazy-initialised OpenAI client.
	 *
	 * @return WP_MCP_AI_OpenAI_Client
	 */
	private function get_client() {
		if ( null === $this->client ) {
			$this->client = new WP_MCP_AI_OpenAI_Client();
		}
		return $this->client;
	}
}
