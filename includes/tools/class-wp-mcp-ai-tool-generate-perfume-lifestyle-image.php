<?php
/**
 * Tool that validates perfume product and person imagery before generating a lifestyle scene.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';

/**
 * Provides a workflow that checks image safety before composing a lifestyle render.
 */
class WP_MCP_AI_Tool_Generate_Perfume_Lifestyle_Image implements WP_MCP_AI_Tool_Interface {
	const DEFAULT_ANALYSIS_MODEL   = 'gpt-4o-mini';
	const DEFAULT_ANALYSIS_TIMEOUT = 120;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_perfume_lifestyle_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Perfume Lifestyle Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates a perfume product image and a person reference before generating a combined lifestyle scene.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_image'         => array(
					'type'        => 'object',
					'description' => __( 'Image reference for the perfume product. Provide an attachment_id, file_id, or remote URL.', 'wp-mcp-ai' ),
				),
				'person_image'          => array(
					'type'        => 'object',
					'description' => __( 'Image reference for the person who should use the product. Provide an attachment_id, file_id, or remote URL.', 'wp-mcp-ai' ),
				),
				'product_url'           => array(
					'type'        => 'string',
					'description' => __( 'Optional reference URL for the perfume product. Must be a safe HTTP or HTTPS URL.', 'wp-mcp-ai' ),
				),
				'scene_description'     => array(
					'type'        => 'string',
					'description' => __( 'Optional creative direction for the final lifestyle scene.', 'wp-mcp-ai' ),
				),
				'analysis_model'        => array(
					'type'        => 'string',
					'description' => __( 'Override the default multimodal model used for validating the input images.', 'wp-mcp-ai' ),
				),
				'analysis_timeout'      => array(
					'type'        => 'integer',
					'description' => __( 'Timeout in seconds for the analysis request (5-300).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
				'image_model'           => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI image model to use for the lifestyle render.', 'wp-mcp-ai' ),
				),
				'image_size'            => array(
					'type'        => 'string',
					'description' => __( 'Optional image size override (e.g. 1024x1024, 1792x1024).', 'wp-mcp-ai' ),
				),
				'image_quality'         => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI image quality (standard or hd).', 'wp-mcp-ai' ),
				),
				'image_response_format' => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI response format for the generated image (b64_json or url).', 'wp-mcp-ai' ),
				),
				'image_timeout'         => array(
					'type'        => 'integer',
					'description' => __( 'Timeout in seconds for the image generation request (5-300).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
				'image_file_name'       => array(
					'type'        => 'string',
					'description' => __( 'Optional base filename to use when storing the lifestyle image.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'product_image', 'person_image' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id             = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$token_authenticated = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $token_authenticated ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate lifestyle imagery.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate lifestyle imagery.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();

		$product_segment = $this->prepare_image_segment( isset( $arguments['product_image'] ) ? $arguments['product_image'] : null, 'product_image', $attachments_helper );
		if ( is_wp_error( $product_segment ) ) {
			return $product_segment;
		}

		$person_segment = $this->prepare_image_segment( isset( $arguments['person_image'] ) ? $arguments['person_image'] : null, 'person_image', $attachments_helper );
		if ( is_wp_error( $person_segment ) ) {
			return $person_segment;
		}

		$product_url = isset( $arguments['product_url'] ) ? $this->sanitize_safe_url( $arguments['product_url'], 'product_url' ) : '';
		if ( is_wp_error( $product_url ) ) {
			return $product_url;
		}
		$scene_description = isset( $arguments['scene_description'] ) ? $this->sanitize_scene_description( $arguments['scene_description'] ) : '';

		$analysis_model = isset( $arguments['analysis_model'] ) ? sanitize_text_field( $arguments['analysis_model'] ) : self::DEFAULT_ANALYSIS_MODEL;
		if ( '' === $analysis_model ) {
			$analysis_model = self::DEFAULT_ANALYSIS_MODEL;
		}

		$analysis_timeout = isset( $arguments['analysis_timeout'] ) ? absint( $arguments['analysis_timeout'] ) : self::DEFAULT_ANALYSIS_TIMEOUT;
		if ( $analysis_timeout < 5 ) {
			$analysis_timeout = self::DEFAULT_ANALYSIS_TIMEOUT;
		} elseif ( $analysis_timeout > 300 ) {
			$analysis_timeout = 300;
		}

		$analysis_messages = $this->build_analysis_messages( $attachments_helper, $product_segment, $person_segment, $product_url, $scene_description );
		$analysis_options  = array(
			'model'           => $analysis_model,
			'attachments'     => $attachments_helper->get_attachments(),
			'temperature'     => 0,
			'response_format' => $this->build_analysis_response_format(),
			'timeout'         => $analysis_timeout,
		);

		$client           = new WP_MCP_AI_OpenAI_Client();
		$analysis_request = $client->create_chat_completion( $analysis_messages, $analysis_options );

		if ( is_wp_error( $analysis_request ) ) {
			return $analysis_request;
		}

		$analysis_data = $this->parse_analysis_result( $analysis_request );
		if ( is_wp_error( $analysis_data ) ) {
			return $analysis_data;
		}

		$validation = $this->validate_analysis_result( $analysis_data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$analysis_summary = $this->prepare_analysis_summary( $analysis_data, $scene_description, $product_url, $analysis_model );

		$image_prompt = $analysis_summary['lifestyle_prompt'];
		if ( '' === $image_prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'The analysis step did not return a lifestyle prompt.', 'wp-mcp-ai' ) );
		}

		$image_arguments = $this->build_image_arguments( $arguments, $image_prompt );

		$image_tool   = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$image_result = $image_tool->execute( $image_arguments, $context );

		if ( is_wp_error( $image_result ) ) {
			if ( method_exists( $image_result, 'add_data' ) ) {
				$existing_data = $image_result->get_error_data();
				if ( ! is_array( $existing_data ) ) {
					$existing_data = array();
				}
				$existing_data['analysis'] = $analysis_summary;
				$image_result->add_data( $existing_data );
			}

			return $image_result;
		}

		WP_MCP_AI_Logger::log_event(
			'perfume_lifestyle_image_generated',
			'Generated perfume lifestyle image.',
			array(
				'analysis'     => $analysis_summary,
				'image_result' => array(
					'attachment_id' => isset( $image_result['attachment_id'] ) ? (int) $image_result['attachment_id'] : 0,
					'model'         => isset( $image_result['model'] ) ? $image_result['model'] : '',
					'size'          => isset( $image_result['size'] ) ? $image_result['size'] : '',
					'quality'       => isset( $image_result['quality'] ) ? $image_result['quality'] : '',
				),
			)
		);

		$result_text = $this->build_result_text( $analysis_summary, $image_result );

		$result = array(
			'analysis' => $analysis_summary,
			'image'    => $image_result,
		);

		if ( '' !== $result_text ) {
			$result['text'] = $result_text;
		}

		if ( isset( $image_result['attachment_id'] ) ) {
			$result['attachment_id'] = (int) $image_result['attachment_id'];
		}

		if ( ! empty( $image_result['url'] ) ) {
			$result['url']          = $image_result['url'];
			$result['download_url'] = $image_result['url'];
		}

		if ( ! empty( $image_result['file_name'] ) ) {
			$result['file_name'] = $image_result['file_name'];
		}

		if ( isset( $image_result['bytes'] ) ) {
			$result['bytes'] = (int) $image_result['bytes'];
		}

		if ( ! empty( $image_result['mime_type'] ) ) {
			$result['mime_type'] = $image_result['mime_type'];
		}

		if ( ! empty( $image_result['size'] ) ) {
			$result['size'] = $image_result['size'];
		}

		if ( ! empty( $image_result['quality'] ) ) {
			$result['quality'] = $image_result['quality'];
		}

		if ( isset( $image_result['created'] ) ) {
			$result['created'] = (int) $image_result['created'];
		}

		return $result;
	}

	/**
	 * Prepare a structured image segment for the analysis request.
	 *
	 * @param mixed                         $segment            Raw segment definition.
	 * @param string                        $argument_key       Argument name for error reporting.
	 * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper instance.
	 *
	 * @return array|WP_Error
	 */
	protected function prepare_image_segment( $segment, $argument_key, WP_MCP_AI_Message_Attachments $attachments_helper ) {
		if ( empty( $segment ) || ! is_array( $segment ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_image_segment', sprintf( __( 'The %s argument must be an object describing the image source.', 'wp-mcp-ai' ), $argument_key ) );
		}

		$prepared = array();

		if ( isset( $segment['attachment_id'] ) ) {
			$attachment_id = absint( $segment['attachment_id'] );
			if ( $attachment_id > 0 ) {
				$prepared['attachment_id'] = $attachment_id;
			}
		}

		if ( isset( $segment['file_id'] ) ) {
			$file_id = sanitize_text_field( wp_unslash( $segment['file_id'] ) );
			if ( '' !== $file_id ) {
				$prepared['file_id'] = $file_id;
			}
		}

		$image_file = isset( $segment['image_file'] ) && is_array( $segment['image_file'] ) ? $segment['image_file'] : array();
		if ( empty( $prepared['file_id'] ) && ! empty( $image_file ) ) {
			if ( isset( $image_file['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $image_file['file_id'] ) );
				if ( '' !== $file_id ) {
					$prepared['file_id'] = $file_id;
				}
			} elseif ( isset( $image_file['id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $image_file['id'] ) );
				if ( '' !== $file_id ) {
					$prepared['file_id'] = $file_id;
				}
			}
		}

		if ( empty( $prepared['file_id'] ) && isset( $segment['file'] ) && is_array( $segment['file'] ) ) {
			$file_segment = $segment['file'];
			if ( isset( $file_segment['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $file_segment['file_id'] ) );
				if ( '' !== $file_id ) {
					$prepared['file_id'] = $file_id;
				}
			} elseif ( isset( $file_segment['id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $file_segment['id'] ) );
				if ( '' !== $file_id ) {
					$prepared['file_id'] = $file_id;
				}
			}
		}

		if ( isset( $segment['url'] ) ) {
			$sanitized_url = $this->sanitize_safe_url( $segment['url'], $argument_key . '_url' );
			if ( is_wp_error( $sanitized_url ) ) {
				return $sanitized_url;
			}
			if ( '' !== $sanitized_url ) {
				$prepared['url'] = $sanitized_url;
			}
		}

		if ( empty( $prepared['attachment_id'] ) && empty( $prepared['file_id'] ) && empty( $prepared['url'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_image_source', sprintf( __( 'The %s argument must include an attachment_id, file_id, or url.', 'wp-mcp-ai' ), $argument_key ) );
		}

		if ( isset( $segment['caption'] ) && '' !== $segment['caption'] ) {
			$prepared['caption'] = $this->sanitize_caption_text( $segment['caption'] );
		} elseif ( ! empty( $image_file['caption'] ) ) {
			$prepared['caption'] = $this->sanitize_caption_text( $image_file['caption'] );
		}

		$detail_hint = '';
		if ( isset( $segment['detail'] ) && '' !== $segment['detail'] ) {
			$detail_hint = $segment['detail'];
		} elseif ( isset( $image_file['detail'] ) && '' !== $image_file['detail'] ) {
			$detail_hint = $image_file['detail'];
		}

		if ( '' !== $detail_hint ) {
			$detail = $this->sanitize_detail_value( $detail_hint );
			if ( '' !== $detail ) {
				$prepared['detail'] = $detail;
			}
		}

		$result = $attachments_helper->prepare_input_image_segment( $prepared );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Ensure a caption-like string is clean.
	 *
	 * @param string $value Raw caption.
	 * @return string
	 */
	protected function sanitize_caption_text( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return $value;
	}

	/**
	 * Normalise the detail hint for OpenAI image segments.
	 *
	 * @param string $value Raw detail value.
	 * @return string
	 */
	protected function sanitize_detail_value( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array( 'auto', 'low', 'high' );

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Validate a URL for safety and return the cleaned value.
	 *
	 * @param mixed  $value       Raw URL.
	 * @param string $argument_key Argument key for error reporting.
	 * @return string|WP_Error
	 */
	protected function sanitize_safe_url( $value, $argument_key ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$value     = trim( $value );
		$validated = wp_http_validate_url( $value );

		if ( ! $validated ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', sprintf( __( 'The %s value must be a valid HTTP or HTTPS URL.', 'wp-mcp-ai' ), $argument_key ) );
		}

		$parsed = wp_parse_url( $validated );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', sprintf( __( 'The %s value must be a valid HTTP or HTTPS URL.', 'wp-mcp-ai' ), $argument_key ) );
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url_scheme', sprintf( __( 'The %s value must use HTTP or HTTPS.', 'wp-mcp-ai' ), $argument_key ) );
		}

		$host = strtolower( $parsed['host'] );
		if ( $this->host_looks_unsafe( $host ) ) {
			return new WP_Error( 'wp_mcp_ai_unsafe_url', sprintf( __( 'The %s value points to a disallowed host.', 'wp-mcp-ai' ), $argument_key ) );
		}

		return esc_url_raw( $validated );
	}

	/**
	 * Determine if a host should be considered unsafe.
	 *
	 * @param string $host Hostname from the URL.
	 * @return bool
	 */
	protected function host_looks_unsafe( $host ) {
		if ( '' === $host ) {
			return true;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $this->ip_is_private_or_reserved( $host );
		}

		if ( false !== strpos( $host, '.local' ) || false !== strpos( $host, '.internal' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether an IP is within private or reserved ranges.
	 *
	 * @param string $ip Address to inspect.
	 * @return bool
	 */
	protected function ip_is_private_or_reserved( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$flags  = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		$public = filter_var( $ip, FILTER_VALIDATE_IP, $flags );

		return false === $public;
	}

	/**
	 * Normalise the user supplied scene description.
	 *
	 * @param string $value Raw scene description.
	 * @return string
	 */
	protected function sanitize_scene_description( $value ) {
		$value = sanitize_textarea_field( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return $value;
	}

	/**
	 * Build the analysis message payload.
	 *
	 * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper.
	 * @param array                         $product_segment    Prepared product segment.
	 * @param array                         $person_segment     Prepared person segment.
	 * @param string                        $product_url        Optional product URL.
	 * @param string                        $scene_description  Optional scene description.
	 *
	 * @return array
	 */
	protected function build_analysis_messages( WP_MCP_AI_Message_Attachments $attachments_helper, array $product_segment, array $person_segment, $product_url, $scene_description ) {
		$system_instructions = __( 'You are a vision safety assistant. Verify that the supplied images meet the requested criteria, then produce JSON that follows the provided schema.', 'wp-mcp-ai' );

		$product_instructions = __( 'Analyse the attached product image. Confirm that it depicts a physical perfume product. Summarise distinguishing visual traits (bottle shape, colours, materials, branding) that can be described textually.', 'wp-mcp-ai' );
		if ( '' !== $product_url ) {
			$product_instructions .= ' ' . sprintf( __( 'Reference product URL: %s', 'wp-mcp-ai' ), $product_url );
		}

		$person_instructions = __( 'Analyse the attached person image. Confirm that it depicts a real person, ensure the clothing and pose are decent, and summarise key visual cues such as gender presentation, age range, hairstyle, outfit details, and notable accessories.', 'wp-mcp-ai' );

		if ( '' !== $scene_description ) {
			$person_instructions .= ' ' . sprintf( __( 'Incorporate this creative direction when crafting the final lifestyle prompt: %s', 'wp-mcp-ai' ), $scene_description );
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => array(
					$attachments_helper->prepare_input_text_segment( $system_instructions ),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					$product_segment,
					$attachments_helper->prepare_input_text_segment( $product_instructions ),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					$person_segment,
					$attachments_helper->prepare_input_text_segment( $person_instructions ),
				),
			),
		);

		if ( '' !== $scene_description ) {
			$scene_note = sprintf( __( 'Scene brief: %s', 'wp-mcp-ai' ), $scene_description );
			$messages[] = array(
				'role'    => 'user',
				'content' => array(
					$attachments_helper->prepare_input_text_segment( $scene_note ),
				),
			);
		}

		return $messages;
	}

	/**
	 * Build the response format schema for the analysis request.
	 *
	 * @return array
	 */
	protected function build_analysis_response_format() {
		return array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'perfume_lifestyle_validation',
				'schema' => array(
					'type'       => 'object',
					'required'   => array( 'product_validation', 'person_validation', 'lifestyle_prompt' ),
					'properties' => array(
						'product_validation' => array(
							'type'       => 'object',
							'required'   => array( 'is_product', 'is_perfume', 'notes' ),
							'properties' => array(
								'is_product'      => array( 'type' => 'boolean' ),
								'is_perfume'      => array( 'type' => 'boolean' ),
								'notes'           => array( 'type' => 'string' ),
								'visual_features' => array( 'type' => 'string' ),
							),
						),
						'person_validation'  => array(
							'type'       => 'object',
							'required'   => array( 'is_person', 'is_decent', 'notes' ),
							'properties' => array(
								'is_person'       => array( 'type' => 'boolean' ),
								'is_decent'       => array( 'type' => 'boolean' ),
								'notes'           => array( 'type' => 'string' ),
								'visual_features' => array( 'type' => 'string' ),
							),
						),
						'lifestyle_prompt'   => array(
							'type'        => 'string',
							'description' => __( 'Detailed prompt for generating the final lifestyle image.', 'wp-mcp-ai' ),
						),
						'safety_notes'       => array(
							'type'        => 'string',
							'description' => __( 'Optional moderation or compliance notes.', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Parse the analysis response payload.
	 *
	 * @param array $response API response.
	 * @return array|WP_Error
	 */
	protected function parse_analysis_result( array $response ) {
		$message_text = $this->extract_first_choice_text( $response );
		if ( '' === $message_text ) {
			return new WP_Error( 'wp_mcp_ai_empty_analysis', __( 'The analysis response did not include any content.', 'wp-mcp-ai' ) );
		}

		$decoded = json_decode( $message_text, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			WP_MCP_AI_Logger::log_error( 'Perfume lifestyle analysis returned invalid JSON.', array( 'content' => $message_text ) );

			return new WP_Error( 'wp_mcp_ai_invalid_analysis', __( 'Failed to decode the analysis response.', 'wp-mcp-ai' ) );
		}

		return $decoded;
	}

	/**
	 * Extract the first choice text from a chat response.
	 *
	 * @param array $response Chat response payload.
	 * @return string
	 */
	protected function extract_first_choice_text( array $response ) {
		if ( empty( $response['choices'] ) || ! is_array( $response['choices'] ) ) {
			return '';
		}

		$choice = $response['choices'][0];

		if ( isset( $choice['message']['content'] ) ) {
			return $this->collapse_response_content( $choice['message']['content'] );
		}

		if ( isset( $choice['content'] ) ) {
			return $this->collapse_response_content( $choice['content'] );
		}

		if ( isset( $choice['text'] ) && ( is_string( $choice['text'] ) || is_numeric( $choice['text'] ) ) ) {
			return trim( (string) $choice['text'] );
		}

		return '';
	}

	/**
	 * Collapse an OpenAI response content payload to text.
	 *
	 * @param mixed $content Response content payload.
	 * @return string
	 */
	protected function collapse_response_content( $content ) {
		if ( is_string( $content ) || is_numeric( $content ) ) {
			return trim( (string) $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();
		foreach ( $content as $segment ) {
			if ( is_string( $segment ) || is_numeric( $segment ) ) {
				$parts[] = (string) $segment;
				continue;
			}

			if ( ! is_array( $segment ) ) {
				continue;
			}

			if ( isset( $segment['text'] ) && ( is_string( $segment['text'] ) || is_numeric( $segment['text'] ) ) ) {
				$parts[] = (string) $segment['text'];
				continue;
			}

			if ( isset( $segment['content'] ) && ( is_string( $segment['content'] ) || is_numeric( $segment['content'] ) ) ) {
				$parts[] = (string) $segment['content'];
			}
		}

		$parts = array_filter( array_map( 'trim', $parts ) );

		return implode( "\n", $parts );
	}

	/**
	 * Validate the AI analysis payload for required flags.
	 *
	 * @param array $analysis Analysis payload.
	 * @return true|WP_Error
	 */
	protected function validate_analysis_result( array $analysis ) {
		$product = isset( $analysis['product_validation'] ) && is_array( $analysis['product_validation'] ) ? $analysis['product_validation'] : array();
		$person  = isset( $analysis['person_validation'] ) && is_array( $analysis['person_validation'] ) ? $analysis['person_validation'] : array();

		$product_is_product = $this->coerce_bool( isset( $product['is_product'] ) ? $product['is_product'] : false );
		$product_is_perfume = $this->coerce_bool( isset( $product['is_perfume'] ) ? $product['is_perfume'] : false );

		if ( ! $product_is_product || ! $product_is_perfume ) {
			return new WP_Error( 'wp_mcp_ai_invalid_product_image', __( 'The supplied product image does not appear to depict a perfume product.', 'wp-mcp-ai' ), array( 'analysis' => $analysis ) );
		}

		$person_is_person = $this->coerce_bool( isset( $person['is_person'] ) ? $person['is_person'] : false );
		$person_is_decent = $this->coerce_bool( isset( $person['is_decent'] ) ? $person['is_decent'] : false );

		if ( ! $person_is_person ) {
			return new WP_Error( 'wp_mcp_ai_invalid_person_image', __( 'The supplied person image does not appear to depict a real person.', 'wp-mcp-ai' ), array( 'analysis' => $analysis ) );
		}

		if ( ! $person_is_decent ) {
			return new WP_Error( 'wp_mcp_ai_indecent_person_image', __( 'The supplied person image was flagged as indecent.', 'wp-mcp-ai' ), array( 'analysis' => $analysis ) );
		}

		return true;
	}

	/**
	 * Coerce a mixed value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected function coerce_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( 'true', '1', 'yes', 'y' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( 'false', '0', 'no', 'n' ), true ) ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Prepare a sanitised summary of the analysis payload.
	 *
	 * @param array  $analysis          Raw analysis payload.
	 * @param string $scene_description Scene description from the request.
	 * @param string $product_url       Product reference URL.
	 * @param string $analysis_model    Model identifier.
	 *
	 * @return array
	 */
	protected function prepare_analysis_summary( array $analysis, $scene_description, $product_url, $analysis_model ) {
		$product = isset( $analysis['product_validation'] ) && is_array( $analysis['product_validation'] ) ? $analysis['product_validation'] : array();
		$person  = isset( $analysis['person_validation'] ) && is_array( $analysis['person_validation'] ) ? $analysis['person_validation'] : array();

		$product_is_product = $this->coerce_bool( isset( $product['is_product'] ) ? $product['is_product'] : true );
		$product_is_perfume = $this->coerce_bool( isset( $product['is_perfume'] ) ? $product['is_perfume'] : true );
		$person_is_person   = $this->coerce_bool( isset( $person['is_person'] ) ? $person['is_person'] : true );
		$person_is_decent   = $this->coerce_bool( isset( $person['is_decent'] ) ? $person['is_decent'] : true );

		$summary = array(
			'product'           => array(
				'is_product'      => $product_is_product,
				'is_perfume'      => $product_is_perfume,
				'notes'           => $this->sanitize_ai_text( isset( $product['notes'] ) ? $product['notes'] : '' ),
				'visual_features' => $this->sanitize_ai_text( isset( $product['visual_features'] ) ? $product['visual_features'] : '' ),
			),
			'person'            => array(
				'is_person'       => $person_is_person,
				'is_decent'       => $person_is_decent,
				'notes'           => $this->sanitize_ai_text( isset( $person['notes'] ) ? $person['notes'] : '' ),
				'visual_features' => $this->sanitize_ai_text( isset( $person['visual_features'] ) ? $person['visual_features'] : '' ),
			),
			'lifestyle_prompt'  => $this->sanitize_ai_text( isset( $analysis['lifestyle_prompt'] ) ? $analysis['lifestyle_prompt'] : '' ),
			'safety_notes'      => $this->sanitize_ai_text( isset( $analysis['safety_notes'] ) ? $analysis['safety_notes'] : '' ),
			'scene_description' => $scene_description,
			'product_url'       => $product_url,
			'analysis_model'    => $analysis_model,
		);

		if ( '' === $summary['lifestyle_prompt'] ) {
			$summary['lifestyle_prompt'] = $this->build_fallback_prompt( $summary['product'], $summary['person'], $scene_description );
		}

		return $summary;
	}

	/**
	 * Sanitize arbitrary AI-generated text for safe output.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected function sanitize_ai_text( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = (string) $value;
		$value = wp_strip_all_tags( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return $value;
	}

	/**
	 * Build a fallback lifestyle prompt when the analysis omits one.
	 *
	 * @param array  $product_summary Product summary data.
	 * @param array  $person_summary  Person summary data.
	 * @param string $scene_description Optional scene description.
	 *
	 * @return string
	 */
	protected function build_fallback_prompt( array $product_summary, array $person_summary, $scene_description ) {
		$parts = array();

		$parts[] = __( 'Create a high-quality lifestyle photograph that pairs the referenced person with the featured perfume product.', 'wp-mcp-ai' );

		if ( ! empty( $product_summary['visual_features'] ) ) {
			$parts[] = sprintf( __( 'Highlight these perfume details: %s.', 'wp-mcp-ai' ), $product_summary['visual_features'] );
		}

		if ( ! empty( $person_summary['visual_features'] ) ) {
			$parts[] = sprintf( __( 'Represent the person with: %s.', 'wp-mcp-ai' ), $person_summary['visual_features'] );
		}

		if ( '' !== $scene_description ) {
			$parts[] = sprintf( __( 'Scene direction: %s.', 'wp-mcp-ai' ), $scene_description );
		}

		$parts[] = __( 'Show the person using or presenting the perfume bottle naturally while keeping the product as the hero of the composition.', 'wp-mcp-ai' );

		return trim( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * Build a human readable summary for the tool response.
	 *
	 * @param array $analysis_summary Structured analysis summary.
	 * @param array $image_result     Stored image information.
	 * @return string
	 */
	protected function build_result_text( array $analysis_summary, array $image_result ) {
		$lines = array(
			__( 'Lifestyle image saved to the Media Library.', 'wp-mcp-ai' ),
		);

		$product_summary = isset( $analysis_summary['product'] ) && is_array( $analysis_summary['product'] ) ? $analysis_summary['product'] : array();
		$person_summary  = isset( $analysis_summary['person'] ) && is_array( $analysis_summary['person'] ) ? $analysis_summary['person'] : array();

		$product_status = array();
		if ( array_key_exists( 'is_product', $product_summary ) ) {
			$product_status[] = ! empty( $product_summary['is_product'] )
				? __( 'product detected', 'wp-mcp-ai' )
				: __( 'product check failed', 'wp-mcp-ai' );
		}
		if ( array_key_exists( 'is_perfume', $product_summary ) ) {
			$product_status[] = ! empty( $product_summary['is_perfume'] )
				? __( 'perfume confirmed', 'wp-mcp-ai' )
				: __( 'not recognised as a perfume', 'wp-mcp-ai' );
		}

		if ( ! empty( $product_status ) ) {
			$lines[] = sprintf(
				__( 'Product validation: %s.', 'wp-mcp-ai' ),
				implode( ', ', $product_status )
			);
		}

		if ( ! empty( $product_summary['notes'] ) ) {
			$lines[] = sprintf( __( 'Product notes: %s', 'wp-mcp-ai' ), $product_summary['notes'] );
		}

		if ( ! empty( $product_summary['visual_features'] ) ) {
			$lines[] = sprintf( __( 'Product features: %s', 'wp-mcp-ai' ), $product_summary['visual_features'] );
		}

		$person_status = array();
		if ( array_key_exists( 'is_person', $person_summary ) ) {
			$person_status[] = ! empty( $person_summary['is_person'] )
				? __( 'person detected', 'wp-mcp-ai' )
				: __( 'person check failed', 'wp-mcp-ai' );
		}
		if ( array_key_exists( 'is_decent', $person_summary ) ) {
			$person_status[] = ! empty( $person_summary['is_decent'] )
				? __( 'content is decent', 'wp-mcp-ai' )
				: __( 'content flagged for decency', 'wp-mcp-ai' );
		}

		if ( ! empty( $person_status ) ) {
			$lines[] = sprintf(
				__( 'Person validation: %s.', 'wp-mcp-ai' ),
				implode( ', ', $person_status )
			);
		}

		if ( ! empty( $person_summary['notes'] ) ) {
			$lines[] = sprintf( __( 'Person notes: %s', 'wp-mcp-ai' ), $person_summary['notes'] );
		}

		if ( ! empty( $person_summary['visual_features'] ) ) {
			$lines[] = sprintf( __( 'Person features: %s', 'wp-mcp-ai' ), $person_summary['visual_features'] );
		}

		if ( ! empty( $analysis_summary['safety_notes'] ) ) {
			$lines[] = sprintf( __( 'Safety notes: %s', 'wp-mcp-ai' ), $analysis_summary['safety_notes'] );
		}

		if ( ! empty( $analysis_summary['scene_description'] ) ) {
			$lines[] = sprintf( __( 'Scene direction: %s', 'wp-mcp-ai' ), $analysis_summary['scene_description'] );
		}

		if ( ! empty( $analysis_summary['product_url'] ) ) {
			$product_url = esc_url_raw( $analysis_summary['product_url'] );
			if ( '' !== $product_url ) {
				$lines[] = sprintf( __( 'Product URL: %s', 'wp-mcp-ai' ), $product_url );
			}
		}

		if ( ! empty( $analysis_summary['lifestyle_prompt'] ) ) {
			$lines[] = sprintf( __( 'Lifestyle prompt: %s', 'wp-mcp-ai' ), $analysis_summary['lifestyle_prompt'] );
		}

		if ( ! empty( $analysis_summary['analysis_model'] ) ) {
			$lines[] = sprintf( __( 'Analysis model: %s', 'wp-mcp-ai' ), $analysis_summary['analysis_model'] );
		}

		$image_settings = array();
		if ( ! empty( $image_result['model'] ) ) {
			$image_settings[] = sprintf( __( 'Model %s', 'wp-mcp-ai' ), $image_result['model'] );
		}
		if ( ! empty( $image_result['size'] ) ) {
			$image_settings[] = sprintf( __( 'Size %s', 'wp-mcp-ai' ), $image_result['size'] );
		}
		if ( ! empty( $image_result['quality'] ) ) {
			$image_settings[] = sprintf( __( 'Quality %s', 'wp-mcp-ai' ), $image_result['quality'] );
		}

		if ( ! empty( $image_settings ) ) {
			$lines[] = sprintf( __( 'Image settings: %s.', 'wp-mcp-ai' ), implode( ' • ', $image_settings ) );
		}

		if ( ! empty( $image_result['file_name'] ) ) {
			$lines[] = sprintf( __( 'File name: %s', 'wp-mcp-ai' ), $image_result['file_name'] );
		}

		if ( ! empty( $image_result['attachment_id'] ) ) {
			$lines[] = sprintf( __( 'Attachment ID: %d', 'wp-mcp-ai' ), (int) $image_result['attachment_id'] );
		}

		if ( ! empty( $image_result['url'] ) ) {
			$url = esc_url_raw( $image_result['url'] );
			if ( '' !== $url ) {
				$lines[] = sprintf( __( 'Media URL: %s', 'wp-mcp-ai' ), $url );
			}
		}

		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Build argument list for the downstream image generator.
	 *
	 * @param array  $arguments   Raw arguments from the request.
	 * @param string $image_prompt Prompt to pass to the generator.
	 *
	 * @return array
	 */
	protected function build_image_arguments( array $arguments, $image_prompt ) {
		$image_arguments = array(
			'prompt' => $image_prompt,
		);

		if ( ! empty( $arguments['image_model'] ) ) {
			$image_arguments['model'] = sanitize_text_field( $arguments['image_model'] );
		}

		if ( ! empty( $arguments['image_size'] ) ) {
			$image_arguments['size'] = sanitize_text_field( $arguments['image_size'] );
		}

		if ( ! empty( $arguments['image_quality'] ) ) {
			$image_arguments['quality'] = sanitize_key( $arguments['image_quality'] );
		}

		if ( ! empty( $arguments['image_response_format'] ) ) {
			$image_arguments['response_format'] = sanitize_key( $arguments['image_response_format'] );
		}

		if ( ! empty( $arguments['image_timeout'] ) ) {
			$image_arguments['timeout'] = absint( $arguments['image_timeout'] );
		}

		if ( ! empty( $arguments['image_file_name'] ) ) {
			$image_arguments['file_name'] = sanitize_file_name( $arguments['image_file_name'] );
		}

		return $image_arguments;
	}
}
