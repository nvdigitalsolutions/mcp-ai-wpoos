<?php
/**
 * Cloudflare AI Text-to-Image tool (D8 Cluster 2c-5 port of the base
 * plugin's WP_MCP_AI_Tool_Generate_CloudflareAI_Image — byte-identical
 * slug, schema, error codes, envelope, request payload, and response
 * parsing; per-mode seams for settings, logging, media URLs, and the
 * Node.js/Media-Worker vectorization path).
 *
 * Seams:
 *  - Settings: base WP_MCP_AI_Admin_Settings in monolith installs; the
 *    Content Graph settings store (ai_api_key_cloudflare +
 *    cloudflare_account_id) standalone.
 *  - Media URLs: base WP_MCP_AI_Media_URL_Utils in monolith installs;
 *    identical wp_upload_bits()/wp_get_attachment_url() preference
 *    standalone.
 *  - The HTTP call itself uses wp_remote_post exactly as the base
 *    client does (same URL, headers, payload, and response parsing).
 *  - SVG vectorization requires Node.js or the Media Worker sidecar;
 *    standalone installs without either degrade to the base-identical
 *    "wp_mcp_ai_nodejs_required" error envelope.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Provides a tool for generating images via Cloudflare Workers AI and storing them as attachments.
 */
class CloudflareAITextToImageTool extends AbstractAiTool {

	const DEFAULT_MODEL     = '@cf/stabilityai/stable-diffusion-xl-base-1.0';
	const DEFAULT_WIDTH     = 1024;
	const DEFAULT_HEIGHT    = 1024;
	const DEFAULT_NUM_STEPS = 20;
	const DEFAULT_GUIDANCE  = 7.5;

	/**
	 * Cached sidecar availability flag (base trait contract).
	 *
	 * @var bool|null
	 */
	private $sidecar_available = null;

	public function getSlug(): string {
		return 'cloudflareai_text_to_image';
	}

