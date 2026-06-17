<?php
/**
 * NV oOS Toolkit Shell — Manifest Registry
 *
 * Discovers per-toolkit JSON manifests under
 * `addons/pro/config/spa-manifests/<toolkit>.json` and a fallback bundled
 * directory under `addons/toolkit-shell/config/spa-manifests/<toolkit>.json`.
 *
 * The bundled directory provides a discovery surface for any environment
 * where the Pro addon is not installed (so the shell remains usable without
 * Pro, even if no live data backs it).
 *
 * Manifests are validated at load time — unknown top-level keys are dropped
 * silently so manifests can grow without breaking older shell versions.
 *
 * @package NV_oOS_Toolkit_Shell
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manifest registry singleton.
 *
 * @since 0.1.0
 */
class NV_oOS_Toolkit_Shell_Manifest_Registry {

	/**
	 * Allowed field types.
	 *
	 * @var array<int, string>
	 */
	const ALLOWED_FIELD_TYPES = array(
		'string',
		'number',
		'integer',
		'email',
		'url',
		'date',
		'datetime',
		'enum',
		'text',
		'boolean',
		'reference',
	);

	/**
	 * Allowed view types.
	 *
	 * @var array<int, string>
	 */
	const ALLOWED_VIEW_TYPES = array(
		'table',
		'kanban',
		'detail',
		'form',
		'calendar',
		'chart',
	);

	/**
	 * Maximum manifest file size in bytes (256 KB).
	 *
	 * @var int
	 */
	const MAX_MANIFEST_SIZE = 262144;

	/**
	 * Cached list of manifests, keyed by toolkit slug.
	 *
	 * @var array<string, array>|null
	 */
	private static $cache = null;

