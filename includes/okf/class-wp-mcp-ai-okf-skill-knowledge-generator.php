<?php
/**
 * OKF Skill Knowledge Bundle Generator.
 *
 * Auto-generates the `skill-knowledge` OKF bundle in the WordPress uploads
 * directory from the SKILL.md files bundled with the plugin. This makes the
 * okf_* MCP tools (okf_search, okf_browse, okf_read_concept, ...) able to
 * search and navigate the bundled skills out of the box, matching the
 * documented runtime bundle layout:
 *
 *     wp-content/uploads/mcp-ai-wpoos/knowledge/skill-knowledge/
 *
 * @package WP_MCP_AI
 * @since   1.1.61
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and refreshes the OKF `skill-knowledge` bundle.
 *
 * Each bundled skill directory is copied verbatim into the bundle, keeping
 * SKILL.md (the concept document, already OKF-conformant with `type: Skill`
 * frontmatter) and its companion reference files at their relative paths, so
 * markdown cross-links between skill documents keep the same structure they
 * have in the plugin source.
 *
 * @since 1.1.61
 */
class WP_MCP_AI_OKF_Skill_Knowledge_Generator {

	/**
	 * Bundle directory name within the runtime knowledge root.
	 *
	 * @since 1.1.61
	 * @var string
	 */
	const BUNDLE_NAME = 'skill-knowledge';

	/**
	 * Option key storing the fingerprint of the last generated bundle.
	 *
	 * @since 1.1.61
	 * @var string
	 */
	const GENERATED_OPTION = 'wp_mcp_ai_okf_skill_knowledge_generated';

	/**
	 * Register the bootstrap hook.
	 *
	 * Hooks `wp_mcp_ai_bootstrapped` at priority 32 so the bundle exists
	 * before any OKF MCP tool can run, but after the plugin (and Paper Store
	 * at priority 30) has finished bootstrapping.
	 *
	 * @since 1.1.61
	 * @return void
	 */
	public static function init() {
		if ( ! has_action( 'wp_mcp_ai_bootstrapped', array( __CLASS__, 'maybe_generate' ) ) ) {
			add_action( 'wp_mcp_ai_bootstrapped', array( __CLASS__, 'maybe_generate' ), 32 );
		}
	}

	/**
	 * Generate the bundle when it is missing or out of date.
	 *
	 * Runs once per plugin version (the stored fingerprint differs on first
	 * install and on every upgrade). Cheap on all subsequent requests: a
	 * single autoloaded option comparison.
	 *
	 * @since 1.1.61
	 * @return void
	 */
	public static function maybe_generate() {
		if ( get_option( self::GENERATED_OPTION, '' ) === self::get_fingerprint() ) {
			return;
		}

		self::generate();
	}

	/**
	 * Compute the current bundled-skill fingerprint.
	 *
	 * The plugin version alone is used: the bundled skill set only changes
	 * when the plugin ships a new version, so the bundle is rebuilt on first
	 * install and on every upgrade. Keeping this a constant read means the
	 * per-request bootstrap cost stays at a single autoloaded option
	 * comparison — no filesystem scan on every request.
	 *
	 * @since 1.1.61
	 * @return string Fingerprint string.
	 */
	public static function get_fingerprint() {
		return defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '0';
	}

