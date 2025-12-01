<?php
/**
 * AI Comments Moderation Settings Section
 *
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
			return __( 'AI Comments Moderation', 'wp-mcp-ai' );
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
			return __( 'Configure AI-powered automatic analysis and moderation of comments to detect spam and toxic content.', 'wp-mcp-ai' );
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
					'label'          => __( 'Enable AI Comments Moderation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze comments for spam and toxicity', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, incoming comments will be automatically analyzed by AI to detect spam, toxic content, and other moderation concerns before they are published.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_comments_sensitivity'              => array(
					'type'        => 'select',
					'label'       => __( 'Moderation Sensitivity', 'wp-mcp-ai' ),
					'description' => __( 'Controls how strict the AI moderation should be. Low = permissive (only flag obvious violations), Medium = balanced (flag clear issues), High = strict (flag anything questionable).', 'wp-mcp-ai' ),
					'options'     => array(
						'low'    => __( 'Low (Permissive)', 'wp-mcp-ai' ),
						'medium' => __( 'Medium (Balanced)', 'wp-mcp-ai' ),
						'high'   => __( 'High (Strict)', 'wp-mcp-ai' ),
					),
					'default'     => 'medium',
				),
				'ai_comments_min_confidence'           => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Confidence Level', 'wp-mcp-ai' ),
					'description' => __( 'Only apply AI recommendations when confidence is at or above this threshold. Lower values trust AI more, higher values require more certainty.', 'wp-mcp-ai' ),
					'options'     => array(
						'0.5' => __( '50% (Trust AI more)', 'wp-mcp-ai' ),
						'0.6' => __( '60%', 'wp-mcp-ai' ),
						'0.7' => __( '70% (Balanced - Recommended)', 'wp-mcp-ai' ),
						'0.8' => __( '80%', 'wp-mcp-ai' ),
						'0.9' => __( '90% (Very conservative)', 'wp-mcp-ai' ),
					),
					'default'     => '0.7',
				),
				'ai_comments_auto_hold_low_confidence' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Hold Low Confidence Comments', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Hold comments for manual review when AI confidence is below threshold', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, comments that AI analyzes with low confidence will be held for moderation instead of being published or marked as spam.', 'wp-mcp-ai' ),
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
						<strong><?php esc_html_e( 'How it works:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'AI analyzes comment text, author information, and context', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects spam indicators: promotional content, suspicious links, generic comments', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects toxic content: hate speech, harassment, threats, offensive language', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Provides a recommendation: approve, hold for moderation, or mark as spam', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Comments from logged-in moderators are never automatically flagged', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'AI analysis is stored as comment metadata for review by moderators', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires an AI provider (OpenAI or Gemini) to be configured. Each comment will consume a small amount of AI tokens.',
								'wp-mcp-ai'
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
