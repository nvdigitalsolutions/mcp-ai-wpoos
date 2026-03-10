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
 */

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
class WP_MCP_AI_Skill_Registry {

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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to uploads dir.
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		// Add an .htaccess that blocks PHP execution inside the skills directory so that
		// even if a malicious file were written it could not be executed via HTTP.
		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to uploads dir.
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

		$parser = new WP_MCP_AI_Skill_Parser();
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
		$parser = new WP_MCP_AI_Skill_Parser();
		$parsed = $parser->parse( $content );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! $this->ensure_skills_dir() ) {
			return new WP_Error(
				'wp_mcp_ai_skill_dir_not_writable',
				__( 'The skills directory is not writable.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$skill_dir = trailingslashit( $this->get_skills_dir() ) . $parsed['name'];

		if ( ! file_exists( $skill_dir ) ) {
			wp_mkdir_p( $skill_dir );
		}

		$skill_file = $skill_dir . '/SKILL.md';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to uploads dir.
		$written = file_put_contents( $skill_file, $content );

		if ( false === $written ) {
			return new WP_Error(
				'wp_mcp_ai_skill_write_error',
				__( 'Failed to write the skill file.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Write any extra files (e.g., examples, resources).
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

			$full_path   = $skill_dir . '/' . $safe_path;
			$file_parent = dirname( $full_path );

			if ( ! file_exists( $file_parent ) ) {
				wp_mkdir_p( $file_parent );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to uploads dir.
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
			return new WP_Error(
				'wp_mcp_ai_skill_invalid_name',
				__( 'Invalid skill name.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$skill_dir = trailingslashit( $this->get_skills_dir() ) . $name;

		if ( ! is_dir( $skill_dir ) ) {
			return new WP_Error(
				'wp_mcp_ai_skill_not_found',
				__( 'Skill not found.', 'mcp-ai-wpoos' ),
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
			: '';

		if ( empty( $bundled_dir ) || ! is_dir( $bundled_dir ) ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Bundled skills directory not found.', 'mcp-ai-wpoos' ) ),
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
					__( 'Failed to read bundled skill: %s', 'mcp-ai-wpoos' ),
					$skill_name
				);
				continue;
			}

			$result = $this->install_skill( $content );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: skill name, 2: error message */
					__( 'Failed to install %1$s: %2$s', 'mcp-ai-wpoos' ),
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
	 * Get the path to the bundled skills directory.
	 *
	 * @since 1.7.1
	 * @return string Absolute path to the bundled skills directory.
	 */
	public function get_bundled_skills_dir() {
		return defined( 'WP_MCP_AI_PATH' )
			? trailingslashit( WP_MCP_AI_PATH ) . 'includes/bundled-skills'
			: '';
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
