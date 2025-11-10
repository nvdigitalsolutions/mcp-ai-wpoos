<?php
/**
 * PHP Compatibility Helper - Dual PHP 7.4 & 8.1+ Support
 *
 * Provides feature detection and compatibility checks for
 * progressive enhancement across PHP versions.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_PHP_Compat class
 *
 * Detects PHP version capabilities and provides feature flags
 * for conditional loading of version-specific implementations.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_PHP_Compat {

	/**
	 * Cached PHP version string
	 *
	 * @var string|null
	 */
	private static $php_version = null;

	/**
	 * Cached feature flags
	 *
	 * @var array|null
	 */
	private static $features = null;

	/**
	 * Check if PHP 8.0+ attributes are available
	 *
	 * @return bool True if attributes supported.
	 */
	public static function has_attributes() {
		return version_compare( self::get_version(), '8.0.0', '>=' );
	}

	/**
	 * Check if PHP 8.1+ enums are available
	 *
	 * @return bool True if enums supported.
	 */
	public static function has_enums() {
		return version_compare( self::get_version(), '8.1.0', '>=' );
	}

	/**
	 * Check if PHP 8.1+ readonly properties are available
	 *
	 * @return bool True if readonly supported.
	 */
	public static function has_readonly() {
		return version_compare( self::get_version(), '8.1.0', '>=' );
	}

	/**
	 * Check if PHP 8.0+ named arguments are supported
	 *
	 * @return bool True if named arguments supported.
	 */
	public static function has_named_arguments() {
		return version_compare( self::get_version(), '8.0.0', '>=' );
	}

	/**
	 * Check if PHP 8.0+ match expressions are supported
	 *
	 * @return bool True if match supported.
	 */
	public static function has_match() {
		return version_compare( self::get_version(), '8.0.0', '>=' );
	}

	/**
	 * Check if PHP 8.1+ fibers are supported
	 *
	 * @return bool True if fibers supported.
	 */
	public static function has_fibers() {
		return version_compare( self::get_version(), '8.1.0', '>=' ) && class_exists( 'Fiber' );
	}

	/**
	 * Check if PHP 8.0+ constructor property promotion is supported
	 *
	 * @return bool True if constructor promotion supported.
	 */
	public static function has_constructor_promotion() {
		return version_compare( self::get_version(), '8.0.0', '>=' );
	}

	/**
	 * Get PHP version string (cached for performance)
	 *
	 * @return string PHP version.
	 */
	private static function get_version() {
		if ( null === self::$php_version ) {
			self::$php_version = PHP_VERSION;
		}
		return self::$php_version;
	}

	/**
	 * Get all feature flags
	 *
	 * @return array Feature flags.
	 */
	public static function get_features() {
		if ( null !== self::$features ) {
			return self::$features;
		}

		self::$features = array(
			'php_version'           => self::get_version(),
			'attributes'            => self::has_attributes(),
			'enums'                 => self::has_enums(),
			'readonly'              => self::has_readonly(),
			'named_arguments'       => self::has_named_arguments(),
			'match'                 => self::has_match(),
			'fibers'                => self::has_fibers(),
			'constructor_promotion' => self::has_constructor_promotion(),
		);

		return self::$features;
	}

	/**
	 * Get feature tier (baseline, enhanced, optimal)
	 *
	 * @return string Feature tier.
	 */
	public static function get_feature_tier() {
		$version = self::get_version();

		if ( version_compare( $version, '8.1.0', '>=' ) ) {
			return 'optimal';
		}

		if ( version_compare( $version, '8.0.0', '>=' ) ) {
			return 'enhanced';
		}

		return 'baseline';
	}

	/**
	 * Get upgrade recommendation message
	 *
	 * @return string Upgrade message.
	 */
	public static function get_upgrade_message() {
		$version = self::get_version();

		if ( version_compare( $version, '8.1.0', '>=' ) ) {
			return sprintf(
				'You are running PHP %s with all features enabled! Great job!',
				$version
			);
		}

		if ( version_compare( $version, '8.0.0', '>=' ) ) {
			return sprintf(
				'You are running PHP %s. Upgrade to PHP 8.1+ to unlock enums, readonly properties, and ~30%% better performance.',
				$version
			);
		}

		return sprintf(
			'You are running PHP %s. Upgrade to PHP 8.1+ to unlock attribute-based tools, enums, and ~30%% better performance.',
			$version
		);
	}

	/**
	 * Get performance estimate
	 *
	 * @return array Performance estimate with tier and percentage.
	 */
	public static function get_performance_estimate() {
		$version = self::get_version();
		$baseline = array(
			'tier'       => 'baseline',
			'percentage' => 100,
			'message'    => 'Baseline performance',
		);

		if ( version_compare( $version, '8.1.0', '>=' ) ) {
			return array(
				'tier'       => 'optimal',
				'percentage' => 130,
				'message'    => '~30% faster than PHP 7.4 with JIT compiler',
			);
		}

		if ( version_compare( $version, '8.0.0', '>=' ) ) {
			return array(
				'tier'       => 'enhanced',
				'percentage' => 125,
				'message'    => '~25% faster than PHP 7.4',
			);
		}

		return $baseline;
	}

	/**
	 * Should use attribute-based implementation?
	 *
	 * @return bool True if should use attributes.
	 */
	public static function should_use_attributes() {
		static $use_attributes = null;

		if ( null !== $use_attributes ) {
			return $use_attributes;
		}

		// Check if attributes are available.
		if ( ! self::has_attributes() ) {
			$use_attributes = false;
			return false;
		}

		// Allow filtering to force reflection mode even on PHP 8+.
		$use_attributes = apply_filters( 'wp_mcp_ai_use_attribute_mode', true );

		return $use_attributes;
	}

	/**
	 * Get appropriate tool registry class name
	 *
	 * @return string Registry class name.
	 */
	public static function get_tool_registry_class() {
		if ( self::should_use_attributes() ) {
			return 'WP_MCP_AI_Attribute_Tool_Registry';
		}
		return 'WP_MCP_AI_Reflection_Tool_Registry';
	}

	/**
	 * Get appropriate schema generator class name
	 *
	 * @return string Schema generator class name.
	 */
	public static function get_schema_generator_class() {
		if ( self::should_use_attributes() ) {
			return 'WP_MCP_AI_Attribute_Schema_Generator';
		}
		return 'WP_MCP_AI_Schema_Generator';
	}

	/**
	 * Log feature detection for debugging
	 *
	 * @return void
	 */
	public static function log_features() {
		if ( ! function_exists( 'wp_mcp_ai_log' ) ) {
			return;
		}

		$features = self::get_features();
		$tier     = self::get_feature_tier();
		$perf     = self::get_performance_estimate();

		wp_mcp_ai_log(
			sprintf(
				'PHP Compatibility: Version=%s, Tier=%s, Performance=%s, Features=%s',
				$features['php_version'],
				$tier,
				$perf['message'],
				wp_json_encode( $features )
			),
			'info'
		);
	}

	/**
	 * Check if minimum requirements are met
	 *
	 * @return bool True if requirements met.
	 */
	public static function meets_minimum_requirements() {
		return version_compare( self::get_version(), '7.4.0', '>=' );
	}

	/**
	 * Get features enabled count
	 *
	 * @return int Number of advanced features enabled.
	 */
	public static function get_enabled_features_count() {
		$features = self::get_features();
		$count    = 0;

		if ( $features['attributes'] ) {
			++$count;
		}
		if ( $features['enums'] ) {
			++$count;
		}
		if ( $features['readonly'] ) {
			++$count;
		}
		if ( $features['match'] ) {
			++$count;
		}
		if ( $features['fibers'] ) {
			++$count;
		}

		return $count;
	}

	/**
	 * Get maximum features count
	 *
	 * @return int Maximum features available.
	 */
	public static function get_max_features_count() {
		return 5; // attributes, enums, readonly, match, fibers.
	}

	/**
	 * Get feature coverage percentage
	 *
	 * @return float Percentage of features enabled (0-100).
	 */
	public static function get_feature_coverage() {
		$enabled = self::get_enabled_features_count();
		$maximum = self::get_max_features_count();

		if ( 0 === $maximum ) {
			return 100.0;
		}

		return ( $enabled / $maximum ) * 100.0;
	}
}
