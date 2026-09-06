<?php
/**
 * Submit Document Prompt tool (D8 Cluster 2c-5 port of the base plugin's
 * WP_MCP_AI_Tool_Submit_Document_Prompt — byte-identical slug, schema,
 * error codes, envelope, document normalisation, and response-text
 * extraction; per-mode seams for the user-context helper, message
 * attachment segments, and the AI completion call).
 *
 * Monolith installs reuse the base WP_MCP_AI_User_Context_Helper,
 * WP_MCP_AI_Message_Attachments, and WP_MCP_AI_OpenAI_Client verbatim.
 * Standalone installs run the WordPress-API degradation envelope:
 * validated user switching, attachment segments built from WordPress
 * metadata (no OpenAI Files-API upload exists outside the base plugin),
 * and completions through the nvoos-core provider clients via
 * CoreBridge with the system prompt folded into a leading system
 * message (core clients do not consume options[system_prompt]).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Forwards a document attachment plus follow-up prompt to the model and
 * returns the model response.
 */
class SubmitDocumentPromptTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'submit_document_prompt';
	}

	public function getName(): string {
		return __( 'Submit Document Prompt', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Uploads the referenced document with a follow-up prompt and returns the model response.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'         => array(
					'type'        => 'string',
					'description' => __( 'Instruction or question that should be answered using the document.', 'nvoos-content-graph-ai' ),
				),
				'attachment_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID that should be submitted.', 'nvoos-content-graph-ai' ),
				),
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'List of WordPress attachment IDs to submit.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
				),
				'file_id'        => array(
					'type'        => 'string',
					'description' => __( 'Previously uploaded OpenAI file identifier to include.', 'nvoos-content-graph-ai' ),
				),
				'file_ids'       => array(
					'type'        => 'array',
					'description' => __( 'List of OpenAI file identifiers to include.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'attachments'    => array(
					'type'        => 'array',
					'description' => __( 'Structured attachment definitions that may include attachment_id or file_id values.', 'nvoos-content-graph-ai' ),
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
					'description' => __( 'Optional model override for the request.', 'nvoos-content-graph-ai' ),
				),
				'temperature'    => array(
					'type'        => array( 'number', 'integer', 'string' ),
					'description' => __( 'Optional temperature override (0-2).', 'nvoos-content-graph-ai' ),
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'Optional system prompt to prepend to the request.', 'nvoos-content-graph-ai' ),
				),
				'timeout'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional request timeout override in seconds.', 'nvoos-content-graph-ai' ),
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array(
			'requires-capability', // Requires upload_files capability for attachments.
			'requires-credentials', // Requires AI provider API credentials.
			'external-api',        // Makes external API calls to AI providers.
			'consumes-tokens',     // Uses AI model tokens/credits.
			'model-dependent',     // Behavior depends on AI model capabilities.
			'large-response',      // Document analysis can produce lengthy responses.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|string|\WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new \WP_Error( 'wp_mcp_ai_missing_prompt', __( 'You must supply a prompt before submitting a document.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			if ( ! $this->safe_set_current_user( $user_id ) ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_user',
					__( 'The authenticated user could not be resolved on this site.', 'nvoos-content-graph-ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		$document_specs = $this->normalise_document_arguments( $arguments );
		if ( empty( $document_specs ) ) {
			return new \WP_Error( 'wp_mcp_ai_missing_document', __( 'No attachments or file identifiers were provided.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
		}

		$attachments_helper = $this->attachments_helper();
		$content_segments   = array();
		$manual_attachments = array();
		$has_file_segment   = false;

		$content_segments[] = $this->prepare_input_text_segment( $prompt, $attachments_helper );

		foreach ( $document_specs as $spec ) {
			if ( isset( $spec['attachment_id'] ) && $spec['attachment_id'] ) {
				$segment_args = array(
					'attachment_id' => $spec['attachment_id'],
				);

				if ( ! empty( $spec['display_name'] ) ) {
					$segment_args['display_name'] = $spec['display_name'];
				}

				if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
					return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
				}
				$segment = $this->prepare_input_file_segment( $segment_args, $attachments_helper );
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

				$segment = $this->prepare_input_file_segment( $segment_args, $attachments_helper );
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
			return new \WP_Error( 'wp_mcp_ai_missing_document', __( 'The tool request must include at least one attachment.', 'nvoos-content-graph-ai' ), array( 'status' => 400 ) );
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

		$attachments_payload = $this->get_attachments( $attachments_helper );

		if ( ! empty( $manual_attachments ) ) {
			$attachments_payload = array_merge( $attachments_payload, array_values( $manual_attachments ) );
		}

		if ( ! empty( $attachments_payload ) ) {
			$options['attachments'] = $attachments_payload;
		}

		$response = $this->create_chat_completion( $messages, $options );

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
	 * Convert the raw tool arguments into a normalised list of attachment
	 * specs (base-identical).
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function normalise_document_arguments( array $arguments ) {
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
	 * Resolve an attachment identifier from a mixed value (base-identical).
	 *
	 * @param mixed $value Raw attachment identifier.
	 * @return int
	 */
	private function maybe_resolve_attachment_id( $value ) {
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
	 * Extract the first available assistant message text from the
	 * response payload (base-identical).
	 *
	 * @param array $response OpenAI response payload.
	 * @return string
	 */
	private function extract_first_choice_text( array $response ) {
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
	 * Validate a user identifier and switch the current-user context
	 * (per-mode seam).
	 *
	 * Monolith installs delegate to the base WP_MCP_AI_User_Context_Helper;
	 * standalone installs run the equivalent WordPress API checks
	 * (positive-integer validation, get_userdata existence, multisite
	 * blog membership) before wp_set_current_user().
	 *
	 * @param int $user_id Candidate WordPress user identifier.
	 * @return bool True when the current user is now $user_id.
	 */
	private function safe_set_current_user( $user_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_User_Context_Helper' ) ) {
			return \WP_MCP_AI_User_Context_Helper::safe_set_current_user( $user_id );
		}

		// Standalone: base-identical WordPress API checks.
		$user_id = filter_var( $user_id, FILTER_VALIDATE_INT );

		if ( false === $user_id || $user_id <= 0 ) {
			return false;
		}

		if ( get_current_user_id() === $user_id ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) || empty( $user->ID ) ) {
			return false;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return false;
		}

		wp_set_current_user( $user_id );

		return true;
	}

	/**
	 * Resolve the message-attachments helper (per-mode seam).
	 *
	 * Monolith installs reuse the base helper so segment preparation and
	 * payload accumulation stay byte-identical; standalone installs get
	 * null and the segment seams below run the WordPress-API degradation
	 * envelope.
	 *
	 * @return \WP_MCP_AI_Message_Attachments|null
	 */
	private function attachments_helper() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			return new \WP_MCP_AI_Message_Attachments();
		}

		return null;
	}

	/**
	 * Prepare a text segment (per-mode seam).
	 *
	 * @param string                                  $text  Raw text.
	 * @param \WP_MCP_AI_Message_Attachments|null     $helper Base helper or null standalone.
	 * @return array
	 */
	private function prepare_input_text_segment( $text, $helper ) {
		if ( null !== $helper ) {
			return $helper->prepare_input_text_segment( $text );
		}

		// Standalone: base-identical WordPress-only behaviour.
		$text = wp_kses_post( (string) $text );
		$text = trim( $text );

		return array(
			'type' => 'text',
			'text' => $text,
		);
	}

	/**
	 * Prepare an input file segment (per-mode seam).
	 *
	 * Standalone degradation envelope: no OpenAI Files-API upload exists
	 * outside the base plugin, so attachment segments carry WordPress
	 * attachment metadata (URL, file name, MIME type, bytes) and the
	 * base-identical local file reference used for providers without a
	 * remote File API instead of a provider file identifier.
	 *
	 * @param array                                  $segment_args Segment definition.
	 * @param \WP_MCP_AI_Message_Attachments|null    $helper       Base helper or null standalone.
	 * @return array|\WP_Error
	 */
	private function prepare_input_file_segment( array $segment_args, $helper ) {
		if ( null !== $helper ) {
			return $helper->prepare_input_file_segment( $segment_args );
		}

		if ( ! empty( $segment_args['file_id'] ) ) {
			$file_id = sanitize_text_field( wp_unslash( $segment_args['file_id'] ) );

			$segment_payload = array(
				'type'    => 'input_file',
				'file_id' => $file_id,
			);

			if ( ! empty( $segment_args['display_name'] ) ) {
				$segment_payload['display_name'] = sanitize_text_field( wp_unslash( $segment_args['display_name'] ) );
			}

			return $segment_payload;
		}

		if ( empty( $segment_args['attachment_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_file_attachment',
				__( 'File segments must include an attachment ID.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$attachment_id = absint( $segment_args['attachment_id'] );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'wp_mcp_ai_attachment_missing', __( 'Attachment not found.', 'nvoos-content-graph-ai' ) );
		}

		if ( ! current_user_can( 'read_post', $attachment_id ) && ! current_user_can( 'edit_post', $attachment_id ) && ! apply_filters( 'wp_mcp_ai_can_use_attachment', false, $attachment_id ) ) {
			return new \WP_Error( 'wp_mcp_ai_attachment_forbidden', __( 'You do not have permission to use the requested attachment.', 'nvoos-content-graph-ai' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_attachment_missing_file', __( 'The attachment file could not be located.', 'nvoos-content-graph-ai' ) );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		$file_size = filesize( $file_path );
		$file_size = false === $file_size ? 0 : (int) $file_size;

		// Base-identical local reference format for providers without a
		// remote File API (WP_MCP_AI_Message_Attachments::register_attachment).
		$file_hash = md5_file( $file_path ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5_file -- md5_file used for file deduplication (content fingerprint), not in a password or security context.
		$file_hash = false === $file_hash ? '' : (string) $file_hash;
		$file_id   = 'local-' . $attachment_id . '-' . substr( $file_hash, 0, 8 );

		$segment_payload = array(
			'type'          => 'input_file',
			'file_id'       => $file_id,
			'attachment_id' => $attachment_id,
		);

		$file_url = wp_get_attachment_url( $attachment_id );
		if ( ! empty( $file_url ) ) {
			$segment_payload['url'] = esc_url_raw( $file_url );
		}

		if ( ! empty( $segment_args['display_name'] ) ) {
			$segment_payload['display_name'] = sanitize_text_field( wp_unslash( $segment_args['display_name'] ) );
		} else {
			$title = get_the_title( $attachment );
			if ( '' !== $title ) {
				$segment_payload['display_name'] = $title;
			}
		}

		$filename = wp_basename( $file_path );
		if ( '' !== $filename ) {
			$segment_payload['file_name'] = sanitize_text_field( $filename );
			$segment_payload['name']      = sanitize_text_field( $filename ); // Compatibility field.
		}
		if ( '' !== $mime_type ) {
			$segment_payload['mime_type'] = sanitize_text_field( $mime_type );
		}
		if ( $file_size > 0 ) {
			$segment_payload['bytes'] = $file_size;
		}

		return $segment_payload;
	}

	/**
	 * Retrieve prepared attachment payloads (per-mode seam).
	 *
	 * Standalone: no OpenAI Files-API registrations happen outside the
	 * base plugin — empty payload.
	 *
	 * @param \WP_MCP_AI_Message_Attachments|null $helper Base helper or null standalone.
	 * @return array
	 */
	private function get_attachments( $helper ) {
		if ( null !== $helper ) {
			return $helper->get_attachments();
		}

		return array();
	}

	/**
	 * Send the chat-completion request (per-mode seam).
	 *
	 * Monolith installs reuse the base OpenAI client verbatim (it
	 * consumes the attachments option and system_prompt override).
	 * Standalone installs route to the nvoos-core provider client via
	 * CoreBridge with the system prompt folded into a leading system
	 * message — core clients do not consume the base's
	 * options[system_prompt] / options[attachments] keys.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return array|\WP_Error
	 */
	private function create_chat_completion( array $messages, array $options ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new \WP_MCP_AI_OpenAI_Client();
			return $client->create_chat_completion( $messages, $options );
		}

		$model    = isset( $options['model'] ) ? sanitize_text_field( (string) $options['model'] ) : '';
		$provider = '' !== $model ? $this->get_provider_for_model( $model ) : CoreBridge::instance()->settings->getDefaultProvider();

		$bridge = CoreBridge::instance();
		$client = $bridge->providers->get( $provider );

		if ( null === $client ) {
			return new \WP_Error(
				'wp_mcp_ai_client_unavailable',
				sprintf(
					/* translators: %s: provider name */
					__( 'AI client not available for provider: %s', 'nvoos-content-graph-ai' ),
					$provider
				)
			);
		}

		$chat_messages = $messages;
		if ( ! empty( $options['system_prompt'] ) ) {
			array_unshift(
				$chat_messages,
				array(
					'role'    => 'system',
					'content' => $options['system_prompt'],
				)
			);
		}

		return $client->chat( $chat_messages, $options );
	}

	/**
	 * Get provider name for a model (base-identical heuristic).
	 *
	 * @param string $model Model identifier.
	 * @return string Provider name (openai, gemini, ollama).
	 */
	private function get_provider_for_model( $model ) {
		// Check for Gemini models.
		if ( false !== strpos( (string) $model, 'gemini' ) ) {
			return 'gemini';
		}

		// Check for Ollama models.
		if ( false !== strpos( (string) $model, 'llama' ) || false !== strpos( (string) $model, 'mistral' ) || false !== strpos( (string) $model, 'qwen' ) ) {
			return 'ollama';
		}

		// Default to OpenAI.
		return 'openai';
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
