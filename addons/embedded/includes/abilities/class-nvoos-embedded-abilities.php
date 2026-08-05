<?php
/**
 * Embedded Addon — Abilities Registration
 *
 * Registers all embedded addon operations as WordPress Abilities
 * for AI agent discoverability via the MCP Adapter.
 *
 * WordPress 6.9+ is required for wp_register_ability(). All registrations
 * are guarded by function_exists() for backward compatibility.
 *
 * Abilities registered:
 *   - nvoos-embedded/transcribe-audio      STT transcription (MCP public)
 *   - nvoos-embedded/get-llm-backends      Backend listing (MCP public)
 *   - nvoos-embedded/get-model-list        Model listing (MCP public)
 *   - nvoos-embedded/get-stt-config        STT backend config (MCP public)
 *   - nvoos-embedded/analyze-image         Vision model analysis (MCP public, v1.3.0)
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities registrar for the embedded addon.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Abilities {

	/**
	 * Prefix for ability categories.
	 *
	 * @var string
	 */
	const CATEGORY_VOICE     = 'nvoos-embedded-voice';
	const CATEGORY_INFERENCE = 'nvoos-embedded-inference';
	const CATEGORY_VISION    = 'nvoos-embedded-vision';

	/**
	 * Register all abilities on wp_abilities_api_init.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register embedded addon abilities.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_transcribe_ability();
		self::register_backend_list_ability();
		self::register_model_list_ability();
		self::register_stt_config_ability();
		self::register_analyze_image_ability();
	}

	// ── Voice / STT ────────────────────────────────────────────────────

	/**
	 * Register transcribe-audio ability.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private static function register_transcribe_ability() {
		wp_register_ability(
			'nvoos-embedded/transcribe-audio',
			array(
				'label'               => __( 'Transcribe Audio', 'nvoos-embedded' ),
				'description'         => __( 'Converts speech audio to text using the configured STT backend. Accepts base64-encoded WAV audio data up to 10MB.', 'nvoos-embedded' ),
				'category'            => self::CATEGORY_VOICE,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'audio'        => array(
							'type'        => 'string',
							'description' => __( 'Base64-encoded WAV audio data (max 10MB).', 'nvoos-embedded' ),
						),
						'language'     => array(
							'type'        => 'string',
							'description' => __( 'ISO 639-1 language code.', 'nvoos-embedded' ),
							'default'     => 'en',
						),
						'unified_mode' => array(
							'type'        => 'boolean',
							'description' => __( 'Use unified STT+LLM mode (Gemma 4 only).', 'nvoos-embedded' ),
							'default'     => false,
						),
					),
					'required'   => array( 'audio' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'text'     => array( 'type' => 'string' ),
						'language' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => function () {
					return is_user_logged_in()
						|| apply_filters( 'nvoos_embedded_allow_guest_transcribe', false );
				},
				'execute_callback'    => function ( $input ) {
					if ( ! class_exists( 'WP_MCP_AI_Embedded_Transcribe' ) ) {
						return new WP_Error(
							'transcriber_unavailable',
							__( 'Transcription service is not available.', 'nvoos-embedded' )
						);
					}

					$transcriber = new WP_MCP_AI_Embedded_Transcribe();
					return $transcriber->transcribe(
						$input['audio'],
						array(
							'language'     => isset( $input['language'] ) ? sanitize_text_field( $input['language'] ) : 'en',
							'unified_mode' => ! empty( $input['unified_mode'] ),
						)
					);
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Register STT configuration listing ability.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private static function register_stt_config_ability() {
		wp_register_ability(
			'nvoos-embedded/get-stt-config',
			array(
				'label'               => __( 'Get STT Configuration', 'nvoos-embedded' ),
				'description'         => __( 'Returns the current speech-to-text configuration including available backends and active settings.', 'nvoos-embedded' ),
				'category'            => self::CATEGORY_VOICE,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active_backend' => array( 'type' => 'string' ),
						'stt_model'      => array( 'type' => 'string' ),
						'vad_threshold'  => array( 'type' => 'number' ),
						'voice_enabled'  => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => function () {
					$settings = get_option( 'nvoos_embedded_settings', array() );

					return array(
						'active_backend' => isset( $settings['stt_backend'] ) ? sanitize_key( $settings['stt_backend'] ) : 'whisper_cpp_wasm',
						'stt_model'      => isset( $settings['stt_model'] ) ? sanitize_text_field( $settings['stt_model'] ) : 'tiny.en',
						'vad_threshold'  => isset( $settings['vad_threshold'] ) ? (float) $settings['vad_threshold'] : 0.5,
						'voice_enabled'  => ! empty( $settings['enable_voice_mode'] ),
					);
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	// ── Inference Backends ─────────────────────────────────────────────

	/**
	 * Register LLM backend listing ability.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private static function register_backend_list_ability() {
		wp_register_ability(
			'nvoos-embedded/get-llm-backends',
			array(
				'label'               => __( 'Get LLM Backends', 'nvoos-embedded' ),
				'description'         => __( 'Lists available embedded LLM inference backends, their availability status, and which backend is currently active.', 'nvoos-embedded' ),
				'category'            => self::CATEGORY_INFERENCE,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'backends' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'        => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'available'   => array( 'type' => 'boolean' ),
									'description' => array( 'type' => 'string' ),
								),
							),
						),
						'active'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => function () {
					if ( ! class_exists( 'NV_oOS_Embedded_Backend_Registry' ) ) {
						return array(
							'backends' => array(),
							'active'   => null,
						);
					}

					$registry = NV_oOS_Embedded_Backend_Registry::get_instance();
					$backends = array();

					foreach ( $registry->get_all_llm_backends() as $slug => $backend ) {
						$backends[] = array(
							'slug'        => $slug,
							'label'       => $backend->get_label(),
							'available'   => $backend->is_available(),
							'description' => $backend->get_description(),
						);
					}

					$active_backend = $registry->get_active_llm_backend();

					return array(
						'backends' => $backends,
						'active'   => $active_backend ? $active_backend->get_slug() : null,
					);
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Register model listing ability.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private static function register_model_list_ability() {
		wp_register_ability(
			'nvoos-embedded/get-model-list',
			array(
				'label'               => __( 'Get Available Models', 'nvoos-embedded' ),
				'description'         => __( 'Lists all available embedded LLM and vision models with size and context window information.', 'nvoos-embedded' ),
				'category'            => self::CATEGORY_INFERENCE,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'backend' => array(
							'type'        => 'string',
							'description' => __( 'Backend slug to query. Omit for all backends.', 'nvoos-embedded' ),
							'default'     => '',
						),
						'type'    => array(
							'type'        => 'string',
							'description' => __( 'Model type filter: llm, vision, or empty for all.', 'nvoos-embedded' ),
							'default'     => '',
							'enum'        => array( '', 'llm', 'vision' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'models' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'           => array( 'type' => 'string' ),
									'label'          => array( 'type' => 'string' ),
									'size_mb'        => array( 'type' => 'integer' ),
									'context_window' => array( 'type' => 'integer' ),
									'backend'        => array( 'type' => 'string' ),
									'type'           => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => function ( $input ) {
					if ( ! class_exists( 'NV_oOS_Embedded_Backend_Registry' ) ) {
						return array( 'models' => array() );
					}

					$registry     = NV_oOS_Embedded_Backend_Registry::get_instance();
					$backend_slug = isset( $input['backend'] ) ? sanitize_key( $input['backend'] ) : '';
					$model_type   = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : '';
					$all_models   = array();

					$collect_models = function ( $backend, $backend_slug, $model_type ) use ( &$all_models ) {
						foreach ( $backend->get_available_models() as $slug => $def ) {
							$model_type_value = isset( $def['type'] ) ? $def['type'] : 'llm';

							if ( ! empty( $model_type ) && $model_type !== $model_type_value ) {
								continue;
							}

							$all_models[] = array_merge(
								$def,
								array(
									'slug'    => $slug,
									'backend' => $backend_slug,
									'type'    => $model_type_value,
								)
							);
						}
					};

					if ( ! empty( $backend_slug ) ) {
						$backend = $registry->get_llm_backend( $backend_slug );
						if ( $backend ) {
							$collect_models( $backend, $backend_slug, $model_type );
						}
					} else {
						foreach ( $registry->get_all_llm_backends() as $slug => $backend ) {
							$collect_models( $backend, $slug, $model_type );
						}
					}

					return array( 'models' => $all_models );
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	// ── Vision / Multi-Modal ───────────────────────────────────────────

	/**
	 * Register analyze-image ability (v1.3.0).
	 *
	 * Provides browser-side AI image analysis using vision-capable WebLLM
	 * models (LLaVA, Qwen2-VL). The analysis runs entirely in the user's
	 * browser — no image data is sent to any server.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private static function register_analyze_image_ability() {
		$settings = get_option( 'nvoos_embedded_settings', array() );

		wp_register_ability(
			'nvoos-embedded/analyze-image',
			array(
				'label'               => __( 'Analyze Image', 'nvoos-embedded' ),
				'description'         => __(
					'Analyzes an image using a browser-side vision AI model. '
					. 'Supports visual question answering, OCR, object recognition, '
					. 'and scene description. Runs entirely in the browser — no image '
					. 'data leaves the device. Requires a vision-capable model '
					. '(LLaVA 1.5 or Qwen2-VL) to be loaded.',
					'nvoos-embedded'
				),
				'category'            => self::CATEGORY_VISION,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'image'    => array(
							'type'        => 'string',
							'description' => __( 'Image as a base64-encoded data URI or URL.', 'nvoos-embedded' ),
						),
						'question' => array(
							'type'        => 'string',
							'description' => __( 'Question about the image. Omit for general description.', 'nvoos-embedded' ),
							'default'     => 'Describe this image in detail.',
						),
						'model'    => array(
							'type'        => 'string',
							'description' => __( 'Vision model to use.', 'nvoos-embedded' ),
							'enum'        => array( 'LLaVA-1.5-7B-q4f16_1-MLC', 'Qwen2-VL-2B-Instruct-q4f16_1-MLC' ),
							'default'     => 'Qwen2-VL-2B-Instruct-q4f16_1-MLC',
						),
					),
					'required'   => array( 'image' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'description' => array( 'type' => 'string' ),
						'model_used'  => array( 'type' => 'string' ),
						'client_side' => array(
							'type'        => 'boolean',
							'description' => __( 'Always true — analysis runs in the browser.', 'nvoos-embedded' ),
						),
					),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => function ( $input ) {
					// This ability returns configuration for the browser JS client.
					// Actual inference happens client-side via WebLLM.
					$model = isset( $input['model'] ) ? sanitize_key( $input['model'] ) : 'Qwen2-VL-2B-Instruct-q4f16_1-MLC';

					return array(
						'description' => sprintf(
							/* translators: %s: vision model name */
							__( 'Image analysis will run in the browser using %s. Send the image as a base64 data URI with your question to the WebLLM vision pipeline.', 'nvoos-embedded' ),
							$model
						),
						'model_used'  => $model,
						'client_side' => true,
					);
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}
}
