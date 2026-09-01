<?php
/**
 * One-time migration that rewrites stored model references away from ids
 * retired during the April 2026 model catalog refresh.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-model-catalog-migration.php` + the
 * `wp_mcp_ai_run_model_catalog_migration()` helper in
 * `includes/bootstrap/hooks.php` (behaviour-preserving; base copy retained
 * permanently — ecosystem port plan D-NOBASE). The legacy-id map, option
 * keys, meta key, query, and bookkeeping semantics are byte-identical.
 *
 * Decoupling (documented, additive):
 * - `migrate_model_configs_option()` uses the base `WP_MCP_AI_Model_Config`
 *   constant in monolith installs and the byte-identical fallback option
 *   name `wp_mcp_ai_model_configs` standalone.
 * - `migrate_default_model_setting()` rewrites `default_model` in the base
 *   settings option in monolith installs and `ai_default_model` in the
 *   content-graph settings option standalone.
 * - The `init` wiring (`run_from_catalog()`) is registered standalone-only
 *   by `Plugin.php` (the base owns it in monolith installs).
 *
 * @package NvoosContentGraphAi\Model
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites legacy model identifiers stored in user options and assistant
 * post meta to documented successors.
 *
 * @since 1.1.0
 */
class ModelCatalogMigration {

	/**
	 * Option key used to record that the migration has run for the current
	 * catalog version.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_model_catalog_migration_version';

	/**
	 * Map of removed ids to their documented successor.
	 *
	 * @return array
	 */
	public static function get_legacy_id_map() {
		return array(
			// OpenAI sunset.
			'gpt-3.5-turbo'                        => 'gpt-4o-mini',
			'gpt-3.5-turbo-16k'                    => 'gpt-4o-mini',
			'gpt-4'                                => 'gpt-4.1',
			'gpt-4-turbo'                          => 'gpt-4.1',
			'gpt-4-1106-preview'                   => 'gpt-4.1',
			'gpt-4-vision-preview'                 => 'gpt-4.1',
			'o1'                                   => 'o3-mini',
			'o1-mini'                              => 'o3-mini',
			'o1-preview'                           => 'o3-mini',
			'o1-pro'                               => 'o3-pro',
			'gpt-4.1-2025-04-14'                   => 'gpt-4.1',
			'chatgpt-4o-latest'                    => 'gpt-4.1',
			// Anthropic retired.
			'claude-3-haiku-20240307'              => 'claude-haiku-4-5',
			'claude-3-opus-20240229'               => 'claude-opus-4-6',
			'claude-3-opus'                        => 'claude-opus-4-6',
			'claude-3-sonnet'                      => 'claude-sonnet-4-6',
			'claude-3-haiku'                       => 'claude-haiku-4-5',
			'claude-opus-4-20250514'               => 'claude-opus-4-6',
			'claude-sonnet-4-20250514'             => 'claude-sonnet-4-6',
			'claude-opus-4-1-20250805'             => 'claude-opus-4-6',
			'claude-mythos-preview'                => 'claude-opus-4-8',
			'claude-3.5-sonnet'                    => 'claude-sonnet-4-6',
			'claude-3.5-sonnet-v2'                 => 'claude-sonnet-4-6',
			'claude-sonnet-4.5'                    => 'claude-sonnet-4-6',
			'claude-haiku-4.5'                     => 'claude-haiku-4-5',
			'claude-opus-4.1'                      => 'claude-opus-4-6',
			'claude-opus-4.0'                      => 'claude-opus-4-6',
			// Gemini sunset.
			'gemini-pro'                           => 'gemini-2.5-pro',
			'gemini-pro-vision'                    => 'gemini-2.5-pro',
			'gemini-1.5-pro'                       => 'gemini-2.5-pro',
			'gemini-1.5-pro-002'                   => 'gemini-2.5-pro',
			'gemini-1.5-flash'                     => 'gemini-2.5-flash',
			'gemini-1.5-flash-002'                 => 'gemini-2.5-flash',
			'gemini-2.0-flash'                     => 'gemini-2.5-flash',
			'gemini-2.0-flash-lite'                => 'gemini-2.5-flash-lite',
			'gemini-2.0-flash-image'               => 'gemini-2.5-flash-image',
			'gemini-2.5-flash-image'               => 'gemini-3.1-flash-image',
			'gemini-3-pro-preview'                 => 'gemini-3.1-pro',
			'gemini-3-flash-preview'               => 'gemini-3.5-flash',
			'gemini-3.1-pro-preview'               => 'gemini-3.1-pro',
			'imagen-3'                             => 'gemini-3.1-flash-image',
			// NIM deprecated.
			'meta/llama-3.1-405b-instruct'         => 'meta/llama-3.3-70b-instruct',
			'meta/llama-3.1-70b-instruct'          => 'meta/llama-3.3-70b-instruct',
			'meta/llama-3.1-8b-instruct'           => 'meta/llama-3.2-3b-instruct',
			'microsoft/phi-3-medium-128k-instruct' => 'microsoft/phi-4',
			'microsoft/phi-3-medium-4k-instruct'   => 'microsoft/phi-4',
			'microsoft/phi-3-mini-128k-instruct'   => 'microsoft/phi-4',
			'microsoft/phi-3-mini-4k-instruct'     => 'microsoft/phi-4',
			'microsoft/phi-3-small-128k-instruct'  => 'microsoft/phi-4',
			'microsoft/phi-3-small-8k-instruct'    => 'microsoft/phi-4',
			// Vertex / GCP stale.

			// DeepSeek legacy aliases → V4 Flash (May 2026).
			'deepseek-chat'                        => 'deepseek-v4-flash',
			'deepseek-reasoner'                    => 'deepseek-v4-flash',
			'deepseek-coder'                       => 'deepseek-v4-flash',
		);
	}

