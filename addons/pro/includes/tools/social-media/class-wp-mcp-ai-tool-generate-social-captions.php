<?php
/**
 * Tool for generating AI-powered social media captions.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Social_Media_Toolkit
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates AI-powered social media caption suggestions for posts.
 *
 * Returns structured prompts and context that the calling AI assistant
 * can use to generate platform-appropriate captions. This tool does not
 * call an external AI service directly; it prepares the ground for the
 * assistant to produce the actual captions.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Generate_Social_Captions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_social_captions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Social Captions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates AI-powered social media captions for posts. Returns caption suggestions that can be reviewed before publishing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'          => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID to generate captions for.', 'mcp-ai-wpoos-pro' ),
				),
				'platform'         => array(
					'type'        => 'string',
					'description' => __( 'Target social media platform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'facebook', 'instagram', 'twitter', 'linkedin' ),
				),
				'tone'             => array(
					'type'        => 'string',
					'description' => __( 'Desired tone for the captions.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'professional', 'casual', 'humorous', 'informative' ),
					'default'     => 'professional',
				),
				'count'            => array(
					'type'        => 'integer',
					'description' => __( 'Number of caption variations to generate (1-5).', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
					'minimum'     => 1,
					'maximum'     => 5,
				),
				'include_hashtags' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include hashtag suggestions.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'post_id', 'platform' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'social_media',
			'post_type'             => 'post',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'social_media_manager', 'content_manager', 'copywriter' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Social Media Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'The social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Generate Social Captions tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate social media captions.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse and sanitize arguments.
		$post_id          = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$platform         = isset( $arguments['platform'] ) ? sanitize_text_field( $arguments['platform'] ) : 'facebook';
		$tone             = isset( $arguments['tone'] ) ? sanitize_text_field( $arguments['tone'] ) : 'professional';
		$count            = isset( $arguments['count'] ) ? absint( $arguments['count'] ) : 3;
		$include_hashtags = isset( $arguments['include_hashtags'] ) ? (bool) $arguments['include_hashtags'] : true;

		// Validate post exists.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					__( 'The specified post was not found.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Clamp count to valid range.
		$count = max( 1, min( 5, $count ) );

		// Platform-specific character limits and tone guidance.
		$platform_specs = array(
			'facebook'  => array(
				'max_length'    => 63206,
				'optimal_range' => '40-150',
				'hashtag_style' => 'minimal (1-3)',
			),
			'instagram' => array(
				'max_length'    => 2200,
				'optimal_range' => '138-150',
				'hashtag_style' => 'generous (5-30)',
			),
			'twitter'   => array(
				'max_length'    => 280,
				'optimal_range' => '71-100',
				'hashtag_style' => 'targeted (1-2)',
			),
			'linkedin'  => array(
				'max_length'    => 3000,
				'optimal_range' => '100-200',
				'hashtag_style' => 'professional (1-5)',
			),
		);

		// Build post context for the AI to use.
		$post_context = array(
			'post_id'    => $post_id,
			'title'      => $post ? $post->post_title : '',
			'excerpt'    => $post ? wp_strip_all_tags( $post->post_excerpt ) : '',
			'permalink'  => $post ? get_permalink( $post ) : '',
			'categories' => $post ? wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) : array(),
			'tags'       => $post ? wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) : array(),
		);

		// Build the structured prompt context.
		$prompt_context = array(
			'platform'         => $platform,
			'tone'             => $tone,
			'count'            => $count,
			'include_hashtags' => $include_hashtags,
			'platform_specs'   => isset( $platform_specs[ $platform ] ) ? $platform_specs[ $platform ] : $platform_specs['facebook'],
			'post_context'     => $post_context,
		);

		// Return structured context for the AI to generate captions.
		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %1$s: platform name, %2$d: number of captions requested */
				__( 'Caption generation context prepared for %1$s (%2$d variations requested). Use this context to generate the captions.', 'mcp-ai-wpoos-pro' ),
				ucfirst( $platform ),
				$count
			),
			'prompt_context' => $prompt_context,
			'tone_guidance'  => $this->get_tone_guidance( $tone ),
		);
	}

	/**
	 * Get tone-specific guidance for caption generation.
	 *
	 * @since 2.8.0
	 * @param string $tone The desired tone.
	 * @return array Tone guidance.
	 */
	private function get_tone_guidance( $tone ) {
		$guidance = array(
			'professional' => array(
				'style' => __( 'Formal, polished, authoritative.', 'mcp-ai-wpoos-pro' ),
				'voice' => __( 'Industry expert sharing valuable insights.', 'mcp-ai-wpoos-pro' ),
				'avoid' => __( 'Slang, excessive emoji, overly casual language.', 'mcp-ai-wpoos-pro' ),
			),
			'casual'       => array(
				'style' => __( 'Friendly, conversational, approachable.', 'mcp-ai-wpoos-pro' ),
				'voice' => __( 'Friend sharing something interesting.', 'mcp-ai-wpoos-pro' ),
				'avoid' => __( 'Jargon, stiff formality, corporate speak.', 'mcp-ai-wpoos-pro' ),
			),
			'humorous'     => array(
				'style' => __( 'Witty, playful, engaging.', 'mcp-ai-wpoos-pro' ),
				'voice' => __( 'Entertainer making the audience smile.', 'mcp-ai-wpoos-pro' ),
				'avoid' => __( 'Offensive humor, dark topics, sarcasm that could be misread.', 'mcp-ai-wpoos-pro' ),
			),
			'informative'  => array(
				'style' => __( 'Educational, clear, data-driven.', 'mcp-ai-wpoos-pro' ),
				'voice' => __( 'Teacher breaking down complex topics.', 'mcp-ai-wpoos-pro' ),
				'avoid' => __( 'Fluff, vague claims, unsupported assertions.', 'mcp-ai-wpoos-pro' ),
			),
		);

		return isset( $guidance[ $tone ] ) ? $guidance[ $tone ] : $guidance['professional'];
	}
}