	/**
	 * Return all directories that may contain manifests.
	 *
	 * Order matters: later directories override earlier ones (so Pro-bundled
	 * manifests override the shell's own bundled defaults).
	 *
	 * Filter `nvoos_toolkit_shell_manifest_dirs` may add or remove directories.
	 *
	 * @return array<int, string>
	 */
	public static function get_manifest_dirs() {
		$dirs = array(
			NVOOS_TOOLKIT_SHELL_PATH . 'config/spa-manifests',
		);
		// Pro addon directory, if installed.
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$dirs[] = WP_PLUGIN_DIR . '/mcp-ai-wpoos/addons/pro/config/spa-manifests';
		}
		// Allow filters to add custom roots.
		$filtered = apply_filters( 'nvoos_toolkit_shell_manifest_dirs', $dirs );
		if ( ! is_array( $filtered ) ) {
			return $dirs;
		}
		// Normalize: strings only, deduplicate.
		$out = array();
		foreach ( $filtered as $dir ) {
			if ( is_string( $dir ) && '' !== $dir ) {
				$out[ $dir ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Return all loaded manifests, keyed by toolkit slug.
	 *
	 * @param bool $force_reload Bypass the cache.
	 * @return array<string, array>
	 */
	public static function get_all( $force_reload = false ) {
		if ( ! $force_reload && null !== self::$cache ) {
			return self::$cache;
		}
		$out = array();
		foreach ( self::get_manifest_dirs() as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( trailingslashit( $dir ) . '*.json' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				$slug     = sanitize_key( basename( $file, '.json' ) );
				$manifest = self::load_file( $file );
				if ( null === $manifest ) {
					continue;
				}
				// Force the slug from the filename to prevent spoofing.
				$manifest['toolkit'] = $slug;
				$out[ $slug ]        = $manifest;
			}
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * Get a single manifest by toolkit slug.
	 *
	 * @param string $toolkit Toolkit slug.
	 * @return array|null
	 */
	public static function get( $toolkit ) {
		$toolkit = sanitize_key( $toolkit );
		if ( '' === $toolkit ) {
			return null;
		}
		$all = self::get_all();
		return isset( $all[ $toolkit ] ) ? $all[ $toolkit ] : null;
	}

	/**
	 * Load and validate a manifest file.
	 *
	 * @param string $path Absolute path to a JSON file.
	 * @return array|null Sanitized manifest, or null on failure.
	 */
	private static function load_file( $path ) {
		// Reject any path that escapes the configured manifest dirs.
		$realpath = realpath( $path );
		if ( false === $realpath ) {
			return null;
		}
		$allowed = false;
		foreach ( self::get_manifest_dirs() as $dir ) {
			$dir_real = realpath( $dir );
			if ( false === $dir_real ) {
				continue;
			}
			// Normalize trailing separator.
			$dir_real = rtrim( $dir_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			if ( 0 === strpos( $realpath, $dir_real ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return null;
		}

		// Cap file size to avoid memory bombs.
		$size = filesize( $realpath );
		if ( false === $size || $size > self::MAX_MANIFEST_SIZE ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local config file.
		$raw = file_get_contents( $realpath );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return self::sanitize_manifest( $decoded );
	}

	/**
	 * Sanitize and validate a decoded manifest array.
	 *
	 * Unknown top-level keys are dropped silently so manifests can grow.
	 *
	 * @param array $raw Decoded JSON.
	 * @return array|null Sanitized manifest, or null if required fields are missing.
	 */
	public static function sanitize_manifest( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$out = array(
			'version'        => isset( $raw['version'] ) ? sanitize_text_field( (string) $raw['version'] ) : '1.0',
			'toolkit'        => isset( $raw['toolkit'] ) ? sanitize_key( (string) $raw['toolkit'] ) : '',
			'label'          => isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '',
			'icon'           => isset( $raw['icon'] ) ? sanitize_text_field( (string) $raw['icon'] ) : 'admin-generic',
			'rest_namespace' => isset( $raw['rest_namespace'] ) ? sanitize_text_field( (string) $raw['rest_namespace'] ) : 'mcp-ai-pro/v1',
			'capability'     => isset( $raw['capability'] ) ? sanitize_key( (string) $raw['capability'] ) : 'edit_posts',
			'resources'      => array(),
			'views'          => array(),
		);

		// rest_namespace must look like 'foo/v1'..'foo/v999'.
		if ( ! preg_match( '#^[a-z0-9\-]+/v\d{1,3}$#', $out['rest_namespace'] ) ) {
			$out['rest_namespace'] = 'mcp-ai-pro/v1';
		}

		// Sanitize resources.
		if ( ! empty( $raw['resources'] ) && is_array( $raw['resources'] ) ) {
			foreach ( $raw['resources'] as $resource ) {
				$sanitized = self::sanitize_resource( $resource );
				if ( null !== $sanitized ) {
					$out['resources'][] = $sanitized;
				}
			}
		}

		// Sanitize views.
		if ( ! empty( $raw['views'] ) && is_array( $raw['views'] ) ) {
			foreach ( $raw['views'] as $view ) {
				$sanitized = self::sanitize_view( $view );
				if ( null !== $sanitized ) {
					$out['views'][] = $sanitized;
				}
			}
		}

		// Reject empty manifests.
		if ( '' === $out['toolkit'] || empty( $out['resources'] ) ) {
			return null;
		}

		return $out;
	}

	/**
	 * Sanitize a single resource definition.
	 *
	 * @param array $resource Resource definition.
	 * @return array|null
	 */
	private static function sanitize_resource( $resource ) {
		if ( ! is_array( $resource ) ) {
			return null;
		}
		$name = isset( $resource['name'] ) ? sanitize_key( (string) $resource['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		$out = array(
			'name'        => $name,
			'label'       => isset( $resource['label'] ) ? sanitize_text_field( (string) $resource['label'] ) : $name,
			'endpoint'    => isset( $resource['endpoint'] ) ? self::sanitize_endpoint( (string) $resource['endpoint'] ) : '/' . $name,
			'primary_key' => isset( $resource['primary_key'] ) ? sanitize_key( (string) $resource['primary_key'] ) : 'id',
			'fields'      => array(),
		);
		if ( ! empty( $resource['fields'] ) && is_array( $resource['fields'] ) ) {
			foreach ( $resource['fields'] as $field ) {
				$sanitized = self::sanitize_field( $field );
				if ( null !== $sanitized ) {
					$out['fields'][] = $sanitized;
				}
			}
		}
		return $out;
	}

	/**
	 * Sanitize a single field definition.
	 *
	 * @param array $field Field definition.
	 * @return array|null
	 */
	private static function sanitize_field( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}
		$name = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'string';
		if ( ! in_array( $type, self::ALLOWED_FIELD_TYPES, true ) ) {
			$type = 'string';
		}
		$out = array(
			'name'     => $name,
			'type'     => $type,
			'label'    => isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $name,
			'required' => ! empty( $field['required'] ),
			'readonly' => ! empty( $field['readonly'] ),
		);
		// Optional enum options.
		if ( 'enum' === $type && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			$out['options'] = array();
			foreach ( $field['options'] as $option ) {
				$option = sanitize_text_field( (string) $option );
				if ( '' !== $option ) {
					$out['options'][] = $option;
				}
			}
		}
		// Optional reference target (resource name).
		if ( 'reference' === $type && isset( $field['reference'] ) ) {
			$out['reference'] = sanitize_key( (string) $field['reference'] );
		}
		return $out;
	}

	/**
	 * Sanitize a single view definition.
	 *
	 * @param array $view View definition.
	 * @return array|null
	 */
	private static function sanitize_view( $view ) {
		if ( ! is_array( $view ) ) {
			return null;
		}
		$name = isset( $view['name'] ) ? sanitize_key( (string) $view['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		$type = isset( $view['type'] ) ? sanitize_key( (string) $view['type'] ) : 'table';
		if ( ! in_array( $type, self::ALLOWED_VIEW_TYPES, true ) ) {
			$type = 'table';
		}
		$out = array(
			'name'     => $name,
			'type'     => $type,
			'resource' => isset( $view['resource'] ) ? sanitize_key( (string) $view['resource'] ) : '',
			'default'  => ! empty( $view['default'] ),
		);
		if ( isset( $view['group_by'] ) ) {
			$out['group_by'] = sanitize_key( (string) $view['group_by'] );
		}
		if ( isset( $view['label'] ) ) {
			$out['label'] = sanitize_text_field( (string) $view['label'] );
		}
		return $out;
	}

	/**
	 * Sanitize a REST endpoint path.
	 *
	 * Allows only ASCII letters, digits, dashes, underscores, slashes, and
	 * curly-brace placeholders (e.g. /resource/{id}). Rejects anything else.
	 *
	 * @param string $endpoint Raw endpoint.
	 * @return string Sanitized endpoint, always starting with `/`.
	 */
	private static function sanitize_endpoint( $endpoint ) {
		$endpoint = trim( $endpoint );
		if ( '' === $endpoint ) {
			return '/';
		}
		if ( '/' !== $endpoint[0] ) {
			$endpoint = '/' . $endpoint;
		}
		// Strip any character outside the allowed set.
		$endpoint = preg_replace( '#[^A-Za-z0-9_\-/{}]#', '', $endpoint );
		if ( null === $endpoint || '' === $endpoint ) {
			return '/';
		}
		return $endpoint;
	}

	/**
	 * Reset the static cache (used in tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}
}