	/**
	 * Run the migration if it has not been recorded for the current catalog version.
	 *
	 * @param string $catalog_version Version string from the bundled JSON catalog.
	 * @return bool True when the migration ran, false when it was already complete.
	 */
	public static function run_if_needed( $catalog_version = '' ) {
		$catalog_version = $catalog_version ? sanitize_text_field( $catalog_version ) : 'unknown';
		$last_ran        = get_option( self::OPTION_KEY, '' );
		if ( $last_ran === $catalog_version ) {
			return false;
		}

		$map = self::get_legacy_id_map();
		if ( empty( $map ) ) {
			update_option( self::OPTION_KEY, $catalog_version, false );
			return false;
		}

		$rewrites = array(
			'configs'    => self::migrate_model_configs_option( $map ),
			'assistants' => self::migrate_assistant_post_meta( $map ),
			'settings'   => self::migrate_default_model_setting( $map ),
		);

		update_option( self::OPTION_KEY, $catalog_version, false );

		static::log_event(
			'model_catalog_migration_completed',
			'Rewrote stored model identifiers to current catalog successors.',
			array(
				'catalog_version' => $catalog_version,
				'rewrites'        => $rewrites,
			)
		);

		return true;
	}

	/**
	 * Run the migration from the bundled catalog JSON (standalone wiring).
	 *
	 * Mirrors the base plugin's `wp_mcp_ai_run_model_catalog_migration()`
	 * helper: reads the version field from the bundled catalog and keys
	 * the bookkeeping option to it, with sentinel versions for missing or
	 * invalid JSON.
	 *
	 * @return void
	 */
	public static function run_from_catalog(): void {
		$catalog_version = '';
		$catalog_path    = __DIR__ . '/model-catalog.json';
		if ( file_exists( $catalog_path ) && is_readable( $catalog_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled JSON catalog.
			$raw     = file_get_contents( $catalog_path );
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['version'] ) ) {
				$catalog_version = (string) $decoded['version'];
			} else {
				$catalog_version = 'invalid-json-' . md5( (string) $raw );
				static::log_error(
					'model_catalog_json_invalid',
					'Bundled model catalog JSON is unreadable or missing a version field. Migration recorded with sentinel version.'
				);
			}
		} else {
			$catalog_version = 'missing-json';
		}