	public function getName(): string {
		return __( 'Generate Cloudflare AI Image', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Creates an image with Cloudflare Workers AI and stores it in the Media Library.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'The text prompt describing the desired image.', 'nvoos-content-graph-ai' ),
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Cloudflare Workers AI model to use. Examples: @cf/black-forest-labs/flux-2-dev, @cf/leonardo/phoenix-1.0, @cf/stabilityai/stable-diffusion-xl-base-1.0', 'nvoos-content-graph-ai' ),
					'default'     => $defaults['model'],
				),
				'width'         => array(
					'type'        => 'integer',
					'description' => __( 'Width of the generated image in pixels (256-2048).', 'nvoos-content-graph-ai' ),
					'minimum'     => 256,
					'maximum'     => 2048,
					'default'     => $defaults['width'],
				),
				'height'        => array(
					'type'        => 'integer',
					'description' => __( 'Height of the generated image in pixels (256-2048).', 'nvoos-content-graph-ai' ),
					'minimum'     => 256,
					'maximum'     => 2048,
					'default'     => $defaults['height'],
				),
				'num_steps'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of diffusion steps. More steps can improve quality but take longer (1-20).', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => $defaults['num_steps'],
				),
				'guidance'      => array(
					'type'        => 'number',
					'description' => __( 'Guidance scale controls how closely the image follows the prompt. Higher values mean stricter adherence.', 'nvoos-content-graph-ai' ),
					'default'     => $defaults['guidance'],
				),
				'seed'          => array(
					'type'        => 'integer',
					'description' => __( 'Random seed for reproducibility. Use the same seed with the same prompt to get similar results.', 'nvoos-content-graph-ai' ),
				),
				'output_format' => array(
					'type'        => 'string',
					'description' => __( 'Output format for the generated image. Use "svg" to vectorize the raster output. Default is raster format.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'default', 'svg' ),
					'default'     => 'default',
				),
				'file_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'nvoos-content-graph-ai' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Override the Cloudflare request timeout in seconds.', 'nvoos-content-graph-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Shortcut task prompts surfaced to the orchestrator (base-identical).
	 *
	 * @return array
	 */
	public function getShortcutTasks(): array {
		return array(
			array(
				'label'   => __( 'cloudflareai_text_to_image', 'nvoos-content-graph-ai' ),
				'payload' => __( 'cloudflareai_text_to_image', 'nvoos-content-graph-ai' ),
			),
			array(
				'label'   => __( 'Generate product visualization', 'nvoos-content-graph-ai' ),
				'payload' => __( 'Use the `cloudflareai_text_to_image` tool to create a product visualization. Gather details about the product, setting, lighting, and camera angle, then assemble a detailed prompt.', 'nvoos-content-graph-ai' ),
			),
			array(
				'label'   => __( 'Create blog post hero image', 'nvoos-content-graph-ai' ),
				'payload' => __( 'Use the `cloudflareai_text_to_image` tool to generate a hero image for a blog post. Ask about the blog post topic and tone, then create a relevant, eye-catching image.', 'nvoos-content-graph-ai' ),
			),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array(
			'requires-credentials', // Requires Cloudflare API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates media files.
			'async',                // May take significant time to generate images.
			'rate-limited',         // Subject to Cloudflare rate limits.
			'requires-model',       // Requires image model specification.
			'model-dependent',      // Output quality varies by model.
		);
	}

	/**
	 * Model requirements surfaced to the orchestrator (base-identical).
	 *
	 * @return array
	 */
	public function getModelRequirements(): array {
		return array(
			'image-generation', // Requires model capable of generating images.
		);
	}

	/**
	 * Tool rules surfaced to the orchestrator (base-identical).
	 *
	 * @return array
	 */
	public function getToolRules(): array {
		return array(
			'model_requirements'    => array(
				'providers' => array( 'cloudflare' ),
				'models'    => array(
					'@cf/stabilityai/stable-diffusion-xl-base-1.0',
					'@cf/bytedance/stable-diffusion-xl-lightning',
					'@cf/black-forest-labs/flux-1-schnell',
					'@cf/black-forest-labs/flux-2-dev',
					'@cf/leonardo/lucid-origin',
					'@cf/leonardo/phoenix-1.0',
					'@cf/lykon/dreamshaper-8-lcm',
				),
				'required'  => false,
			),
			'parameter_constraints' => array(
				'required_fields'   => array( 'prompt' ),
				'optional_fields'   => array( 'model', 'width', 'height', 'num_steps', 'guidance', 'seed', 'file_name', 'output_format', 'timeout' ),
				'max_prompt_length' => 4000,
			),
			'rate_limits'           => array(
				'requests_per_minute' => 15,
				'requests_per_hour'   => 100,
				'concurrent_requests' => 2,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 60,
				'max_execution_time'  => 120,
			),
			'response_constraints'  => array(
				'max_size'           => 5242880, // 5MB typical image size.
				'supports_streaming' => false,
			),
			'dependencies'          => array(
				'required_settings'   => array(
					'api_token'  => 'cloudflare_api_token',
					'account_id' => 'cloudflare_account_id',
				),
				'required_extensions' => array( 'gd' ), // For image processing.
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,
				'requires_lock'    => false,
				'cache_ttl'        => 0, // Don't cache - each generation unique.
				'retry_strategy'   => 'exponential_backoff',
				'max_retries'      => 3,
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate images.', 'nvoos-content-graph-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate images.', 'nvoos-content-graph-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
			}
		}

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new \WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied for the image request.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
		}

		$defaults = $this->get_configured_defaults();

		// Process parameters.
		$model     = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		$width     = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : $defaults['width'];
		$height    = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : $defaults['height'];
		$num_steps = isset( $arguments['num_steps'] ) ? absint( $arguments['num_steps'] ) : $defaults['num_steps'];
		$guidance  = isset( $arguments['guidance'] ) ? (float) $arguments['guidance'] : $defaults['guidance'];
		$seed      = isset( $arguments['seed'] ) ? absint( $arguments['seed'] ) : null;
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$timeout   = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 0;

		// Validate and clamp values.
		$width     = max( 256, min( 2048, $width ) );
		$height    = max( 256, min( 2048, $height ) );
		$num_steps = max( 1, min( 20, $num_steps ) );

		$options = array(
			'model'     => $model,
			'width'     => $width,
			'height'    => $height,
			'num_steps' => $num_steps,
			'guidance'  => $guidance,
		);

		if ( null !== $seed ) {
			$options['seed'] = $seed;
		}

		if ( $timeout > 0 ) {
			$options['timeout'] = max( 5, min( 300, $timeout ) );
		}

		$image = $this->generate_cloudflare_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Cloudflare Workers AI returned an empty image response.', 'nvoos-content-graph-ai' ) );
		}

		$storage = $this->store_image_attachment( $image, $file_name, $prompt, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Check if SVG output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'default';

		if ( 'svg' === $output_format ) {
			// Convert the generated raster image to SVG.
			$svg_storage = $this->convert_to_svg( $storage, $arguments );

			if ( is_wp_error( $svg_storage ) ) {
				// If SVG conversion fails, return the original raster image.
				// Reproduce the base tool's three-argument log_error() call.
				if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
					\WP_MCP_AI_Logger::log_error(
						'cloudflareai_svg_conversion_failed',
						'Failed to convert Cloudflare AI-generated image to SVG',
						array(
							'error'         => $svg_storage->get_error_message(),
							'attachment_id' => $storage['attachment_id'],
						)
					);
				}
			} else {
				// Replace storage with SVG version.
				$storage = $svg_storage;
			}
		}

		// Build descriptive text message for the LLM and chat UI.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: attachment ID */
			__( 'Successfully generated image (ID: %d).', 'nvoos-content-graph-ai' ),
			$storage['attachment_id']
		);

		$text_parts[] = sprintf(
			/* translators: 1: width, 2: height, 3: num_steps */
			__( 'Size: %1$dx%2$d, Steps: %3$d', 'nvoos-content-graph-ai' ),
			$width,
			$height,
			$num_steps
		);

		if ( null !== $seed ) {
			$text_parts[] = sprintf(
				/* translators: %d: seed value */
				__( 'Seed: %d', 'nvoos-content-graph-ai' ),
				$seed
			);
		}

		$text    = implode( ' ', $text_parts );
		$message = $text;

		$result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'download_url'  => isset( $storage['download_url'] ) && '' !== $storage['download_url'] ? $storage['download_url'] : $storage['url'],
			'file_path'     => $storage['file'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'format'        => isset( $image['format'] ) ? $image['format'] : 'png',
			'width'         => $width,
			'height'        => $height,
			'num_steps'     => $num_steps,
			'guidance'      => $guidance,
			'model'         => $model,
			'provider'      => 'cloudflare',
			'created'       => isset( $image['created'] ) ? $image['created'] : time(), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Byte-identical port of the base tool's wall-clock semantics.
			'text'          => $text,
			'message'       => $message,
		);

		if ( null !== $seed ) {
			$result['seed'] = $seed;
		}

		// Add usage metadata if available (Cloudflare typically doesn't return this).
		if ( isset( $image['usage'] ) && is_array( $image['usage'] ) ) {
			$result['usage'] = $image['usage'];
		}

		/**
		 * Allow third parties to filter the Cloudflare AI image generation result before it is returned.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_cloudflareai_image_result', $result, $arguments, $context );

		// Add rendered image HTML to the response for display in chat UI.
		$result = $this->add_image_html_to_response( $result );

		return $result;
	}

	/**
	 * Resolve the settings array (per-install-mode seam).
	 *
	 * @return array
	 */
	private function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$all = CoreBridge::instance()->settings->all();
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Retrieve the configured defaults for image generation.
	 *
	 * Reads the base settings keys (cloudflare_image_*), which standalone
	 * installs may also provide through the Content Graph settings store.
	 *
	 * @return array
	 */
	private function get_configured_defaults() {
		$defaults = array(
			'model'     => self::DEFAULT_MODEL,
			'width'     => self::DEFAULT_WIDTH,
			'height'    => self::DEFAULT_HEIGHT,
			'num_steps' => self::DEFAULT_NUM_STEPS,
			'guidance'  => self::DEFAULT_GUIDANCE,
		);

		$settings = $this->get_settings();

		if ( ! empty( $settings['cloudflare_image_model'] ) ) {
			$defaults['model'] = sanitize_text_field( $settings['cloudflare_image_model'] );
		}

		if ( ! empty( $settings['cloudflare_image_width'] ) ) {
			$width = absint( $settings['cloudflare_image_width'] );
			if ( $width >= 256 && $width <= 2048 ) {
				$defaults['width'] = $width;
			}
		}

		if ( ! empty( $settings['cloudflare_image_height'] ) ) {
			$height = absint( $settings['cloudflare_image_height'] );
			if ( $height >= 256 && $height <= 2048 ) {
				$defaults['height'] = $height;
			}
		}

		if ( isset( $settings['cloudflare_image_num_steps'] ) && '' !== $settings['cloudflare_image_num_steps'] ) {
			$num_steps = absint( $settings['cloudflare_image_num_steps'] );
			if ( $num_steps >= 1 && $num_steps <= 20 ) {
				$defaults['num_steps'] = $num_steps;
			}
		}

		if ( isset( $settings['cloudflare_image_guidance'] ) && '' !== $settings['cloudflare_image_guidance'] ) {
			$guidance = (float) $settings['cloudflare_image_guidance'];
			if ( $guidance >= 1.0 && $guidance <= 20.0 ) {
				$defaults['guidance'] = $guidance;
			}
		}

		return $defaults;
	}

	/**
	 * Resolve the Cloudflare credentials (per-install-mode seam).
	 *
	 * Monolith installs read cloudflare_api_token from the base settings;
	 * standalone installs resolve ai_api_key_cloudflare through the
	 * Content Graph credential resolver plus cloudflare_account_id.
	 *
	 * @return array
	 */
	private function get_cloudflare_credentials() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = $this->get_settings();

			return array(
				'api_token'  => isset( $settings['cloudflare_api_token'] ) ? $settings['cloudflare_api_token'] : '',
				'account_id' => isset( $settings['cloudflare_account_id'] ) ? $settings['cloudflare_account_id'] : '',
			);
		}

		$store = CoreBridge::instance()->settings;
		$token = $store->getApiKey( 'cloudflare' );

		return array(
			'api_token'  => is_string( $token ) ? $token : '',
			'account_id' => (string) $store->get( 'cloudflare_account_id', '' ),
		);
	}

	/**
	 * Generate an image using Cloudflare Workers AI (inline port of the
	 * base plugin's WP_MCP_AI_Cloudflare_Client::generate_image() —
	 * identical URL, headers, payload, and response parsing).
	 *
	 * @param string $prompt  Text prompt for image generation.
	 * @param array  $options Optional parameters (model, width, height, num_steps, guidance, seed, timeout).
	 * @return array|\WP_Error Image data array or error.
	 */
	private function generate_cloudflare_image( $prompt, array $options = array() ) {
		$credentials = $this->get_cloudflare_credentials();
		$api_token   = $credentials['api_token'];
		$account_id  = $credentials['account_id'];

		if ( empty( $api_token ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_cloudflare_api_token',
				__( 'No Cloudflare API token has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_cloudflare_api_token' => __( 'Add a Cloudflare API token in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		if ( empty( $account_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_cloudflare_account_id',
				__( 'No Cloudflare account ID has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_cloudflare_account_id' => __( 'Add a Cloudflare account ID in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$settings = $this->get_settings();
		$model    = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : ( isset( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '' );

		if ( empty( $model ) ) {
			// Default to stable-diffusion-xl-base-1.0 if no model is configured.
			$model = '@cf/stabilityai/stable-diffusion-xl-base-1.0';
		}

		// Validate model ID format.
		if ( ! preg_match( '/^@[a-zA-Z0-9\/_.-]+$/', $model ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_model_id',
				__( 'Invalid Cloudflare model ID format.', 'nvoos-content-graph-ai' ),
				array( 'model' => $model )
			);
		}

		// Build request payload.
		$payload = array(
			'prompt' => sanitize_textarea_field( $prompt ),
		);

		// Add optional parameters if provided.
		if ( isset( $options['width'] ) && is_numeric( $options['width'] ) ) {
			$payload['width'] = max( 256, min( 2048, absint( $options['width'] ) ) );
		}

		if ( isset( $options['height'] ) && is_numeric( $options['height'] ) ) {
			$payload['height'] = max( 256, min( 2048, absint( $options['height'] ) ) );
		}

		if ( isset( $options['num_steps'] ) && is_numeric( $options['num_steps'] ) ) {
			$payload['num_steps'] = max( 1, min( 20, absint( $options['num_steps'] ) ) );
		}

		if ( isset( $options['guidance'] ) && is_numeric( $options['guidance'] ) ) {
			$payload['guidance'] = (float) $options['guidance'];
		}

		if ( isset( $options['seed'] ) && is_numeric( $options['seed'] ) ) {
			$payload['seed'] = absint( $options['seed'] );
		}

		// Escape model for URL path (preserve forward slashes).
		$escaped_model = str_replace( array( '@', ' ' ), array( '%40', '%20' ), $model );

		$url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
			rawurlencode( $account_id ),
			$escaped_model
		);

		$timeout = isset( $options['timeout'] ) && $options['timeout'] > 0 ? absint( $options['timeout'] ) : 60;

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $timeout,
		);

		$this->log_event(
			'cloudflare_image_request',
			'Sending image generation request to Cloudflare Workers AI.',
			array(
				'model'  => $model,
				'width'  => isset( $payload['width'] ) ? $payload['width'] : 'default',
				'height' => isset( $payload['height'] ) ? $payload['height'] : 'default',
			)
		);

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			// Reproduce the base client's three-argument log_error() call.
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'cloudflare_image_error',
					'Cloudflare Workers AI image generation failed.',
					array( 'error' => $response->get_error_message() )
				);
			}

			return $this->prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'Cloudflare Workers AI image generation request failed.', 'nvoos-content-graph-ai' ),
				__( 'Cloudflare Workers AI', 'nvoos-content-graph-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			// Parse Cloudflare error response.
			$error_message = __( 'Cloudflare Workers AI returned an error.', 'nvoos-content-graph-ai' );
			$decoded_body  = json_decode( $body, true );

			if ( is_array( $decoded_body ) && isset( $decoded_body['errors'] ) && is_array( $decoded_body['errors'] ) ) {
				foreach ( $decoded_body['errors'] as $error ) {
					if ( isset( $error['message'] ) ) {
						$error_message .= ' ' . sanitize_text_field( $error['message'] );
						if ( isset( $error['code'] ) ) {
							$error_message .= ' (Code: ' . absint( $error['code'] ) . ')';
						}
						break;
					}
				}
			}

			// Reproduce the base client's three-argument log_error() call.
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					'cloudflare_image_error',
					'Cloudflare Workers AI returned an error.',
					array(
						'status' => $code,
						'body'   => $body,
					)
				);
			}

			return new \WP_Error(
				'wp_mcp_ai_api_error',
				$error_message,
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		// Cloudflare can return either binary image data or JSON with base64 encoded image.
		// Check content type to determine format.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( false !== strpos( $content_type, 'application/json' ) ) {
			// JSON response with base64 encoded image.
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid JSON response from Cloudflare Workers AI.', 'nvoos-content-graph-ai' ),
					array( 'body' => $body )
				);
			}

			if ( ! isset( $decoded['result'] ) || ! isset( $decoded['result']['image'] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Unexpected response format from Cloudflare Workers AI.', 'nvoos-content-graph-ai' ),
					array( 'decoded' => $decoded )
				);
			}

			// Base64 encoded image data.
			$image_data = base64_decode( $decoded['result']['image'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary image data from Cloudflare Workers AI API response.

			if ( false === $image_data || '' === $image_data ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_image',
					__( 'Failed to decode base64 image data from Cloudflare Workers AI.', 'nvoos-content-graph-ai' )
				);
			}

			return array(
				'image'     => $image_data,
				'format'    => 'png', // Most Cloudflare models return PNG.
				'mime_type' => 'image/png',
				'model'     => $model,
				'created'   => time(), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Byte-identical port of the base client's wall-clock semantics.
				'bytes'     => strlen( $image_data ),
				'width'     => isset( $payload['width'] ) ? $payload['width'] : null,
				'height'    => isset( $payload['height'] ) ? $payload['height'] : null,
				'num_steps' => isset( $payload['num_steps'] ) ? $payload['num_steps'] : null,
				'provider'  => 'cloudflare',
			);
		} else {
			// Binary image data (PNG, JPEG, etc.).
			$image_data = $body;

			if ( '' === $image_data ) {
				return new \WP_Error(
					'wp_mcp_ai_empty_image',
					__( 'Cloudflare Workers AI returned an empty image.', 'nvoos-content-graph-ai' )
				);
			}

			// Detect image format from binary data.
			$format    = 'png'; // Default.
			$mime_type = 'image/png';

			// Check for PNG signature.
			if ( 0 === strpos( $image_data, "\x89PNG" ) ) {
				$format    = 'png';
				$mime_type = 'image/png';
			} elseif ( 0 === strpos( $image_data, "\xFF\xD8\xFF" ) ) {
				// Check for JPEG signature.
				$format    = 'jpeg';
				$mime_type = 'image/jpeg';
			} elseif ( 0 === strpos( $image_data, 'RIFF' ) && false !== strpos( substr( $image_data, 0, 12 ), 'WEBP' ) ) {
				// Check for WebP signature.
				$format    = 'webp';
				$mime_type = 'image/webp';
			}

			return array(
				'image'     => $image_data,
				'format'    => $format,
				'mime_type' => $mime_type,
				'model'     => $model,
				'created'   => time(), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Byte-identical port of the base client's wall-clock semantics.
				'bytes'     => strlen( $image_data ),
				'width'     => isset( $payload['width'] ) ? $payload['width'] : null,
				'height'    => isset( $payload['height'] ) ? $payload['height'] : null,
				'num_steps' => isset( $payload['num_steps'] ) ? $payload['num_steps'] : null,
				'provider'  => 'cloudflare',
			);
		}
	}

	/**
	 * Prepare a transport error (per-mode seam; standalone replication of
	 * the base WP_MCP_AI_HTTP::prepare_transport_error envelope).
	 *
	 * @param \WP_Error $transport_error Transport-level error.
	 * @param string    $default_code    Fallback error code.
	 * @param string    $default_message Fallback error message.
	 * @param string    $service_label   Human-readable service name.
	 * @return \WP_Error
	 */
	private function prepare_transport_error( $transport_error, $default_code, $default_message, $service_label = '' ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_HTTP' ) ) {
			return \WP_MCP_AI_HTTP::prepare_transport_error(
				$transport_error,
				$default_code,
				$default_message,
				$service_label
			);
		}

		if ( ! $transport_error instanceof \WP_Error ) {
			return new \WP_Error( $default_code, $default_message );
		}

		return new \WP_Error(
			$default_code,
			$default_message,
			array( 'error' => $transport_error )
		);
	}

	/**
	 * Store the generated image as a WordPress attachment.
	 *
	 * @param array  $image     Response payload from the Cloudflare client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $prompt    Original text prompt.
	 * @param int    $user_id   Acting user ID.
	 * @param array  $context   Optional. Execution context containing parent_job_id.
	 * @return array|\WP_Error
	 */
	private function store_image_attachment( array $image, $file_name, $prompt, $user_id, array $context = array() ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$format    = isset( $image['format'] ) ? sanitize_key( $image['format'] ) : 'png';
		$mime_type = isset( $image['mime_type'] ) ? sanitize_mime_type( $image['mime_type'] ) : 'image/png';

		if ( '' === $data ) {
			return new \WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to store image: no image data provided.', 'nvoos-content-graph-ai' ) );
		}

		// Map format to file extension.
		$extensions = array(
			'png'  => 'png',
			'jpeg' => 'jpg',
			'jpg'  => 'jpg',
			'webp' => 'webp',
		);

		$extension = isset( $extensions[ $format ] ) ? $extensions[ $format ] : 'png';

		// Use job_id for filename if available, otherwise use file_name or default.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'cloudflare-image-%s.%s', $job_id, $extension );
		} else {
			$file_stem = $this->normalise_file_stem( $file_name );
			$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $extension );
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to save the generated image file.', 'nvoos-content-graph-ai' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to write the generated image file to disk.', 'nvoos-content-graph-ai' ) );
		}

		$title = $this->generate_attachment_title( $prompt );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			$this->delete_file_safely( $file_path );

			return new \WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated image as an attachment.', 'nvoos-content-graph-ai' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store Cloudflare response metadata for reference.
		$cloudflare_meta = array(
			'source'          => 'cloudflare',
			'original_prompt' => sanitize_textarea_field( $prompt ),
		);

		if ( ! empty( $image['model'] ) ) {
			$cloudflare_meta['model'] = sanitize_text_field( $image['model'] );
		}

		if ( isset( $image['width'] ) && $image['width'] > 0 ) {
			$cloudflare_meta['width'] = absint( $image['width'] );
		}

		if ( isset( $image['height'] ) && $image['height'] > 0 ) {
			$cloudflare_meta['height'] = absint( $image['height'] );
		}

		if ( isset( $image['num_steps'] ) && $image['num_steps'] > 0 ) {
			$cloudflare_meta['num_steps'] = absint( $image['num_steps'] );
		}

		if ( ! empty( $format ) ) {
			$cloudflare_meta['format'] = sanitize_key( $format );
		}

		// Store job_id if available - allows correlation between job IDs and files.
		if ( ! empty( $job_id ) ) {
			$cloudflare_meta['job_id'] = $job_id;
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_cloudflare_image_meta', $cloudflare_meta );

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get local WordPress URL (media URL utility seam).
		$local_url = $this->get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $local_url,
			'download_url'  => $local_url,
			'mime_type'     => $mime_type,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
	}

	/**
	 * Normalise a file stem used for generated attachments.
	 *
	 * @param string $file_name Raw file name input.
	 * @return string
	 */
	private function normalise_file_stem( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return 'cloudflare-image';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			return 'cloudflare-image';
		}

		return $stem;
	}

	/**
	 * Generate a human readable attachment title using the source prompt.
	 *
	 * @param string $prompt Original prompt text.
	 * @return string
	 */
	private function generate_attachment_title( $prompt ) {
		$prompt = (string) $prompt;
		$prompt = preg_replace( '/\s+/', ' ', $prompt );
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return __( 'Cloudflare AI Image', 'nvoos-content-graph-ai' );
		}

		$excerpt = wp_trim_words( $prompt, 12, '…' );

		/* translators: %s: Short excerpt of the prompt used to generate an image. */
		return sprintf( __( 'Cloudflare AI Image: %s', 'nvoos-content-graph-ai' ), $excerpt );
	}

	/**
	 * Delete a generated file from disk safely when an error occurs.
	 *
	 * @param string $file_path Absolute file path.
	 * @return void
	 */
	private function delete_file_safely( $file_path ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_delete_file( $file_path );
	}

	/**
	 * Get the local WordPress URL for an uploaded file (per-mode seam).
	 *
	 * Monolith installs delegate to the base WP_MCP_AI_Media_URL_Utils;
	 * standalone installs replicate its wp_upload_bits()-over-
	 * wp_get_attachment_url() preference inline.
	 *
	 * @param array $upload        Upload result from wp_upload_bits() containing 'url', 'file', 'error'.
	 * @param int   $attachment_id Optional. Attachment ID to fall back to if upload URL not available.
	 * @return string Local WordPress media URL, or empty string if not available.
	 */
	private function get_local_upload_url( $upload, $attachment_id = 0 ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Media_URL_Utils' ) ) {
			return \WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );
		}

		// Prefer the upload URL as it's always the local WordPress URL.
		if ( isset( $upload['url'] ) && '' !== $upload['url'] ) {
			return $upload['url'];
		}

		// Fallback to wp_get_attachment_url if upload URL not available.
		// Note: This may return an external URL if offloading plugins are active.
		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}

		return '';
	}

	/**
	 * Convert a raster image to SVG format using vectorization.
	 *
	 * @param array $storage   Stored raster image data.
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error SVG storage data or error.
	 */
	private function convert_to_svg( array $storage, array $arguments ) {
		// Check if Node.js is available (or the Media Worker sidecar).
		if ( ! $this->is_nodejs_available() && ! $this->is_sidecar_upload_supported() ) {
			return new \WP_Error(
				'wp_mcp_ai_nodejs_required',
				__( 'Node.js is required for SVG vectorization but was not found on the system. Configure the Media Worker sidecar or install Node.js.', 'nvoos-content-graph-ai' )
			);
		}

		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Generated image file not found for SVG conversion.', 'nvoos-content-graph-ai' )
			);
		}

		// Prepare SVG output file.
		$temp_output = wp_tempnam( 'cloudflare-svg-' );
		if ( ! $temp_output ) {
			return new \WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary SVG output file.', 'nvoos-content-graph-ai' ) );
		}

		// Add .svg extension.
		$temp_output_svg = $temp_output . '.svg';
		rename( $temp_output, $temp_output_svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		$temp_output = $temp_output_svg;

		// Prepare vectorization options.
		$vectorization_options = array(
			'colorMode'      => 'color',
			'colorPrecision' => isset( $arguments['color_precision'] ) ? absint( $arguments['color_precision'] ) : 6,
			'filterSpeckle'  => isset( $arguments['filter_speckle'] ) ? absint( $arguments['filter_speckle'] ) : 4,
			'mode'           => isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'spline',
			'hierarchical'   => isset( $arguments['hierarchical'] ) ? sanitize_text_field( $arguments['hierarchical'] ) : 'stacked',
		);

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured or the health check fails).
		$vectorize_result = null;
		if ( $this->is_sidecar_upload_supported() ) {
			$sidecar = $this->sidecar_upload(
				'/api/image/vectorize',
				$file_path,
				array( 'options' => wp_json_encode( $vectorization_options ) ),
				120
			);
			if ( ! is_wp_error( $sidecar ) && ! empty( $sidecar['svg'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the worker-returned SVG into the temp output file consumed by the shared save flow.
				if ( false !== file_put_contents( $temp_output, $sidecar['svg'] ) ) {
					$vectorize_result = array( 'success' => true );
				}
			}
		}

		// Fall back to the local vectorization script.
		if ( null === $vectorize_result ) {
			// Standalone installs have no bundled script: the base-identical
			// "script not found" envelope degrades the SVG path gracefully.
			$script_path = defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_PATH . 'bin/vectorize-image.js' : '';
			$script_args = array(
				$file_path,
				$temp_output,
				wp_json_encode( $vectorization_options ),
			);

			$vectorize_result = $this->execute_nodejs_script(
				$script_path,
				$script_args,
				array(
					'timeout'    => 60,
					'parse_json' => true,
				)
			);
		}

		if ( is_wp_error( $vectorize_result ) ) {
			wp_delete_file( $temp_output );
			return $vectorize_result;
		}

		if ( ! isset( $vectorize_result['success'] ) || ! $vectorize_result['success'] ) {
			wp_delete_file( $temp_output );
			return new \WP_Error(
				'wp_mcp_ai_vectorization_failed',
				isset( $vectorize_result['error'] ) ? $vectorize_result['error'] : __( 'SVG vectorization failed.', 'nvoos-content-graph-ai' )
			);
		}

		// Read SVG file.
		$svg_data = file_get_contents( $temp_output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( false === $svg_data || '' === $svg_data ) {
			wp_delete_file( $temp_output );
			return new \WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read vectorized SVG file.', 'nvoos-content-graph-ai' ) );
		}

		// Cleanup temporary output file.
		wp_delete_file( $temp_output );

		// Save as WordPress attachment.
		$svg_storage = $this->save_svg_as_attachment( $svg_data, $arguments );
		if ( is_wp_error( $svg_storage ) ) {
			return $svg_storage;
		}

		// Add vectorization metadata.
		$svg_storage['vectorized']  = true;
		$svg_storage['svg_size']    = isset( $vectorize_result['output_size'] ) ? $vectorize_result['output_size'] : $svg_storage['bytes'];
		$svg_storage['source_size'] = isset( $vectorize_result['input_size'] ) ? $vectorize_result['input_size'] : $storage['bytes'];
		$svg_storage['duration_ms'] = isset( $vectorize_result['duration_ms'] ) ? $vectorize_result['duration_ms'] : 0;

		return $svg_storage;
	}

	/**
	 * Save SVG data as WordPress attachment.
	 *
	 * @param string $svg_data  SVG file content.
	 * @param array  $arguments Tool arguments for naming.
	 * @return array|\WP_Error Attachment data or error.
	 */
	private function save_svg_as_attachment( $svg_data, array $arguments ) {
		// Generate file name.
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'cloudflare-image';
		if ( empty( $base_name ) ) {
			$base_name = 'cloudflare-image';
		}

		// Remove extension if present.
		$base_name = preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-svg-' . gmdate( 'Ymd-His' ) . '.svg';

		// Upload SVG file.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to save SVG file.', 'nvoos-content-graph-ai' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to write SVG file to disk.', 'nvoos-content-graph-ai' ) );
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sanitize_text_field( __( 'Cloudflare AI SVG Image', 'nvoos-content-graph-ai' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return new \WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register SVG as an attachment.', 'nvoos-content-graph-ai' ), array( 'error' => $attachment_id ) );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get attachment URL using the media URL utility seam.
		$local_url = $this->get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $local_url,
			'download_url'  => $local_url,
			'mime_type'     => 'image/svg+xml',
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => get_the_title( $attachment_id ),
		);
	}

	/**
	 * Execute a Node.js script and return the result (inline port of the
	 * base plugin's WP_MCP_AI_NodeJS_Subprocess trait; per-mode seam).
	 *
	 * @param string $script_path Absolute path to the Node.js script.
	 * @param array  $arguments   Arguments to pass to the script.
	 * @param array  $options     Optional execution options (timeout, working_dir, parse_json).
	 * @return array|\WP_Error Result array on success, WP_Error on failure.
	 */
	private function execute_nodejs_script( $script_path, array $arguments = array(), array $options = array() ) {
		// Validate script path.
		if ( ! file_exists( $script_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_script_not_found',
				sprintf(
					/* translators: %s: script path */
					__( 'Node.js script not found: %s', 'nvoos-content-graph-ai' ),
					$script_path
				)
			);
		}

		// Get Node.js executable path.
		$node_path = $this->get_nodejs_executable();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		// Parse options.
		$timeout     = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30;
		$working_dir = isset( $options['working_dir'] ) ? $options['working_dir'] : dirname( $script_path );
		$parse_json  = isset( $options['parse_json'] ) ? (bool) $options['parse_json'] : true;

		// Build command array.
		$command = array_merge( array( $node_path, $script_path ), $arguments );

		// Per-mode seam: the base Process Service (with timeout
		// enforcement) in monolith installs; a shell-out equivalent
		// standalone (no timeout enforcement).
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( '\WP_MCP_AI\Services\WP_MCP_AI_Process_Service' ) ) {
			$process_options = array( 'timeout' => $timeout );

			if ( $working_dir && is_dir( $working_dir ) ) {
				$process_options['cwd'] = $working_dir;
			}

			$result = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance()->run_silent( $command, $process_options );
		} else {
			$escaped = array_map( 'escapeshellarg', $command );
			$lines   = array();
			$code    = -1;

			exec( implode( ' ', $escaped ) . ' 2>&1', $lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Standalone subprocess fallback; the base plugin routes this through its Process Service.

			$result = array(
				'output'    => trim( implode( "\n", $lines ) ),
				'error'     => '',
				'exit_code' => $code,
				'success'   => 0 === $code,
			);
		}

		// Check for timeout.
		if ( isset( $result['timeout'] ) && $result['timeout'] ) {
			return new \WP_Error(
				'wp_mcp_ai_script_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Node.js script execution timed out after %d seconds.', 'nvoos-content-graph-ai' ),
					$timeout
				)
			);
		}

		$return_code   = $result['exit_code'];
		$output_string = trim( $result['output'] );

		// Check return code.
		if ( 0 !== $return_code ) {
			// Try to parse error as JSON.
			$error_data = null;
			if ( $parse_json ) {
				$decoded = json_decode( $output_string, true );
				if ( null !== $decoded && is_array( $decoded ) && isset( $decoded['error'] ) ) {
					$error_data = $decoded;
				}
			}

			$error_message = $error_data && isset( $error_data['error'] )
				? $error_data['error']
				: $output_string;

			return new \WP_Error(
				'wp_mcp_ai_script_error',
				sprintf(
					/* translators: 1: return code, 2: error message */
					__( 'Node.js script failed with code %1$d: %2$s', 'nvoos-content-graph-ai' ),
					$return_code,
					$error_message
				),
				array(
					'return_code' => $return_code,
					'output'      => $output_string,
					'error_data'  => $error_data,
				)
			);
		}

		// Parse JSON output if requested.
		if ( $parse_json ) {
			$decoded = json_decode( $output_string, true );
			if ( null === $decoded ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_json',
					sprintf(
						/* translators: %s: json error */
						__( 'Failed to parse Node.js script output as JSON: %s', 'nvoos-content-graph-ai' ),
						json_last_error_msg()
					),
					array(
						'output' => $output_string,
					)
				);
			}

			return $decoded;
		}

		// Return raw output.
		return array(
			'output'      => $output_string,
			'return_code' => $return_code,
		);
	}

	/**
	 * Get the path to the Node.js executable.
	 *
	 * @return string|\WP_Error Path to Node.js executable or WP_Error if not found.
	 */
	private function get_nodejs_executable() {
		// Per-mode seam: the base Process Service in monolith installs, a
		// shell-out equivalent standalone.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( '\WP_MCP_AI\Services\WP_MCP_AI_Process_Service' ) ) {
			$which_node = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance()->get_command_path( 'node' );
			if ( $which_node ) {
				return $which_node;
			}
		} else {
			$lookup = stripos( PHP_OS, 'WIN' ) === 0 ? 'where node 2>NUL' : 'which node 2>/dev/null';

			$which_node = trim( (string) shell_exec( $lookup ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Portable PATH lookup for the Node.js binary; the base plugin uses its Process Service here.
			if ( '' !== $which_node ) {
				$paths = explode( "\n", $which_node );
				return trim( $paths[0] );
			}
		}

		// Check for Node.js in common locations.
		$possible_paths = array(
			'/usr/bin/node',
			'/usr/local/bin/node',
			'/opt/homebrew/bin/node', // macOS Homebrew.
		);

		// Check possible paths.
		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}

		// Allow filtering of Node.js path.
		$node_path = apply_filters( 'wp_mcp_ai_nodejs_executable_path', '' );
		if ( ! empty( $node_path ) && file_exists( $node_path ) && is_executable( $node_path ) ) {
			return $node_path;
		}

		return new \WP_Error(
			'wp_mcp_ai_nodejs_not_found',
			__( 'Node.js executable not found. Please ensure Node.js is installed and accessible.', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Check if Node.js is available.
	 *
	 * @return bool True if Node.js is available, false otherwise.
	 */
	private function is_nodejs_available() {
		$node_path = $this->get_nodejs_executable();
		return ! is_wp_error( $node_path );
	}

	/**
	 * Check whether multipart file uploads to the sidecar are possible.
	 *
	 * @return bool True when files can be uploaded to the sidecar.
	 */
	private function is_sidecar_upload_supported() {
		return function_exists( 'curl_file_create' ) && $this->is_sidecar_available();
	}

	/**
	 * Check whether the Media Worker sidecar is reachable.
	 *
	 * @return bool True if the sidecar responded to /api/health.
	 */
	private function is_sidecar_available() {
		if ( null !== $this->sidecar_available ) {
			return $this->sidecar_available;
		}

		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$response = wp_remote_get(
			rtrim( $url, '/' ) . '/api/health',
			array( 'timeout' => 3 )
		);

		if ( is_wp_error( $response ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->sidecar_available = ( 200 === $status && isset( $body['status'] ) && 'ok' === $body['status'] );
		return $this->sidecar_available;
	}

	/**
	 * Get the sidecar URL from the WordPress constant or option.
	 *
	 * @return string Sidecar base URL or empty string.
	 */
	private function get_sidecar_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( WP_MEDIA_WORKER_URL, '/' );
		}

		$option = get_option( 'wp_mcp_ai_media_worker_url', '' );
		return $option ? rtrim( $option, '/' ) : '';
	}

	/**
	 * Get a lightweight site token for sidecar authentication.
	 *
	 * @return string Token string.
	 */
	private function get_sidecar_token() {
		if ( defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN ) {
			return WP_MEDIA_WORKER_TOKEN;
		}

		// Per-blog override (multisite only; never read on single-site).
		if ( is_multisite() ) {
			$blog_token = get_option( 'wp_mcp_ai_media_worker_token_' . get_current_blog_id(), '' );
			if ( ! empty( $blog_token ) ) {
				return $blog_token;
			}
		}

		$token = get_option( 'wp_mcp_ai_media_worker_token', '' );
		if ( ! empty( $token ) ) {
			return $token;
		}

		return wp_hash( home_url() );
	}

	/**
	 * Upload a local file to the sidecar as multipart/form-data (inline
	 * port of the base plugin's WP_MCP_AI_Media_Worker_Client trait).
	 *
	 * @param string $endpoint  API path (e.g. '/api/image/vectorize').
	 * @param string $file_path Local file path.
	 * @param array  $fields    Extra form fields sent alongside the file.
	 * @param int    $timeout   Request timeout in seconds.
	 * @return array|\WP_Error Decoded JSON body or error.
	 */
	private function sidecar_upload( $endpoint, $file_path, $fields = array(), $timeout = 330 ) {
		if ( ! function_exists( 'curl_file_create' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_curl_required',
				__( 'Multipart uploads require the cURL extension.', 'nvoos-content-graph-ai' )
			);
		}
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'File not found.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new \WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'nvoos-content-graph-ai' )
			);
		}

		$filetype = wp_check_filetype( $file_path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$postfields = $fields;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_file_create -- cURL streaming multipart upload; the WordPress HTTP API cannot stream file parts.
		$postfields['file'] = curl_file_create( $file_path, $mime, basename( $file_path ) );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close,Generic.PHP.DeprecatedFunctions.Deprecated -- Streaming multipart upload via cURL; see method docblock (curl_close() deprecation joined for PHP 8.5+ CI).
		$ch = curl_init( rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' ) );
		if ( false === $ch ) {
			return new \WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'nvoos-content-graph-ai' ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $timeout );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		/**
		 * Filter: modify the sidecar upload request before sending.
		 *
		 * @param resource $ch       cURL handle.
		 * @param string   $endpoint The API endpoint path.
		 * @param array    $fields   Extra form fields.
		 */
		$ch = apply_filters( 'wp_mcp_ai_sidecar_upload_handle', $ch, $endpoint, $fields );

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			$this->sidecar_available = false;
			return new \WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		// phpcs:enable

		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new \WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		if ( null === $decoded ) {
			return new \WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'nvoos-content-graph-ai' )
			);
		}

		$this->sidecar_available = true;
		return $decoded;
	}

	/**
	 * Add rendered image HTML to a tool response (inline port of the base
	 * plugin's WP_MCP_AI_Tool_Image_Response trait).
	 *
	 * @param array $result Tool result containing attachment_id and optionally text/message.
	 * @return array Modified result with image HTML added to message field.
	 */
	private function add_image_html_to_response( array $result ) {
		// Check if we have required data.
		if ( empty( $result['attachment_id'] ) ) {
			return $result;
		}

		$attachment_id = absint( $result['attachment_id'] );

		// Verify attachment exists.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $result;
		}

		// Get alt text from various possible sources.
		$alt_text = $this->get_image_alt_text( $result );

		// Get title text.
		$title_text = isset( $result['title'] ) ? $result['title'] : get_the_title( $attachment_id );

		// Generate the image HTML.
		$image_html = $this->generate_image_html( $attachment_id, $alt_text, $title_text );

		// Get existing text message (prefer 'text' over 'message' for base content).
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered image.
		// Use double newline to separate text from image for better readability.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $image_html : $image_html;

		return $result;
	}

	/**
	 * Generate clean, optimized image HTML tag.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $alt_text      Alt text for accessibility.
	 * @param string $title_text    Title text for tooltip.
	 * @return string HTML img tag.
	 */
	private function generate_image_html( $attachment_id, $alt_text = '', $title_text = '' ) {
		// Get image URL at large size (suitable for chat display).
		$image_url = wp_get_attachment_image_url( $attachment_id, 'large' );

		if ( ! $image_url ) {
			// Fallback to full size if large doesn't exist.
			$image_url = wp_get_attachment_url( $attachment_id );
		}

		if ( ! $image_url ) {
			return '';
		}

		// Get image metadata for width/height attributes (improves layout stability).
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$width    = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : '';
		$height   = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : '';

		// If we have large size dimensions, use those instead.
		if ( isset( $metadata['sizes']['large'] ) ) {
			$width  = absint( $metadata['sizes']['large']['width'] );
			$height = absint( $metadata['sizes']['large']['height'] );
		}

		// Build IMG tag with proper escaping.
		$html = '<img src="' . esc_url( $image_url ) . '"';

		if ( ! empty( $alt_text ) ) {
			$html .= ' alt="' . esc_attr( $alt_text ) . '"';
		} else {
			$html .= ' alt=""'; // Empty alt for decorative images per accessibility standards.
		}

		if ( ! empty( $title_text ) ) {
			$html .= ' title="' . esc_attr( $title_text ) . '"';
		}

		if ( ! empty( $width ) ) {
			$html .= ' width="' . $width . '"';
		}

		if ( ! empty( $height ) ) {
			$html .= ' height="' . $height . '"';
		}

		// Add CSS class for styling.
		$html .= ' class="wp-mcp-ai-generated-image"';

		// Add loading="lazy" for performance (images below the fold).
		$html .= ' loading="lazy"';

		$html .= ' />';

		return $html;
	}

	/**
	 * Extract appropriate alt text from result data.
	 *
	 * @param array $result Tool result array.
	 * @return string Alt text.
	 */
	private function get_image_alt_text( array $result ) {
		// Priority order for alt text sources.
		$alt_candidates = array(
			'revised_prompt', // OpenAI/Gemini revised prompts.
			'prompt',         // Original prompt.
			'title',          // Image title.
			'file_name',      // Fallback to filename.
		);

		foreach ( $alt_candidates as $key ) {
			if ( ! empty( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				$alt_text = $result[ $key ];
				return sanitize_text_field( $alt_text );
			}
		}

		return '';
	}

	/**
	 * Sanitize image generation results for LLM consumption.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata and image_url for vision.
	 */
	public function sanitizeForLlm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'attachment_id',
			'url',
			'download_url',
			'file_name',
			'mime_type',
			'bytes',
			'format',
			'width',
			'height',
			'num_steps',
			'guidance',
			'seed',
			'model',
			'provider',
			'text',
			'usage',
			'cost',
			'vectorized',
			'svg_size',
			'source_size',
			'duration_ms',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the generated image in subsequent iterations.
		// Prefer download_url (if available) over url for Cloudflare images.
		$image_url = isset( $result['download_url'] ) && '' !== $result['download_url']
			? $result['download_url']
			: ( isset( $result['url'] ) && '' !== $result['url'] ? $result['url'] : '' );

		if ( '' !== $image_url ) {
			$sanitized['image_url'] = array(
				'url' => $image_url,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * Log an activity event (per-mode seam).
	 *
	 * @param string $type    Event type.
	 * @param string $message Event message.
	 * @param array  $data    Event context.
	 * @return void
	 */
	private function log_event( $type, $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $type, $message, $data );
		}
	}

	/**
	 * Log an error event (per-mode seam).
	 *
	 * @param string $message Error message.
	 * @param array  $data    Error context.
	 * @return void
	 */
	private function log_error( $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}
}
