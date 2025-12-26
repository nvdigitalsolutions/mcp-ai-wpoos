<?php
/**
 * Tool that submits a document alongside a follow-up prompt.
 *
 * @package WP_MCP_AI
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
class WP_MCP_AI_Tool_Submit_Document_Prompt implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Submit Document Prompt', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads the referenced document with a follow-up prompt and returns the model response.', 'wp-mcp-ai' );
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
					'description' => __( 'Instruction or question that should be answered using the document.', 'wp-mcp-ai' ),
				),
				'attachment_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID that should be submitted.', 'wp-mcp-ai' ),
				),
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'List of WordPress attachment IDs to submit.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
				),
				'file_id'        => array(
					'type'        => 'string',
					'description' => __( 'Previously uploaded OpenAI file identifier to include.', 'wp-mcp-ai' ),
				),
				'file_ids'       => array(
					'type'        => 'array',
					'description' => __( 'List of OpenAI file identifiers to include.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'attachments'    => array(
					'type'        => 'array',
					'description' => __( 'Structured attachment definitions that may include attachment_id or file_id values.', 'wp-mcp-ai' ),
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
					'description' => __( 'Optional model override for the request.', 'wp-mcp-ai' ),
				),
				'temperature'    => array(
					'type'        => array( 'number', 'integer', 'string' ),
					'description' => __( 'Optional temperature override (0-2).', 'wp-mcp-ai' ),
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'Optional system prompt to prepend to the request.', 'wp-mcp-ai' ),
				),
				'timeout'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional request timeout override in seconds.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'You must supply a prompt before submitting a document.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( $user_id > 0 && $user_id !== get_current_user_id() ) {
			wp_set_current_user( $user_id );
		}

		$document_specs = $this->normalise_document_arguments( $arguments );
		if ( empty( $document_specs ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_document', __( 'No attachments or file identifiers were provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
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
					return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
				}
				$segment = $attachments_helper->prepare_input_file_segment( $segment_args );
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
			return new WP_Error( 'wp_mcp_ai_missing_document', __( 'The tool request must include at least one attachment.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
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

		$client   = new WP_MCP_AI_OpenAI_Client();
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
