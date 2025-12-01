<?php
/**
 * Tool for analyzing comment content for spam and toxicity using AI.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Analyzes comment content to detect spam, toxicity, and other moderation concerns.
 */
class WP_MCP_AI_Tool_Analyze_Comment_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_comment_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Comment Content', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes comment content for spam, toxicity, and moderation concerns using AI to assist with comment moderation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_content' => array(
					'type'        => 'string',
					'description' => __( 'The comment text to analyze.', 'wp-mcp-ai' ),
				),
				'comment_author'  => array(
					'type'        => 'string',
					'description' => __( 'The name of the comment author.', 'wp-mcp-ai' ),
				),
				'comment_email'   => array(
					'type'        => 'string',
					'description' => __( 'The email address of the comment author.', 'wp-mcp-ai' ),
				),
				'comment_url'     => array(
					'type'        => 'string',
					'description' => __( 'The URL provided by the comment author.', 'wp-mcp-ai' ),
				),
				'user_ip'         => array(
					'type'        => 'string',
					'description' => __( 'The IP address of the commenter.', 'wp-mcp-ai' ),
				),
				'sensitivity'     => array(
					'type'        => 'string',
					'enum'        => array( 'low', 'medium', 'high' ),
					'description' => __( 'Moderation sensitivity level: low (permissive), medium (balanced), high (strict).', 'wp-mcp-ai' ),
					'default'     => 'medium',
				),
			),
			'required'             => array( 'comment_content' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user capabilities - moderators and admins can use this.
		if ( $user_id && ! user_can( $user_id, 'moderate_comments' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to analyze comments.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Validate required fields.
		if ( empty( $arguments['comment_content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_content',
				__( 'Comment content is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$comment_content = sanitize_textarea_field( $arguments['comment_content'] );
		$comment_author  = isset( $arguments['comment_author'] ) ? sanitize_text_field( $arguments['comment_author'] ) : '';
		$comment_email   = isset( $arguments['comment_email'] ) ? sanitize_email( $arguments['comment_email'] ) : '';
		$comment_url     = isset( $arguments['comment_url'] ) ? esc_url_raw( $arguments['comment_url'] ) : '';
		$user_ip         = isset( $arguments['user_ip'] ) ? sanitize_text_field( $arguments['user_ip'] ) : '';
		$sensitivity     = isset( $arguments['sensitivity'] ) ? sanitize_text_field( $arguments['sensitivity'] ) : 'medium';

		// Get settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';

		// Build analysis prompt.
		$prompt = $this->build_analysis_prompt( $comment_content, $comment_author, $comment_email, $comment_url, $user_ip, $sensitivity );

		// Call AI model and capture usage/provider metadata.
		$api_response = $this->call_ai_model( $prompt, $default_provider );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// Extract analysis text and metadata.
		$analysis = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		// Parse the analysis result.
		$result = $this->parse_analysis( $analysis, $sensitivity );

		// Include provider/model/usage metadata for accurate cost tracking.
		if ( ! is_wp_error( $result ) ) {
			if ( $provider ) {
				$result['provider'] = $provider;
			}
			if ( $model ) {
				$result['model'] = $model;
			}
			if ( $usage ) {
				$result['usage'] = $usage;
			}
		}

		return $result;
	}

	/**
	 * Build the analysis prompt.
	 *
	 * @param string $comment_content Comment text.
	 * @param string $comment_author  Author name.
	 * @param string $comment_email   Author email.
	 * @param string $comment_url     Author URL.
	 * @param string $user_ip         Author IP.
	 * @param string $sensitivity     Sensitivity level.
	 * @return string
	 */
	private function build_analysis_prompt( $comment_content, $comment_author, $comment_email, $comment_url, $user_ip, $sensitivity ) {
		$prompt = "Analyze this comment for spam and toxic content. Provide your assessment in JSON format.\n\n";

		$prompt .= 'Comment: "' . $comment_content . "\"\n";

		if ( ! empty( $comment_author ) ) {
			$prompt .= 'Author: ' . $comment_author . "\n";
		}

		if ( ! empty( $comment_email ) ) {
			$prompt .= 'Email: ' . $comment_email . "\n";
		}

		if ( ! empty( $comment_url ) ) {
			$prompt .= 'URL: ' . $comment_url . "\n";
		}

		if ( ! empty( $user_ip ) ) {
			$prompt .= 'IP: ' . $user_ip . "\n";
		}

		$prompt .= "\nSensitivity Level: " . $sensitivity . "\n\n";

		$prompt .= "Evaluate the comment and respond with ONLY a JSON object in this exact format:\n";
		$prompt .= "{\n";
		$prompt .= '  "is_spam": boolean,';
		$prompt .= "\n";
		$prompt .= '  "is_toxic": boolean,';
		$prompt .= "\n";
		$prompt .= '  "toxicity_level": "none" | "low" | "medium" | "high",';
		$prompt .= "\n";
		$prompt .= '  "spam_indicators": ["indicator1", "indicator2"],';
		$prompt .= "\n";
		$prompt .= '  "recommended_action": "approved" | "spam" | "hold",';
		$prompt .= "\n";
		$prompt .= '  "confidence": number between 0 and 1,';
		$prompt .= "\n";
		$prompt .= '  "reason": "brief explanation"';
		$prompt .= "\n}\n\n";

		$prompt .= "Consider:\n";
		$prompt .= "- Spam: promotional content, suspicious links, generic comments, keyword stuffing\n";
		$prompt .= "- Toxic: hate speech, harassment, threats, offensive language\n";
		$prompt .= "- Context: legitimate criticism vs toxic behavior\n";

		switch ( $sensitivity ) {
			case 'low':
				$prompt .= "- Be permissive: Only flag obvious spam and severe toxicity\n";
				break;
			case 'high':
				$prompt .= "- Be strict: Flag anything questionable or borderline inappropriate\n";
				break;
			default:
				$prompt .= "- Be balanced: Flag clear violations but allow legitimate discourse\n";
				break;
		}

		return $prompt;
	}

	/**
	 * Call AI model to analyze comment.
	 *
	 * @param string $prompt   Analysis prompt.
	 * @param string $provider AI provider to use.
	 * @return array|WP_Error Analysis result with metadata or error.
	 */
	private function call_ai_model( $prompt, $provider ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( 'gemini' === $provider ) {
			return $this->call_gemini( $prompt, $settings );
		} else {
			// Default to OpenAI.
			return $this->call_openai( $prompt, $settings );
		}
	}

	/**
	 * Call OpenAI model.
	 *
	 * @param string $prompt   Prompt for the model.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Analysis result with metadata or error.
	 */
	private function call_openai( $prompt, $settings ) {
		$api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'OpenAI API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$model = 'gpt-4o-mini';

		$request_body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are a comment moderation assistant. Analyze comments objectively and provide structured JSON responses.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.3,
			'max_tokens'  => 300,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'OpenAI API returned error code %d.', 'wp-mcp-ai' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from OpenAI API.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Return text with metadata for cost tracking.
		return array(
			'text'     => trim( $body['choices'][0]['message']['content'] ),
			'provider' => 'openai',
			'model'    => isset( $body['model'] ) ? $body['model'] : $model,
			'usage'    => isset( $body['usage'] ) ? array(
				'prompt_tokens'     => isset( $body['usage']['prompt_tokens'] ) ? (int) $body['usage']['prompt_tokens'] : 0,
				'completion_tokens' => isset( $body['usage']['completion_tokens'] ) ? (int) $body['usage']['completion_tokens'] : 0,
				'total_tokens'      => isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : 0,
			) : null,
		);
	}

	/**
	 * Call Gemini model.
	 *
	 * @param string $prompt   Prompt for the model.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Analysis result with metadata or error.
	 */
	private function call_gemini( $prompt, $settings ) {
		$api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$model        = 'gemini-1.5-flash';
		$request_body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature' => 0.3,
			),
		);

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Gemini API returned error code %d.', 'wp-mcp-ai' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Gemini API.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Extract usage metadata if available.
		$usage = null;
		if ( isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ) {
			$usage = array(
				'prompt_tokens'     => isset( $body['usageMetadata']['promptTokenCount'] ) ? (int) $body['usageMetadata']['promptTokenCount'] : 0,
				'completion_tokens' => isset( $body['usageMetadata']['candidatesTokenCount'] ) ? (int) $body['usageMetadata']['candidatesTokenCount'] : 0,
				'total_tokens'      => isset( $body['usageMetadata']['totalTokenCount'] ) ? (int) $body['usageMetadata']['totalTokenCount'] : 0,
			);
		}

		// Return text with metadata for cost tracking.
		return array(
			'text'     => trim( $body['candidates'][0]['content']['parts'][0]['text'] ),
			'provider' => 'gemini',
			'model'    => $model,
			'usage'    => $usage,
		);
	}

	/**
	 * Parse analysis result.
	 *
	 * @param string $analysis    AI response.
	 * @param string $sensitivity Sensitivity level.
	 * @return array|WP_Error Parsed analysis or error.
	 */
	private function parse_analysis( $analysis, $sensitivity ) {
		// Try to extract JSON from the response.
		// Sometimes the model adds markdown code blocks.
		$json_string = $analysis;

		// Remove markdown code blocks if present.
		$json_string = preg_replace( '/```json\s*/', '', $json_string );
		$json_string = preg_replace( '/```\s*$/', '', $json_string );
		$json_string = trim( $json_string );

		$parsed = json_decode( $json_string, true );

		if ( null === $parsed ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_json',
				__( 'Failed to parse AI response as JSON.', 'wp-mcp-ai' ),
				array(
					'status'   => 500,
					'response' => $analysis,
				)
			);
		}

		// Validate and sanitize the parsed result.
		$result = array(
			'is_spam'             => isset( $parsed['is_spam'] ) ? (bool) $parsed['is_spam'] : false,
			'is_toxic'            => isset( $parsed['is_toxic'] ) ? (bool) $parsed['is_toxic'] : false,
			'toxicity_level'      => isset( $parsed['toxicity_level'] ) ? sanitize_text_field( $parsed['toxicity_level'] ) : 'none',
			'spam_indicators'     => isset( $parsed['spam_indicators'] ) && is_array( $parsed['spam_indicators'] ) ? array_map( 'sanitize_text_field', $parsed['spam_indicators'] ) : array(),
			'recommended_action'  => isset( $parsed['recommended_action'] ) ? sanitize_text_field( $parsed['recommended_action'] ) : 'approved',
			'confidence'          => isset( $parsed['confidence'] ) ? floatval( $parsed['confidence'] ) : 0.5,
			'reason'              => isset( $parsed['reason'] ) ? sanitize_text_field( $parsed['reason'] ) : '',
			'sensitivity_applied' => $sensitivity,
		);

		// Ensure recommended_action is valid.
		if ( ! in_array( $result['recommended_action'], array( 'approved', 'spam', 'hold' ), true ) ) {
			$result['recommended_action'] = 'hold';
		}

		// Ensure toxicity_level is valid.
		if ( ! in_array( $result['toxicity_level'], array( 'none', 'low', 'medium', 'high' ), true ) ) {
			$result['toxicity_level'] = 'none';
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'consumes-tokens',           // Uses AI model tokens.
			'requires-capability',  // Requires user capabilities.
			'external-api',              // Makes external API calls.
			'network-dependent',         // Requires internet connectivity.
			'requires-credentials',      // Requires API credentials.
			'read-only',                 // Only reads data, doesn't modify.
			'non-deterministic',         // Results may vary.
		);
	}
}
