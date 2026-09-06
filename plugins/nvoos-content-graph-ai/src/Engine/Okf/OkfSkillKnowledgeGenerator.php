<?php
/**
 * OKF skill-knowledge generator (Wave E6, sub-cluster 4).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_OKF_Skill_Knowledge_Generator`
 * (`includes/okf/class-wp-mcp-ai-okf-skill-knowledge-generator.php`):
 * byte-identical auto-generation of the `skill-knowledge` OKF bundle —
 * the `wp_mcp_ai_okf_skill_knowledge_generated` fingerprint option
 * (autoloaded, one comparison per request), the
 * `wp_mcp_ai_bootstrapped` priority-32 `maybe_generate` hook with the
 * `has_action` guard, the rebuild-from-scratch generation flow
 * (skeleton dirs, per-skill recursive copy, root index regeneration
 * with the `okf_version: "0.2"` stamp), the
 * `wp_mcp_ai_okf_bundle_initialized` action, and the byte-identical
 * summary shape (`generated`/`concepts`/`bundle`/`errors`).
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `get_fingerprint()` resolves per install mode: base
 *    `WP_MCP_AI_VERSION` monolith / this addon's
 *    `NVOOS_CONTENT_GRAPH_AI_VERSION` standalone (the fingerprint
 *    tracks the owning plugin's version so upgrades rebuild the
 *    bundle).
 *  - `collect_bundled_skill_dirs()` scans the base + Pro bundled-skill
 *    roots monolith (byte-identical) and additionally the addon's own
 *    `includes/bundled-skills` root standalone (none shipped today —
 *    generation degrades with the byte-identical
 *    'No bundled skills found' error without persisting the
 *    fingerprint option).
 *  - The base's `class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' )`
 *    fallback branch collapses — PSR-4 autoloading always resolves
 *    this package's `OkfBundleManager` (byte-identical behavior).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Okf
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Okf;

/**
 * Generates and refreshes the OKF `skill-knowledge` bundle.
 *
 * Each bundled skill directory is copied verbatim into the bundle, keeping
 * SKILL.md (the concept document, already OKF-conformant with `type: Skill`
 * frontmatter) and its companion reference files at their relative paths, so
 * markdown cross-links between skill documents keep the same structure they
 * have in the plugin source.
 *
 * @since 1.1.0
 */
class OkfSkillKnowledgeGenerator {

	/**
	 * Bundle directory name within the runtime knowledge root.
	 *
	 * @var string
	 */
	const BUNDLE_NAME = 'skill-knowledge';

	/**
	 * Option key storing the fingerprint of the last generated bundle.
	 *
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
	 * @return void
	 */
	public static function init() {
		if ( ! \has_action( 'wp_mcp_ai_bootstrapped', array( __CLASS__, 'maybe_generate' ) ) ) {
			\add_action( 'wp_mcp_ai_bootstrapped', array( __CLASS__, 'maybe_generate' ), 32 );
		}
	}

