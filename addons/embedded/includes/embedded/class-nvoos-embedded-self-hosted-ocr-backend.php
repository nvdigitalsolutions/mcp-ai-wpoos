<?php
/**
 * Self-Hosted OCR Backend for Embedded Addon.
 *
 * Implements NV_oOS_Embedded_LLM_Backend for Unlimited-OCR and DeepSeek-OCR
 * self-hosted inference. Wraps WP_MCP_AI_Self_Hosted_OCR_Client as an
 * internal implementation detail.
 *
 * @package NV_oOS_Embedded
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-hosted OCR backend for embedded addon.
 *
 * @since 0.3.0
 */
class NV_oOS_Embedded_Self_Hosted_OCR_Backend implements NV_oOS_Embedded_LLM_Backend {

	/**
	 * Internal client instance.
	 *
	 * @since 0.3.0
	 *
	 * @var WP_MCP_AI_Self_Hosted_OCR_Client|null
	 */
	private $client = null;

	/**
	 * Model type for this backend instance.
	 *
	 * @since 0.3.0
	 *
	 * @var string
	 */
	private $model_type;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param string $model_type Model type ('unlimited_ocr' or 'deepseek_ocr').
	 */
	public function __construct( $model_type = 'unlimited_ocr' ) {
		$this->model_type = $model_type;
	}

	/**
	 * Get the internal client, creating if needed.
	 *
	 * @since 0.3.0
	 *
	 * @return WP_MCP_AI_Self_Hosted_OCR_Client|null
	 */
	private function get_client() {
		if ( null === $this->client && class_exists( 'WP_MCP_AI_Self_Hosted_OCR_Client' ) ) {
			$this->client = new WP_MCP_AI_Self_Hosted_OCR_Client();
		}
		return $this->client;
	}

	/**
	 * Get the backend slug.
	 *
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'self_hosted_ocr_' . $this->model_type;
	}

	/**
	 * Get the backend display label.
	 *
	 * @inheritDoc
	 */
	public function get_label() {
		$client = $this->get_client();
		if ( $client ) {
			return sprintf(
				/* translators: %s: model name */
				__( '%s (Self-Hosted OCR)', 'nvoos-embedded' ),
				$client->get_model_label( $this->model_type )
			);
		}
		return __( 'Self-Hosted OCR (vLLM)', 'nvoos-embedded' );
	}

	/**
	 * Get the backend description.
	 *
	 * @inheritDoc
	 */
	public function get_description() {
		return __(
			'Process images and PDFs with state-of-the-art self-hosted OCR models '
			. 'running on your own GPU via vLLM. Documents never leave your server. '
			. 'Requires Docker with NVIDIA GPU and the appropriate vLLM image.',
			'nvoos-embedded'
		);
	}

	/**
	 * Check whether this backend can operate in the current environment.
	 *
	 * @inheritDoc
	 */
	public function is_available() {
		$client = $this->get_client();
		if ( ! $client ) {
			return false;
		}

		$endpoint = $client->get_endpoint_url( $this->model_type );
		if ( empty( $endpoint ) ) {
			return false;
		}

		$result = $client->test_connection( $this->model_type );
		return ! is_wp_error( $result );
	}

	/**
	 * Human-readable requirements list.
	 *
	 * @inheritDoc
	 */
	public function get_requirements() {
		$client   = $this->get_client();
		$endpoint = $client ? $client->get_endpoint_url( $this->model_type ) : '';

		return array(
			array(
				'label'  => __( 'GPU with CUDA 12.9+ support', 'nvoos-embedded' ),
				'status' => true,
				'note'   => __( 'NVIDIA GPU required for vLLM inference.', 'nvoos-embedded' ),
			),
			array(
				'label'  => __( 'Docker installed', 'nvoos-embedded' ),
				'status' => true,
				'note'   => __( 'vLLM runs as a Docker container.', 'nvoos-embedded' ),
			),
			array(
				'label'  => __( 'Endpoint configured', 'nvoos-embedded' ),
				'status' => ! empty( $endpoint ),
				'note'   => empty( $endpoint )
					? __( 'No endpoint URL configured.', 'nvoos-embedded' )
					: sprintf(
						/* translators: %s: endpoint URL */
						__( 'Configured: %s', 'nvoos-embedded' ),
						esc_html( $endpoint )
					),
			),
		);
	}

