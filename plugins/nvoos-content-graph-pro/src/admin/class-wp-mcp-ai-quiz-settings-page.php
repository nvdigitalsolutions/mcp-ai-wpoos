<?php
/**
 * admin/class-wp-mcp-ai-quiz-settings-page.php (ecosystem port — Wave F5, quiz-management admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-quiz-settings-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the base-owned `WP_MCP_AI_URL`/`WP_MCP_AI_VERSION`
 * enhanced-research-page asset refs stay byte-identical — page-hook-gated, PM/calendar precedent).
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

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Quiz Settings Page
 */
class WP_MCP_AI_Quiz_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_quiz_settings';
		$this->post_type   = 'mcp_ai_quiz';
		$this->page_title  = __( 'Quiz Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'quiz-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.2.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Quiz Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Comprehensive quiz creation and management system with AI-powered research, grading, analytics, and student submissions.', 'nvoos-content-graph-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Quiz Creation: Build quizzes with multiple question types and AI assistance', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'AI Research: Generate quiz questions from topics using AI research', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Student Submissions: Manage quiz attempts and student responses', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Auto-Grading: Automated grading for multiple choice and objective questions', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Analytics: Track completion rates, average scores, and question difficulty', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Time Limits: Configure time-limited quizzes for assessments', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Passing Scores: Set minimum passing percentages and certificates', 'nvoos-content-graph-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'nvoos-content-graph-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Online learning platforms and educational institutions', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Employee training and certification programs', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Knowledge assessments and skill evaluations', 'nvoos-content-graph-pro' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 1.2.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			'create_quiz'          => __( 'Create Quiz', 'nvoos-content-graph-pro' ),
			'list_quizzes'         => __( 'List Quizzes', 'nvoos-content-graph-pro' ),
			'get_quiz'             => __( 'Get Quiz', 'nvoos-content-graph-pro' ),
			'update_quiz'          => __( 'Update Quiz', 'nvoos-content-graph-pro' ),
			'delete_quiz'          => __( 'Delete Quiz', 'nvoos-content-graph-pro' ),
			'research_quiz_topic'  => __( 'Research Quiz Topic', 'nvoos-content-graph-pro' ),
			'submit_quiz_answer'   => __( 'Submit Quiz Answer', 'nvoos-content-graph-pro' ),
			'get_quiz_submissions' => __( 'Get Quiz Submissions', 'nvoos-content-graph-pro' ),
			'get_quiz_results'     => __( 'Get Quiz Results', 'nvoos-content-graph-pro' ),
			'grade_quiz'           => __( 'Grade Quiz', 'nvoos-content-graph-pro' ),
			'get_quiz_analytics'   => __( 'Get Quiz Analytics', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add quiz-specific settings section.
		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default Quiz Settings', 'nvoos-content-graph-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_time_limit',
			__( 'Default Time Limit (minutes)', 'nvoos-content-graph-pro' ),
			array( $this, 'render_default_time_limit_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_passing_score',
			__( 'Default Passing Score (%)', 'nvoos-content-graph-pro' ),
			array( $this, 'render_default_passing_score_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'nvoos-content-graph-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default values for new quizzes.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render default time limit field.
	 */
	public function render_default_time_limit_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_time_limit'] ) ? absint( $options['default_time_limit'] ) : 0;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[default_time_limit]"
			id="default_time_limit"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			step="1"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Default time limit for new quizzes. Set to 0 for no time limit.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render default passing score field.
	 */
	public function render_default_passing_score_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_passing_score'] ) ? absint( $options['default_passing_score'] ) : 70;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[default_passing_score]"
			id="default_passing_score"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			max="100"
			step="1"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Default minimum percentage required to pass. Must be between 0 and 100.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for quiz topic research', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create quizzes using AI assistance.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add quiz-specific sanitization.
		if ( isset( $input['default_time_limit'] ) ) {
			// Cast rather than absint(): absint( -10 ) would coerce a negative
			// time limit into a positive value (10); negatives must clamp to 0.
			$sanitized['default_time_limit'] = max( 0, (int) $input['default_time_limit'] );
		}

		if ( isset( $input['default_passing_score'] ) ) {
			// absint() flips negatives to positives; clamp with max(0, ...)
			// so out-of-range low values resolve to 0 instead of a positive.
			$passing_score                      = max( 0, min( 100, (int) $input['default_passing_score'] ) );
			$sanitized['default_passing_score'] = $passing_score;
		}

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Quiz_Settings_Page();
