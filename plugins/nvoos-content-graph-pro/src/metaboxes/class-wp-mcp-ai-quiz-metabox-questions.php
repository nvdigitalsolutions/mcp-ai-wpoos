<?php
/**
 * metaboxes/class-wp-mcp-ai-quiz-metabox-questions.php (ecosystem port — Wave F5, quiz-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/metaboxes/class-wp-mcp-ai-quiz-metabox-questions.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Quiz Questions metabox for quiz posts.
 *
 * Manages adding, editing, removing, and reordering quiz questions.
 */
class WP_MCP_AI_Quiz_Metabox_Questions extends WP_MCP_AI_Quiz_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_quiz_questions';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Quiz Questions', 'nvoos-content-graph-pro' );
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		// Enqueue scripts and styles.
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'wp-mcp-ai-quiz-questions',
			NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/js/quiz-questions.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			NVOOS_CONTENT_GRAPH_PRO_VERSION,
			true
		);

		wp_enqueue_style(
			'wp-mcp-ai-quiz-admin',
			NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/quiz-admin.css',
			array(),
			NVOOS_CONTENT_GRAPH_PRO_VERSION
		);

		// Get existing questions.
		$questions = get_post_meta( $post->ID, '_mcp_ai_quiz_questions', true );
		if ( ! is_array( $questions ) ) {
			$questions = array();
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_quiz_questions_nonce', 'wp_mcp_ai_quiz_questions_nonce' );
		?>
		<div class="wp-mcp-ai-quiz-questions-wrapper">
			<div id="wp-mcp-ai-quiz-questions-container" class="quiz-questions-container">
				<?php
				if ( ! empty( $questions ) ) {
					foreach ( $questions as $index => $question ) {
						$this->render_question_row( $index, $question );
					}
				}
				?>
			</div>

			<p>
				<button type="button" id="wp-mcp-ai-add-question" class="button button-secondary">
					<?php esc_html_e( '+ Add Question', 'nvoos-content-graph-pro' ); ?>
				</button>
			</p>

			<!-- Question template (hidden, used by JavaScript) -->
			<script type="text/template" id="wp-mcp-ai-question-template">
				<?php $this->render_question_row( '{INDEX}', array() ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Render a single question row.
	 *
	 * @param int|string $index    Question index.
	 * @param array      $question Question data.
	 * @return void
	 */
	protected function render_question_row( $index, $question = array() ) {
		$question_text  = isset( $question['question'] ) ? $question['question'] : '';
		$type           = isset( $question['type'] ) ? $question['type'] : 'multiple_choice';
		$points         = isset( $question['points'] ) ? $question['points'] : 1;
		$correct_answer = isset( $question['correct_answer'] ) ? $question['correct_answer'] : '';
		$options        = isset( $question['options'] ) && is_array( $question['options'] ) ? $question['options'] : array( '', '', '' );
		?>
		<div class="quiz-question-row" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="question-header">
				<span class="question-number"><?php echo esc_html( sprintf( /* translators: %s: question number */ __( 'Question %s', 'nvoos-content-graph-pro' ), '{NUMBER}' ) ); ?></span>
				<span class="question-handle dashicons dashicons-move"></span>
				<button type="button" class="button-link remove-question" title="<?php esc_attr_e( 'Remove Question', 'nvoos-content-graph-pro' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>

			<div class="question-fields">
				<p>
					<label>
						<strong><?php esc_html_e( 'Question Text:', 'nvoos-content-graph-pro' ); ?></strong>
					</label>
					<textarea
						name="wp_mcp_ai_questions[<?php echo esc_attr( $index ); ?>][question]"
						class="widefat question-text"
						rows="3"
						required
					><?php echo esc_textarea( $question_text ); ?></textarea>
				</p>

				<div class="question-meta-row">
					<p class="question-type-field">
						<label>
							<strong><?php esc_html_e( 'Type:', 'nvoos-content-graph-pro' ); ?></strong>
						</label>
						<select name="wp_mcp_ai_questions[<?php echo esc_attr( $index ); ?>][type]" class="question-type">
							<option value="multiple_choice" <?php selected( $type, 'multiple_choice' ); ?>><?php esc_html_e( 'Multiple Choice', 'nvoos-content-graph-pro' ); ?></option>
							<option value="true_false" <?php selected( $type, 'true_false' ); ?>><?php esc_html_e( 'True/False', 'nvoos-content-graph-pro' ); ?></option>
							<option value="short_answer" <?php selected( $type, 'short_answer' ); ?>><?php esc_html_e( 'Short Answer', 'nvoos-content-graph-pro' ); ?></option>
						</select>
					</p>

					<p class="question-points-field">
						<label>
							<strong><?php esc_html_e( 'Points:', 'nvoos-content-graph-pro' ); ?></strong>
						</label>
						<input
							type="number"
							name="wp_mcp_ai_questions[<?php echo esc_attr( $index ); ?>][points]"
							value="<?php echo esc_attr( $points ); ?>"
							min="1"
							step="1"
							class="small-text"
						/>
					</p>
				</div>

				<!-- Multiple Choice Options -->
				<div class="question-options multiple-choice-options" style="<?php echo ( 'multiple_choice' !== $type ) ? 'display:none;' : ''; ?>">
					<label><strong><?php esc_html_e( 'Answer Options:', 'nvoos-content-graph-pro' ); ?></strong></label>
					<div class="options-list">
						<?php
						foreach ( $options as $opt_index => $option_value ) {
							?>
							<div class="option-row">
								<input
									type="text"
									name="wp_mcp_ai_questions[<?php echo esc_attr( $index ); ?>][options][]"
									value="<?php echo esc_attr( $option_value ); ?>"
									placeholder="<?php esc_attr_e( 'Option text', 'nvoos-content-graph-pro' ); ?>"
									class="widefat"
								/>
								<button type="button" class="button-link remove-option" title="<?php esc_attr_e( 'Remove option', 'nvoos-content-graph-pro' ); ?>">
									<span class="dashicons dashicons-no-alt"></span>
								</button>
							</div>
							<?php
						}
						?>
					</div>
					<button type="button" class="button button-small add-option"><?php esc_html_e( '+ Add Option', 'nvoos-content-graph-pro' ); ?></button>
				</div>

				<!-- Correct Answer -->
				<p class="correct-answer-field">
					<label>
						<strong><?php esc_html_e( 'Correct Answer (for grading reference):', 'nvoos-content-graph-pro' ); ?></strong>
					</label>
					<input
						type="text"
						name="wp_mcp_ai_questions[<?php echo esc_attr( $index ); ?>][correct_answer]"
						value="<?php echo esc_attr( $correct_answer ); ?>"
						class="widefat"
						placeholder="<?php esc_attr_e( 'Optional: Enter the correct answer', 'nvoos-content-graph-pro' ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'This is used as a reference for grading. For true/false, use "true" or "false".', 'nvoos-content-graph-pro' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_quiz_questions_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_quiz_questions_nonce'] ) ), 'wp_mcp_ai_quiz_questions_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Process questions.
		$questions    = array();
		$total_points = 0;

		if ( isset( $_POST['wp_mcp_ai_questions'] ) && is_array( $_POST['wp_mcp_ai_questions'] ) ) {
			foreach ( wp_unslash( $_POST['wp_mcp_ai_questions'] ) as $question_data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array elements sanitized individually below.
				// Validate question text.
				if ( empty( $question_data['question'] ) ) {
					continue;
				}

				$question = array(
					'question'       => sanitize_textarea_field( $question_data['question'] ),
					'type'           => sanitize_text_field( $question_data['type'] ),
					'points'         => absint( $question_data['points'] ),
					'correct_answer' => sanitize_text_field( $question_data['correct_answer'] ),
				);

				// Validate type.
				if ( ! in_array( $question['type'], array( 'multiple_choice', 'true_false', 'short_answer' ), true ) ) {
					$question['type'] = 'multiple_choice';
				}

				// Process options for multiple choice.
				if ( 'multiple_choice' === $question['type'] && isset( $question_data['options'] ) && is_array( $question_data['options'] ) ) {
					$question['options'] = array();
					foreach ( $question_data['options'] as $option ) {
						$option = sanitize_text_field( $option );
						if ( ! empty( $option ) ) {
							$question['options'][] = $option;
						}
					}
				}

				$questions[]   = $question;
				$total_points += $question['points'];
			}
		}

		// Save questions.
		update_post_meta( $post_id, '_mcp_ai_quiz_questions', $questions );
		update_post_meta( $post_id, '_mcp_ai_quiz_total_points', $total_points );

		// Also save description from content editor.
		if ( isset( $_POST['content'] ) ) {
			$description = wp_kses_post( wp_unslash( $_POST['content'] ) );
			update_post_meta( $post_id, '_mcp_ai_quiz_description', $description );
		}
	}
}
