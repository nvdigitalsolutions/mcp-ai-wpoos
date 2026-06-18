<?php
/**
 * Pro Content Template Engine.
 *
 * Converts Content Format Template CPT data into structured AI prompts
 * with variable substitution. Produces Anthropic-optimised XML-sectioned
 * prompts that instruct the AI assistant on content type, tone, word count,
 * heading structure, required sections, and featured image style.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Content_Template_Engine' ) ) {
	/**
	 * Content Template Engine — static methods, no constructor state.
	 *
	 * Builds structured AI prompts from Content Format Template CPT data.
	 */
	class WP_MCP_AI_Content_Template_Engine {

		/**
		 * Build a structured AI prompt from a content format template.
		 *
		 * @since 1.0.0
		 *
		 * @param string $template_slug Template slug (post_name) or post ID.
		 * @param array  $variables     {
		 *     Key-value pairs for substitution in the template.
		 *
		 *     @type string $topic           The blog topic.
		 *     @type string $primary_keyword Primary SEO keyword.
		 *     @type string $audience        Override target audience.
		 *     @type string $tone            Override tone.
		 *     @type int    $word_count      Override target word count.
		 * }
		 * @return string Assembled prompt string, or empty string if template not found.
		 */
		public static function build_prompt( $template_slug, array $variables = array() ) {
			if ( ! class_exists( 'WP_MCP_AI_Content_Format_Template_CPT' ) ) {
				return '';
			}

			$data = WP_MCP_AI_Content_Format_Template_CPT::get_template_data( $template_slug );
			if ( ! $data ) {
				return '';
			}

			// Merge overrides into template data.
			if ( isset( $variables['audience'] ) ) {
				$data['target_audience'] = $variables['audience'];
			}
			if ( isset( $variables['tone'] ) ) {
				$data['tone'] = $variables['tone'];
			}
			if ( isset( $variables['word_count'] ) ) {
				$data['target_word_count_min'] = absint( $variables['word_count'] );
				$data['target_word_count_max'] = absint( $variables['word_count'] );
			}

			$topic           = isset( $variables['topic'] ) ? $variables['topic'] : '{{auto_from_research}}';
			$primary_keyword = isset( $variables['primary_keyword'] ) ? $variables['primary_keyword'] : '';

			return self::assemble_prompt( $data, $topic, $primary_keyword, $variables );
		}

		/**
		 * Assemble the full structured prompt from template data.
		 *
		 * @param array  $data            Template data from get_template_data().
		 * @param string $topic           Blog topic.
		 * @param string $primary_keyword Primary keyword.
		 * @param array  $variables       Additional variables.
		 * @return string Assembled XML-sectioned prompt.
		 */
		protected static function assemble_prompt( array $data, $topic, $primary_keyword, array $variables ) {
			$parts = array();

			// Background information.
			$parts[] = '<background_information>';
			if ( ! empty( $data['target_audience'] ) ) {
				$parts[] = sprintf(
					/* translators: %s: target audience description */
					__( 'Target audience: %s', 'mcp-ai-wpoos-pro' ),
					$data['target_audience']
				);
			}
			$parts[] = sprintf(
				/* translators: %s: tone */
				__( 'Brand voice: %s', 'mcp-ai-wpoos-pro' ),
				ucfirst( $data['tone'] )
			);
			$parts[] = '</background_information>';
			$parts[] = '';

			// Instructions.
			$parts[] = '<instructions>';
			$parts[] = sprintf(
				/* translators: %s: topic */
				__( '1. Research the topic "%s" using research_blog_post.', 'mcp-ai-wpoos-pro' ),
				$topic
			);

			if ( ! empty( $data['required_sections']['featured_image'] ) ) {
				$image_style    = isset( $data['featured_image_style'] ) ? $data['featured_image_style'] : 'photographic';
				$image_provider = isset( $data['featured_image_provider'] ) ? $data['featured_image_provider'] : 'openai';
				$parts[]        = self::get_image_gen_instruction( $image_provider, $image_style );
				$parts[]        = __( '3. Create the post using create_post with featured_image_id set to the attachment_id from Step 2. Set post_status to draft.', 'mcp-ai-wpoos-pro' );
			} else {
				$parts[] = __( '2. Create the post using create_post. Set post_status to draft.', 'mcp-ai-wpoos-pro' );
			}
			$parts[] = '</instructions>';
			$parts[] = '';

			// Output format.
			$parts[] = '<output_format>';
			$parts[] = sprintf(
				/* translators: %s: content type */
				__( 'Content type: %s', 'mcp-ai-wpoos-pro' ),
				ucwords( str_replace( '_', ' ', $data['content_type'] ) )
			);
			$parts[] = sprintf(
				/* translators: 1: min words, 2: max words */
				__( 'Word count: %1$d–%2$d words', 'mcp-ai-wpoos-pro' ),
				$data['target_word_count_min'],
				$data['target_word_count_max']
			);
			$parts[] = sprintf(
				/* translators: %s: tone */
				__( 'Tone: %s', 'mcp-ai-wpoos-pro' ),
				ucfirst( $data['tone'] )
			);

			if ( ! empty( $primary_keyword ) ) {
				$parts[] = sprintf(
					/* translators: %s: primary keyword */
					__( 'Primary keyword: %s', 'mcp-ai-wpoos-pro' ),
					$primary_keyword
				);
			}

			// Required sections.
			$parts[]        = __( 'Required sections:', 'mcp-ai-wpoos-pro' );
			$section_labels = array(
				'seo_title'        => __( 'SEO-optimised title (H1) with primary keyword', 'mcp-ai-wpoos-pro' ),
				'meta_description' => __( 'Meta description (150-160 chars) with call-to-action', 'mcp-ai-wpoos-pro' ),
				'intro_hook'       => __( 'Introduction that hooks the reader with a statistic, question, or story', 'mcp-ai-wpoos-pro' ),
				'data_points'      => __( '3-5 data points or statistics with citations', 'mcp-ai-wpoos-pro' ),
				'internal_links'   => __( 'Internal links to 2-3 related posts', 'mcp-ai-wpoos-pro' ),
				'schema_markup'    => __( 'Schema.org Article markup', 'mcp-ai-wpoos-pro' ),
				'author_bio'       => __( 'Author bio snippet', 'mcp-ai-wpoos-pro' ),
				'cta'              => __( 'Call-to-action at the end', 'mcp-ai-wpoos-pro' ),
				'featured_image'   => __( 'AI-generated featured image', 'mcp-ai-wpoos-pro' ),
			);
			foreach ( $section_labels as $key => $label ) {
				if ( ! empty( $data['required_sections'][ $key ] ) ) {
					$parts[] = '  - ' . $label;
				}
			}

			// Heading structure.
			if ( ! empty( $data['heading_structure'] ) ) {
				$parts[] = '';
				$parts[] = __( 'Heading structure (use as H2 sections):', 'mcp-ai-wpoos-pro' );
				foreach ( $data['heading_structure'] as $heading ) {
					$parts[] = '  - ' . $heading;
				}
			}

			$parts[] = '</output_format>';
			$parts[] = '';

			// Custom instructions.
			if ( ! empty( $data['custom_instructions'] ) ) {
				$parts[] = '<custom_instructions>';
				$parts[] = $data['custom_instructions'];
				$parts[] = '</custom_instructions>';
				$parts[] = '';
			}

			// Constraints.
			$parts[] = '<constraints>';
			$parts[] = __( '- Use only H2 and H3 headings.', 'mcp-ai-wpoos-pro' );
			$parts[] = __( '- Every image must have descriptive alt text.', 'mcp-ai-wpoos-pro' );
			$parts[] = __( '- Never fabricate statistics or claims.', 'mcp-ai-wpoos-pro' );
			$parts[] = __( '- Write in clear, accessible language.', 'mcp-ai-wpoos-pro' );
			$parts[] = '</constraints>';

			$prompt = implode( "\n", $parts );

			/**
			 * Filter the assembled content template prompt.
			 *
			 * @since 1.0.0
			 *
			 * @param string $prompt    The assembled prompt.
			 * @param array  $data      The template data.
			 * @param array  $variables The substitution variables.
			 */
			return apply_filters( 'wp_mcp_ai_content_template_build_prompt', $prompt, $data, $variables );
		}

		/**
		 * Resolve {{variable}} placeholders in a text string.
		 *
		 * @since 1.0.0
		 *
		 * @param string $text      Text containing {{placeholders}}.
		 * @param array  $variables Key-value pairs for substitution.
		 * @return string Text with placeholders resolved.
		 */
		public static function resolve_variables( $text, array $variables ) {
			if ( empty( $variables ) || '' === $text ) {
				return $text;
			}

			foreach ( $variables as $key => $value ) {
				$placeholder = '{{' . $key . '}}';
				if ( is_scalar( $value ) ) {
					$text = str_replace( $placeholder, (string) $value, $text );
				}
			}

			return $text;
		}

		/**
		 * Build a simple prompt (non-XML format) for environments that prefer
		 * plain-text instructions.
		 *
		 * @since 1.0.0
		 *
		 * @param string $template_slug Template slug or post ID.
		 * @param array  $variables     Substitution variables.
		 * @return string Plain-text prompt, or empty string.
		 */
		public static function build_simple_prompt( $template_slug, array $variables = array() ) {
			if ( ! class_exists( 'WP_MCP_AI_Content_Format_Template_CPT' ) ) {
				return '';
			}

			$data = WP_MCP_AI_Content_Format_Template_CPT::get_template_data( $template_slug );
			if ( ! $data ) {
				return '';
			}

			$topic = isset( $variables['topic'] ) ? $variables['topic'] : '{{auto_from_research}}';

			$lines   = array();
			$lines[] = sprintf(
				/* translators: 1: content type, 2: topic */
				__( 'Draft a %1$s about: %2$s', 'mcp-ai-wpoos-pro' ),
				ucwords( str_replace( '_', ' ', $data['content_type'] ) ),
				$topic
			);

			if ( ! empty( $data['target_audience'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: audience */
					__( 'Target audience: %s.', 'mcp-ai-wpoos-pro' ),
					$data['target_audience']
				);
			}

			$lines[] = sprintf(
				/* translators: 1: min, 2: max, 3: tone */
				__( 'Write %1$d–%2$d words in a %3$s tone.', 'mcp-ai-wpoos-pro' ),
				$data['target_word_count_min'],
				$data['target_word_count_max'],
				$data['tone']
			);

			if ( ! empty( $data['heading_structure'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: comma-separated headings */
					__( 'Use these H2 headings: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $data['heading_structure'] )
				);
			}

			if ( ! empty( $data['custom_instructions'] ) ) {
				$lines[] = $data['custom_instructions'];
			}

			return implode( ' ', $lines );
		}

		/**
		 * Convert template section toggles into a human-readable list for prompts.
		 *
		 * @since 1.0.0
		 *
		 * @param array $sections Required sections toggle map.
		 * @return string Comma-separated list of enabled sections.
		 */
		public static function sections_to_text( array $sections ) {
			$labels = array(
				'seo_title'        => __( 'SEO title', 'mcp-ai-wpoos-pro' ),
				'meta_description' => __( 'meta description', 'mcp-ai-wpoos-pro' ),
				'intro_hook'       => __( 'introduction hook', 'mcp-ai-wpoos-pro' ),
				'data_points'      => __( 'data points with citations', 'mcp-ai-wpoos-pro' ),
				'internal_links'   => __( 'internal links', 'mcp-ai-wpoos-pro' ),
				'schema_markup'    => __( 'schema markup', 'mcp-ai-wpoos-pro' ),
				'author_bio'       => __( 'author bio', 'mcp-ai-wpoos-pro' ),
				'cta'              => __( 'call-to-action', 'mcp-ai-wpoos-pro' ),
				'featured_image'   => __( 'featured image', 'mcp-ai-wpoos-pro' ),
			);

			$enabled = array();
			foreach ( $labels as $key => $label ) {
				if ( ! empty( $sections[ $key ] ) ) {
					$enabled[] = $label;
				}
			}

			return implode( ', ', $enabled );
		}

		/**
		 * Build the AI instruction string for featured image generation.
		 *
		 * Produces a provider-specific tool-call instruction so the AI calls the
		 * correct tool with the right arguments.  Each provider uses different
		 * argument shapes: OpenAI takes size/model/quality, Gemini takes
		 * aspect_ratio, and Cloudflare only needs the prompt.
		 *
		 * @since 1.0.0
		 *
		 * @param string $provider Image generation provider slug.
		 * @param string $style    Image style slug.
		 * @return string Formatted instruction line.
		 */
		protected static function get_image_gen_instruction( $provider, $style ) {
			$style_label = ucfirst( $style );

			switch ( $provider ) {
				case 'gemini':
					return sprintf(
						/* translators: %s: image style */
						__( '2. Generate a featured image using generate_gemini_image with the prompt: "Professional %s blog featured image for: {article title}". The tool uses the model and aspect ratio from Provider Settings. Capture the attachment_id from the result.', 'mcp-ai-wpoos-pro' ),
						$style_label
					);

				case 'cloudflare':
					return sprintf(
						/* translators: %s: image style */
						__( '2. Generate a featured image using generate_cloudflareai_image with the prompt: "Professional %s blog featured image for: {article title}". Capture the attachment_id from the result.', 'mcp-ai-wpoos-pro' ),
						$style_label
					);

				case 'openai':
				default:
					return sprintf(
						/* translators: %s: image style */
						__( '2. Generate a featured image using generate_openai_image with the prompt: "Professional %s blog featured image for: {article title}". The tool uses the model and quality from Provider Settings. Capture the attachment_id from the result.', 'mcp-ai-wpoos-pro' ),
						$style_label
					);
			}
		}
	}
}
