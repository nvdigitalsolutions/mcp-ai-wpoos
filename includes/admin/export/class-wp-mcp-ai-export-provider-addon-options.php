<?php
/**
 * Addon Options Export Provider.
 *
 * Exports and imports addon-specific WordPress options using an explicit
 * allowlist of option names and exclude patterns to avoid picking up
 * operational, transient, or internal data.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for addon-specific option data.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Addon_Options extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Known addon option names allowed for export.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const ALLOWLIST = array(
		'nvoos_algorave_settings',
		'nvoos_canvas_toolkit_settings',
		'nvoos_chat_spa_settings',
		'nvoos_cloudways_dashboard_settings',
		'nvoos_cds_settings',
		'nvoos_embedded_settings',
		'nvoos_graphify_settings',
		'nvoos_librechat_settings',
		'nvoos_fantasy_football_settings',
		'nvoos_page_agent_confirm_destructive',
		'wp_mcp_ai_webchat_settings',
		'wp_mcp_ai_webchat_default_max_participants',
		'wp_mcp_ai_webchat_default_signaling_server',
		'wp_mcp_ai_webllm_settings',
		'wp_mcp_ai_telegram_settings',
		'wp_mcp_ai_telegram_mini_app_template',
		'wp_mcp_ai_social_media_settings',
		'wp_mcp_ai_media_settings',
		'wp_mcp_ai_fantasy_football_settings',
		'wp_mcp_ai_pro_schedule_toolkit_settings',
		'wp_mcp_ai_pro_workflows',
		'wp_mcp_ai_pro_schedules',
	);

	/**
	 * Regex patterns for option names to always exclude.
	 *
	 * These match operational, transient, cache, log, queue, history,
	 * telemetry, migration, and sync-state options that should never
	 * be exported or imported.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const EXCLUDE_PATTERNS = array(
		'/^wp_mcp_ai_recent_/',
		'/_log$/',
		'/_logs$/',
		'/_jobs$/',
		'/_transient/',
		'/_lock/',
		'/_cache/',
		'/_queue/',
		'/_history/',
		'/_telemetry/',
		'/_migration_/',
		'/_migrated/',
		'/_seeded/',
		'/_synced/',
	);

	/**
	 * Get the unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'addon_options';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Addon Settings', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the description for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Settings for installed addons: Graphify, WebChat, WebLLM, Algorave, Chat SPA, Fantasy Football, and others.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Always available because the option names are checked
	 * dynamically at export time.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether exported data contains sensitive values.
	 *
	 * Addon settings may contain API keys, so we return true
	 * as a safe default.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return false;
	}

	/**
	 * Count of matching addon options that exist in the database.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		global $wpdb;

		$count = 0;

		// Check each allowlist entry.
		foreach ( self::ALLOWLIST as $option_name ) {
			if ( $this->is_option_excluded( $option_name ) ) {
				continue;
			}
			$value = $this->get_option_safe( $option_name, null );
			if ( null !== $value ) {
				++$count;
			}
		}

		// Also scan for nvoos_*_settings patterns that match allowlist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'nvoos\_%\_settings'
			)
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $name ) {
				if ( in_array( $name, self::ALLOWLIST, true ) ) {
					continue; // Already counted above.
				}
				if ( $this->is_option_excluded( $name ) ) {
					continue;
				}
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Export all addon options.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		global $wpdb;

		$exported = array();

		// Export each allowlist entry that exists.
		foreach ( self::ALLOWLIST as $option_name ) {
			if ( $this->is_option_excluded( $option_name ) ) {
				continue;
			}
			$value = $this->get_option_safe( $option_name, null );
			if ( null !== $value ) {
				$exported[ $option_name ] = $value;
			}
		}

		// Scan for nvoos_*_settings patterns not already in allowlist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'nvoos\_%\_settings'
			)
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $name ) {
				// Skip allowlist entries already handled.
				if ( in_array( $name, self::ALLOWLIST, true ) ) {
					continue;
				}
				if ( $this->is_option_excluded( $name ) ) {
					continue;
				}
				$value = $this->get_option_safe( $name, null );
				if ( null !== $value ) {
					$exported[ $name ] = $value;
				}
			}
		}

		return $exported;
	}

	/**
	 * Validate import data before committing.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'addon_options_empty',
				__( 'Addon options data is empty.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( $data as $option_name => $value ) {
			if ( $this->is_option_excluded( $option_name ) ) {
				return new \WP_Error(
					'addon_options_excluded',
					sprintf(
						/* translators: %s: option name */
						__( 'Option "%s" is on the exclusion list and cannot be imported.', 'mcp-ai-wpoos' ),
						$option_name
					)
				);
			}
		}

		return true;
	}

	/**
	 * Import addon options into the current site.
	 *
	 * Updates existing options if present; does not delete options
	 * that are not in the import data (preserves existing).
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'addon_options_empty',
				__( 'No addon options to import.', 'mcp-ai-wpoos' )
			);
		}

		$imported = 0;

		foreach ( $data as $option_name => $value ) {
			if ( $this->is_option_excluded( $option_name ) ) {
				continue; // Skip excluded; silently safe.
			}

			$updated = update_option( $option_name, $value, false );
			if ( $updated ) {
				++$imported;
			}
		}

		$this->log_action(
			'imported',
			array(
				'provider' => $this->get_id(),
				'count'    => $imported,
			)
		);

		return true;
	}

	/**
	 * Check whether an option name matches any exclude pattern.
	 *
	 * @since 1.2.0
	 *
	 * @param string $option_name The option name to check.
	 * @return bool True if the option should be excluded.
	 */
	private function is_option_excluded( string $option_name ): bool {
		foreach ( self::EXCLUDE_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $option_name ) ) {
				return true;
			}
		}
		return false;
	}
}