		self::run_if_needed( $catalog_version );
	}

	/**
	 * Rewrite legacy keys inside the model configs option array.
	 *
	 * @param array $map Legacy id => successor id.
	 * @return int Number of rewritten entries.
	 */
	protected static function migrate_model_configs_option( array $map ) {
		$option_name = ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Model_Config' ) )
			? \WP_MCP_AI_Model_Config::CONFIGS_OPTION
			: 'wp_mcp_ai_model_configs';
		$configs     = get_option( $option_name, array() );
		if ( ! is_array( $configs ) || empty( $configs ) ) {
			return 0;
		}

		$rewritten = 0;
		foreach ( $map as $legacy => $successor ) {
			if ( ! isset( $configs[ $legacy ] ) ) {
				continue;
			}
			// Only move the entry when there is no existing successor configured.
			if ( ! isset( $configs[ $successor ] ) ) {
				$configs[ $successor ] = $configs[ $legacy ];
			}
			unset( $configs[ $legacy ] );
			++$rewritten;
		}

		// Also rewrite fallback_model fields pointing at retired ids.
		foreach ( $configs as $key => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( ! empty( $config['fallback_model'] ) && isset( $map[ $config['fallback_model'] ] ) ) {
				$configs[ $key ]['fallback_model'] = $map[ $config['fallback_model'] ];
				++$rewritten;
			}
		}

		if ( $rewritten > 0 ) {
			update_option( $option_name, $configs, false );
		}

		return $rewritten;
	}

	/**
	 * Rewrite assistant post meta `_wp_mcp_ai_model` values.
	 *
	 * @param array $map Legacy id => successor id.
	 * @return int Number of rewritten posts.
	 */
	protected static function migrate_assistant_post_meta( array $map ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}

		$legacy_ids = array_keys( $map );
		if ( empty( $legacy_ids ) ) {
			return 0;
		}

		// Single IN-clause query to find every post_id whose stored model is a legacy id.
		$placeholders = implode( ', ', array_fill( 0, count( $legacy_ids ), '%s' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; caching would return stale data.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a fixed list of %s tokens.
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ( {$placeholders} )",
				array_merge( array( '_wp_mcp_ai_model' ), $legacy_ids )
			)
		);

		$rewritten = 0;
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$legacy = $row->meta_value;
				if ( ! isset( $map[ $legacy ] ) ) {
					continue;
				}
				update_post_meta( (int) $row->post_id, '_wp_mcp_ai_model', $map[ $legacy ] );
				++$rewritten;
			}
		}

		return $rewritten;
	}

	/**
	 * Rewrite the global default_model setting if it points at a removed id.
	 *
	 * @param array $map Legacy id => successor id.
	 * @return int 1 if rewritten, 0 otherwise.
	 */
	protected static function migrate_default_model_setting( array $map ) {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$key      = 'default_model';
		} else {
			$settings = get_option( 'nvoos_content_graph_settings', array() );
			$key      = 'ai_default_model';
		}

		if ( ! is_array( $settings ) || empty( $settings[ $key ] ) ) {
			return 0;
		}

		$current = $settings[ $key ];
		if ( ! isset( $map[ $current ] ) ) {
			return 0;
		}

		$settings[ $key ] = $map[ $current ];
		update_option( defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings', $settings );
		return 1;
	}

	/**
	 * Log an event through the base plugin's logger (monolith only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional event data.
	 * @return void
	 */
	protected static function log_event( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}

	/**
	 * Log an error through the base plugin's logger (monolith only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	protected static function log_error( $event, $message ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $event, $message );
		}
	}
}
