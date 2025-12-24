<?php
/**
 * Tool that checks if the WordPress site has security issues.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks WordPress site security and warns about potential risks for using this plugin.
 */
class WP_MCP_AI_Tool_Check_Site_Security implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Capability required to run the tool.
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_site_security';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Site Security', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks if the WordPress site has security vulnerabilities that make it unsafe to use this AI plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check site security.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$checks = array(
			'https'           => $this->check_https(),
			'debug_mode'      => $this->check_debug_mode(),
			'file_edit'       => $this->check_file_edit(),
			'default_admin'   => $this->check_default_admin(),
			'wp_version'      => $this->check_wordpress_version(),
			'ssl_verify'      => $this->check_ssl_verify(),
			'force_ssl_admin' => $this->check_force_ssl_admin(),
			'db_prefix'       => $this->check_database_prefix(),
		);

		// Calculate risk level.
		$critical_issues = 0;
		$warnings        = 0;
		$passed          = 0;

		foreach ( $checks as $check ) {
			switch ( $check['severity'] ) {
				case 'critical':
					++$critical_issues;
					break;
				case 'warning':
					++$warnings;
					break;
				case 'pass':
					++$passed;
					break;
			}
		}

		$risk_level     = $this->calculate_risk_level( $critical_issues, $warnings );
		$is_safe        = ( 'safe' === $risk_level || 'low' === $risk_level );
		$recommendation = $this->get_recommendation( $risk_level, $critical_issues, $warnings );

		return array(
			'risk_level'     => $risk_level,
			'is_safe_to_use' => $is_safe,
			'recommendation' => $recommendation,
			'summary'        => array(
				'critical' => $critical_issues,
				'warning'  => $warnings,
				'pass'     => $passed,
				'total'    => count( $checks ),
			),
			'checks'         => $checks,
		);
	}

	/**
	 * Check if site is using HTTPS.
	 *
	 * @return array Check result.
	 */
	protected function check_https() {
		$is_ssl = is_ssl();

		if ( $is_ssl ) {
			return array(
				'name'     => __( 'HTTPS Enabled', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'Site is using HTTPS, which encrypts data in transit.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'HTTPS Not Enabled', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'critical',
			'message'  => __( 'Site is not using HTTPS. AI API keys and sensitive data could be intercepted. This plugin should NOT be enabled without HTTPS.', 'wp-mcp-ai' ),
			'action'   => __( 'Install an SSL certificate and enable HTTPS on your site.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return array Check result.
	 */
	protected function check_debug_mode() {
		$debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! $debug_enabled ) {
			return array(
				'name'     => __( 'Debug Mode', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'Debug mode is disabled.', 'wp-mcp-ai' ),
			);
		}

		// Check if it's a local/development environment.
		$is_local = $this->is_local_environment();

		if ( $is_local ) {
			return array(
				'name'     => __( 'Debug Mode', 'wp-mcp-ai' ),
				'status'   => 'info',
				'severity' => 'pass',
				'message'  => __( 'Debug mode is enabled on local environment.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'Debug Mode Enabled', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'warning',
			'message'  => __( 'Debug mode is enabled in production. This may expose sensitive information in error messages.', 'wp-mcp-ai' ),
			'action'   => __( 'Set WP_DEBUG to false in wp-config.php for production environments.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if file editing is disabled.
	 *
	 * @return array Check result.
	 */
	protected function check_file_edit() {
		$file_edit_disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		if ( $file_edit_disabled ) {
			return array(
				'name'     => __( 'File Editing', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'File editing is disabled in the WordPress admin.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'File Editing Enabled', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'warning',
			'message'  => __( 'File editing is enabled in the WordPress admin. If an attacker gains access, they could modify plugin/theme files.', 'wp-mcp-ai' ),
			'action'   => __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if default admin username exists.
	 *
	 * @return array Check result.
	 */
	protected function check_default_admin() {
		$admin_user = get_user_by( 'login', 'admin' );

		if ( ! $admin_user ) {
			return array(
				'name'     => __( 'Default Admin Username', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'No default "admin" username found.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'Default Admin Username Found', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'warning',
			'message'  => __( 'The default "admin" username exists. This makes brute force attacks easier.', 'wp-mcp-ai' ),
			'action'   => __( 'Create a new administrator account with a unique username and delete the "admin" account.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check WordPress version for known vulnerabilities.
	 *
	 * @return array Check result.
	 */
	protected function check_wordpress_version() {
		global $wp_version;

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return array(
				'name'     => __( 'WordPress Version', 'wp-mcp-ai' ),
				'status'   => 'info',
				'severity' => 'pass',
				'message'  => sprintf(
					/* translators: %s: WordPress version number */
					__( 'WordPress version %s is installed.', 'wp-mcp-ai' ),
					$wp_version
				),
			);
		}

		$update = reset( $updates );

		if ( 'latest' === $update->response ) {
			return array(
				'name'     => __( 'WordPress Version', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => sprintf(
					/* translators: %s: WordPress version number */
					__( 'WordPress is up to date (version %s).', 'wp-mcp-ai' ),
					$wp_version
				),
			);
		}

		return array(
			'name'     => __( 'Outdated WordPress Version', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'warning',
			'message'  => sprintf(
				/* translators: 1: Current WordPress version, 2: Available WordPress version */
				__( 'WordPress version %1$s is outdated. Version %2$s is available.', 'wp-mcp-ai' ),
				$wp_version,
				isset( $update->version ) ? $update->version : __( 'unknown', 'wp-mcp-ai' )
			),
			'action'   => __( 'Update WordPress to the latest version.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if SSL verification is enabled for outbound requests.
	 *
	 * @return array Check result.
	 */
	protected function check_ssl_verify() {
		// Check if there's a filter that disables SSL verification.
		$ssl_verify_disabled = false;

		// Temporarily hook to check if SSL verification is being disabled.
		$test_verify = apply_filters( 'https_ssl_verify', true );

		if ( ! $test_verify ) {
			$ssl_verify_disabled = true;
		}

		if ( ! $ssl_verify_disabled ) {
			return array(
				'name'     => __( 'SSL Verification', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'SSL certificate verification is enabled for outbound requests.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'SSL Verification Disabled', 'wp-mcp-ai' ),
			'status'   => 'fail',
			'severity' => 'critical',
			'message'  => __( 'SSL certificate verification is disabled. This makes the site vulnerable to man-in-the-middle attacks when communicating with AI APIs.', 'wp-mcp-ai' ),
			'action'   => __( 'Remove any filters or code that disable SSL verification.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if forced SSL for admin is enabled.
	 *
	 * @return array Check result.
	 */
	protected function check_force_ssl_admin() {
		$force_ssl_admin = defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN;

		if ( ! is_ssl() ) {
			// If site is not on SSL at all, this check doesn't matter.
			return array(
				'name'     => __( 'Force SSL Admin', 'wp-mcp-ai' ),
				'status'   => 'info',
				'severity' => 'pass',
				'message'  => __( 'Not applicable (site is not using HTTPS).', 'wp-mcp-ai' ),
			);
		}

		if ( $force_ssl_admin ) {
			return array(
				'name'     => __( 'Force SSL Admin', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'SSL is enforced for admin area.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'Force SSL Admin', 'wp-mcp-ai' ),
			'status'   => 'info',
			'severity' => 'pass',
			'message'  => __( 'FORCE_SSL_ADMIN is not set. Consider enabling it for additional security.', 'wp-mcp-ai' ),
			'action'   => __( 'Add define( \'FORCE_SSL_ADMIN\', true ); to wp-config.php.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if custom database prefix is used.
	 *
	 * @return array Check result.
	 */
	protected function check_database_prefix() {
		global $wpdb;

		$prefix = $wpdb->prefix;

		if ( 'wp_' !== $prefix ) {
			return array(
				'name'     => __( 'Database Prefix', 'wp-mcp-ai' ),
				'status'   => 'pass',
				'severity' => 'pass',
				'message'  => __( 'Custom database prefix is used.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'name'     => __( 'Default Database Prefix', 'wp-mcp-ai' ),
			'status'   => 'info',
			'severity' => 'pass',
			'message'  => __( 'Default database prefix "wp_" is used. While not critical, using a custom prefix adds a minor security layer.', 'wp-mcp-ai' ),
			'action'   => __( 'Consider using a custom database prefix for new installations.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Check if environment is local/development.
	 *
	 * @return bool True if local environment.
	 */
	protected function is_local_environment() {
		$site_url = get_site_url();

		$local_hosts = array(
			'localhost',
			'127.0.0.1',
			'.local',
			'.dev',
			'.test',
			'::1',
		);

		foreach ( $local_hosts as $host ) {
			if ( false !== strpos( $site_url, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate overall risk level based on findings.
	 *
	 * @param int $critical_issues Number of critical issues.
	 * @param int $warnings        Number of warnings.
	 * @return string Risk level: 'safe', 'low', 'medium', 'high', 'critical'.
	 */
	protected function calculate_risk_level( $critical_issues, $warnings ) {
		if ( $critical_issues >= 2 ) {
			return 'critical';
		}

		if ( $critical_issues >= 1 ) {
			return 'high';
		}

		if ( $warnings >= 3 ) {
			return 'medium';
		}

		if ( $warnings >= 1 ) {
			return 'low';
		}

		return 'safe';
	}

	/**
	 * Get recommendation based on risk level.
	 *
	 * @param string $risk_level       Calculated risk level.
	 * @param int    $critical_issues  Number of critical issues.
	 * @param int    $warnings         Number of warnings.
	 * @return string Recommendation message.
	 */
	protected function get_recommendation( $risk_level, $critical_issues, $warnings ) {
		switch ( $risk_level ) {
			case 'critical':
				return sprintf(
					/* translators: %d: Number of critical security issues */
					__( 'CRITICAL: This site has %d critical security issues. This plugin should NOT be enabled until these issues are resolved. AI API keys and sensitive data are at risk.', 'wp-mcp-ai' ),
					$critical_issues
				);

			case 'high':
				return __( 'HIGH RISK: This site has critical security issues that must be addressed before enabling this plugin. AI functionality could expose sensitive data.', 'wp-mcp-ai' );

			case 'medium':
				return sprintf(
					/* translators: %d: Number of security warnings */
					__( 'MEDIUM RISK: This site has %d security warnings. While the plugin can be used, it is strongly recommended to address these issues first.', 'wp-mcp-ai' ),
					$warnings
				);

			case 'low':
				return sprintf(
					/* translators: %d: Number of security warnings */
					__( 'LOW RISK: This site has %d minor security warning(s). The plugin can be safely used, but addressing these warnings will improve overall security.', 'wp-mcp-ai' ),
					$warnings
				);

			case 'safe':
			default:
				return __( 'SAFE: This site has no critical security issues detected. The plugin can be safely enabled.', 'wp-mcp-ai' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
