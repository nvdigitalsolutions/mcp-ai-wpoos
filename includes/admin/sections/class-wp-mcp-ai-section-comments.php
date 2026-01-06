<?php
/**
 * AI Comments Moderation Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Comments' ) ) {
	/**
	 * Comments moderation AI features settings section.
	 */
	class WP_MCP_AI_Section_Comments extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'comments';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'AI Comments Moderation', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 40;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure AI-powered automatic analysis and moderation of comments to detect spam and toxic content.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/ai-providers/openai/OPENAI_API_GAP_ANALYSIS.md#moderation-api';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'enable_ai_comments_moderation'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Comments Moderation', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically analyze comments for spam and toxicity', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, incoming comments will be automatically analyzed by AI to detect spam, toxic content, and other moderation concerns before they are published.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'ai_comments_sensitivity'              => array(
					'type'        => 'select',
					'label'       => __( 'Moderation Sensitivity', 'mcp-ai-wpoos' ),
					'description' => __( 'Controls how strict the AI moderation should be. Low = permissive (only flag obvious violations), Medium = balanced (flag clear issues), High = strict (flag anything questionable).', 'mcp-ai-wpoos' ),
					'options'     => array(
						'low'    => __( 'Low (Permissive)', 'mcp-ai-wpoos' ),
						'medium' => __( 'Medium (Balanced)', 'mcp-ai-wpoos' ),
						'high'   => __( 'High (Strict)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'medium',
				),
				'ai_comments_min_confidence'           => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Confidence Level', 'mcp-ai-wpoos' ),
					'description' => __( 'Only apply AI recommendations when confidence is at or above this threshold. Lower values trust AI more, higher values require more certainty.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'0.5' => __( '50% (Trust AI more)', 'mcp-ai-wpoos' ),
						'0.6' => __( '60%', 'mcp-ai-wpoos' ),
						'0.7' => __( '70% (Balanced - Recommended)', 'mcp-ai-wpoos' ),
						'0.8' => __( '80%', 'mcp-ai-wpoos' ),
						'0.9' => __( '90% (Very conservative)', 'mcp-ai-wpoos' ),
					),
					'default'     => '0.7',
				),
				'ai_comments_auto_hold_low_confidence' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Hold Low Confidence Comments', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Hold comments for manual review when AI confidence is below threshold', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, comments that AI analyzes with low confidence will be held for moderation instead of being published or marked as spam.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
			);
		}

		/**
		 * Render the section.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}

			// Add informational note about how it works.
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How it works:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'AI analyzes comment text, author information, and context', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Detects spam indicators: promotional content, suspicious links, generic comments', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Detects toxic content: hate speech, harassment, threats, offensive language', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Provides a recommendation: approve, hold for moderation, or mark as spam', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Comments from logged-in moderators are never automatically flagged', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'AI analysis is stored as comment metadata for review by moderators', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires an AI provider (OpenAI or Gemini) to be configured. Each comment will consume a small amount of AI tokens.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}
	}
}
