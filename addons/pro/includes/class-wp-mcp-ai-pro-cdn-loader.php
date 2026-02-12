<?php
/**
 * Pro CDN Loader
 *
 * Manages CDN loading for popular libraries in the Pro addon to reduce plugin size.
 * Implements fallback mechanism to local copies if CDN fails.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_CDN_Loader
 *
 * Provides CDN-first loading for popular libraries with automatic fallback
 * to bundled versions for offline/intranet installations.
 */
class WP_MCP_AI_Pro_CDN_Loader {

	/**
	 * Library configurations with CDN URLs and fallback paths
	 *
	 * @var array
	 */
	private static $libraries = array(
		'chart.js'  => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
			'fallback_url'  => 'assets/vendor/chart.js/chart.umd.min.js',
			'version'       => '4.4.7',
			'handle'        => 'chartjs-pro',
			'dependencies'  => array(),
			'in_footer'     => true,
			'sri'           => 'sha384-', // Optional: Add SRI hash for security
		),
		'katex'     => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js',
			'cdn_css'       => 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css',
			'fallback_url'  => 'assets/vendor/katex/dist/katex.min.js',
			'fallback_css'  => 'assets/vendor/katex/dist/katex.min.css',
			'version'       => '0.16.11',
			'handle'        => 'katex',
			'dependencies'  => array(),
			'in_footer'     => true,
		),
		'd3'        => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/d3@7.8.5/dist/d3.min.js',
			'fallback_url'  => 'assets/vendor/d3/dist/d3.min.js',
			'version'       => '7.8.5',
			'handle'        => 'd3',
			'dependencies'  => array(),
			'in_footer'     => true,
		),
		'axios'     => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/axios@1.6.5/dist/axios.min.js',
			'fallback_url'  => 'assets/vendor/axios/dist/axios.min.js',
			'version'       => '1.6.5',
			'handle'        => 'axios',
			'dependencies'  => array(),
			'in_footer'     => true,
		),
		'mathjs'    => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/mathjs@12.3.0/lib/browser/math.js',
			'fallback_url'  => 'assets/vendor/mathjs/lib/browser/math.js',
			'version'       => '12.3.0',
			'handle'        => 'mathjs',
			'dependencies'  => array(),
			'in_footer'     => true,
		),
		'prettier'  => array(
			'cdn_url'       => 'https://cdn.jsdelivr.net/npm/prettier@3.4.2/standalone.js',
			'fallback_url'  => 'assets/vendor/prettier/standalone.js',
			'version'       => '3.4.2',
			'handle'        => 'prettier',
			'dependencies'  => array(),
			'in_footer'     => true,
		),
	);

	/**
	 * Get list of CDN-managed package names.
	 *
	 * @return array List of package names that are loaded from CDN.
	 */
	public static function get_cdn_packages() {
		return array_keys( self::$libraries );
	}

	/**
	 * Initialize the CDN loader
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_libraries' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_libraries' ), 5 );
	}

	/**
	 * Register all libraries for use
	 *
	 * @return void
	 */
	public static function register_libraries() {
		foreach ( self::$libraries as $library => $config ) {
			self::register_library( $library, $config );
		}
	}

	/**
	 * Register a single library
	 *
	 * @param string $library Library key.
	 * @param array  $config  Library configuration.
	 * @return void
	 */
	private static function register_library( $library, $config ) {
		$use_cdn = self::should_use_cdn();

		// Determine source URL.
		$script_url = $use_cdn ? $config['cdn_url'] : self::get_fallback_url( $config['fallback_url'] );

		// Register JavaScript.
		wp_register_script(
			$config['handle'],
			$script_url,
			$config['dependencies'],
			$config['version'],
			$config['in_footer']
		);

		// Register CSS if available.
		if ( isset( $config['cdn_css'] ) ) {
			$css_url = $use_cdn ? $config['cdn_css'] : self::get_fallback_url( $config['fallback_css'] );
			wp_register_style(
				$config['handle'] . '-css',
				$css_url,
				array(),
				$config['version']
			);
		}

		// Add SRI for CDN resources if configured.
		if ( $use_cdn && ! empty( $config['sri'] ) ) {
			add_filter(
				'script_loader_tag',
				function ( $tag, $handle ) use ( $config ) {
					if ( $config['handle'] === $handle ) {
						$tag = str_replace( '<script ', '<script integrity="' . esc_attr( $config['sri'] ) . '" crossorigin="anonymous" ', $tag );
					}
					return $tag;
				},
				10,
				2
			);
		}
	}

	/**
	 * Check if CDN should be used
	 *
	 * @return bool True if CDN should be used, false for local fallback.
	 */
	private static function should_use_cdn() {
		// Allow disabling CDN via filter (for offline/intranet installs).
		$cdn_enabled = apply_filters( 'wp_mcp_ai_pro_use_cdn', true );

		if ( ! $cdn_enabled ) {
			return false;
		}

		// Check for specific constant to disable CDN.
		if ( defined( 'WP_MCP_AI_PRO_DISABLE_CDN' ) && WP_MCP_AI_PRO_DISABLE_CDN ) {
			return false;
		}

		// Check plugin setting.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( isset( $settings['disable_cdn_loading'] ) && $settings['disable_cdn_loading'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Get fallback URL for local file
	 *
	 * @param string $fallback_path Relative path to fallback file.
	 * @return string Full URL to fallback file.
	 */
	private static function get_fallback_url( $fallback_path ) {
		// Check if file exists in vendor directory.
		$full_path = WP_MCP_AI_PRO_PATH . $fallback_path;
		
		if ( ! file_exists( $full_path ) ) {
			// Log warning in debug mode.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'WP MCP AI Pro: Fallback file not found: %s', $full_path ) );
			}
		}

		return WP_MCP_AI_PRO_URL . $fallback_path;
	}

	/**
	 * Enqueue a library by key
	 *
	 * @param string $library Library key (e.g., 'katex', 'd3').
	 * @return bool True if enqueued successfully, false otherwise.
	 */
	public static function enqueue( $library ) {
		if ( ! isset( self::$libraries[ $library ] ) ) {
			return false;
		}

		$config = self::$libraries[ $library ];
		
		// Enqueue script.
		wp_enqueue_script( $config['handle'] );

		// Enqueue CSS if available.
		if ( isset( $config['cdn_css'] ) ) {
			wp_enqueue_style( $config['handle'] . '-css' );
		}

		return true;
	}

	/**
	 * Check if a library is available (either via CDN or locally)
	 *
	 * @param string $library Library key.
	 * @return bool True if library is available.
	 */
	public static function is_available( $library ) {
		if ( ! isset( self::$libraries[ $library ] ) ) {
			return false;
		}

		$config = self::$libraries[ $library ];

		// If CDN is enabled, assume it's available.
		if ( self::should_use_cdn() ) {
			return true;
		}

		// Check if local fallback exists.
		$full_path = WP_MCP_AI_PRO_PATH . $config['fallback_url'];
		return file_exists( $full_path );
	}

	/**
	 * Get library configuration
	 *
	 * @param string $library Library key.
	 * @return array|null Library configuration or null if not found.
	 */
	public static function get_library_config( $library ) {
		return isset( self::$libraries[ $library ] ) ? self::$libraries[ $library ] : null;
	}

	/**
	 * Get all library handles
	 *
	 * @return array Array of library handles.
	 */
	public static function get_library_handles() {
		return array_map(
			function ( $config ) {
				return $config['handle'];
			},
			self::$libraries
		);
	}

	/**
	 * Check if a package is available via CDN or local vendor
	 *
	 * This is a helper method for settings pages to determine if NPM packages
	 * are available, considering both CDN-loaded and locally bundled packages.
	 *
	 * @param string $package_name Package name (e.g., 'katex', 'chart.js').
	 * @return bool True if package is available.
	 */
	public static function is_package_available( $package_name ) {
		// Check if it's a CDN-managed library.
		if ( isset( self::$libraries[ $package_name ] ) ) {
			return self::is_available( $package_name );
		}

		// Check vendor directory (for non-CDN packages).
		$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
		if ( is_dir( $vendor_path ) ) {
			return true;
		}

		// Check node_modules (development).
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
		if ( is_dir( $node_modules_path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get package status for settings display
	 *
	 * Returns a detailed status array for displaying in settings pages.
	 *
	 * @param string $package_name Package name.
	 * @return array Status array with 'available', 'source', and 'message' keys.
	 */
	public static function get_package_status( $package_name ) {
		// CDN-loaded packages.
		if ( isset( self::$libraries[ $package_name ] ) ) {
			$using_cdn = self::should_use_cdn();
			return array(
				'available' => true,
				'source'    => $using_cdn ? 'cdn' : 'local-fallback',
				'message'   => $using_cdn 
					? sprintf( 
						/* translators: %s: package name */
						__( '%s (CDN-loaded)', 'mcp-ai-wpoos-pro' ), 
						$package_name 
					)
					: sprintf(
						/* translators: %s: package name */
						__( '%s (Local fallback)', 'mcp-ai-wpoos-pro' ),
						$package_name
					),
			);
		}

		// Vendor directory (bundled packages).
		$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/' . $package_name;
		if ( is_dir( $vendor_path ) ) {
			return array(
				'available' => true,
				'source'    => 'vendor',
				'message'   => sprintf(
					/* translators: %s: package name */
					__( '%s (Bundled)', 'mcp-ai-wpoos-pro' ),
					$package_name
				),
			);
		}

		// Node modules (development).
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/' . $package_name;
		if ( is_dir( $node_modules_path ) ) {
			return array(
				'available' => true,
				'source'    => 'node_modules',
				'message'   => sprintf(
					/* translators: %s: package name */
					__( '%s (Development)', 'mcp-ai-wpoos-pro' ),
					$package_name
				),
			);
		}

		// Not available.
		return array(
			'available' => false,
			'source'    => 'none',
			'message'   => sprintf(
				/* translators: %s: package name */
				__( '%s (Not installed)', 'mcp-ai-wpoos-pro' ),
				$package_name
			),
		);
	}
}

// Initialize the CDN loader.
WP_MCP_AI_Pro_CDN_Loader::init();
