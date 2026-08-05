<?php
/**
 * Client-Side WebLLM Backend
 *
 * Implements NV_oOS_Embedded_LLM_Backend for browser-side WebLLM inference.
 * This backend does NOT execute inference on the server — it returns
 * configuration that the browser JS client uses to run WebLLM/WebGPU
 * inference client-side.
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client-side WebLLM backend.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Client_Backend implements NV_oOS_Embedded_LLM_Backend {

	/**
	 * Available MLC-compiled models for browser inference.
	 *
	 * @since 0.2.0
	 *
	 * @var array
	 */
	const MODELS = array(
		'Llama-3.2-1B-Instruct-q4f16_1-MLC' => array(
			'label'          => 'Llama 3.2 1B Instruct',
			'size_mb'        => 800,
			'context_window' => 4096,
			'recommended'    => true,
		),
		'Qwen2.5-0.5B-Instruct-q4f16_1-MLC' => array(
			'label'          => 'Qwen2.5 0.5B Instruct',
			'size_mb'        => 400,
			'context_window' => 2048,
		),
		'Qwen2.5-1.5B-Instruct-q4f16_1-MLC' => array(
			'label'          => 'Qwen2.5 1.5B Instruct',
			'size_mb'        => 1000,
			'context_window' => 4096,
		),
		'Llama-3.2-3B-Instruct-q4f16_1-MLC' => array(
			'label'          => 'Llama 3.2 3B Instruct',
			'size_mb'        => 2000,
			'context_window' => 8192,
		),
		'Phi-3.5-mini-instruct-q4f16_1-MLC' => array(
			'label'          => 'Phi-3.5 Mini Instruct',
			'size_mb'        => 2500,
			'context_window' => 4096,
		),
	);

	/**
	 * Get the backend slug.
	 *
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'client_side';
	}

	/**
	 * Get the backend display label.
	 *
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Client-Side WebLLM (Browser)', 'nvoos-embedded' );
	}

	/**
	 * Get the backend description.
	 *
	 * @inheritDoc
	 */
	public function get_description() {
		return __(
			'Runs AI models entirely in the user\'s browser using WebGPU/WebAssembly. '
			. 'Zero server CPU/RAM usage. Works on shared hosting. Requires Chrome 113+, '
			. 'Edge 113+, or Safari 18+. Models auto-download to browser cache on first use.',
			'nvoos-embedded'
		);
	}

	/**
	 * Check if the backend is available.
	 *
	 * @inheritDoc
	 */
	public function is_available() {
		// Client-side has no server requirements — always available.
		return true;
	}

	/**
	 * Get backend requirements list.
	 *
	 * @inheritDoc
	 */
	public function get_requirements() {
		return array(
			'webgpu_browser' => array(
				'label'  => __( 'Browser with WebGPU support', 'nvoos-embedded' ),
				'status' => true,
				'note'   => __( 'Chrome 113+, Edge 113+, Safari 18+. Firefox uses WebAssembly fallback.', 'nvoos-embedded' ),
			),
			'model_download' => array(
				'label'  => __( 'First-use model download', 'nvoos-embedded' ),
				'status' => true,
				'note'   => __( '400MB-2.5GB download on first use per browser. Subsequent uses load from IndexedDB cache.', 'nvoos-embedded' ),
			),
		);
	}

	/**
	 * Execute a chat completion (returns config for browser JS).
	 *
	 * @inheritDoc
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options ) {
		// Client-side doesn't execute inference on server.
		// Returns configuration for the browser JS client.
		$settings = get_option( 'nvoos_embedded_settings', array() );
		$model    = isset( $options['model'] ) ? $options['model'] : ( isset( $settings['client_model'] ) ? $settings['client_model'] : 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );

		return array(
			'backend'     => 'client_side',
			'model'       => $model,
			'cdn_url'     => 'https://cdn.jsdelivr.net/npm/@mlc-ai/web-llm@latest/dist/web-llm.min.js',
			'stream'      => ! empty( $options['stream'] ),
			'max_tokens'  => isset( $options['max_tokens'] ) ? $options['max_tokens'] : 512,
			'temperature' => isset( $options['temperature'] ) ? $options['temperature'] : 0.7,
		);
	}

	/**
	 * Get available models for this backend.
	 *
	 * @inheritDoc
	 */
	public function get_available_models() {
		return self::MODELS;
	}

	/**
	 * Get health status for Site Health integration.
	 *
	 * @inheritDoc
	 */
	public function get_health_status() {
		return array(
			'status'      => 'good',
			'label'       => __( 'Client-Side WebLLM Backend', 'nvoos-embedded' ),
			'description' => __( 'No server requirements. Works on any hosting.', 'nvoos-embedded' ),
			'test'        => array(
				'label'       => __( 'Client-side WebLLM availability', 'nvoos-embedded' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Always available', 'nvoos-embedded' ),
					'color' => 'green',
				),
				'description' => '<p>' . esc_html__( 'Runs in the user browser — no server configuration needed.', 'nvoos-embedded' ) . '</p>',
				'test'        => 'nvoos_embedded_client_backend',
			),
		);
	}
}
