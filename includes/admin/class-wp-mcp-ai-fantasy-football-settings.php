<?php
/**
 * Fantasy Football Settings Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Fantasy Football settings administration page.
 */
class WP_MCP_AI_Fantasy_Football_Settings {
const PAGE_SLUG     = 'wp-mcp-ai-fantasy-football-settings';
const OPTION_GROUP  = 'wp_mcp_ai_fantasy_football';
const SETTINGS_KEY  = 'wp_mcp_ai_fantasy_football_settings';

/**
 * Constructor.
 */
public function __construct() {
add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
add_action( 'admin_init', array( $this, 'register_settings' ) );
}

/**
 * Register the Fantasy Football settings page.
 */
public function register_settings_page() {
add_submenu_page(
'edit.php?post_type=mcp_ai_assistant',
__( 'Fantasy Football Settings', 'mcp-ai-wpoos' ),
__( 'FF Settings', 'mcp-ai-wpoos' ),
'manage_options',
self::PAGE_SLUG,
array( $this, 'render_settings_page' )
);
}

/**
 * Register settings with WordPress Settings API.
 */
public function register_settings() {
register_setting(
self::OPTION_GROUP,
self::SETTINGS_KEY,
array(
'sanitize_callback' => array( $this, 'sanitize_settings' ),
)
);

// Yahoo API Credentials section.
add_settings_section(
'yahoo_api_credentials',
__( 'Yahoo Fantasy Sports API', 'mcp-ai-wpoos' ),
array( $this, 'render_yahoo_api_section' ),
self::PAGE_SLUG
);

add_settings_field(
'yahoo_client_id',
__( 'Yahoo Client ID', 'mcp-ai-wpoos' ),
array( $this, 'render_text_field' ),
self::PAGE_SLUG,
'yahoo_api_credentials',
array(
'id'          => 'yahoo_client_id',
'placeholder' => __( 'Consumer Key from Yahoo Developer', 'mcp-ai-wpoos' ),
)
);

add_settings_field(
'yahoo_client_secret',
__( 'Yahoo Client Secret', 'mcp-ai-wpoos' ),
array( $this, 'render_text_field' ),
self::PAGE_SLUG,
'yahoo_api_credentials',
array(
'id'          => 'yahoo_client_secret',
'type'        => 'password',
'placeholder' => __( 'Consumer Secret from Yahoo Developer', 'mcp-ai-wpoos' ),
)
);

// Default Preferences section.
add_settings_section(
'default_preferences',
__( 'Default Preferences', 'mcp-ai-wpoos' ),
array( $this, 'render_preferences_section' ),
self::PAGE_SLUG
);

add_settings_field(
'default_season',
__( 'Default Season', 'mcp-ai-wpoos' ),
array( $this, 'render_text_field' ),
self::PAGE_SLUG,
'default_preferences',
array(
'id'          => 'default_season',
'type'        => 'number',
'placeholder' => gmdate( 'Y' ),
)
);

add_settings_field(
'auto_sync',
__( 'Auto-Sync Teams', 'mcp-ai-wpoos' ),
array( $this, 'render_checkbox_field' ),
self::PAGE_SLUG,
'default_preferences',
array(
'id'          => 'auto_sync',
'description' => __( 'Automatically sync team data from Yahoo daily', 'mcp-ai-wpoos' ),
)
);

// Team Branding Defaults section.
add_settings_section(
'branding_defaults',
__( 'Team Branding Defaults', 'mcp-ai-wpoos' ),
array( $this, 'render_branding_section' ),
self::PAGE_SLUG
);

add_settings_field(
'default_logo_style',
__( 'Default Logo Style', 'mcp-ai-wpoos' ),
array( $this, 'render_select_field' ),
self::PAGE_SLUG,
'branding_defaults',
array(
'id'      => 'default_logo_style',
'options' => array(
'modern'     => __( 'Modern', 'mcp-ai-wpoos' ),
'classic'    => __( 'Classic', 'mcp-ai-wpoos' ),
'minimalist' => __( 'Minimalist', 'mcp-ai-wpoos' ),
'mascot'     => __( 'Mascot', 'mcp-ai-wpoos' ),
'emblem'     => __( 'Emblem', 'mcp-ai-wpoos' ),
),
)
);

add_settings_field(
'default_team_color',
__( 'Default Team Color', 'mcp-ai-wpoos' ),
array( $this, 'render_color_field' ),
self::PAGE_SLUG,
'branding_defaults',
array(
'id' => 'default_team_color',
)
);

// Report Preferences section.
add_settings_section(
'report_preferences',
__( 'Report Preferences', 'mcp-ai-wpoos' ),
array( $this, 'render_report_section' ),
self::PAGE_SLUG
);

add_settings_field(
'include_charts_default',
__( 'Include Charts', 'mcp-ai-wpoos' ),
array( $this, 'render_checkbox_field' ),
self::PAGE_SLUG,
'report_preferences',
array(
'id'          => 'include_charts_default',
'description' => __( 'Include Chart.js visualizations in reports by default', 'mcp-ai-wpoos' ),
)
);

add_settings_field(
'include_analysis_default',
__( 'Include AI Analysis', 'mcp-ai-wpoos' ),
array( $this, 'render_checkbox_field' ),
self::PAGE_SLUG,
'report_preferences',
array(
'id'          => 'include_analysis_default',
'description' => __( 'Include AI-powered insights in reports by default', 'mcp-ai-wpoos' ),
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
/* translators: %s: Yahoo Developer URL */
esc_html__( 'Enter your Yahoo Fantasy Sports API credentials. Get them from %s.', 'mcp-ai-wpoos' ),
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
<p><?php esc_html_e( 'Configure default preferences for fantasy football management.', 'mcp-ai-wpoos' ); ?></p>
<?php
}

/**
 * Render branding section description.
 */
public function render_branding_section() {
?>
<p><?php esc_html_e( 'Set default branding options for new team logos and graphics.', 'mcp-ai-wpoos' ); ?></p>
<?php
}

/**
 * Render report section description.
 */
public function render_report_section() {
?>
<p><?php esc_html_e( 'Configure default options for league reports.', 'mcp-ai-wpoos' ); ?></p>
<?php
}

/**
 * Render text field.
 *
 * @param array $args Field arguments.
 */
public function render_text_field( $args ) {
$settings    = get_option( self::SETTINGS_KEY, array() );
$value       = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
$type        = isset( $args['type'] ) ? $args['type'] : 'text';
$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
?>
<input 
type="<?php echo esc_attr( $type ); ?>" 
id="<?php echo esc_attr( $args['id'] ); ?>" 
name="<?php echo esc_attr( self::SETTINGS_KEY . '[' . $args['id'] . ']' ); ?>" 
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
$settings    = get_option( self::SETTINGS_KEY, array() );
$value       = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : false;
$description = isset( $args['description'] ) ? $args['description'] : '';
?>
<label>
<input 
type="checkbox" 
id="<?php echo esc_attr( $args['id'] ); ?>" 
name="<?php echo esc_attr( self::SETTINGS_KEY . '[' . $args['id'] . ']' ); ?>" 
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
$settings = get_option( self::SETTINGS_KEY, array() );
$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
?>
<select 
id="<?php echo esc_attr( $args['id'] ); ?>" 
name="<?php echo esc_attr( self::SETTINGS_KEY . '[' . $args['id'] . ']' ); ?>"
>
<option value=""><?php esc_html_e( '-- Select --', 'mcp-ai-wpoos' ); ?></option>
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
$settings = get_option( self::SETTINGS_KEY, array() );
$value    = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '#000000';
?>
<input 
type="color" 
id="<?php echo esc_attr( $args['id'] ); ?>" 
name="<?php echo esc_attr( self::SETTINGS_KEY . '[' . $args['id'] . ']' ); ?>" 
value="<?php echo esc_attr( $value ); ?>"
/>
<?php
}

/**
 * Render the settings page.
 */
public function render_settings_page() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
}
?>
<div class="wrap">
<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

<p>
<?php
esc_html_e( 
'Configure Fantasy Football toolkit settings including Yahoo API credentials, default preferences, and branding options.',
'mcp-ai-wpoos'
);
?>
</p>

<form method="post" action="options.php">
<?php
settings_fields( self::OPTION_GROUP );
do_settings_sections( self::PAGE_SLUG );
submit_button();
?>
</form>

<hr />

<h2><?php esc_html_e( 'Quick Links', 'mcp-ai-wpoos' ); ?></h2>
<ul>
<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ff_team' ) ); ?>"><?php esc_html_e( 'Manage Fantasy Teams', 'mcp-ai-wpoos' ); ?></a></li>
<li><a href="https://developer.yahoo.com/apps/" target="_blank"><?php esc_html_e( 'Yahoo Developer Network', 'mcp-ai-wpoos' ); ?></a></li>
<li><a href="https://developer.yahoo.com/fantasysports/guide/" target="_blank"><?php esc_html_e( 'Yahoo Fantasy Sports API Documentation', 'mcp-ai-wpoos' ); ?></a></li>
</ul>
</div>
<?php
}

/**
 * Sanitize settings before saving.
 *
 * @param array $input Raw input values.
 * @return array Sanitized values.
 */
public function sanitize_settings( $input ) {
$sanitized = array();

// Text fields.
$text_fields = array( 'yahoo_client_id', 'yahoo_client_secret', 'default_season', 'default_logo_style' );
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
 * @param string $key     Setting key.
 * @param mixed  $default Default value if not set.
 * @return mixed Setting value.
 */
public static function get_setting( $key, $default = null ) {
$settings = get_option( self::SETTINGS_KEY, array() );
return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}
}
