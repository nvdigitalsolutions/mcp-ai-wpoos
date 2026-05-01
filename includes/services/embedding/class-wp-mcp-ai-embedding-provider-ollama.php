<?php
/**
 * Ollama embedding provider.
 *
 * Local-first embedding backend that POSTs to a self-hosted Ollama instance's
 * `/api/embeddings` endpoint. Enables MemPalace-style "no data leaves the
 * server" memory operation.
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
 * Ollama embedding provider.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Embedding_Provider_Ollama implements WP_MCP_AI_Embedding_Provider_Interface {

	/**
	 * Default embedding model id when none is configured.
	 *
	 * `nomic-embed-text` is widely available, fast, and produces 768-dim
	 * vectors that work well with the existing cosine-similarity scorer.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'nomic-embed-text';

	/**
	 * Endpoint URL override (when supplied to the constructor).
	 *
	 * @var string|null
	 */
	private $endpoint_override;

	/**
	 * Model id override (when supplied to the constructor).
	 *
	 * @var string|null
	 */
	private $model_override;

	/**
	 * Constructor.
	 *
	 * @param string|null $endpoint Optional Ollama base URL override.
	 * @param string|null $model    Optional embedding model override.
	 */
	public function __construct( $endpoint = null, $model = null ) {
		$this->endpoint_override = is_string( $endpoint ) && '' !== $endpoint ? $endpoint : null;
		$this->model_override    = is_string( $model ) && '' !== $model ? $model : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id() {
		return 'ollama';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model() {
		if ( null !== $this->model_override ) {
			return $this->model_override;
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$model    = isset( $settings['ollama_embedding_model'] ) ? (string) $settings['ollama_embedding_model'] : '';
		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		/**
		 * Filter the Ollama embedding model id.
		 *
		 * @since 1.1.0
		 *
		 * @param string $model Resolved model id.
		 */
		$model = (string) apply_filters( 'wp_mcp_ai_embedding_provider_ollama_model', $model );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available() {
		return '' !== $this->get_endpoint_url();
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

		$endpoint = $this->get_endpoint_url();
		if ( '' === $endpoint ) {
			return new WP_Error(
				'ollama_not_configured',
				__( 'Ollama endpoint URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$url = trailingslashit( $endpoint ) . 'api/embeddings';

		/**
		 * Filter the request timeout (seconds) for Ollama embedding calls.
		 *
		 * Local embedding models can be slow on cold starts; the default of 30
		 * seconds is generous on purpose.
		 *
		 * @since 1.1.0
		 *
		 * @param int $timeout Default 30 seconds.
		 */
		$timeout = (int) apply_filters( 'wp_mcp_ai_embedding_provider_ollama_timeout', 30 );

		$body = wp_json_encode(
			array(
				'model'  => $this->get_model(),
				'prompt' => $text,
			)
		);

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => $timeout > 0 ? $timeout : 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'ollama_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by Ollama. */
					__( 'Ollama embeddings endpoint returned HTTP %d.', 'mcp-ai-wpoos' ),
					$code
				)
			);
		}

		$raw = wp_remote_retrieve_body( $response );
		if ( '' === $raw ) {
			return new WP_Error( 'ollama_empty_body', __( 'Ollama returned an empty response body.', 'mcp-ai-wpoos' ) );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ollama_invalid_json', __( 'Ollama returned malformed JSON.', 'mcp-ai-wpoos' ) );
		}

		// Ollama responses use the "embedding" key (singular). Some forks/
		// future versions may use "embeddings" (plural, list-of-vectors): we
		// accept the first element in that case.
		$vector = null;
		if ( isset( $decoded['embedding'] ) && is_array( $decoded['embedding'] ) ) {
			$vector = $decoded['embedding'];
		} elseif ( isset( $decoded['embeddings'][0] ) && is_array( $decoded['embeddings'][0] ) ) {
			$vector = $decoded['embeddings'][0];
		}

		if ( null === $vector || empty( $vector ) ) {
			return new WP_Error(
				'ollama_invalid_response',
				__( 'Ollama response did not include an embedding vector.', 'mcp-ai-wpoos' )
			);
		}

		return array_values( array_map( 'floatval', $vector ) );
	}

	/**
	 * Resolve the configured Ollama base URL.
	 *
	 * Uses the constructor override first, otherwise falls back to the
	 * existing `ollama_endpoint_url` setting that the plugin already exposes
	 * for chat completions.
	 *
	 * @return string Endpoint URL with no trailing slash, or empty string
	 *                when not configured.
	 */
	private function get_endpoint_url() {
		if ( null !== $this->endpoint_override ) {
			return untrailingslashit( esc_url_raw( $this->endpoint_override ) );
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$url      = isset( $settings['ollama_endpoint_url'] ) ? (string) $settings['ollama_endpoint_url'] : '';

		/**
		 * Filter the Ollama endpoint URL used for embedding requests.
		 *
		 * @since 1.1.0
		 *
		 * @param string $url Resolved endpoint URL (may be empty).
		 */
		$url = (string) apply_filters( 'wp_mcp_ai_embedding_provider_ollama_endpoint', $url );
		$url = esc_url_raw( $url );
		return '' !== $url ? untrailingslashit( $url ) : '';
	}
}
