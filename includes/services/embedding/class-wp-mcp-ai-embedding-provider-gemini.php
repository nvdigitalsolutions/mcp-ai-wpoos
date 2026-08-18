<?php
/**
 * Gemini embedding provider.
 *
 * Google-backed embedding backend that wraps
 * {@see WP_MCP_AI_Gemini_Client::create_embedding()} so the vector context
 * service can route semantic-search queries to Gemini when an OpenAI key is
 * not configured (e.g. assistants backed by Gemini or DeepSeek on sites that
 * only hold a Gemini key).
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini embedding provider.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Embedding_Provider_Gemini implements WP_MCP_AI_Embedding_Provider_Interface {

	/**
	 * Default embedding model id when none is configured.
	 *
	 * `gemini-embedding-001` is the GA embedding model (replaces the
	 * deprecated `text-embedding-004`) and produces 3072-dim vectors.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gemini-embedding-001';

	/**
	 * Cached Gemini client instance.
	 *
	 * @var WP_MCP_AI_Gemini_Client|null
	 */
	private $client = null;

	/**
	 * Model id override (when supplied to the constructor).
	 *
	 * @var string|null
	 */
	private $model_override;

	/**
	 * Constructor.
	 *
	 * @param string|null $model Optional embedding model override.
	 */
	public function __construct( $model = null ) {
		$this->model_override = is_string( $model ) && '' !== $model ? $model : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id() {
		return 'gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model() {
		if ( null !== $this->model_override ) {
			return $this->model_override;
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$model    = isset( $settings['gemini_embedding_model'] ) ? (string) $settings['gemini_embedding_model'] : '';
		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		/**
		 * Filter the Gemini embedding model id.
		 *
		 * @since 1.2.0
		 *
		 * @param string $model Resolved model id.
		 */
		$model = (string) apply_filters( 'wp_mcp_ai_embedding_provider_gemini_model', $model );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
		return ! empty( $api_key ) && class_exists( 'WP_MCP_AI_Gemini_Client' );
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
			return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ) );
		}

		try {
			$response = $this->get_client()->create_embedding(
				$text,
				array(
					'model'     => $this->get_model(),
					'task_type' => 'RETRIEVAL_QUERY',
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'embedding_error', $e->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Gemini embedContent responses carry the vector under embedding.values.
		if ( isset( $response['embedding']['values'] ) && is_array( $response['embedding']['values'] ) ) {
			return array_values( array_map( 'floatval', $response['embedding']['values'] ) );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid embedding response from Gemini.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Lazy-initialised Gemini client.
	 *
	 * @return WP_MCP_AI_Gemini_Client
	 */
	private function get_client() {
		if ( null === $this->client ) {
			$this->client = new WP_MCP_AI_Gemini_Client();
		}
		return $this->client;
	}
}