	/**
	 * Generate the bundle when it is missing or out of date.
	 *
	 * Runs once per plugin version (the stored fingerprint differs on first
	 * install and on every upgrade). Cheap on all subsequent requests: a
	 * single autoloaded option comparison.
	 *
	 * @return void
	 */
	public static function maybe_generate() {
		if ( \get_option( self::GENERATED_OPTION, '' ) === self::get_fingerprint() ) {
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
	 * @return string Fingerprint string.
	 */
	public static function get_fingerprint() {
		// Per-mode seam (see the class docblock): the owning plugin's version.
		if ( defined( 'WP_MCP_AI_VERSION' ) ) {
			return WP_MCP_AI_VERSION;
		}

		return defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : '0';
	}

	/**
	 * (Re)generate the OKF skill-knowledge bundle on disk.
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

		if ( ! $force && \get_option( self::GENERATED_OPTION, '' ) === self::get_fingerprint() ) {
			return $result;
		}

		$bundle_path = self::get_bundle_root();

		if ( '' === $bundle_path ) {
			$result['errors'][] = __( 'Uploads directory is unavailable; cannot generate the OKF skill-knowledge bundle.', 'nvoos-content-graph-ai' );
			return $result;
		}

		// Rebuild from scratch: skills are small markdown files (the bundled
		// set is ~1 MB), and a clean rebuild avoids stale files from removed
		// or renamed skills.
		if ( \is_dir( $bundle_path ) ) {
			self::remove_directory( $bundle_path );
		}

		// Ensure the runtime knowledge root and the documented bundle
		// skeletons exist. site-knowledge and external-bundles are user-
		// curated; creating them empty lets okf_write_concept target them
		// without a manual directory setup.
		$knowledge_root = \dirname( $bundle_path );
		$skeletons      = array(
			$knowledge_root,
			$bundle_path,
			$knowledge_root . '/site-knowledge',
			$knowledge_root . '/external-bundles',
		);
		foreach ( $skeletons as $dir ) {
			if ( ! \is_dir( $dir ) && ! \wp_mkdir_p( $dir ) ) {
				$result['errors'][] = \sprintf(
					/* translators: %s: directory path */
					__( 'Failed to create OKF directory: %s', 'nvoos-content-graph-ai' ),
					$dir
				);
			}
		}

		if ( ! \is_dir( $bundle_path ) ) {
			return $result;
		}

		// Copy every bundled skill into the bundle.
		$skill_dirs = self::collect_bundled_skill_dirs();
		if ( empty( $skill_dirs ) ) {
			$result['errors'][] = __( 'No bundled skills found; cannot generate the OKF skill-knowledge bundle.', 'nvoos-content-graph-ai' );
			return $result;
		}

		$concepts = 0;
		foreach ( $skill_dirs as $skill_dir ) {
			$skill_file = $skill_dir . '/SKILL.md';
			if ( ! \file_exists( $skill_file ) ) {
				continue;
			}

			$skill_name = \basename( $skill_dir );
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
			$result['errors'] = \array_merge( $result['errors'], $index_errors );
		}

		$result['generated'] = true;
		$result['concepts']  = $concepts;

		// Remember the fingerprint so bootstrap stays cheap on later requests.
		\update_option( self::GENERATED_OPTION, self::get_fingerprint() );

		/**
		 * Fires after the OKF skill-knowledge bundle has been (re)generated.
		 *
		 * @since 1.1.0
		 *
		 * @param string $bundle_path Absolute path to the bundle directory.
		 * @param int    $concept_count Number of skill concepts copied.
		 */
		\do_action( 'wp_mcp_ai_okf_bundle_initialized', $bundle_path, $concepts );

		return $result;
	}

	/**
	 * Get the absolute path to the skill-knowledge bundle directory.
	 *
	 * Resolved through the OKF Bundle Manager so the `wp_mcp_ai_okf_knowledge_root`
	 * filter and the knowledge-root security guards apply to the generated
	 * bundle exactly as they do to every tool-resolved bundle.
	 *
	 * @return string Absolute normalized path, or empty string when the
	 *                uploads directory is unavailable.
	 */
	public static function get_bundle_root() {
		$manager = new OkfBundleManager();
		$root    = $manager->get_knowledge_root();

		if ( \is_wp_error( $root ) ) {
			return '';
		}

		return \wp_normalize_path( $root . '/' . self::BUNDLE_NAME );
	}

	/**
	 * Collect the bundled-skills source directories.
	 *
	 * Includes the base plugin's bundled skills and, when present, the Pro
	 * addon's, mirroring the skill installer's source resolution — plus the
	 * addon's own bundled-skills root standalone (see the class docblock).
	 *
	 * @return string[] Absolute paths to skill directories containing SKILL.md.
	 */
	private static function collect_bundled_skill_dirs() {
		$roots = array();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$base_dir = \trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills';
			if ( \is_dir( $base_dir ) ) {
				$roots[] = $base_dir;
			}
		}

		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pro_dir = \trailingslashit( WP_MCP_AI_PRO_PATH ) . 'includes/bundled-skills';
			if ( \is_dir( $pro_dir ) ) {
				$roots[] = $pro_dir;
			}
		}

		if ( defined( 'NVOOS_CONTENT_GRAPH_AI_PATH' ) ) {
			$addon_dir = \trailingslashit( NVOOS_CONTENT_GRAPH_AI_PATH ) . 'includes/bundled-skills';
			if ( \is_dir( $addon_dir ) ) {
				$roots[] = $addon_dir;
			}
		}

		$skill_dirs = array();
		foreach ( $roots as $root ) {
			$dirs = \glob( \trailingslashit( $root ) . '*', GLOB_ONLYDIR );
			if ( ! \is_array( $dirs ) ) {
				continue;
			}
			foreach ( $dirs as $dir ) {
				if ( \file_exists( $dir . '/SKILL.md' ) ) {
					$skill_dirs[] = \wp_normalize_path( $dir );
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
	 * @param string $source Absolute path to the source skill directory.
	 * @param string $target Absolute path to the destination directory.
	 * @return string[] Error messages; empty when the copy succeeded.
	 */
	private static function copy_skill_directory( $source, $target ) {
		$errors = array();

		if ( ! \is_dir( $source ) ) {
			$errors[] = \sprintf(
				/* translators: %s: source path */
				__( 'Bundled skill directory not found: %s', 'nvoos-content-graph-ai' ),
				$source
			);
			return $errors;
		}

		$source = \rtrim( $source, '/\\' );
		$prefix = \strlen( $source ) + 1;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \UnexpectedValueException $e ) {
			$errors[] = \sprintf(
				/* translators: %s: source path */
				__( 'Failed to scan bundled skill directory: %s', 'nvoos-content-graph-ai' ),
				$source
			);
			return $errors;
		}

		foreach ( $iterator as $file_info ) {
			$relative = \str_replace( '\\', '/', \substr( $file_info->getPathname(), $prefix ) );
			$dest     = \wp_normalize_path( $target . '/' . $relative );

			if ( $file_info->isDir() ) {
				if ( ! \is_dir( $dest ) && ! \wp_mkdir_p( $dest ) ) {
					$errors[] = \sprintf(
						/* translators: %s: destination path */
						__( 'Failed to create OKF bundle directory: %s', 'nvoos-content-graph-ai' ),
						$dest
					);
				}
				continue;
			}

			$parent = \dirname( $dest );
			if ( ! \is_dir( $parent ) && ! \wp_mkdir_p( $parent ) ) {
				$errors[] = \sprintf(
					/* translators: %s: destination path */
					__( 'Failed to create OKF bundle directory: %s', 'nvoos-content-graph-ai' ),
					$parent
				);
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Plugin-to-uploads copy; result checked below. OKF bundles are local filesystem only.
			if ( ! @\copy( $file_info->getPathname(), $dest ) ) {
				$errors[] = \sprintf(
					/* translators: %s: destination path */
					__( 'Failed to copy OKF concept file: %s', 'nvoos-content-graph-ai' ),
					$dest
				);
			}
		}

		return $errors;
	}

	/**
	 * Write the bundle root index.md listing every skill concept.
	 *
	 * @param string $bundle_path Absolute path to the bundle directory.
	 * @return string[] Error messages; empty when the index was written.
	 */
	private static function write_root_index( $bundle_path ) {
		$errors = array();
		$lines  = array( '# Skill Knowledge', '' );

		$parser = new OkfParser();

		$skill_dirs = \glob( \trailingslashit( $bundle_path ) . '*', GLOB_ONLYDIR );
		if ( \is_array( $skill_dirs ) ) {
			\sort( $skill_dirs );
			foreach ( $skill_dirs as $skill_dir ) {
				$skill_file = $skill_dir . '/SKILL.md';
				if ( ! \file_exists( $skill_file ) ) {
					continue;
				}

				$skill_name = \basename( $skill_dir );
				$title      = $skill_name;
				$desc       = '';

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read of a plugin-authored file.
				$content = \file_get_contents( $skill_file );
				if ( false !== $content ) {
					$parsed = $parser->parse( $content );
					if ( \is_array( $parsed ) ) {
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

		$content = \implode( "\n", $lines ) . "\n";
		// Bundle-root indexes may carry an okf_version frontmatter block
		// (OKF v0.2 §12 — the only index frontmatter the spec permits).
		$content = "---\nokf_version: \"" . OkfWriter::OKF_VERSION . "\"\n---\n\n" . $content;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local filesystem write of a plugin-generated index. OKF bundles are local filesystem only.
		$written = \file_put_contents( $index_path, $content, LOCK_EX );
		if ( false === $written ) {
			$errors[] = \sprintf(
				/* translators: %s: file path */
				__( 'Failed to write OKF bundle index: %s', 'nvoos-content-graph-ai' ),
				$index_path
			);
		}

		return $errors;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function remove_directory( $dir ) {
		if ( ! \is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( \UnexpectedValueException $e ) {
			return;
		}

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Rebuilding a plugin-generated bundle in the uploads directory.
				@\rmdir( $file_info->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Rebuilding a plugin-generated bundle in the uploads directory.
				@\unlink( $file_info->getPathname() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Rebuilding a plugin-generated bundle in the uploads directory.
		@\rmdir( $dir );
	}
}
