<?php
/**
 * Toolkit manifest registry + storage bootstrap.
 *
 * A Pro toolkit is data: one JSON manifest declares its settings, entities,
 * columns, dashboard cards and screens. This registry finds and validates
 * those manifests, owns the shared records table, and stores per-toolkit
 * settings. Everything downstream (the REST controller, the console screens)
 * reads from here, so a new family ships as a file rather than bespoke PHP.
 *
 * The validator itself lives in `lib/core`
 * (`Nvoos\Core\Domain\ValueObject\ToolkitManifest`) and is shared with the
 * Laravel runtime, so both backends reject the same documents.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

if ( ! class_exists( 'WP_MCP_AI_Pro_Toolkit_Manifests' ) ) {
	/**
	 * Manifest discovery, schema validation and the shared records table.
	 *
	 * @since 2.6.0
	 */
	class WP_MCP_AI_Pro_Toolkit_Manifests {

		/**
		 * Records-table schema version. Bump to re-run dbDelta.
		 */
		const DB_VERSION = '1.0.0';

		/**
		 * Option holding the installed schema version.
		 */
		const OPTION_DB_VERSION = 'wp_mcp_ai_pro_toolkit_db_version';

		/**
		 * Option holding every toolkit's settings, keyed by slug.
		 */
		const OPTION_SETTINGS = 'wp_mcp_ai_pro_toolkit_settings';

		/**
		 * Placeholder returned for stored secrets.
		 */
		const MASKED_PLACEHOLDER = '**************';

		/**
		 * Validated manifests, keyed by slug (per-request cache).
		 *
		 * @var array<string, ToolkitManifest>|null
		 */
		private static $cache = null;

		/**
		 * Validation errors, keyed by manifest file name.
		 *
		 * @var array<string, array<int, string>>
		 */
		private static $errors = array();

		/**
		 * Whether install() has already been considered this request.
		 *
		 * @var bool
		 */
		private static $installed = false;

		/**
		 * Fully-qualified records table name.
		 *
		 * @return string
		 */
		public static function table(): string {
			global $wpdb;

			return $wpdb->prefix . 'nvoos_toolkit_records';
		}

		/**
		 * Create or migrate the records table.
		 *
		 * @return void
		 */
		public static function install(): void {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table   = self::table();
			$collate = $wpdb->get_charset_collate();

			// One row per business record; `data` is the manifest-shaped payload.
			$sql = "CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				toolkit varchar(64) NOT NULL,
				entity varchar(64) NOT NULL,
				external_id varchar(191) NOT NULL,
				data longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY toolkit_entity_external (toolkit,entity,external_id),
				KEY toolkit_entity (toolkit,entity)
			) {$collate};";

			dbDelta( $sql );

			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}

		/**
		 * Install the schema once per request when the version moved.
		 *
		 * Cheap on the hot path: one option read unless the version differs.
		 * This covers both fresh activations and existing sites that updated
		 * the plugin without re-activating it.
		 *
		 * @return void
		 */
		public static function maybe_install(): void {
			if ( self::$installed ) {
				return;
			}

			self::$installed = true;

			if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
				self::install();
			}
		}

		/**
		 * Every valid manifest, keyed by slug.
		 *
		 * @return array<string, ToolkitManifest>
		 */
		public static function manifests(): array {
			if ( null !== self::$cache ) {
				return self::$cache;
			}

			self::$cache  = array();
			self::$errors = array();

			if ( ! class_exists( 'Nvoos\\Core\\Domain\\ValueObject\\ToolkitManifest' ) ) {
				self::$errors['runtime'] = array( 'The nvoos/core manifest validator is not loaded.' );

				return self::$cache;
			}

			$directories = (array) apply_filters(
				'wp_mcp_ai_pro_toolkit_manifest_dirs',
				array( WP_MCP_AI_PRO_PATH . 'includes/toolkits/manifests' )
			);

			foreach ( $directories as $directory ) {
				$files = glob( trailingslashit( (string) $directory ) . '*.json' );

				foreach ( (array) $files as $file ) {
					self::load_file( (string) $file );
				}
			}

			/**
			 * Register manifests programmatically (e.g. from another plugin).
			 *
			 * @param array<string, array<string, mixed>> $manifests Raw documents keyed by slug.
			 */
			$extra = (array) apply_filters( 'wp_mcp_ai_pro_toolkit_manifests', array() );

			foreach ( $extra as $slug => $document ) {
				self::register( is_array( $document ) ? $document : array(), (string) $slug );
			}

			return self::$cache;
		}

		/**
		 * Validate one JSON file and register it.
		 *
		 * @param string $file Absolute path.
		 * @return void
		 */
		private static function load_file( string $file ): void {
			$name = basename( $file );

			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled manifest, not a remote request.

			if ( false === $raw ) {
				self::$errors[ $name ] = array( 'Could not read the manifest.' );

				return;
			}

			$document = json_decode( $raw, true );

			if ( ! is_array( $document ) ) {
				self::$errors[ $name ] = array( 'Invalid JSON: ' . json_last_error_msg() );

				return;
			}

			self::register( $document, $name );
		}

		/**
		 * Validate a decoded document and register it.
		 *
		 * @param array<string, mixed> $document Decoded manifest.
		 * @param string               $source   File name or slug, for error reporting.
		 * @return void
		 */
		private static function register( array $document, string $source ): void {
			try {
				$manifest = ToolkitManifest::fromArray( $document );
			} catch ( \Throwable $error ) {
				self::$errors[ $source ] = array( $error->getMessage() );

				return;
			}

			// A file named after another toolkit is almost always a copy/paste
			// mistake and would make the catalogue lie about its source.
			if ( str_ends_with( $source, '.json' ) && basename( $source, '.json' ) !== $manifest->slug() ) {
				self::$errors[ $source ] = array( 'Manifest slug does not match its file name.' );

				return;
			}

			self::$cache[ $manifest->slug() ] = $manifest;
		}

		/**
		 * Validation errors from the last discovery pass.
		 *
		 * @return array<string, array<int, string>>
		 */
		public static function errors(): array {
			self::manifests();

			return self::$errors;
		}

		/**
		 * One manifest by slug, or null.
		 *
		 * @param string $slug Toolkit slug.
		 * @return ToolkitManifest|null
		 */
		public static function find( string $slug ): ?ToolkitManifest {
			$manifests = self::manifests();

			return $manifests[ $slug ] ?? null;
		}

		/**
		 * Settings schema + stored values for one toolkit.
		 *
		 * @param ToolkitManifest $manifest Manifest.
		 * @return array<string, mixed> Keyed by setting key.
		 */
		public static function settings( ToolkitManifest $manifest ): array {
			$stored = self::stored_settings();
			$slug   = $manifest->slug();
			$values = array();

			foreach ( $manifest->settings() as $field ) {
				$key     = (string) ( $field['key'] ?? '' );
				$default = $field['default'] ?? null;
				$value   = array_key_exists( $key, (array) ( $stored[ $slug ] ?? array() ) )
					? $stored[ $slug ][ $key ]
					: $default;

				if ( 'secret' === (string) ( $field['type'] ?? 'text' ) ) {
					$value = is_string( $value ) && '' !== $value ? self::MASKED_PLACEHOLDER : '';
				}

				$values[ $key ] = $value;
			}

			return $values;
		}

		/**
		 * Validate and persist settings values.
		 *
		 * @param ToolkitManifest      $manifest Manifest.
		 * @param array<string, mixed> $values   Submitted values.
		 * @return array{0: array<string, string>, 1: array<string, mixed>} Errors, then stored values.
		 */
		public static function save_settings( ToolkitManifest $manifest, array $values ): array {
			$errors  = array();
			$stored  = self::stored_settings();
			$slug    = $manifest->slug();
			$current = (array) ( $stored[ $slug ] ?? array() );
			$next    = $current;

			foreach ( $manifest->settings() as $field ) {
				$key = (string) ( $field['key'] ?? '' );

				if ( ! array_key_exists( $key, $values ) ) {
					continue;
				}

				$type  = (string) ( $field['type'] ?? 'text' );
				$value = $values[ $key ];

				// A masked secret means "leave the stored value alone".
				if ( 'secret' === $type && self::MASKED_PLACEHOLDER === $value ) {
					continue;
				}

				list( $valid, $cast, $message ) = self::cast_setting( $field, $value );

				if ( ! $valid ) {
					$errors[ $key ] = (string) $message;
					continue;
				}

				$next[ $key ] = $cast;
			}

			if ( array() !== $errors ) {
				return array( $errors, self::settings( $manifest ) );
			}

			$stored[ $slug ] = $next;

			update_option( self::OPTION_SETTINGS, $stored, false );

			return array( array(), self::settings( $manifest ) );
		}

		/**
		 * Raw settings option, normalised to an array.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private static function stored_settings(): array {
			$stored = get_option( self::OPTION_SETTINGS, array() );

			return is_array( $stored ) ? $stored : array();
		}

		/**
		 * Cast one submitted setting against its declared type.
		 *
		 * @param array<string, mixed> $field Setting field.
		 * @param mixed                $value Submitted value.
		 * @return array{0: bool, 1: mixed, 2: string|null}
		 */
		private static function cast_setting( array $field, $value ): array {
			switch ( (string) ( $field['type'] ?? 'text' ) ) {
				case 'number':
					return is_numeric( $value )
						? array( true, 0 + $value, null )
						: array( false, null, __( 'Must be a number.', 'mcp-ai-wpoos-pro' ) );

				case 'boolean':
					if ( is_bool( $value ) ) {
						return array( true, $value, null );
					}

					if ( in_array( $value, array( 1, '1', 'true' ), true ) ) {
						return array( true, true, null );
					}

					if ( in_array( $value, array( 0, '0', 'false' ), true ) ) {
						return array( true, false, null );
					}

					return array( false, null, __( 'Must be a boolean.', 'mcp-ai-wpoos-pro' ) );

				case 'select':
					$options = (array) ( $field['options'] ?? array() );
					$allowed = array_is_list( $options ) ? $options : array_keys( $options );

					/* translators: %s: comma-separated allowed values. */
					$message = sprintf( __( 'Must be one of: %s.', 'mcp-ai-wpoos-pro' ), implode( ', ', $allowed ) );

					return in_array( $value, $allowed, true )
						? array( true, $value, null )
						: array( false, null, $message );

				case 'text':
				case 'secret':
				default:
					return is_scalar( $value )
						? array( true, (string) $value, null )
						: array( false, null, __( 'Must be a string.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
