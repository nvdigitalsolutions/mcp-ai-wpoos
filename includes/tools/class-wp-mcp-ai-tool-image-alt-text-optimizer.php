<?php
/**
 * Tool for generating SEO-optimized alt text for images using AI.
 *
 * Creates descriptive, keyword-rich alt text following accessibility
 * and SEO best practices for 2026.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * Image Alt Text Optimizer Tool
 *
 * Generates SEO-optimized alt text for images using AI vision models.
 * Follows accessibility and SEO best practices.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Image_Alt_Text_Optimizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'image_alt_text_optimizer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Image Alt Text Optimizer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates SEO-optimized and accessible alt text for images using AI vision models. Creates descriptive, natural alt text that improves both accessibility and image SEO.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID to generate alt text for.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'Image URL to analyze (if attachment_id not provided).', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Context about how/where the image is used.', 'mcp-ai-wpoos' ),
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => __( 'Primary keyword to include in alt text (optional).', 'mcp-ai-wpoos' ),
				),
				'max_length'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum alt text length in characters.', 'mcp-ai-wpoos' ),
					'minimum'     => 50,
					'maximum'     => 125,
					'default'     => 125,
				),
				'tone'          => array(
					'type'        => 'string',
					'description' => __( 'Writing tone for alt text.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'descriptive', 'concise', 'detailed', 'professional' ),
					'default'     => 'descriptive',
				),
				'auto_save'     => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically save alt text to attachment (requires attachment_id).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'batch_mode'    => array(
					'type'        => 'boolean',
					'description' => __( 'Process multiple images without alt text (requires post_id or limit).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'post_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to process images from (for batch mode).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of images to process in batch mode.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'attachment_id' ) ),
				array( 'required' => array( 'image_url' ) ),
				array( 'required' => array( 'batch_mode' ) ),
			),
		);
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

			'toolkit'               => 'media_processing',

			'pattern_compatibility' => array( 'sequential', 'orchestrator' ),

			'profession_tags'       => array( 'seo_specialist', 'content_creator' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'state-changing',
			'cacheable',
			'consumes-tokens',
			'model-dependent',
			'vision-required',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook.
		$this->do_before_execute( $arguments, $context );

		// Check if batch mode or single image.
		if ( $arguments['batch_mode'] ?? false ) {
			$result = $this->process_batch( $arguments, $context );
		} else {
			$result = $this->process_single_image( $arguments, $context );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Process a single image.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result.
	 */
	private function process_single_image( $arguments, $context ) {
		// Validate arguments.
		$validation = $this->validate_arguments( $arguments );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Check cache if not auto-saving.
		if ( ! ( $arguments['auto_save'] ?? false ) && $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get image data.
		$image_data = $this->get_image_data( $arguments );
		if ( is_wp_error( $image_data ) ) {
			return $image_data;
		}

		// Generate alt text.
		$alt_text = $this->generate_alt_text( $image_data, $arguments, $context );
		if ( is_wp_error( $alt_text ) ) {
			return $alt_text;
		}

		// Validate alt text.
		$validation_results = $this->validate_alt_text( $alt_text, $arguments );

		// Apply filter hook.
		$alt_text = apply_filters(
			'wp_mcp_ai_image_alt_text_generated',
			$alt_text,
			$image_data['attachment_id'] ?? 0,
			$arguments
		);

		// Auto-save if requested.
		$saved = false;
		if ( ! empty( $image_data['attachment_id'] ) && ( $arguments['auto_save'] ?? false ) ) {
			$saved = $this->save_alt_text( $image_data['attachment_id'], $alt_text );

			if ( ! is_wp_error( $saved ) && $saved ) {
				do_action(
					'wp_mcp_ai_image_alt_text_saved',
					$image_data['attachment_id'],
					$alt_text
				);
			}
		}

		// Build result.
		$result = array(
			'alt_text'           => $alt_text,
			'char_count'         => strlen( $alt_text ),
			'attachment_id'      => $image_data['attachment_id'] ?? null,
			'image_url'          => $image_data['url'] ?? null,
			'saved'              => $saved,
			'validation'         => $validation_results,
			'best_practices_met' => $validation_results['all_passed'] ?? false,
		);

		// Cache result if not auto-saving.
		if ( ! ( $arguments['auto_save'] ?? false ) && $this->should_cache() ) {
			$this->set_cached_result( $arguments, $result );
		}

		return $result;
	}

	/**
	 * Process multiple images in batch.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Results.
	 */
	private function process_batch( $arguments, $context ) {
		$limit   = $arguments['limit'] ?? 10;
		$post_id = $arguments['post_id'] ?? 0;

		// Get images without alt text.
		$images = $this->get_images_without_alt_text( $post_id, $limit );

		if ( empty( $images ) ) {
			return array(
				'processed' => 0,
				'message'   => __( 'No images without alt text found.', 'mcp-ai-wpoos' ),
			);
		}

		$results = array();
		foreach ( $images as $attachment_id ) {
			$single_args = array_merge(
				$arguments,
				array(
					'attachment_id' => $attachment_id,
					'batch_mode'    => false,
				)
			);

			$result = $this->process_single_image( $single_args, $context );

			$results[] = array(
				'attachment_id' => $attachment_id,
				'success'       => ! is_wp_error( $result ),
				'alt_text'      => is_wp_error( $result ) ? null : $result['alt_text'],
				'error'         => is_wp_error( $result ) ? $result->get_error_message() : null,
			);
		}

		$success_count = count(
			array_filter(
				$results,
				function ( $r ) {
					return $r['success'];
				}
			)
		);

		return array(
			'processed'     => count( $results ),
			'success_count' => $success_count,
			'results'       => $results,
		);
	}

	/**
	 * Validate tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_arguments( $arguments ) {
		if ( empty( $arguments['attachment_id'] ) && empty( $arguments['image_url'] ) ) {
			return new WP_Error(
				'missing_image',
				__( 'Either attachment_id or image_url must be provided.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment = get_post( $arguments['attachment_id'] );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Attachment not found.', 'mcp-ai-wpoos' )
				);
			}
		}

		return true;
	}

	/**
	 * Get image data for processing.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Image data.
	 */
	private function get_image_data( $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = $arguments['attachment_id'];
			$image_url     = wp_get_attachment_url( $attachment_id );
			$current_alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			return array(
				'attachment_id' => $attachment_id,
				'url'           => $image_url,
				'filename'      => basename( $image_url ),
				'current_alt'   => $current_alt,
			);
		}

		return array(
			'url'      => $arguments['image_url'],
			'filename' => basename( $arguments['image_url'] ),
		);
	}

	/**
	 * Generate alt text using AI vision model.
	 *
	 * @param array $image_data Image data.
	 * @param array $arguments  Tool arguments.
	 * @param array $context    Execution context.
	 * @return string|WP_Error Generated alt text.
	 */
	private function generate_alt_text( $image_data, $arguments, $context = array() ) {
		// Build AI prompt.
		$prompt = $this->build_alt_text_prompt( $image_data, $arguments );

		// Get AI client with vision support.
		$client = $this->get_ai_client( $arguments, $context );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Call AI vision model.
		try {
			$response = $client->complete(
				array(
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => array(
								array(
									'type' => 'text',
									'text' => $prompt,
								),
								array(
									'type'      => 'image_url',
									'image_url' => array(
										'url' => $image_data['url'],
									),
								),
							),
						),
					),
					'model'       => $arguments['model'] ?? 'gpt-4o-mini',
					'temperature' => 0.5,
					'max_tokens'  => 100,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$alt_text = trim( $response['content'] ?? '' );
			$alt_text = trim( $alt_text, '"\'""' );

			return $alt_text;
		} catch ( Exception $e ) {
			return new WP_Error(
				'alt_text_generation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Alt text generation failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Build AI prompt for alt text generation.
	 *
	 * @param array $image_data Image data.
	 * @param array $arguments  Tool arguments.
	 * @return string AI prompt.
	 */
	private function build_alt_text_prompt( $image_data, $arguments ) {
		$max_length    = $arguments['max_length'] ?? 125;
		$tone          = $arguments['tone'] ?? 'descriptive';
		$focus_keyword = $arguments['focus_keyword'] ?? '';
		$context       = $arguments['context'] ?? '';

		$prompt  = "Generate SEO-optimized and accessible alt text for this image.\n\n";
		$prompt .= "Requirements:\n";
		$prompt .= "- Maximum {$max_length} characters\n";
		$prompt .= "- Be {$tone} and specific\n";
		$prompt .= "- Describe what's in the image naturally\n";
		$prompt .= "- Do NOT start with 'Image of' or 'Picture of'\n";
		$prompt .= "- Use proper grammar and punctuation\n";
		$prompt .= "- Avoid keyword stuffing\n";

		if ( $focus_keyword ) {
			$prompt .= "- Try to naturally include the keyword: '{$focus_keyword}'\n";
		}

		if ( $context ) {
			$prompt .= "- Context: {$context}\n";
		}

		$prompt .= "\nWrite only the alt text, without quotes or additional commentary.";

		return $prompt;
	}

	/**
	 * Validate alt text against best practices.
	 *
	 * @param string $alt_text  Generated alt text.
	 * @param array  $arguments Tool arguments.
	 * @return array Validation results.
	 */
	private function validate_alt_text( $alt_text, $arguments ) {
		$max_length    = $arguments['max_length'] ?? 125;
		$focus_keyword = $arguments['focus_keyword'] ?? '';

		$validation = array(
			'length_ok'     => false,
			'no_prefix'     => false,
			'has_keyword'   => false,
			'not_too_short' => false,
			'all_passed'    => false,
			'issues'        => array(),
		);

		// Check length.
		$length = strlen( $alt_text );
		if ( $length <= $max_length ) {
			$validation['length_ok'] = true;
		} else {
			$validation['issues'][] = sprintf(
				/* translators: %1$d: actual length, %2$d: max length */
				__( 'Alt text too long (%1$d chars, max %2$d).', 'mcp-ai-wpoos' ),
				$length,
				$max_length
			);
		}

		// Check minimum length.
		if ( $length >= 10 ) {
			$validation['not_too_short'] = true;
		} else {
			$validation['issues'][] = __( 'Alt text too short (minimum 10 characters).', 'mcp-ai-wpoos' );
		}

		// Check for bad prefixes.
		$bad_prefixes = array( 'image of', 'picture of', 'photo of', 'a picture', 'an image' );
		$alt_lower    = strtolower( $alt_text );
		$has_prefix   = false;

		foreach ( $bad_prefixes as $prefix ) {
			if ( strpos( $alt_lower, $prefix ) === 0 ) {
				$has_prefix = true;
				break;
			}
		}

		$validation['no_prefix'] = ! $has_prefix;
		if ( $has_prefix ) {
			$validation['issues'][] = __( 'Alt text should not start with "Image of" or similar phrases.', 'mcp-ai-wpoos' );
		}

		// Check for keyword.
		if ( $focus_keyword ) {
			if ( stripos( $alt_text, $focus_keyword ) !== false ) {
				$validation['has_keyword'] = true;
			} else {
				$validation['issues'][] = __( 'Focus keyword not found in alt text.', 'mcp-ai-wpoos' );
			}
		} else {
			$validation['has_keyword'] = true; // N/A.
		}

		// Determine if all validations passed.
		$validation['all_passed'] = $validation['length_ok'] &&
			$validation['no_prefix'] &&
			$validation['not_too_short'] &&
			$validation['has_keyword'];

		return $validation;
	}

	/**
	 * Save alt text to attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt_text      Alt text.
	 * @return bool True on success.
	 */
	private function save_alt_text( $attachment_id, $alt_text ) {
		return update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt_text ) );
	}

	/**
	 * Get images without alt text.
	 *
	 * @param int $post_id Post ID (0 for all).
	 * @param int $limit   Maximum number to return.
	 * @return array Attachment IDs.
	 */
	private function get_images_without_alt_text( $post_id, $limit ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			),
		);

		if ( $post_id > 0 ) {
			$args['post_parent'] = $post_id;
		}

		return get_posts( $args );
	}

	/**
	 * Get AI client with vision support.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return object|WP_Error AI client instance.
	 */
	private function get_ai_client( $arguments, $context ) {
		// Get model router or client.
		if ( class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$router = WP_MCP_AI_Language_Model_Router::get_instance();
			return $router->get_client( $arguments['model'] ?? 'gpt-4o-mini' );
		}

		// Fallback to OpenAI client.
		if ( class_exists( 'WP_MCP_AI_Enhanced_OpenAI_Client' ) ) {
			return new WP_MCP_AI_Enhanced_OpenAI_Client();
		}

		return new WP_Error(
			'no_ai_client',
			__( 'No AI client available for image analysis.', 'mcp-ai-wpoos' )
		);
	}
}
