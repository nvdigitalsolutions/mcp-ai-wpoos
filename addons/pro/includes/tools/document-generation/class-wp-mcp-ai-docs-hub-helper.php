<?php
/**
 * Docs Hub content-folder helper for the Document Generation Toolkit.
 *
 * Shared filesystem + integration helpers used by the docs_hub_* document
 * generation tools that manage the Markdown/text files published by the
 * standalone NV oOS Docs Hub plugin (addons/docs-hub/). Centralises the
 * security-critical path-resolution logic so every tool enforces the same
 * traversal protections.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
 * @since 2.10.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper for the docs_hub_* document generation tools.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Docs_Hub_Helper {

	/**
	 * Allowed document extensions — mirrors NV_oOS_Docs_Hub_Scanner::ALLOWED_EXTENSIONS.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array( 'md', 'txt' );

	/**
	 * Maximum file size in bytes — mirrors NV_oOS_Docs_Hub_Scanner::MAX_FILE_SIZE (2 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 2097152;

	/**
	 * Maximum length of a relative document path.
	 *
	 * @var int
	 */
	const MAX_PATH_LENGTH = 240;

	/**
	 * Whether the Document Generation Toolkit is enabled in plugin settings.
	 *
	 * @since 2.10.0
	 *
	 * @return bool
	 */
	public static function toolkit_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_document_generation_toolkit'] );
	}

	/**
	 * Whether the standalone NV oOS Docs Hub plugin is loaded.
	 *
	 * @since 2.10.0
	 *
	 * @return bool
	 */
	public static function is_docs_hub_active() {
		return class_exists( 'NV_oOS_Docs_Hub_Plugin' );
	}

	/**
	 * Absolute path to the Docs Hub uploads content directory.
	 *
	 * Uses the plugin's own (filterable) resolver when the Docs Hub plugin
	 * is active; otherwise falls back to the default slug-named uploads
	 * folder so files can be staged before the plugin is installed.
	 *
	 * @since 2.10.0
	 *
	 * @return string Absolute directory path (trailing slash removed).
	 */
	public static function content_dir() {
		if ( self::is_docs_hub_active() ) {
			return untrailingslashit( NV_oOS_Docs_Hub_Plugin::uploads_docs_dir() );
		}

		$info = wp_upload_dir();
		$base = isset( $info['basedir'] ) ? (string) $info['basedir'] : '';
		return untrailingslashit( $base . '/nvoos-docs-hub/content' );
	}

	/**
	 * Ensure the content directory (and any subdirectory) exists.
	 *
	 * @since 2.10.0
	 *
	 * @param string $relative Optional subdirectory relative to the content root.
	 * @return string|false Absolute directory path, or false when it could not be created.
	 */
	public static function ensure_content_dir( $relative = '' ) {
		$root = self::content_dir();
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		if ( '' !== $relative ) {
			$target = $root . '/' . $relative;
			if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
				return false;
			}
			return untrailingslashit( $target );
		}

		return $root;
	}

	/**
	 * Validate and normalise a relative path inside the Docs Hub content folder.
	 *
	 * Gate-1 sanitisation for every path argument: rejects absolute paths,
	 * drive letters, `..` segments, empty segments and any filename that does
	 * not carry an allowed extension. Returns an empty string when the input
	 * cannot be made safe.
	 *
	 * @since 2.10.0
	 *
	 * @param mixed $raw              Raw path argument.
	 * @param bool  $require_doc_file When true (default) the path must end in .md/.txt.
	 * @return string Normalised forward-slash relative path, or '' when invalid.
	 */
	public static function sanitize_relative_path( $raw, $require_doc_file = true ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$path = trim( $raw );

		// Normalise Windows separators before validation so `..\secret`
		// cannot slip past the segment checks below.
		$path = wp_normalize_path( $path );

		if ( '' === $path || strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return '';
		}

		// Reject drive-letter prefixes (C:/...) that survive normalisation.
		if ( preg_match( '#^[a-zA-Z]:#', $path ) ) {
			return '';
		}

		// Reject absolute and self-referencing prefixes.
		if ( preg_match( '#^[./\\\\]#', $path ) ) {
			return '';
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			// Each segment must start alphanumeric and only contain safe
			// characters. `..` and `.` fail the first-character rule.
			if ( '' === $segment || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $segment ) ) {
				return '';
			}
		}

		if ( $require_doc_file ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				return '';
			}
		}

		return $path;
	}

	/**
	 * Resolve a validated relative path to an absolute path inside the content root.
	 *
	 * When the target exists, its realpath() must stay strictly inside the
	 * realpath() of the content root — the same guard NV_oOS_Docs_Hub_Scanner
	 * applies — so symlinked subdirectories cannot escape the root.
	 *
	 * @since 2.10.0
	 *
	 * @param string $relative Validated relative path (see sanitize_relative_path).
	 * @return string Absolute file path, or '' when the resolution fails.
	 */
	public static function resolve_safe_path( $relative ) {
		$root = self::content_dir();

		if ( ! is_dir( $root ) ) {
			// Root does not exist yet: the concatenation is safe because the
			// relative path already passed segment validation (no `..`, no
			// absolute prefix, no drive letter).
			return $root . '/' . $relative;
		}

		$candidate = $root . '/' . $relative;

		if ( file_exists( $candidate ) || is_link( $candidate ) ) {
			$resolved  = realpath( $candidate );
			$real_root = realpath( $root );

			if ( false === $resolved || false === $real_root ) {
				return '';
			}

			if ( $resolved !== $real_root && 0 !== strpos( $resolved, $real_root . DIRECTORY_SEPARATOR ) ) {
				return '';
			}

			return $resolved;
		}

		// Not created yet: walk up to the deepest existing ancestor and verify
		// it stays inside the root before allowing the write to create the rest.
		$ancestor = dirname( $candidate );
		while ( ! is_dir( $ancestor ) && $ancestor !== $root ) {
			$parent = dirname( $ancestor );
			if ( $parent === $ancestor ) {
				return '';
			}
			$ancestor = $parent;
		}

		$real_ancestor = realpath( $ancestor );
		$real_root     = realpath( $root );
		if ( false === $real_ancestor || false === $real_root ) {
			return '';
		}
		if ( $real_ancestor !== $real_root && 0 !== strpos( $real_ancestor, $real_root . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Whether the Docs Hub 'uploads' source is enabled in its settings.
	 *
	 * Returns null when the Docs Hub plugin is not active (status unknown).
	 *
	 * @since 2.10.0
	 *
	 * @return bool|null
	 */
	public static function is_uploads_source_enabled() {
		if ( ! self::is_docs_hub_active() ) {
			return null;
		}

		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$sources  = isset( $settings['sources'] ) ? (array) $settings['sources'] : array();

		return in_array( 'uploads', $sources, true );
	}

	/**
	 * Build a YAML frontmatter block accepted by NV_oOS_Docs_Hub_Indexer.
	 *
	 * Every value is sanitised so a newline or closing delimiter cannot be
	 * smuggled out of the frontmatter block.
	 *
	 * @since 2.10.0
	 *
	 * @param string $title  Page title (empty to omit).
	 * @param string $slug   Canonical slug override (empty to omit).
	 * @param int    $order  Sidebar order (null to omit).
	 * @param array  $extra  Additional scalar frontmatter pairs.
	 * @return string Frontmatter block including trailing blank line, or '' when empty.
	 */
	public static function build_frontmatter( $title = '', $slug = '', $order = null, $extra = array() ) {
		$lines = array();

		if ( '' !== $title ) {
			$lines[] = 'title: ' . sanitize_text_field( $title );
		}

		if ( '' !== $slug ) {
			$lines[] = 'slug: ' . sanitize_title( $slug );
		}

		if ( null !== $order ) {
			$lines[] = 'order: ' . absint( $order );
		}

		foreach ( (array) $extra as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$lines[] = $key . ': ' . sanitize_text_field( (string) $value );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "---\n" . implode( "\n", $lines ) . "\n---\n\n";
	}

	/**
	 * Extract the `title` field from a leading YAML frontmatter block.
	 *
	 * Lightweight parser mirroring NV_oOS_Docs_Hub_Indexer::extract_frontmatter() —
	 * used by the list/read tools to label files without booting the full
	 * indexer.
	 *
	 * @since 2.10.0
	 *
	 * @param string $content Raw file content.
	 * @return string Title, or '' when absent.
	 */
	public static function extract_frontmatter_title( $content ) {
		if ( ! preg_match( '/^-{3}\r?\n(.*?)\r?\n-{3}/s', (string) $content, $matches ) ) {
			return '';
		}

		foreach ( explode( "\n", $matches[1] ) as $line ) {
			if ( preg_match( '/^title:\s*(.+)$/', trim( $line ), $pair ) ) {
				return sanitize_text_field( trim( $pair[1], " \t\"'" ) );
			}
		}

		return '';
	}

	/**
	 * Read a validated document file's raw content.
	 *
	 * @since 2.10.0
	 *
	 * @param string $absolute Absolute file path from resolve_safe_path().
	 * @return string|WP_Error File content, or a WP_Error.
	 */
	public static function read_file( $absolute ) {
		if ( ! file_exists( $absolute ) || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_not_found', __( 'The requested document does not exist or is not readable.', 'mcp-ai-wpoos-pro' ) );
		}

		$size = filesize( $absolute );
		if ( false !== $size && $size > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_file_too_large', __( 'The document exceeds the 2 MB size limit.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- direct local file read; path was validated against the content root.
		$content = file_get_contents( $absolute );
		if ( false === $content ) {
			return new WP_Error( 'wp_mcp_ai_docs_hub_read_failed', __( 'The document could not be read.', 'mcp-ai-wpoos-pro' ) );
		}

		return $content;
	}

	/**
	 * Trigger a Docs Hub index rebuild when the plugin is active.
	 *
	 * @since 2.10.0
	 *
	 * @return array|null Summary from NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async(), or null when the plugin is inactive.
	 */
	public static function enqueue_rebuild() {
		if ( ! self::is_docs_hub_active() || ! class_exists( 'NV_oOS_Docs_Hub_Rebuild_Job' ) ) {
			return null;
		}

		return NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
	}

	/**
	 * Recursively collect document files under a directory.
	 *
	 * @since 2.10.0
	 *
	 * @param string $dir Absolute directory to walk.
	 * @return string[] Absolute file paths.
	 */
	public static function collect_doc_files( $dir ) {
		$results = array();

		if ( ! is_dir( $dir ) ) {
			return $results;
		}

		foreach ( self::ALLOWED_EXTENSIONS as $ext ) {
			$files = glob( rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '*.' . $ext );
			if ( ! empty( $files ) ) {
				$results = array_merge( $results, $files );
			}
		}

		$subdirs = glob( rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
		if ( ! empty( $subdirs ) ) {
			foreach ( $subdirs as $subdir ) {
				if ( is_link( $subdir ) ) {
					continue;
				}
				$results = array_merge( $results, self::collect_doc_files( $subdir ) );
			}
		}

		return $results;
	}
}
