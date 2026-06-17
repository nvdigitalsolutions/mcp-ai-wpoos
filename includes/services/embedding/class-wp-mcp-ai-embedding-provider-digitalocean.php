<?php
/**
 * DigitalOcean embedding provider.
 *
 * Wraps the {@see WP_MCP_AI_DigitalOcean_Client::create_embedding()} call so
 * the vector service can use DigitalOcean Serverless Inference as an
 * embedding backend alongside OpenAI and Ollama.
 *
 * @package WP_MCP_AI
 * @since 1.1.16
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DigitalOcean embedding provider.
 *
 * @since 1.1.16
 */
class WP_MCP_AI_Embedding_Provider_DigitalOcean implements WP_MCP_AI_Embedding_Provider_Interface {

	/**
	 * Default embedding model id.
	 *
	 * Mirrors WP_MCP_AI_DigitalOcean_Client::DEFAULT_EMBEDDING_MODEL.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gte-large-en-v1.5';

	/**
	 * Cached DigitalOcean client instance.
	 *
	 * @var WP_MCP_AI_DigitalOcean_Client|null
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
		return 'digitalocean';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model() {
		if ( null !== $this->model_override ) {
			return $this->model_override;
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$model    = isset( $settings['digitalocean_embedding_model'] ) ? (string) $settings['digitalocean_embedding_model'] : '';
		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		/**
		 * Filter the DigitalOcean embedding model id.
		 *
		 * @since 1.1.16
		 *
		 * @param string $model Resolved model id.
		 */
		$model = (string) apply_filters( 'wp_mcp_ai_embedding_provider_digitalocean_model', $model );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$api_key  = isset( $settings['digitalocean_api_key'] ) ? $settings['digitalocean_api_key'] : '';
		return ! empty( $api_key ) && class_exists( 'WP_MCP_AI_DigitalOcean_Client' );
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
			return new WP_Error( 'no_api_key', __( 'DigitalOcean model access key is not configured.', 'mcp-ai-wpoos' ) );
		}

		try {
			$response = $this->get_client()->create_embedding(
				array(
					'model' => $this->get_model(),
					'input' => $text,
				)
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

		return new WP_Error( 'invalid_response', __( 'Invalid embedding response from DigitalOcean.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Lazy-initialised DigitalOcean client.
	 *
	 * @return WP_MCP_AI_DigitalOcean_Client
	 */
	private function get_client() {
		if ( null === $this->client ) {
			$this->client = new WP_MCP_AI_DigitalOcean_Client();
		}
		return $this->client;
	}
}
