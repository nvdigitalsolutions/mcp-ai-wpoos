<?php
/**
 * Fantasy Football Settings Page
 *
 * Provides tabbed settings page for configuring AI assistant
 * and Fantasy Football toolkit preferences.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Fantasy Football Settings Page
 */
class WP_MCP_AI_Fantasy_Football_Settings extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_fantasy_football_settings';
		$this->post_type   = 'ff_team';
		$this->page_title  = __( 'Fantasy Football Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'fantasy-football-settings';

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
		<h2><?php esc_html_e( 'Fantasy Football Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Comprehensive fantasy football management system with Yahoo Fantasy Sports API integration, AI-powered team logo generation, league reports, and player research.', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Yahoo Fantasy Sports Integration: Connect with Yahoo Fantasy Sports API to sync leagues, rosters, and player data', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'League Management: Track team standings, win/loss records, and points for/against', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Trade Analysis: AI-powered trade analyzer to evaluate potential trades', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Team Branding: Generate custom team logos with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'League Reports: Create comprehensive league reports with AI analysis', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Player Research: AI-powered player research and watchlist management', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Fantasy football league commissioners managing multiple leagues', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Sports enthusiasts tracking team performance and player stats', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Content creators producing fantasy football analysis and reports', 'mcp-ai-wpoos-pro' ); ?></li>
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
			// Yahoo Fantasy Football API tools.
			'yahoo_ff_auth'             => __( 'Yahoo Fantasy Sports Authentication', 'mcp-ai-wpoos-pro' ),
			'yahoo_ff_get_leagues'      => __( 'Get User Leagues', 'mcp-ai-wpoos-pro' ),
			'yahoo_ff_get_roster'       => __( 'Get Team Roster', 'mcp-ai-wpoos-pro' ),
			'yahoo_ff_get_player_stats' => __( 'Get Player Statistics', 'mcp-ai-wpoos-pro' ),
			'yahoo_ff_trade_analyzer'   => __( 'Analyze Trade Proposals', 'mcp-ai-wpoos-pro' ),
			'yahoo_ff_league_standings' => __( 'Get League Standings', 'mcp-ai-wpoos-pro' ),
			// Fantasy Football specific tools.
			'ff_generate_team_logo'     => __( 'Generate Team Logo', 'mcp-ai-wpoos-pro' ),
			'ff_create_league_report'   => __( 'Create League Report', 'mcp-ai-wpoos-pro' ),
			'ff_player_research'        => __( 'Player Research & Watchlist', 'mcp-ai-wpoos-pro' ),
			// Research and analysis tools.
			'web_search'                => __( 'Web Search', 'mcp-ai-wpoos-pro' ),
			'deep_research'             => __( 'Deep Research', 'mcp-ai-wpoos-pro' ),
			'search_content'            => __( 'Search Site Content', 'mcp-ai-wpoos-pro' ),
			'semantic_content_search'   => __( 'Semantic Content Search', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add Yahoo API Credentials notice section - credentials now managed in Connections.
		add_settings_section(
			$this->option_name . '_yahoo_api_section',
			__( 'Yahoo Fantasy Sports API', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_yahoo_api_section' ),
			$this->option_name
		);

		// Default Preferences section.
		add_settings_section(
			$this->option_name . '_preferences_section',
			__( 'Default Preferences', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_preferences_section' ),
			$this->option_name
		);

		add_settings_field(
			'default_season',
			__( 'Default Season', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_text_field' ),
			$this->option_name,
			$this->option_name . '_preferences_section',
			array(
				'id'          => 'default_season',
				'type'        => 'number',
				'placeholder' => gmdate( 'Y' ),
			)
		);

		add_settings_field(
			'auto_sync',
			__( 'Auto-Sync Teams', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_checkbox_field' ),
			$this->option_name,
			$this->option_name . '_preferences_section',
			array(
				'id'          => 'auto_sync',
				'description' => __( 'Automatically sync team data from Yahoo daily', 'mcp-ai-wpoos-pro' ),
			)
		);

		// Team Branding Defaults section.
		add_settings_section(
			$this->option_name . '_branding_section',
			__( 'Team Branding Defaults', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_branding_section' ),
			$this->option_name
		);

		add_settings_field(
			'default_logo_style',
			__( 'Default Logo Style', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_select_field' ),
			$this->option_name,
			$this->option_name . '_branding_section',
			array(
				'id'      => 'default_logo_style',
				'options' => array(
					'modern'     => __( 'Modern', 'mcp-ai-wpoos-pro' ),
					'classic'    => __( 'Classic', 'mcp-ai-wpoos-pro' ),
					'minimalist' => __( 'Minimalist', 'mcp-ai-wpoos-pro' ),
					'mascot'     => __( 'Mascot', 'mcp-ai-wpoos-pro' ),
					'emblem'     => __( 'Emblem', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		add_settings_field(
			'default_team_color',
			__( 'Default Team Color', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_color_field' ),
			$this->option_name,
			$this->option_name . '_branding_section',
			array(
				'id' => 'default_team_color',
			)
		);

		// Report Preferences section.
		add_settings_section(
			$this->option_name . '_report_section',
			__( 'Report Preferences', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_report_section' ),
			$this->option_name
		);

		add_settings_field(
			'include_charts_default',
			__( 'Include Charts', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_checkbox_field' ),
			$this->option_name,
			$this->option_name . '_report_section',
			array(
				'id'          => 'include_charts_default',
				'description' => __( 'Include Chart.js visualizations in reports by default', 'mcp-ai-wpoos-pro' ),
			)
		);

		add_settings_field(
			'include_analysis_default',
			__( 'Include AI Analysis', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_checkbox_field' ),
			$this->option_name,
			$this->option_name . '_report_section',
			array(
				'id'          => 'include_analysis_default',
				'description' => __( 'Include AI-powered insights in reports by default', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Render Yahoo API section description.
	 */
	public function render_yahoo_api_section() {
		?>
		<p>
			<?php
			printf(
				/* translators: %1$s: URL to connections settings, %2$s: Yahoo Developer URL */
				esc_html__( 'Yahoo Fantasy Sports API credentials are now managed in the %1$s. Once configured there, the credentials will be automatically available to all Fantasy Football tools. To create your Yahoo application and get credentials, visit %2$s.', 'mcp-ai-wpoos-pro' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=yahoo_sports' ) ) . '">' . esc_html__( 'Connections Settings', 'mcp-ai-wpoos-pro' ) . '</a>',
				'<a href="https://developer.yahoo.com/apps/" target="_blank">Yahoo Developer Network</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render preferences section description.
	 */
	public function render_preferences_section() {
		?>
		<p><?php esc_html_e( 'Configure default preferences for fantasy football management.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render branding section description.
	 */
	public function render_branding_section() {
		?>
		<p><?php esc_html_e( 'Set default branding options for new team logos and graphics.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render report section description.
	 */
	public function render_report_section() {
		?>
		<p><?php esc_html_e( 'Configure default options for league reports.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$settings    = get_option( $this->option_name, array() );
		$value       = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		$type        = isset( $args['type'] ) ? $args['type'] : 'text';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		?>
		<input 
			type="<?php echo esc_attr( $type ); ?>" 
			id="<?php echo esc_attr( $args['id'] ); ?>" 
			name="<?php echo esc_attr( $this->option_name . '[' . $args['id'] . ']' ); ?>" 
			value="<?php echo esc_attr( $value ); ?>" 
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
		/>
		<?php
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( $args ) {
		$settings    = get_option( $this->option_name, array() );
		$value       = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : false;
		$description = isset( $args['description'] ) ? $args['description'] : '';
		?>
		<label>
			<input 
				type="checkbox" 
				id="<?php echo esc_attr( $args['id'] ); ?>" 
				name="<?php echo esc_attr( $this->option_name . '[' . $args['id'] . ']' ); ?>" 
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php echo esc_html( $description ); ?>
		</label>
		<?php
	}

	/**
	 * Render select field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_select_field( $args ) {
		$settings = get_option( $this->option_name, array() );
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		?>
		<select 
			id="<?php echo esc_attr( $args['id'] ); ?>" 
			name="<?php echo esc_attr( $this->option_name . '[' . $args['id'] . ']' ); ?>"
		>
			<option value=""><?php esc_html_e( '-- Select --', 'mcp-ai-wpoos-pro' ); ?></option>
			<?php foreach ( $args['options'] as $option_value => $option_label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
					<?php echo esc_html( $option_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render color field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_color_field( $args ) {
		$settings = get_option( $this->option_name, array() );
		$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '#000000';
		?>
		<input 
			type="color" 
			id="<?php echo esc_attr( $args['id'] ); ?>" 
			name="<?php echo esc_attr( $this->option_name . '[' . $args['id'] . ']' ); ?>" 
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = parent::sanitize_settings( $input );

		// Text fields (yahoo credentials removed - now in Connections settings).
		$text_fields = array( 'default_season', 'default_logo_style' );
		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		// Checkbox fields.
		$checkbox_fields = array( 'auto_sync', 'include_charts_default', 'include_analysis_default' );
		foreach ( $checkbox_fields as $field ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) && '1' === $input[ $field ];
		}

		// Color field.
		if ( isset( $input['default_team_color'] ) ) {
			$sanitized['default_team_color'] = sanitize_hex_color( $input['default_team_color'] );
		}

		return $sanitized;
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Default value if not set.
	 * @return mixed Setting value.
	 */
	public static function get_setting( $key, $default_value = null ) {
		$settings = get_option( 'wp_mcp_ai_fantasy_football_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
	}
}
