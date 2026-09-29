<?php
/**
 * Tool that submits a document alongside a follow-up prompt.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Provides a tool for forwarding an attachment and prompt to the model.
 */
class WP_MCP_AI_Tool_Submit_Document_Prompt implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_document_prompt';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit Document Prompt', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads the referenced document with a follow-up prompt and returns the model response.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Asking the model about a specific attachment or uploaded OpenAI file with a follow-up prompt in one call.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Checking whether a file suits a task first; use analyze_file_suitability, or transcribe_openai_audio for audio-only files.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'analyze_file_suitability', 'transcribe_openai_audio', 'analyze_image' ),
			'notes'           => __( 'Consumes AI tokens and provider credentials; requires a prompt plus at least one attachment_id or file_id.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'         => array(
					'type'        => 'string',
					'description' => __( 'Instruction or question that should be answered using the document.', 'mcp-ai-wpoos' ),
				),
				'attachment_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID that should be submitted.', 'mcp-ai-wpoos' ),
				),
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'List of WordPress attachment IDs to submit.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
				),
				'file_id'        => array(
					'type'        => 'string',
					'description' => __( 'Previously uploaded OpenAI file identifier to include.', 'mcp-ai-wpoos' ),
				),
				'file_ids'       => array(
					'type'        => 'array',
					'description' => __( 'List of OpenAI file identifiers to include.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'attachments'    => array(
					'type'        => 'array',
					'description' => __( 'Structured attachment definitions that may include attachment_id or file_id values.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'attachment_id' => array(
								'type' => array( 'integer', 'string' ),
							),
							'id'            => array(
								'type' => array( 'integer', 'string' ),
							),
							'file_id'       => array(
								'type' => 'string',
							),
							'display_name'  => array(
								'type' => 'string',
							),
						),
						'additionalProperties' => false,
					),
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Optional model override for the request.', 'mcp-ai-wpoos' ),
				),
				'temperature'    => array(
					'type'        => array( 'number', 'integer', 'string' ),
					'description' => __( 'Optional temperature override (0-2).', 'mcp-ai-wpoos' ),
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'Optional system prompt to prepend to the request.', 'mcp-ai-wpoos' ),
				),
				'timeout'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional request timeout override in seconds.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'You must supply a prompt before submitting a document.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			if ( ! WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'The authenticated user could not be resolved on this site.', 'mcp-ai-wpoos' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		$document_specs = $this->normalise_document_arguments( $arguments );
		if ( empty( $document_specs ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_document', __( 'No attachments or file identifiers were provided.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Resolve the assistant's provider up front so attachments and the
		// completion request are handled by the same provider client.
		$provider            = $this->resolve_execution_provider( $context );
		$attachments_helper = new WP_MCP_AI_Message_Attachments( $provider );
		$content_segments   = array();
		$manual_attachments = array();
		$has_file_segment   = false;

		$content_segments[] = $attachments_helper->prepare_input_text_segment( $prompt );

		foreach ( $document_specs as $spec ) {
			if ( isset( $spec['attachment_id'] ) && $spec['attachment_id'] ) {
				$segment_args = array(
					'attachment_id' => $spec['attachment_id'],
				);

				if ( ! empty( $spec['display_name'] ) ) {
					$segment_args['display_name'] = $spec['display_name'];
				}

				if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
					return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
				}
				$segment = $this->prepare_document_segment( $attachments_helper, $segment_args, $provider );
				if ( is_wp_error( $segment ) ) {
					return $segment;
				}

				$content_segments[] = $segment;
				$has_file_segment   = true;
				continue;
			}

			if ( isset( $spec['file_id'] ) && '' !== $spec['file_id'] ) {
				$segment_args = array( 'file_id' => $spec['file_id'] );

				if ( ! empty( $spec['display_name'] ) ) {
					$segment_args['display_name'] = $spec['display_name'];
				}

				$segment = $attachments_helper->prepare_input_file_segment( $segment_args );
				if ( is_wp_error( $segment ) ) {
					return $segment;
				}

				$content_segments[] = $segment;
				$has_file_segment   = true;

				if ( ! isset( $manual_attachments[ $spec['file_id'] ] ) ) {
					$entry = array(
						'id'      => $spec['file_id'],
						'file_id' => $spec['file_id'],
					);

					if ( ! empty( $spec['display_name'] ) ) {
						$entry['display_name'] = $spec['display_name'];
					}

					$manual_attachments[ $spec['file_id'] ] = $entry;
				}
			}
		}

		if ( ! $has_file_segment ) {
			return new WP_Error( 'wp_mcp_ai_missing_document', __( 'The tool request must include at least one attachment.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $content_segments,
			),
		);

		$options = array();

		if ( isset( $arguments['model'] ) && '' !== $arguments['model'] ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		} elseif ( isset( $context['assistant_config']['model'] ) && '' !== $context['assistant_config']['model'] ) {
			$options['model'] = sanitize_text_field( $context['assistant_config']['model'] );
		}

		if ( isset( $arguments['temperature'] ) && '' !== $arguments['temperature'] && null !== $arguments['temperature'] ) {
			$options['temperature'] = floatval( $arguments['temperature'] );
		} elseif ( isset( $context['assistant_config']['temperature'] ) && '' !== $context['assistant_config']['temperature'] ) {
			$options['temperature'] = floatval( $context['assistant_config']['temperature'] );
		}

		if ( isset( $arguments['system_prompt'] ) && '' !== $arguments['system_prompt'] ) {
			$options['system_prompt'] = wp_kses_post( $arguments['system_prompt'] );
		} elseif ( isset( $context['assistant_config']['system_prompt'] ) && '' !== $context['assistant_config']['system_prompt'] ) {
			$options['system_prompt'] = wp_kses_post( $context['assistant_config']['system_prompt'] );
		}

		if ( isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] && null !== $arguments['timeout'] ) {
			$options['timeout'] = absint( $arguments['timeout'] );
		}

		$attachments_payload = $attachments_helper->get_attachments();

		if ( ! empty( $manual_attachments ) ) {
			$attachments_payload = array_merge( $attachments_payload, array_values( $manual_attachments ) );
		}

		if ( ! empty( $attachments_payload ) ) {
			$options['attachments'] = $attachments_payload;
		}

		$client = $this->get_chat_client( $provider );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$response = $client->create_chat_completion( $messages, $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_first_choice_text( $response );

		if ( '' !== $text ) {
			return $text;
		}

		return $response;
	}

	/**
	 * Resolve the provider that should own the request.
	 *
	 * Prefers the assistant's configured provider, then the site-wide
	 * default provider, then OpenAI (the tool's historical default).
	 *
	 * @since 1.2.0
	 *
	 * @param array $context Execution context, including assistant_config.
	 * @return string Provider slug (e.g. 'openai', 'deepseek', 'gemini').
	 */
	protected function resolve_execution_provider( array $context ) {
		if ( ! empty( $context['assistant_config']['provider'] ) ) {
			return sanitize_key( $context['assistant_config']['provider'] );
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['default_provider'] ) ) {
				return sanitize_key( $settings['default_provider'] );
			}
		}

		return 'openai';
	}

	/**
	 * Prepare a single document segment, routing images through the vision path.
	 *
	 * The file-segment MIME allowlist deliberately excludes image types on
	 * every provider, so a JPEG/PNG/WebP attachment must travel as an
	 * `input_image` segment. Vision-capable models (GPT-4o/GPT-4.1,
	 * Gemini, deepseek-flash) then receive it as an image_url content block
	 * instead of an unsupported file reference.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper bound to the provider.
	 * @param array                         $segment_args       Segment definition (attachment_id, display_name).
	 * @param string                        $provider           Provider slug.
	 * @return array|WP_Error Prepared segment or error.
	 */
	protected function prepare_document_segment( $attachments_helper, array $segment_args, $provider ) {
		$attachment_id = isset( $segment_args['attachment_id'] ) ? absint( $segment_args['attachment_id'] ) : 0;
		$mime_type     = $attachment_id > 0 ? (string) get_post_mime_type( $attachment_id ) : '';

		if ( '' !== $mime_type && WP_MCP_AI_Message_Attachments::is_image_mime_type( $mime_type, $provider ) ) {
			return $attachments_helper->prepare_input_image_segment( $segment_args );
		}

		return $attachments_helper->prepare_input_file_segment( $segment_args );
	}

	/**
	 * Resolve a chat-completion client for the given provider.
	 *
	 * Prefers the shared container bindings (`client.<provider>`), falling
	 * back to direct instantiation for the core providers and to the OpenAI
	 * client for unknown providers (preserving the tool's legacy behaviour).
	 *
	 * @since 1.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return object|WP_Error Client instance or error.
	 */
	protected function get_chat_client( $provider ) {
		$provider = sanitize_key( $provider );

		// Normalise the Google alias to the Gemini client.
		if ( 'google' === $provider ) {
			$provider = 'gemini';
		}

		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container && $container->has( 'client.' . $provider ) ) {
				try {
					return $container->get( 'client.' . $provider );
					// phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Intentional: fall through to direct instantiation.
				} catch ( \Exception $e ) {
					// Fall through.
				}
			}
		}

		switch ( $provider ) {
			case 'deepseek':
				if ( ! class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-deepseek-client.php';
				}
				return new WP_MCP_AI_DeepSeek_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_MCP_AI_OpenAI_Client();
		}
	}

	/**
	 * Convert the raw tool arguments into a normalised list of attachment specs.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function normalise_document_arguments( array $arguments ) {
		$normalised         = array();
		$seen_attachments   = array();
		$seen_file_ids      = array();
		$structured_entries = array();

		if ( isset( $arguments['attachments'] ) && is_array( $arguments['attachments'] ) ) {
			$structured_entries = $arguments['attachments'];
		}

		foreach ( $structured_entries as $entry ) {
			if ( $entry instanceof \Traversable ) {
				$entry = iterator_to_array( $entry );
			}

			if ( is_object( $entry ) ) {
				$entry = (array) $entry;
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$display_name = '';
			if ( isset( $entry['display_name'] ) ) {
				$display_name = sanitize_text_field( wp_unslash( $entry['display_name'] ) );
			}

			$attachment_id = 0;
			if ( isset( $entry['attachment_id'] ) ) {
				$attachment_id = $this->maybe_resolve_attachment_id( $entry['attachment_id'] );
			} elseif ( isset( $entry['id'] ) ) {
				$attachment_id = $this->maybe_resolve_attachment_id( $entry['id'] );
			}

			if ( $attachment_id && ! isset( $seen_attachments[ $attachment_id ] ) ) {
				$seen_attachments[ $attachment_id ] = true;
				$normalised[]                       = array(
					'attachment_id' => $attachment_id,
					'display_name'  => $display_name,
				);
				continue;
			}

			$file_id = '';
			if ( isset( $entry['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $entry['file_id'] ) );
			} elseif ( isset( $entry['id'] ) && is_string( $entry['id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $entry['id'] ) );
			}

			if ( '' !== $file_id && ! isset( $seen_file_ids[ $file_id ] ) ) {
				$seen_file_ids[ $file_id ] = true;
				$normalised[]              = array(
					'file_id'      => $file_id,
					'display_name' => $display_name,
				);
			}
		}

		if ( isset( $arguments['attachment_id'] ) ) {
			$attachment_id = $this->maybe_resolve_attachment_id( $arguments['attachment_id'] );
			if ( $attachment_id && ! isset( $seen_attachments[ $attachment_id ] ) ) {
				$seen_attachments[ $attachment_id ] = true;
				$normalised[]                       = array( 'attachment_id' => $attachment_id );
			}
		}

		if ( isset( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ) {
			foreach ( $arguments['attachment_ids'] as $maybe_id ) {
				$attachment_id = $this->maybe_resolve_attachment_id( $maybe_id );
				if ( $attachment_id && ! isset( $seen_attachments[ $attachment_id ] ) ) {
					$seen_attachments[ $attachment_id ] = true;
					$normalised[]                       = array( 'attachment_id' => $attachment_id );
				}
			}
		}

		if ( isset( $arguments['file_id'] ) ) {
			$file_id = sanitize_text_field( $arguments['file_id'] );
			if ( '' !== $file_id && ! isset( $seen_file_ids[ $file_id ] ) ) {
				$seen_file_ids[ $file_id ] = true;
				$normalised[]              = array( 'file_id' => $file_id );
			}
		}

		if ( isset( $arguments['file_ids'] ) && is_array( $arguments['file_ids'] ) ) {
			foreach ( $arguments['file_ids'] as $maybe_id ) {
				$file_id = sanitize_text_field( $maybe_id );
				if ( '' !== $file_id && ! isset( $seen_file_ids[ $file_id ] ) ) {
					$seen_file_ids[ $file_id ] = true;
					$normalised[]              = array( 'file_id' => $file_id );
				}
			}
		}

		return $normalised;
	}

	/**
	 * Resolve an attachment identifier from a mixed value.
	 *
	 * @param mixed $value Raw attachment identifier.
	 * @return int
	 */
	protected function maybe_resolve_attachment_id( $value ) {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}

		if ( is_string( $value ) ) {
			$value = trim( wp_unslash( $value ) );

			if ( preg_match( '/^wp-attachment-(\d+)$/', $value, $matches ) ) {
				return absint( $matches[1] );
			}
		}

		return 0;
	}

	/**
	 * Extract the first available assistant message text from the response payload.
	 *
	 * @param array $response OpenAI response payload.
	 * @return string
	 */
	protected function extract_first_choice_text( array $response ) {
		if ( empty( $response['choices'] ) || ! is_array( $response['choices'] ) ) {
			return '';
		}

		$choice = $response['choices'][0];
		if ( isset( $choice['message']['content'] ) ) {
			$content = $choice['message']['content'];
			if ( is_string( $content ) || is_numeric( $content ) ) {
				return trim( (string) $content );
			}
		}

		if ( isset( $choice['message']['text'] ) ) {
			$content = $choice['message']['text'];
			if ( is_string( $content ) || is_numeric( $content ) ) {
				return trim( (string) $content );
			}
		}

		if ( isset( $choice['text'] ) && ( is_string( $choice['text'] ) || is_numeric( $choice['text'] ) ) ) {
			return trim( (string) $choice['text'] );
		}

		return '';
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability', // Requires upload_files capability for attachments.
			'requires-credentials', // Requires AI provider API credentials.
			'external-api',        // Makes external API calls to AI providers.
			'consumes-tokens',     // Uses AI model tokens/credits.
			'model-dependent',     // Behavior depends on AI model capabilities.
			'large-response',      // Document analysis can produce lengthy responses.
		);
	}
}
