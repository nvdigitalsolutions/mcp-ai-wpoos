<?php
/**
 * Threads Database Schema — Creates and updates tables for threads, messages,
 * checkpoints, and profiles.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Threads_Schema
 *
 * Handles database table creation and migration for the thread management
 * subsystem. Tables are created on plugin activation and updated on version
 * bumps via dbDelta().
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Threads_Schema {

	/**
	 * Schema version — bump when tables change.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option key for tracking installed schema version.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_threads_schema_version';

	/**
	 * Run schema migration — create or update tables as needed.
	 *
	 * Safe to call on every plugin load; dbDelta() is idempotent.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function migrate() {
		$installed_version = get_option( self::OPTION_KEY, '0' );

		if ( version_compare( $installed_version, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		self::create_threads_table();
		self::create_thread_messages_table();
		self::create_checkpoints_table();
		self::create_profiles_table();

		update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
	}

	/**
	 * Create the threads table.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private static function create_threads_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mcp_ai_threads';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			`id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`assistant_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`title`           VARCHAR(255) NOT NULL DEFAULT '',
			`model_provider`  VARCHAR(50) NOT NULL DEFAULT '',
			`model_name`      VARCHAR(100) NOT NULL DEFAULT '',
			`profile_name`    VARCHAR(50) NOT NULL DEFAULT 'write',
			`scope_type`      VARCHAR(50) NOT NULL DEFAULT 'site',
			`scope_value`     VARCHAR(255) NOT NULL DEFAULT '',
			`status`          VARCHAR(20) NOT NULL DEFAULT 'active',
			`message_count`   INT UNSIGNED NOT NULL DEFAULT 0,
			`token_count`     INT UNSIGNED NOT NULL DEFAULT 0,
			`created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			`archived_at`     DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`),
			KEY `assistant_id` (`assistant_id`),
			KEY `status` (`status`),
			KEY `created_at` (`created_at`)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the thread messages table.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private static function create_thread_messages_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mcp_ai_thread_messages';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			`id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`thread_id`       BIGINT UNSIGNED NOT NULL,
			`role`            VARCHAR(20) NOT NULL,
			`content`         LONGTEXT NOT NULL,
			`tool_calls`      LONGTEXT NULL,
			`tool_results`    LONGTEXT NULL,
			`checkpoint_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
			`token_usage`     INT UNSIGNED NOT NULL DEFAULT 0,
			`model_provider`  VARCHAR(50) NOT NULL DEFAULT '',
			`model_name`      VARCHAR(100) NOT NULL DEFAULT '',
			`created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `thread_id` (`thread_id`),
			KEY `created_at` (`created_at`),
			KEY `thread_id_created` (`thread_id`, `created_at`)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the checkpoints table.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private static function create_checkpoints_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mcp_ai_checkpoints';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			`id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`thread_id`         BIGINT UNSIGNED NOT NULL,
			`message_id`        BIGINT UNSIGNED NULL DEFAULT NULL,
			`label`             VARCHAR(255) NOT NULL DEFAULT '',
			`state_snapshot`    LONGTEXT NOT NULL,
			`affected_entities` LONGTEXT NULL,
			`created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `thread_id` (`thread_id`),
			KEY `thread_id_created` (`thread_id`, `created_at`)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the profiles table.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private static function create_profiles_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'mcp_ai_profiles';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			`id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`name`             VARCHAR(50) NOT NULL,
			`label`            VARCHAR(255) NOT NULL DEFAULT '',
			`is_builtin`       TINYINT(1) NOT NULL DEFAULT 0,
			`tool_allowlist`   LONGTEXT NULL,
			`tool_denylist`    LONGTEXT NULL,
			`always_confirm`   LONGTEXT NULL,
			`always_allow`     LONGTEXT NULL,
			`default_approval` VARCHAR(20) NOT NULL DEFAULT 'confirm',
			`user_id`          BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `name_user` (`name`, `user_id`),
			KEY `user_id` (`user_id`)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop all thread management tables.
	 *
	 * Used during uninstall when user opts to remove all data.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$tables = array(
			'mcp_ai_threads',
			'mcp_ai_thread_messages',
			'mcp_ai_checkpoints',
			'mcp_ai_profiles',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table name is a safe literal.
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}

		delete_option( self::OPTION_KEY );
	}
}
