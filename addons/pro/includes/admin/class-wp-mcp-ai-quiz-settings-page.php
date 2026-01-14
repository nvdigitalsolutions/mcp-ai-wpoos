<?php
/**
 * Quiz Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Quiz Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Quiz Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'quiz-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant, provider, model).
		parent::register_settings();

		// Add quiz-specific settings section.
		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default Quiz Settings', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_time_limit',
			__( 'Default Time Limit (minutes)', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_time_limit_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_passing_score',
			__( 'Default Passing Score (%)', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_passing_score_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default values for new quizzes.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
			<?php esc_html_e( 'Default time limit for new quizzes. Set to 0 for no time limit.', 'mcp-ai-wpoos-pro' ); ?>
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
			<?php esc_html_e( 'Default minimum percentage required to pass. Must be between 0 and 100.', 'mcp-ai-wpoos-pro' ); ?>
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
			<?php esc_html_e( 'Enable the Research & Add page for quiz topic research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create quizzes using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
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
			$sanitized['default_time_limit'] = absint( $input['default_time_limit'] );
		}

		if ( isset( $input['default_passing_score'] ) ) {
			$passing_score                     = absint( $input['default_passing_score'] );
			$sanitized['default_passing_score'] = max( 0, min( 100, $passing_score ) );
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
