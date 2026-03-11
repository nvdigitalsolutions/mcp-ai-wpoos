<?php
/**
 * Google Site Kit Integration
 *
 * Provides integration with Google Site Kit plugin for accessing
 * Google Analytics, Search Console, PageSpeed, and AdSense data
 * through AI assistant tools.
 *
 * This integration is part of the BASE PLUGIN (not Pro-only).
 * It's optional and only loads when Site Kit is active.
 *
 * @package    WP_MCP_AI
 * @subpackage Integrations
 * @since      1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Site Kit Integration Class
 *
 * Handles integration with Google Site Kit plugin to provide
 * AI assistant tools for accessing Google service data.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_SiteKit_Integration {

	/**
	 * Singleton instance
	 *
	 * @var WP_MCP_AI_SiteKit_Integration
	 */
	private static $instance = null;

	/**
	 * Cache group name
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai_sitekit';

	/**
	 * Default cache duration in seconds
	 *
	 * @var int
	 */
	const CACHE_DURATION = 900; // 15 minutes

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_SiteKit_Integration
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Hook into NV oOS initialization.
		add_action( 'wp_mcp_ai_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize integration
	 *
	 * @since 1.2.0
	 */
	public function init() {
		if ( ! $this->is_sitekit_available() ) {
			return;
		}

		// Register Site Kit tools.
		add_filter( 'wp_mcp_ai_tools', array( $this, 'register_tools' ) );

		// Add admin settings.
		add_action( 'wp_mcp_ai_settings_integrations', array( $this, 'render_settings' ) );

		// Log integration status.
		if ( defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG ) {
			$this->log( 'Google Site Kit integration initialized' );
		}
	}

	/**
	 * Check if Google Site Kit is available
	 *
	 * @since 1.2.0
	 * @return bool True if Site Kit is active and configured
	 */
	public function is_sitekit_available() {
		// Check if Site Kit plugin is active.
		if ( ! class_exists( 'Google\\Site_Kit\\Plugin' ) ) {
			return false;
		}

		// Check if integration is enabled in settings.
		$enabled = get_option( 'wp_mcp_ai_sitekit_enabled', true );
		if ( ! $enabled ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if user has Site Kit access
	 *
	 * User must have the capability to view Site Kit data and
	 * must have connected their Google account.
	 *
	 * @since 1.2.0
	 * @return bool True if user can access Site Kit data
	 */
	public function user_has_sitekit_access() {
		// Check basic WordPress capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Additional checks can be added here for Site Kit-specific permissions.
		return true;
	}

	/**
	 * Register Site Kit tools
	 *
	 * @since 1.2.0
	 * @param array $tools Existing tools array.
	 * @return array Modified tools array
	 */
	public function register_tools( $tools ) {
		// Only register tools if user has access.
		if ( ! $this->user_has_sitekit_access() ) {
			return $tools;
		}

		$sitekit_tools = array(
			'sitekit_get_analytics'      => array(
				'name'                => 'sitekit_get_analytics',
				'description'         => 'Retrieve Google Analytics data through Site Kit',
				'class'               => 'WP_MCP_AI_Tool_SiteKit_Analytics',
				'required_capability' => 'manage_options',
				'category'            => 'analytics',
			),
			'sitekit_get_search_console' => array(
				'name'                => 'sitekit_get_search_console',
				'description'         => 'Retrieve Google Search Console data through Site Kit',
				'class'               => 'WP_MCP_AI_Tool_SiteKit_Search_Console',
				'required_capability' => 'manage_options',
				'category'            => 'seo',
			),
			'sitekit_get_pagespeed'      => array(
				'name'                => 'sitekit_get_pagespeed',
				'description'         => 'Get PageSpeed Insights scores and recommendations',
				'class'               => 'WP_MCP_AI_Tool_SiteKit_PageSpeed',
				'required_capability' => 'manage_options',
				'category'            => 'performance',
			),
			'sitekit_get_adsense'        => array(
				'name'                => 'sitekit_get_adsense',
				'description'         => 'Retrieve AdSense earnings and performance metrics',
				'class'               => 'WP_MCP_AI_Tool_SiteKit_AdSense',
				'required_capability' => 'manage_options',
				'category'            => 'monetization',
			),
		);

		return array_merge( $tools, $sitekit_tools );
	}

	/**
	 * Make REST API request to Site Kit
	 *
	 * @since 1.2.0
	 * @param string $endpoint Site Kit REST endpoint path.
	 * @param array  $args     Request arguments.
	 * @return array|WP_Error Response data or error
	 */
	public function make_sitekit_request( $endpoint, $args = array() ) {
		// Check if Site Kit is available.
		if ( ! $this->is_sitekit_available() ) {
			return new WP_Error(
				'sitekit_not_available',
				__( 'Google Site Kit is not active or configured', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Check cache first.
		$cache_key = $this->get_cache_key( $endpoint, $args );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->log( sprintf( 'Using cached Site Kit data for %s', $endpoint ) );
			return $cached;
		}

		// Make REST API request.
		$request = new WP_REST_Request( 'GET', $endpoint );
		foreach ( $args as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// Execute request.
		$response = rest_do_request( $request );

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Site Kit request error: %s', $response->get_error_message() ), 'error' );
			return $response;
		}

		// Get data from response.
		$data = $response->get_data();

		// Check for API errors in response.
		if ( isset( $data['error'] ) ) {
			$error_message = isset( $data['message'] ) ? $data['message'] : __( 'Unknown error', 'mcp-ai-wpoos' );
			$this->log( sprintf( 'Site Kit API error: %s', $error_message ), 'error' );
			return new WP_Error(
				'sitekit_api_error',
				$error_message,
				array( 'status' => 500 )
			);
		}

		// Cache successful response.
		$cache_duration = $this->get_cache_duration();
		set_transient( $cache_key, $data, $cache_duration );

		$this->log( sprintf( 'Site Kit data fetched and cached for %s', $endpoint ) );

		return $data;
	}

	/**
	 * Get cache key for request
	 *
	 * @since 1.2.0
	 * @param string $endpoint Site Kit endpoint.
	 * @param array  $args     Request arguments.
	 * @return string Cache key
	 */
	private function get_cache_key( $endpoint, $args ) {
		$key_data = array(
			'endpoint' => $endpoint,
			'args'     => $args,
			'user_id'  => get_current_user_id(),
		);
		return self::CACHE_GROUP . '_' . md5( wp_json_encode( $key_data ) );
	}

	/**
	 * Get cache duration from settings
	 *
	 * @since 1.2.0
	 * @return int Cache duration in seconds
	 */
	private function get_cache_duration() {
		$duration = get_option( 'wp_mcp_ai_sitekit_cache_duration', self::CACHE_DURATION );
		return absint( $duration );
	}

	/**
	 * Clear Site Kit cache
	 *
	 * @since 1.2.0
	 */
	public function clear_cache() {
		global $wpdb;

		// Delete all transients with our cache group prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Kit analytics integration: direct query required to clear custom transient cache entries from wp_options.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::CACHE_GROUP . '_%'
			)
		);

		$this->log( 'Site Kit cache cleared' );
	}

	/**
	 * Render integration settings
	 *
	 * @since 1.2.0
	 */
	public function render_settings() {
		$enabled        = get_option( 'wp_mcp_ai_sitekit_enabled', true );
		$cache_duration = get_option( 'wp_mcp_ai_sitekit_cache_duration', self::CACHE_DURATION );
		$default_range  = get_option( 'wp_mcp_ai_sitekit_default_range', 'last_28_days' );
		$enable_logging = get_option( 'wp_mcp_ai_sitekit_enable_logging', false );
		?>
		<tr>
			<th scope="row">
				<label for="wp_mcp_ai_sitekit_enabled">
					<?php esc_html_e( 'Enable Site Kit Integration', 'mcp-ai-wpoos' ); ?>
				</label>
			</th>
			<td>
				<input type="checkbox" 
					id="wp_mcp_ai_sitekit_enabled" 
					name="wp_mcp_ai_sitekit_enabled" 
					value="1" 
					<?php checked( $enabled ); ?> />
				<p class="description">
					<?php esc_html_e( 'Enable AI assistant tools for Google Site Kit data access', 'mcp-ai-wpoos' ); ?>
				</p>
				<?php if ( ! $this->is_sitekit_available() ) : ?>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						printf(
							/* translators: %s: URL to Site Kit plugin */
							esc_html__( 'Google Site Kit is not installed. %s to enable this integration.', 'mcp-ai-wpoos' ),
							'<a href="' . esc_url( admin_url( 'plugin-install.php?s=google+site+kit&tab=search' ) ) . '">' . esc_html__( 'Install Google Site Kit', 'mcp-ai-wpoos' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp_mcp_ai_sitekit_cache_duration">
					<?php esc_html_e( 'Cache Duration', 'mcp-ai-wpoos' ); ?>
				</label>
			</th>
			<td>
				<select id="wp_mcp_ai_sitekit_cache_duration" name="wp_mcp_ai_sitekit_cache_duration">
					<option value="300" <?php selected( $cache_duration, 300 ); ?>>
						<?php esc_html_e( '5 minutes', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="900" <?php selected( $cache_duration, 900 ); ?>>
						<?php esc_html_e( '15 minutes', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="1800" <?php selected( $cache_duration, 1800 ); ?>>
						<?php esc_html_e( '30 minutes', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="3600" <?php selected( $cache_duration, 3600 ); ?>>
						<?php esc_html_e( '1 hour', 'mcp-ai-wpoos' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'How long to cache Site Kit data to reduce API calls', 'mcp-ai-wpoos' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp_mcp_ai_sitekit_default_range">
					<?php esc_html_e( 'Default Date Range', 'mcp-ai-wpoos' ); ?>
				</label>
			</th>
			<td>
				<select id="wp_mcp_ai_sitekit_default_range" name="wp_mcp_ai_sitekit_default_range">
					<option value="last_7_days" <?php selected( $default_range, 'last_7_days' ); ?>>
						<?php esc_html_e( 'Last 7 days', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="last_28_days" <?php selected( $default_range, 'last_28_days' ); ?>>
						<?php esc_html_e( 'Last 28 days', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="last_90_days" <?php selected( $default_range, 'last_90_days' ); ?>>
						<?php esc_html_e( 'Last 90 days', 'mcp-ai-wpoos' ); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Default date range for analytics queries', 'mcp-ai-wpoos' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp_mcp_ai_sitekit_enable_logging">
					<?php esc_html_e( 'Enable Detailed Logging', 'mcp-ai-wpoos' ); ?>
				</label>
			</th>
			<td>
				<input type="checkbox" 
					id="wp_mcp_ai_sitekit_enable_logging" 
					name="wp_mcp_ai_sitekit_enable_logging" 
					value="1" 
					<?php checked( $enable_logging ); ?> />
				<p class="description">
					<?php esc_html_e( 'Log all Site Kit API requests for debugging', 'mcp-ai-wpoos' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Cache Management', 'mcp-ai-wpoos' ); ?>
			</th>
			<td>
				<button type="button" class="button" id="wp-mcp-ai-sitekit-clear-cache">
					<?php esc_html_e( 'Clear Site Kit Cache', 'mcp-ai-wpoos' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Clear all cached Site Kit data to force fresh API requests', 'mcp-ai-wpoos' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Log message
	 *
	 * @since 1.2.0
	 * @param string $message Log message.
	 * @param string $level   Log level (info, error, warning).
	 */
	private function log( $message, $level = 'info' ) {
		$enable_logging = get_option( 'wp_mcp_ai_sitekit_enable_logging', false );

		if ( ! $enable_logging && 'error' !== $level ) {
			return;
		}

		// Use NV oOS logging system if available.
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log( '[Site Kit] ' . $message, $level );
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// Fallback to error_log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG_LOG-gated fallback; only runs when NV oOS logging is unavailable and WP_DEBUG_LOG is enabled.
			error_log( sprintf( '[WP_MCP_AI][Site Kit][%s] %s', strtoupper( $level ), $message ) );
		}
	}

	/**
	 * Get integration status
	 *
	 * @since 1.2.0
	 * @return array Status information
	 */
	public function get_status() {
		return array(
			'available'           => $this->is_sitekit_available(),
			'user_has_access'     => $this->user_has_sitekit_access(),
			'sitekit_installed'   => class_exists( 'Google\\Site_Kit\\Plugin' ),
			'integration_enabled' => get_option( 'wp_mcp_ai_sitekit_enabled', true ),
			'cache_duration'      => $this->get_cache_duration(),
		);
	}
}

// Initialize integration.
WP_MCP_AI_SiteKit_Integration::get_instance();
