<?php
/**
 * Anthropic Agent Skills registry.
 *
 * Manages discovery, registration, and retrieval of Agent Skills
 * stored as SKILL.md files in the WordPress uploads directory.
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 * @see     https://agentskills.io/specification
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry for managing Agent Skills.
 *
 * Skills are stored in wp-content/uploads/mcp-ai-skills/{skill-name}/SKILL.md.
 *
 * @since 1.7.0
 */
class SkillRegistry {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Subdirectory within wp-content/uploads for skill storage.
	 *
	 * @var string
	 */
	const UPLOAD_DIR = 'mcp-ai-skills';

	/**
	 * Option key for cached skill index.
	 *
	 * @var string
	 */
	const OPTION_SKILL_INDEX = 'wp_mcp_ai_skill_index';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * In-memory cache of loaded skills keyed by slug.
	 *
	 * @var array
	 */
	private $skills = array();

	/**
	 * Whether the skills have been loaded from disk for this request.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.7.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 1.7.0
	 */
	private function __construct() {}

	/**
	 * Get the base directory path for skill storage.
	 *
	 * @since 1.7.0
	 * @return string Absolute path to the skills directory.
	 */
	public function get_skills_dir() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_DIR;
	}

	/**
	 * File extensions that are allowed for extra skill files (e.g. examples, resources).
	 *
	 * PHP-executable extensions (php, phtml, phar, etc.) are intentionally absent so
	 * that a malicious ZIP cannot introduce a server-side script into the uploads dir.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTRA_EXTENSIONS = array( 'md', 'txt', 'json', 'yaml', 'yml', 'png', 'jpg', 'jpeg', 'gif', 'webp' );

	/**
	 * Maximum total decompressed size (in bytes) allowed from a ZIP archive (16 MB).
	 *
	 * Prevents zip-bomb (decompression bomb) attacks where a small compressed file
	 * expands into an arbitrarily large payload that exhausts server memory or disk.
	 *
	 * @var int
	 */
	const MAX_ZIP_DECOMPRESSED_BYTES = 16 * 1024 * 1024; // 16 MB.

	/**
	 * Ensure the skills directory exists with proper protections.
	 *
	 * @since 1.7.0
	 * @return bool True if the directory exists or was created successfully.
	 */
	public function ensure_skills_dir() {
		$dir = $this->get_skills_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Add an index.php to prevent directory listing.
		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		// Add an .htaccess that blocks PHP execution inside the skills directory so that
		// even if a malicious file were written it could not be executed via HTTP.
		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
			file_put_contents(
				$htaccess_file,
				"# Block direct PHP execution in the skills directory.\n" .
				"<FilesMatch \"\\.ph(p[2-9]?|tml|ar)$\">\n" .
				"  Require all denied\n" .
				"</FilesMatch>\n" .
				"# Apache 2.2 compat\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"  <FilesMatch \"\\.ph(p[2-9]?|tml|ar)$\">\n" .
				"    deny from all\n" .
				"  </FilesMatch>\n" .
				"</IfModule>\n"
			);
		}

		return is_dir( $dir ) && is_writable( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
	}

	/**
	 * Scan the skills directory and load all valid skills.
	 *
	 * @since 1.7.0
	 * @param bool $force Force re-scan even if already loaded.
	 * @return array Array of parsed skill data keyed by skill name.
	 */
	public function load_skills( $force = false ) {
		if ( $this->loaded && ! $force ) {
			return $this->skills;
		}

		$this->skills = array();
		$skills_dir   = $this->get_skills_dir();

		if ( ! is_dir( $skills_dir ) ) {
			$this->loaded = true;
			return $this->skills;
		}

		$parser = new SkillParser();
		$dirs   = glob( $skills_dir . '/*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			$this->loaded = true;
			return $this->skills;
		}

		foreach ( $dirs as $dir ) {
			$skill_file = $dir . '/SKILL.md';
			if ( ! file_exists( $skill_file ) ) {
				continue;
			}

			$parsed = $parser->parse_file( $skill_file );
			if ( is_wp_error( $parsed ) ) {
				continue;
			}

			// Verify folder name matches the skill name from frontmatter.
			$folder_name = basename( $dir );
			if ( $folder_name !== $parsed['name'] ) {
				continue;
			}

			$this->skills[ $parsed['name'] ] = $parsed;
		}

		/**
		 * Filters whether agent-evolved skills (produced by the Continual
		 * Harness evolver) are merged into the registry index.
		 *
		 * Default false — evolved skills are never exposed automatically.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $include_evolved Whether to include evolved skills. Default false.
		 */
		if ( apply_filters( 'wp_mcp_ai_skill_registry_include_evolved', false )
			// Gate behind the boot discriminator: the base evolver's files
			// reference WP_MCP_AI_PATH and the monorepo root autoloader can
			// classmap them even when the base plugin is inactive. The ported
			// Harness evolver is probed when the Harness subsystem is
			// extracted (Wave C).
			&& defined( 'WP_MCP_AI_PATH' )
			&& class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' )
			&& is_callable( array( 'WP_MCP_AI_Agent_Harness_Evolver', 'get_evolved_skills' ) ) ) {
			$this->skills = array_merge( $this->skills, WP_MCP_AI_Agent_Harness_Evolver::get_evolved_skills() );
		}

		$this->loaded = true;

		// Update the cached index for quick lookups.
		$this->update_skill_index();

		return $this->skills;
	}

	/**
	 * Get a single skill by name.
	 *
	 * @since 1.7.0
	 * @param string $name Skill name (slug).
	 * @return array|null Skill data or null if not found.
	 */
	public function get_skill( $name ) {
		$this->load_skills();

		return isset( $this->skills[ $name ] ) ? $this->skills[ $name ] : null;
	}

	/**
	 * Get all registered skills.
	 *
	 * @since 1.7.0
	 * @return array Array of all skill data keyed by name.
	 */
	public function get_all_skills() {
		return $this->load_skills();
	}

	/**
	 * Get a lightweight index of all skills (name and description only).
	 *
	 * @since 1.7.0
	 * @return array Array of arrays with 'name' and 'description' keys.
	 */
	public function get_skill_index() {
		$skills = $this->load_skills();
		$index  = array();

		foreach ( $skills as $name => $skill ) {
			$index[] = array(
				'name'        => $skill['name'],
				'description' => $skill['description'],
			);
		}

		return $index;
	}

	/**
	 * Install a skill from raw SKILL.md content.
	 *
	 * @since 1.7.0
	 * @param string $content  Raw SKILL.md content.
	 * @param array  $extra_files Optional associative array of additional files
	 *                            (relative path => content) to store alongside SKILL.md.
	 * @return array|WP_Error Parsed skill data on success, WP_Error on failure.
	 */
	public function install_skill( $content, $extra_files = array() ) {
		$parser = new SkillParser();
		$parsed = $parser->parse( $content );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! $this->ensure_skills_dir() ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_dir_not_writable',
				__( 'The skills directory is not writable.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		$skill_dir = trailingslashit( $this->get_skills_dir() ) . $parsed['name'];

		if ( ! file_exists( $skill_dir ) ) {
			wp_mkdir_p( $skill_dir );
		}

		$skill_file = $skill_dir . '/SKILL.md';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		$written = file_put_contents( $skill_file, $content );

		if ( false === $written ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_write_error',
				__( 'Failed to write the skill file.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		// Write any extra files (e.g., examples, resources).
		$decompressed_bytes = strlen( $content ); // Count SKILL.md itself.
		foreach ( $extra_files as $relative_path => $file_content ) {
			// Prevent directory traversal.
			$safe_path = ltrim( $relative_path, '/' );
			if ( false !== strpos( $safe_path, '..' ) ) {
				continue;
			}

			// Only allow safe, non-executable extensions to prevent PHP RCE via
			// a crafted ZIP that embeds a .php file alongside SKILL.md.
			$ext = strtolower( pathinfo( $safe_path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTRA_EXTENSIONS, true ) ) {
				continue;
			}

			// Guard against decompression bombs: reject the whole archive if the
			// cumulative decompressed size exceeds the configured limit.
			$decompressed_bytes += strlen( $file_content );
			if ( $decompressed_bytes > self::MAX_ZIP_DECOMPRESSED_BYTES ) {
				return new \WP_Error(
					'wp_mcp_ai_skill_zip_too_large',
					__( 'The ZIP archive decompresses to more than the allowed 16 MB. Please reduce the archive size.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 400 )
				);
			}

			$full_path   = $skill_dir . '/' . $safe_path;
			$file_parent = dirname( $full_path );

			if ( ! file_exists( $file_parent ) ) {
				wp_mkdir_p( $file_parent );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
			file_put_contents( $full_path, $file_content );
		}

		// Force reload on next access.
		$this->loaded = false;
		$this->load_skills( true );

		return $parsed;
	}

	/**
	 * Uninstall a skill by removing its directory.
	 *
	 * @since 1.7.0
	 * @param string $name Skill name (slug).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function uninstall_skill( $name ) {
		$name = sanitize_file_name( $name );

		// Prevent directory traversal.
		if ( empty( $name ) || false !== strpos( $name, '..' ) || false !== strpos( $name, '/' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_invalid_name',
				__( 'Invalid skill name.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$skill_dir = trailingslashit( $this->get_skills_dir() ) . $name;

		if ( ! is_dir( $skill_dir ) ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_not_found',
				__( 'Skill not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		// Recursively remove the skill directory.
		$this->recursive_rmdir( $skill_dir );

		// Force reload.
		$this->loaded = false;
		unset( $this->skills[ $name ] );
		$this->update_skill_index();

		return true;
	}

	/**
	 * Build a lightweight skills *index* for system-prompt injection.
	 *
	 * Unlike `build_skills_prompt()`, this method does NOT include the full
	 * `SKILL.md` instructions — only the skill name and description. The model
	 * is told to call the `load_skill` tool to fetch the full instructions on
	 * demand. This is the "progressive disclosure" pattern described at
	 * https://agentskills.io/specification: it keeps the per-turn context
	 * window small even when many skills are assigned.
	 *
	 * @since 1.11.0
	 * @param array $skill_names Array of skill name strings to include in the index.
	 * @return string Compact skill catalogue, or '' when there are no usable skills.
	 */
	public function build_skills_index_prompt( $skill_names ) {
		if ( ! is_array( $skill_names ) || empty( $skill_names ) ) {
			return '';
		}

		$this->load_skills();

		$entries = array();
		foreach ( $skill_names as $name ) {
			$skill = $this->get_skill( $name );
			if ( ! $skill || empty( $skill['name'] ) ) {
				continue;
			}

			// When disable-model-invocation is set, the skill is slash-command
			// only and should not appear in the autonomous catalog.
			if ( ! empty( $skill['disable_model_invocation'] ) ) {
				continue;
			}

			$desc = isset( $skill['description'] ) ? trim( (string) $skill['description'] ) : '';
			if ( '' === $desc ) {
				$desc = __( '(no description)', 'nvoos-content-graph-ai-platform' );
			}
			$entries[] = sprintf( '- **%s** — %s', $skill['name'], $desc );
		}

		if ( empty( $entries ) ) {
			return '';
		}

		$prompt  = "# Available Skills\n\n";
		$prompt .= "You have access to the following specialised skills. Each entry below is only a short summary — the full step-by-step instructions are NOT loaded yet.\n\n";
		$prompt .= "When a user request matches one of these skills, call the `load_skill` tool with the skill's name to retrieve the full instructions before proceeding. Do not invent skill behaviour from the summary alone.\n\n";
		$prompt .= implode( "\n", $entries );

		return $prompt;
	}

	/**
	 * Build system prompt text from selected skill names.
	 *
	 * @since 1.7.0
	 * @param array $skill_names Array of skill name strings to include.
	 * @return string Combined skill instructions for system prompt injection.
	 */
	public function build_skills_prompt( $skill_names ) {
		if ( ! is_array( $skill_names ) || empty( $skill_names ) ) {
			return '';
		}

		$this->load_skills();

		$prompt_parts = array();

		foreach ( $skill_names as $name ) {
			$skill = $this->get_skill( $name );
			if ( ! $skill || empty( $skill['instructions'] ) ) {
				continue;
			}

			$prompt_parts[] = sprintf(
				"## Skill: %s\n\n**Description:** %s\n\n%s",
				$skill['name'],
				$skill['description'],
				$skill['instructions']
			);
		}

		if ( empty( $prompt_parts ) ) {
			return '';
		}

		$prompt  = "# Active Skills\n\n";
		$prompt .= "You have the following specialized skills loaded. Use them when relevant to the user's request:\n\n";
		$prompt .= implode( "\n\n---\n\n", $prompt_parts );

		return $prompt;
	}

	/**
	 * Update the lightweight skill index in the database.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function update_skill_index() {
		$index = array();

		foreach ( $this->skills as $name => $skill ) {
			$index[ $name ] = array(
				'name'        => $skill['name'],
				'description' => $skill['description'],
			);
		}

		update_option( self::OPTION_SKILL_INDEX, $index, false );
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @since 1.7.0
	 * @param string $dir Directory path to remove.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing uploaded skill file.
				unlink( $path );
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
	}

	/**
	 * Install bundled skills that ship with the plugin.
	 *
	 * Copies SKILL.md files from the plugin's bundled-skills directory
	 * to the uploads skill storage. Skips skills that are already installed.
	 *
	 * @since 1.7.1
	 * @return array Array with 'installed' and 'skipped' counts plus any 'errors'.
	 */
	public function install_bundled_skills() {
		$bundled_dir = defined( 'WP_MCP_AI_PATH' )
			? trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills'
			: trailingslashit( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH ) . 'data/bundled-skills';

		if ( empty( $bundled_dir ) || ! is_dir( $bundled_dir ) ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Bundled skills directory not found.', 'nvoos-content-graph-ai-platform' ) ),
			);
		}

		return $this->install_bundled_skills_from_dir( $bundled_dir );
	}

	/**
	 * Install bundled skills from a specific directory.
	 *
	 * Copies SKILL.md files from the given directory to the uploads skill
	 * storage. Skips skills that are already installed. This method allows
	 * add-ons (e.g. the Pro addon) to bundle their own skills independently.
	 *
	 * @since 1.7.2
	 * @param string $bundled_dir Absolute path to a directory containing skill subdirectories.
	 * @return array Array with 'installed' and 'skipped' counts plus any 'errors'.
	 */
	public function install_bundled_skills_from_dir( $bundled_dir ) {
		$bundled_dir = (string) $bundled_dir;

		if ( empty( $bundled_dir ) || ! is_dir( $bundled_dir ) ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Bundled skills directory not found.', 'nvoos-content-graph-ai-platform' ) ),
			);
		}

		$dirs = glob( $bundled_dir . '/*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) || empty( $dirs ) ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array(),
			);
		}

		$installed = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $dirs as $dir ) {
			$skill_file = $dir . '/SKILL.md';
			if ( ! file_exists( $skill_file ) ) {
				continue;
			}

			$skill_name = basename( $dir );

			// Skip if already installed in uploads.
			$target_dir = trailingslashit( $this->get_skills_dir() ) . $skill_name;
			if ( is_dir( $target_dir ) && file_exists( $target_dir . '/SKILL.md' ) ) {
				++$skipped;
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
			$content = file_get_contents( $skill_file );
			if ( false === $content ) {
				$errors[] = sprintf(
					/* translators: %s: skill name */
					__( 'Failed to read bundled skill: %s', 'nvoos-content-graph-ai-platform' ),
					$skill_name
				);
				continue;
			}

			// Collect any companion files (reference.md, examples, JSON, images, etc.)
			// shipped alongside SKILL.md in the bundled directory, so that skill bodies
			// referencing `reference.md` or other resources resolve once the skill is
			// installed in uploads. Filtered by ALLOWED_EXTRA_EXTENSIONS in install_skill().
			$extra_files = $this->collect_companion_files( $dir );

			$result = $this->install_skill( $content, $extra_files );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: skill name, 2: error message */
					__( 'Failed to install %1$s: %2$s', 'nvoos-content-graph-ai-platform' ),
					$skill_name,
					$result->get_error_message()
				);
			} else {
				++$installed;
			}
		}

		return array(
			'installed' => $installed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Collect companion files shipped alongside a bundled SKILL.md.
	 *
	 * Walks the skill folder recursively and returns an associative array of
	 * { relative_path => contents } suitable for passing to `install_skill()`.
	 * The SKILL.md itself is excluded because it is written separately.
	 * `install_skill()` enforces the extension allowlist and decompression-size
	 * cap, so this method does not need to filter or limit what it returns.
	 *
	 * @since 1.7.3
	 * @param string $dir Absolute path to a skill folder.
	 * @return array Associative array of relative paths to file contents.
	 */
	private function collect_companion_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$dir      = rtrim( $dir, '/\\' );
		$base_len = strlen( $dir ) + 1;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $e ) {
			return $files;
		}

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}
			$abs = $file_info->getPathname();
			$rel = str_replace( '\\', '/', substr( $abs, $base_len ) );
			if ( '' === $rel || 'SKILL.md' === $rel ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
			$contents = file_get_contents( $abs );
			if ( false === $contents ) {
				continue;
			}
			$files[ $rel ] = $contents;
		}

		return $files;
	}

	/**
	 * Get the path to the bundled skills directory.
	 *
	 * @since 1.7.1
	 * @return string Absolute path to the bundled skills directory.
	 */
	public function get_bundled_skills_dir() {
		return defined( 'WP_MCP_AI_PATH' )
			? trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills'
			: trailingslashit( NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH ) . 'data/bundled-skills';
	}

	/**
	 * Install a single bundled skill (with companion files) by name.
	 *
	 * Searches the supplied source directories — falling back to the base
	 * plugin's bundled-skills directory and the Pro add-on's, when present —
	 * for a `{$skill_name}/SKILL.md`, then installs that skill via
	 * `install_skill()`. Used by the skill-pack registry so packs can
	 * install only their members without copying every bundled skill.
	 *
	 * @since 1.11.0
	 * @param string     $skill_name  Skill folder name (e.g. `wp-rest-api`).
	 * @param array|null $source_dirs Optional list of bundled-skill root directories to search.
	 *                                When null/empty the registry's own roots (base + Pro when defined) are used.
	 * @return true|WP_Error True on success, WP_Error on failure (including not found).
	 */
	public function install_bundled_skill_by_name( $skill_name, $source_dirs = null ) {
		$skill_name = sanitize_key( (string) $skill_name );
		if ( '' === $skill_name ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_invalid_name',
				__( 'Invalid skill name.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! is_array( $source_dirs ) || empty( $source_dirs ) ) {
			$source_dirs = array();
			$base_dir    = $this->get_bundled_skills_dir();
			if ( is_string( $base_dir ) && is_dir( $base_dir ) ) {
				$source_dirs[] = $base_dir;
			}
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_dir = trailingslashit( WP_MCP_AI_PRO_PATH ) . 'includes/bundled-skills';
				if ( is_dir( $pro_dir ) ) {
					$source_dirs[] = $pro_dir;
				}
			}
		}

		$skill_dir = '';
		foreach ( $source_dirs as $dir ) {
			if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
				continue;
			}
			$candidate = trailingslashit( $dir ) . $skill_name;
			if ( is_dir( $candidate ) && file_exists( $candidate . '/SKILL.md' ) ) {
				$skill_dir = $candidate;
				break;
			}
		}

		if ( '' === $skill_dir ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_not_bundled',
				/* translators: %s: skill slug */
				sprintf( __( 'Bundled skill not found: %s', 'nvoos-content-graph-ai-platform' ), $skill_name )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
		$content = file_get_contents( $skill_dir . '/SKILL.md' );
		if ( false === $content ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_read_failed',
				/* translators: %s: skill slug */
				sprintf( __( 'Failed to read bundled skill: %s', 'nvoos-content-graph-ai-platform' ), $skill_name )
			);
		}

		$extra_files = $this->collect_companion_files( $skill_dir );
		$result      = $this->install_skill( $content, $extra_files );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Reset the singleton instance (for testing purposes).
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function reset() {
		if ( null !== self::$instance ) {
			self::$instance->skills = array();
			self::$instance->loaded = false;
		}
		self::$instance = null;
	}
}