	/**
	 * Execute a chat completion request (not the primary use case for OCR).
	 *
	 * OCR backends are specialized — they use ocr_document() below.
	 * This method exists to satisfy the interface contract.
	 *
	 * @since 0.3.0
	 *
	 * @param array $messages Chat messages (unused).
	 * @param array $options  Model options (unused).
	 * @inheritDoc
	 */
	public function create_chat_completion( array $messages, array $options ) {
		return new WP_Error(
			'not_supported',
			__( 'This backend is specialized for OCR. Use ocr_document() instead.', 'nvoos-embedded' )
		);
	}

	/**
	 * List models available through this backend.
	 *
	 * @inheritDoc
	 */
	public function get_available_models() {
		$client = $this->get_client();
		if ( ! $client ) {
			return array();
		}

		$defaults = $client->get_model_defaults( $this->model_type );
		if ( ! $defaults ) {
			return array();
		}

		return array(
			array(
				'slug'           => $this->model_type,
				'label'          => $defaults['model_name'],
				'size_mb'        => 6000, // ~6GB for 3B model weights.
				'context_window' => 32768,
				'recommended'    => true,
			),
		);
	}

	/**
	 * Health status for WordPress Site Health integration.
	 *
	 * @inheritDoc
	 */
	public function get_health_status() {
		$available = $this->is_available();

		if ( $available ) {
			return array(
				'status'      => 'good',
				/* translators: %s: model label */
				'label'       => sprintf( __( '%s — Connected', 'nvoos-embedded' ), $this->get_label() ),
				'description' => __( 'The self-hosted OCR backend is available and responding.', 'nvoos-embedded' ),
				'test'        => 'nvoos_embedded_self_hosted_ocr',
			);
		}

		$client   = $this->get_client();
		$endpoint = $client ? $client->get_endpoint_url( $this->model_type ) : '';

		if ( empty( $endpoint ) ) {
			return array(
				'status'      => 'recommended',
				/* translators: %s: model label */
				'label'       => sprintf( __( '%s — Not Configured', 'nvoos-embedded' ), $this->get_label() ),
				'description' => __( 'Self-hosted OCR is not configured. Set an endpoint URL to enable local document processing.', 'nvoos-embedded' ),
				'test'        => 'nvoos_embedded_self_hosted_ocr',
			);
		}

		return array(
			'status'      => 'critical',
			/* translators: %s: model label */
			'label'       => sprintf( __( '%s — Unreachable', 'nvoos-embedded' ), $this->get_label() ),
			'description' => __( 'The configured OCR endpoint is not responding. Check that the vLLM Docker container is running.', 'nvoos-embedded' ),
			'test'        => 'nvoos_embedded_self_hosted_ocr',
		);
	}

	/**
	 * Perform OCR on a document via the self-hosted model.
	 *
	 * This is the primary method for this backend — not create_chat_completion().
	 *
	 * @since 0.3.0
	 *
	 * @param array $args Arguments:
	 *                    - 'image_data' (string)      Base64-encoded image data.
	 *                    - 'image_data_array' (array) Array of base64-encoded images for multi-page.
	 *                    - 'prompt' (string)          Optional OCR prompt.
	 *                    - 'options' (array)          Optional processing options.
	 * @return array|WP_Error OCR result or error.
	 */
	public function ocr_document( array $args ) {
		$client = $this->get_client();
		if ( ! $client ) {
			return new WP_Error(
				'client_unavailable',
				__( 'Self-hosted OCR client is not available.', 'nvoos-embedded' )
			);
		}

		$image_data_array = array();
		$prompt           = isset( $args['prompt'] ) ? sanitize_text_field( $args['prompt'] ) : '';
		$options          = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

		if ( ! empty( $args['image_data_array'] ) && is_array( $args['image_data_array'] ) ) {
			$image_data_array = array_map( 'sanitize_text_field', $args['image_data_array'] );
		} elseif ( ! empty( $args['image_data'] ) ) {
			$image_data_array = array( sanitize_text_field( $args['image_data'] ) );
		} else {
			return new WP_Error(
				'missing_image',
				__( 'No image data provided for OCR.', 'nvoos-embedded' )
			);
		}

		if ( count( $image_data_array ) > 1 ) {
			return $client->ocr_images( $image_data_array, $prompt, $this->model_type, $options );
		}

		return $client->ocr_image( $image_data_array[0], $prompt, $this->model_type, $options );
	}
}