	/**
	 * (Re)generate the OKF skill-knowledge bundle on disk.
	 *
	 * @since 1.1.61
	 *
	 * @param bool $force Rebuild even when the stored fingerprint is current.
	 * @return array Summary array with 'generated' (bool), 'concepts' (int),
	 *               'bundle' (string), and 'errors' (string[]) keys.
	 */
	public static function generate( $force = false ) {
		$result = array(
			'bundle'    => self::BUNDLE_NAME,
			'generated' => false,
			'concepts'  => 0,
			'errors'    => array(),
		);

		if ( ! $force && get_option( self::GENERATED_OPTION, '' ) === self::get_fingerprint() ) {
			return $result;
		}

		$bundle_path = self::get_bundle_root();

		if ( '' === $bundle_path ) {
			$result['errors'][] = __( 'Uploads directory is unavailable; cannot generate the OKF skill-knowledge bundle.', 'mcp-ai-wpoos' );
			return $result;
		}

		// Rebuild from scratch: skills are small markdown files (the bundled
		// set is ~1 MB), and a clean rebuild avoids stale files from removed
		// or renamed skills.
		if ( is_dir( $bundle_path ) ) {
			self::remove_directory( $bundle_path );
		}

		// Ensure the runtime knowledge root and the documented bundle
		// skeletons exist. site-knowledge and external-bundles are user-
		// curated; creating them empty lets okf_write_concept target them
		// without a manual directory setup.
		$knowledge_root = dirname( $bundle_path );
		$skeletons      = array(
			$knowledge_root,
			$bundle_path,
			$knowledge_root . '/site-knowledge',
			$knowledge_root . '/external-bundles',
		);
		foreach ( $skeletons as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: directory path */
					__( 'Failed to create OKF directory: %s', 'mcp-ai-wpoos' ),
					$dir
				);
			}
		}

		if ( ! is_dir( $bundle_path ) ) {
			return $result;
		}

		// Copy every bundled skill into the bundle.
		$skill_dirs = self::collect_bundled_skill_dirs();
		if ( empty( $skill_dirs ) ) {
			$result['errors'][] = __( 'No bundled skills found; cannot generate the OKF skill-knowledge bundle.', 'mcp-ai-wpoos' );
			return $result;
		}

		$concepts = 0;
		foreach ( $skill_dirs as $skill_dir ) {
			$skill_file = $skill_dir . '/SKILL.md';
			if ( ! file_exists( $skill_file ) ) {
				continue;
			}

			$skill_name = basename( $skill_dir );
			$target_dir = $bundle_path . '/' . $skill_name;

			$copy_errors = self::copy_skill_directory( $skill_dir, $target_dir );
			if ( ! empty( $copy_errors ) ) {
				foreach ( $copy_errors as $copy_error ) {
					$result['errors'][] = $copy_error;
				}
				continue;
			}

			++$concepts;
		}

		// Regenerate the bundle root index (progressive disclosure).
		$index_errors = self::write_root_index( $bundle_path );
		if ( ! empty( $index_errors ) ) {
			$result['errors'] = array_merge( $result['errors'], $index_errors );
		}

		$result['generated'] = true;
		$result['concepts']  = $concepts;

		// Remember the fingerprint so bootstrap stays cheap on later requests.
		update_option( self::GENERATED_OPTION, self::get_fingerprint() );

		/**
		 * Fires after the OKF skill-knowledge bundle has been (re)generated.
		 *
		 * @since 1.1.61
		 *
		 * @param string $bundle_path Absolute path to the bundle directory.
		 * @param int    $concept_count Number of skill concepts copied.
		 */
		do_action( 'wp_mcp_ai_okf_bundle_initialized', $bundle_path, $concepts );

		return $result;
	}

	/**
	 * Get the absolute path to the skill-knowledge bundle directory.
	 *
	 * Resolved through the OKF Bundle Manager so the `wp_mcp_ai_okf_knowledge_root`
	 * filter and the knowledge-root security guards apply to the generated
	 * bundle exactly as they do to every tool-resolved bundle.
	 *
	 * @since 1.1.61
	 * @since 1.1.62 — Routed through WP_MCP_AI_OKF_Bundle_Manager.
	 * @return string Absolute normalized path, or empty string when the
	 *                uploads directory is unavailable.
	 */
	public static function get_bundle_root() {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			$upload_dir = wp_upload_dir();

			return empty( $upload_dir['basedir'] )
				? ''
				: wp_normalize_path( $upload_dir['basedir'] . '/mcp-ai-wpoos/knowledge/' . self::BUNDLE_NAME );
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->get_knowledge_root();

		if ( is_wp_error( $root ) ) {
			return '';
		}

		return wp_normalize_path( $root . '/' . self::BUNDLE_NAME );
	}

	/**
	 * Collect the bundled-skills source directories.
	 *
	 * Includes the base plugin's bundled skills and, when present, the Pro
	 * addon's, mirroring the skill installer's source resolution.
	 *
	 * @since 1.1.61
	 * @return string[] Absolute paths to skill directories containing SKILL.md.
	 */
	private static function collect_bundled_skill_dirs() {
		$roots = array();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$base_dir = trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills';
			if ( is_dir( $base_dir ) ) {
				$roots[] = $base_dir;
			}
		}

		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pro_dir = trailingslashit( WP_MCP_AI_PRO_PATH ) . 'includes/bundled-skills';
			if ( is_dir( $pro_dir ) ) {
				$roots[] = $pro_dir;
			}
		}

		$skill_dirs = array();
		foreach ( $roots as $root ) {
			$dirs = glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR );
			if ( ! is_array( $dirs ) ) {
				continue;
			}
			foreach ( $dirs as $dir ) {
				if ( file_exists( $dir . '/SKILL.md' ) ) {
					$skill_dirs[] = wp_normalize_path( $dir );
				}
			}
		}

		return $skill_dirs;
	}

	/**
	 * Recursively copy a bundled skill directory into the bundle.
	 *
	 * Copies every file (SKILL.md plus companion reference/example files) so
	 * markdown cross-links inside the skill body keep resolving against the
	 * same relative paths they have in the plugin source.
	 *
	 * @since 1.1.61
	 *
	 * @param string $source Absolute path to the source skill directory.
	 * @param string $target Absolute path to the destination directory.
	 * @return string[] Error messages; empty when the copy succeeded.
	 */
	private static function copy_skill_directory( $source, $target ) {
		$errors = array();

		if ( ! is_dir( $source ) ) {
			$errors[] = sprintf(
				/* translators: %s: source path */
				__( 'Bundled skill directory not found: %s', 'mcp-ai-wpoos' ),
				$source
			);
			return $errors;
		}

		$source = rtrim( $source, '/\\' );
		$prefix = strlen( $source ) + 1;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( UnexpectedValueException $e ) {
			$errors[] = sprintf(
				/* translators: %s: source path */
				__( 'Failed to scan bundled skill directory: %s', 'mcp-ai-wpoos' ),
				$source
			);
			return $errors;
		}

		foreach ( $iterator as $file_info ) {
			$relative = str_replace( '\\', '/', substr( $file_info->getPathname(), $prefix ) );
			$dest     = wp_normalize_path( $target . '/' . $relative );

			if ( $file_info->isDir() ) {
				if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
					$errors[] = sprintf(
						/* translators: %s: destination path */
						__( 'Failed to create OKF bundle directory: %s', 'mcp-ai-wpoos' ),
						$dest
					);
				}
				continue;
			}

			$parent = dirname( $dest );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				$errors[] = sprintf(
					/* translators: %s: destination path */
					__( 'Failed to create OKF bundle directory: %s', 'mcp-ai-wpoos' ),
					$parent
				);
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Plugin-to-uploads copy; result checked below. OKF bundles are local filesystem only.
			if ( ! @copy( $file_info->getPathname(), $dest ) ) {
				$errors[] = sprintf(
					/* translators: %s: destination path */
					__( 'Failed to copy OKF concept file: %s', 'mcp-ai-wpoos' ),
					$dest
				);
			}
		}

		return $errors;
	}

	/**
	 * Write the bundle root index.md listing every skill concept.
	 *
	 * @since 1.1.61
	 *
	 * @param string $bundle_path Absolute path to the bundle directory.
	 * @return string[] Error messages; empty when the index was written.
	 */
	private static function write_root_index( $bundle_path ) {
		$errors = array();
		$lines  = array( '# Skill Knowledge', '' );

		$parser = new WP_MCP_AI_OKF_Parser();

		$skill_dirs = glob( trailingslashit( $bundle_path ) . '*', GLOB_ONLYDIR );
		if ( is_array( $skill_dirs ) ) {
			sort( $skill_dirs );
			foreach ( $skill_dirs as $skill_dir ) {
				$skill_file = $skill_dir . '/SKILL.md';
				if ( ! file_exists( $skill_file ) ) {
					continue;
				}

				$skill_name = basename( $skill_dir );
				$title      = $skill_name;
				$desc       = '';

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read of a plugin-authored file.
				$content = file_get_contents( $skill_file );
				if ( false !== $content ) {
					$parsed = $parser->parse( $content );
					if ( is_array( $parsed ) ) {
						if ( ! empty( $parsed['frontmatter']['name'] ) ) {
							$title = $parsed['frontmatter']['name'];
						}
						if ( ! empty( $parsed['frontmatter']['description'] ) ) {
							$desc = $parsed['frontmatter']['description'];
						}
					}
				}

				$line = '* [' . $title . '](' . $skill_name . '/SKILL.md)';
				if ( '' !== $desc ) {
					$line .= ' - ' . $desc;
				}
				$lines[] = $line;
			}
		}

		$index_path = $bundle_path . '/index.md';

		$content = implode( "\n", $lines ) . "\n";
		// Bundle-root indexes may carry an okf_version frontmatter block
		// (OKF v0.2 §12 — the only index frontmatter the spec permits).
		$content = "---\nokf_version: \"" . WP_MCP_AI_OKF_Writer::OKF_VERSION . "\"\n---\n\n" . $content;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write of a plugin-generated index. OKF bundles are local filesystem only.
		$written = file_put_contents( $index_path, $content, LOCK_EX );
		if ( false === $written ) {
			$errors[] = sprintf(
				/* translators: %s: file path */
				__( 'Failed to write OKF bundle index: %s', 'mcp-ai-wpoos' ),
				$index_path
			);
		}

		return $errors;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @since 1.1.61
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( UnexpectedValueException $e ) {
			return;
		}

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Rebuilding a plugin-generated bundle in the uploads directory.
				@rmdir( $file_info->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Rebuilding a plugin-generated bundle in the uploads directory.
				@unlink( $file_info->getPathname() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Rebuilding a plugin-generated bundle in the uploads directory.
		@rmdir( $dir );
	}
}
