<?php
/**
 * Custom Tool Loader for WP MCP AI
 *
 * Safely loads and manages custom tools from the custom-tools directory.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Custom_Tool_Loader' ) ) {
	/**
	 * Manages loading of custom tools from a safe directory.
	 */
	class WP_MCP_AI_Custom_Tool_Loader {
		/**
		 * Custom tools directory path.
		 *
		 * @var string
		 */
		private $custom_tools_dir;

		/**
		 * Loaded custom tools.
		 *
		 * @var array
		 */
		private $loaded_tools = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Use wp-content/uploads for custom tools (writable and safe).
			$upload_dir             = wp_upload_dir();
			$this->custom_tools_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-custom-tools';

			// Ensure the directory exists.
			$this->ensure_custom_tools_directory();
		}

		/**
		 * Ensure the custom tools directory exists with proper security.
		 */
		private function ensure_custom_tools_directory() {
			if ( ! file_exists( $this->custom_tools_dir ) ) {
				wp_mkdir_p( $this->custom_tools_dir );

				// Add index.php to prevent directory listing.
				$index_file = $this->custom_tools_dir . '/index.php';
				if ( ! file_exists( $index_file ) ) {
					file_put_contents( $index_file, '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}

				// Add .htaccess to prevent direct access.
				$htaccess_file = $this->custom_tools_dir . '/.htaccess';
				if ( ! file_exists( $htaccess_file ) ) {
					$htaccess_content  = "# Deny direct access to custom tools\n";
					$htaccess_content .= "Order deny,allow\n";
					$htaccess_content .= "Deny from all\n";
					file_put_contents( $htaccess_file, $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}

				// Create a README file.
				$readme_file = $this->custom_tools_dir . '/README.txt';
				if ( ! file_exists( $readme_file ) ) {
					$readme_content  = "Custom Tools Directory for WP MCP AI\n";
					$readme_content .= "====================================\n\n";
					$readme_content .= "This directory contains custom tools developed via the GitHub integration.\n";
					$readme_content .= "Only tools in this directory can be modified via the plugin interface.\n";
					$readme_content .= "Core plugin tools in the includes/tools/ directory are protected.\n\n";
					$readme_content .= "Tool Naming Convention:\n";
					$readme_content .= "- Files must start with: class-wp-mcp-ai-tool-custom-\n";
					$readme_content .= "- Example: class-wp-mcp-ai-tool-custom-my-tool.php\n\n";
					$readme_content .= "All tools must implement WP_MCP_AI_Tool_Interface.\n";
					file_put_contents( $readme_file, $readme_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
			}
		}

		/**
		 * Get the custom tools directory path.
		 *
		 * @return string
		 */
		public function get_custom_tools_directory() {
			return $this->custom_tools_dir;
		}

		/**
		 * Load all custom tools from the custom tools directory.
		 *
		 * @return array Array of loaded tool instances.
		 */
		public function load_custom_tools() {
			if ( ! empty( $this->loaded_tools ) ) {
				return $this->loaded_tools;
			}

			$tool_files = $this->get_custom_tool_files();

			foreach ( $tool_files as $file ) {
				$tool = $this->load_custom_tool( $file );
				if ( $tool && ! is_wp_error( $tool ) ) {
					$this->loaded_tools[] = $tool;
				}
			}

			return $this->loaded_tools;
		}

		/**
		 * Get list of custom tool files.
		 *
		 * @return array
		 */
		private function get_custom_tool_files() {
			$files = array();

			if ( ! is_dir( $this->custom_tools_dir ) ) {
				return $files;
			}

			$iterator = new DirectoryIterator( $this->custom_tools_dir );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getExtension() === 'php' ) {
					$filename = $file->getFilename();

					// Only load files that follow the naming convention.
					if ( 0 === strpos( $filename, 'class-wp-mcp-ai-tool-custom-' ) ) {
						$files[] = $file->getPathname();
					}
				}
			}

			return $files;
		}

		/**
		 * Safely load a custom tool file.
		 *
		 * @param string $file_path Path to the tool file.
		 * @return object|WP_Error Tool instance or WP_Error on failure.
		 */
		private function load_custom_tool( $file_path ) {
			// Validate the file path.
			if ( ! $this->is_safe_tool_file( $file_path ) ) {
				WP_MCP_AI_Admin_Settings::log(
					'Rejected unsafe custom tool file.',
					array( 'file' => $file_path )
				);
				return new WP_Error( 'wp_mcp_ai_unsafe_tool', __( 'Tool file failed safety validation.', 'wp-mcp-ai' ) );
			}

			// Include the tool file.
			require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

			// Use output buffering to catch any errors during include.
			ob_start();
			$include_result = include_once $file_path;
			$output         = ob_get_clean();

			if ( false === $include_result ) {
				WP_MCP_AI_Admin_Settings::log(
					'Failed to include custom tool file.',
					array(
						'file'   => $file_path,
						'output' => $output,
					)
				);
				return new WP_Error( 'wp_mcp_ai_tool_include_failed', __( 'Failed to include tool file.', 'wp-mcp-ai' ) );
			}

			// Extract the class name from the filename.
			$class_name = $this->get_class_name_from_file( $file_path );

			if ( ! $class_name || ! class_exists( $class_name ) ) {
				WP_MCP_AI_Admin_Settings::log(
					'Custom tool class not found.',
					array(
						'file'       => $file_path,
						'class_name' => $class_name,
					)
				);
				return new WP_Error( 'wp_mcp_ai_tool_class_not_found', __( 'Tool class not found in file.', 'wp-mcp-ai' ) );
			}

			// Instantiate the tool.
			try {
				$tool = new $class_name();

				// Verify it implements the required interface.
				if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
					WP_MCP_AI_Admin_Settings::log(
						'Custom tool does not implement required interface.',
						array(
							'file'  => $file_path,
							'class' => $class_name,
						)
					);
					return new WP_Error( 'wp_mcp_ai_invalid_tool', __( 'Tool does not implement WP_MCP_AI_Tool_Interface.', 'wp-mcp-ai' ) );
				}

				WP_MCP_AI_Admin_Settings::log(
					'Custom tool loaded successfully.',
					array(
						'file' => $file_path,
						'slug' => $tool->get_slug(),
					)
				);

				return $tool;
			} catch ( Exception $e ) {
				WP_MCP_AI_Admin_Settings::log(
					'Failed to instantiate custom tool.',
					array(
						'file'  => $file_path,
						'error' => $e->getMessage(),
					)
				);
				return new WP_Error( 'wp_mcp_ai_tool_instantiation_failed', $e->getMessage() );
			}
		}

		/**
		 * Validate that a tool file is safe to load.
		 *
		 * @param string $file_path Path to the tool file.
		 * @return bool
		 */
		private function is_safe_tool_file( $file_path ) {
			// Must be in the custom tools directory.
			if ( 0 !== strpos( $file_path, $this->custom_tools_dir ) ) {
				return false;
			}

			// Must be a PHP file.
			if ( '.php' !== substr( $file_path, -4 ) ) {
				return false;
			}

			// Must follow naming convention.
			$filename = basename( $file_path );
			if ( 0 !== strpos( $filename, 'class-wp-mcp-ai-tool-custom-' ) ) {
				return false;
			}

			// File must be readable.
			if ( ! is_readable( $file_path ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Extract the class name from a tool file path.
		 *
		 * @param string $file_path Path to the tool file.
		 * @return string|null
		 */
		private function get_class_name_from_file( $file_path ) {
			$filename = basename( $file_path, '.php' );

			// Convert filename to class name (e.g., class-wp-mcp-ai-tool-custom-example -> WP_MCP_AI_Tool_Custom_Example).
			$filename = str_replace( 'class-', '', $filename );
			$parts    = explode( '-', $filename );
			$parts    = array_map( 'ucfirst', $parts );

			return implode( '_', $parts );
		}

		/**
		 * Create a template tool file to help users get started.
		 *
		 * @param string $tool_name Tool name (e.g., 'my_example_tool').
		 * @return string|WP_Error Path to created file or WP_Error on failure.
		 */
		public function create_tool_template( $tool_name ) {
			// Sanitize the tool name.
			$tool_name = sanitize_key( $tool_name );

			if ( empty( $tool_name ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_tool_name', __( 'Invalid tool name.', 'wp-mcp-ai' ) );
			}

			// Generate filename.
			$filename = 'class-wp-mcp-ai-tool-custom-' . $tool_name . '.php';
			$filepath = $this->custom_tools_dir . '/' . $filename;

			if ( file_exists( $filepath ) ) {
				return new WP_Error( 'wp_mcp_ai_tool_exists', __( 'A tool with this name already exists.', 'wp-mcp-ai' ) );
			}

			// Generate class name.
			$parts      = explode( '_', $tool_name );
			$parts      = array_map( 'ucfirst', $parts );
			$class_name = 'WP_MCP_AI_Tool_Custom_' . implode( '_', $parts );

			// Create template content.
			$template = $this->get_tool_template_content( $class_name, $tool_name );

			// Write the file.
			$result = file_put_contents( $filepath, $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $result ) {
				return new WP_Error( 'wp_mcp_ai_file_write_failed', __( 'Failed to create tool file.', 'wp-mcp-ai' ) );
			}

			return $filepath;
		}

		/**
		 * Get the tool template content.
		 *
		 * @param string $class_name Class name for the tool.
		 * @param string $tool_slug  Tool slug.
		 * @return string
		 */
		private function get_tool_template_content( $class_name, $tool_slug ) {
			$template = <<<PHP
<?php
/**
 * Custom Tool: {$class_name}
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Custom tool implementation.
 */
class {$class_name} implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return '{$tool_slug}';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Custom Tool: {$tool_slug}', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Description of your custom tool.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'example_param' => array(
					'type'        => 'string',
					'description' => __( 'Example parameter description.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Implement your tool logic here.
		return array(
			'success' => true,
			'message' => __( 'Tool executed successfully.', 'wp-mcp-ai' ),
		);
	}
}

PHP;

			return $template;
		}

		/**
		 * Delete a custom tool file.
		 *
		 * @param string $tool_slug Tool slug.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function delete_custom_tool( $tool_slug ) {
			$tool_slug = sanitize_key( $tool_slug );
			$filename  = 'class-wp-mcp-ai-tool-custom-' . $tool_slug . '.php';
			$filepath  = $this->custom_tools_dir . '/' . $filename;

			if ( ! file_exists( $filepath ) ) {
				return new WP_Error( 'wp_mcp_ai_tool_not_found', __( 'Tool file not found.', 'wp-mcp-ai' ) );
			}

			if ( ! $this->is_safe_tool_file( $filepath ) ) {
				return new WP_Error( 'wp_mcp_ai_unsafe_delete', __( 'Cannot delete this file for safety reasons.', 'wp-mcp-ai' ) );
			}

			$result = unlink( $filepath );

			if ( ! $result ) {
				return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete tool file.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * List all custom tools.
		 *
		 * @return array
		 */
		public function list_custom_tools() {
			$tool_files = $this->get_custom_tool_files();
			$tools      = array();

			foreach ( $tool_files as $file ) {
				$filename = basename( $file );
				$slug     = str_replace( array( 'class-wp-mcp-ai-tool-custom-', '.php' ), '', $filename );

				$tools[] = array(
					'slug'     => $slug,
					'filename' => $filename,
					'filepath' => $file,
					'size'     => filesize( $file ),
					'modified' => filemtime( $file ),
				);
			}

			return $tools;
		}
	}
}
