<?php
/**
 * Tool for importing Elementor template kits.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports Elementor template kits from ZIP files in the Media Library.
 */
class WP_MCP_AI_Tool_Import_Elementor_Template_Kit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Determine whether Elementor is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$has_elementor = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false );

		return $has_elementor;
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Import Elementor Template Kit tool is disabled because Elementor is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_elementor_template_kit';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Elementor Template Kit', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports an Elementor template kit ZIP file from the Media Library and creates pages. Requires Elementor to be active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Media Library attachment ID of the template kit ZIP file.', 'wp-mcp-ai' ),
				),
				'max_pages'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of pages to create (1-5).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 5,
					'default'     => 5,
				),
				'page_status'        => array(
					'type'        => 'string',
					'description' => __( 'Status for created pages (draft or publish).', 'wp-mcp-ai' ),
					'enum'        => array( 'draft', 'publish' ),
					'default'     => 'draft',
				),
				'set_front_page'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to set the Home page as the static front page.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'overwrite_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to overwrite existing pages with the same title.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'dry_run'            => array(
					'type'        => 'boolean',
					'description' => __( 'If true, simulates the import without creating pages.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'attachment_id' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_elementor_missing',
				__( 'Elementor is not active on this site.', 'wp-mcp-ai' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to import Elementor template kits.', 'wp-mcp-ai' )
			);
		}

		// Check site creator settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The site_creator tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai' )
			);
		}

		// Validate attachment_id.
		if ( empty( $arguments['attachment_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_attachment',
				__( 'attachment_id is required.', 'wp-mcp-ai' )
			);
		}

		$attachment_id = absint( $arguments['attachment_id'] );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_attachment',
				__( 'Invalid attachment ID provided.', 'wp-mcp-ai' )
			);
		}

		// Validate file type.
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Attachment file not found.', 'wp-mcp-ai' )
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( 'application/zip' !== $mime_type && 'application/x-zip-compressed' !== $mime_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				__( 'The attachment must be a ZIP file.', 'wp-mcp-ai' )
			);
		}

		// Parse arguments with defaults.
		$max_pages          = isset( $arguments['max_pages'] ) ? min( 5, max( 1, absint( $arguments['max_pages'] ) ) ) : 5;
		$page_status        = isset( $arguments['page_status'] ) && in_array( $arguments['page_status'], array( 'draft', 'publish' ), true ) ? $arguments['page_status'] : 'draft';
		$set_front_page     = ! empty( $arguments['set_front_page'] );
		$overwrite_existing = ! empty( $arguments['overwrite_existing'] );
		$dry_run            = ! empty( $arguments['dry_run'] );

		// Extract and parse the template kit.
		$extract_result = $this->extract_template_kit( $file_path );
		if ( is_wp_error( $extract_result ) ) {
			return $extract_result;
		}

		$manifest      = $extract_result['manifest'];
		$templates_dir = $extract_result['templates_dir'];
		$temp_dir      = $extract_result['temp_dir'];

		// Process templates and create pages.
		$result = $this->process_templates(
			$manifest,
			$templates_dir,
			array(
				'max_pages'          => $max_pages,
				'page_status'        => $page_status,
				'set_front_page'     => $set_front_page,
				'overwrite_existing' => $overwrite_existing,
				'dry_run'            => $dry_run,
				'user_id'            => $user_id,
			)
		);

		// Clean up temp directory.
		$this->cleanup_temp_dir( $temp_dir );

		return $result;
	}

	/**
	 * Extract the template kit ZIP file and parse manifest.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return array|WP_Error Extracted data or error.
	 */
	protected function extract_template_kit( $file_path ) {
		// Require the filesystem API.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'wp_mcp_ai_filesystem_error',
				__( 'Unable to initialize WordPress filesystem.', 'wp-mcp-ai' )
			);
		}

		// Create temp directory.
		$temp_dir = wp_tempnam( 'elementor_kit_' );
		if ( file_exists( $temp_dir ) ) {
			wp_delete_file( $temp_dir );
		}
		wp_mkdir_p( $temp_dir );

		// Extract ZIP.
		$unzip_result = unzip_file( $file_path, $temp_dir );
		if ( is_wp_error( $unzip_result ) ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'wp_mcp_ai_unzip_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to extract ZIP file: %s', 'wp-mcp-ai' ),
					$unzip_result->get_error_message()
				)
			);
		}

		// Find manifest.json.
		$manifest_path = $this->find_manifest( $temp_dir );
		if ( ! $manifest_path ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'wp_mcp_ai_manifest_not_found',
				__( 'manifest.json not found in the template kit.', 'wp-mcp-ai' )
			);
		}

		// Parse manifest.
		$manifest_content = $wp_filesystem->get_contents( $manifest_path );
		if ( ! $manifest_content ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'wp_mcp_ai_manifest_read_error',
				__( 'Unable to read manifest.json.', 'wp-mcp-ai' )
			);
		}

		$manifest = json_decode( $manifest_content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'wp_mcp_ai_manifest_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Invalid manifest.json: %s', 'wp-mcp-ai' ),
					json_last_error_msg()
				)
			);
		}

		$templates_dir = dirname( $manifest_path );

		return array(
			'manifest'      => $manifest,
			'templates_dir' => $templates_dir,
			'temp_dir'      => $temp_dir,
		);
	}

	/**
	 * Find manifest.json in extracted directory.
	 *
	 * @param string $dir Directory to search.
	 * @return string|false Path to manifest.json or false if not found.
	 */
	protected function find_manifest( $dir ) {
		// Check direct manifest.json.
		$manifest_path = trailingslashit( $dir ) . 'manifest.json';
		if ( file_exists( $manifest_path ) ) {
			return $manifest_path;
		}

		// Search in subdirectories (one level deep).
		$subdirs = glob( trailingslashit( $dir ) . '*', GLOB_ONLYDIR );
		if ( $subdirs ) {
			foreach ( $subdirs as $subdir ) {
				$manifest_path = trailingslashit( $subdir ) . 'manifest.json';
				if ( file_exists( $manifest_path ) ) {
					return $manifest_path;
				}
			}
		}

		return false;
	}

	/**
	 * Process templates and create pages.
	 *
	 * @param array $manifest      Parsed manifest data.
	 * @param string $templates_dir Directory containing templates.
	 * @param array $options       Processing options.
	 * @return array Results of the import.
	 */
	protected function process_templates( $manifest, $templates_dir, $options ) {
		$results = array(
			'success'       => true,
			'dry_run'       => $options['dry_run'],
			'pages_created' => array(),
			'pages_updated' => array(),
			'pages_skipped' => array(),
			'errors'        => array(),
			'front_page'    => null,
		);

		// Get templates from manifest.
		$templates = $this->get_prioritized_templates( $manifest );
		if ( empty( $templates ) ) {
			$results['errors'][] = __( 'No valid templates found in manifest.', 'wp-mcp-ai' );
			$results['success']  = false;
			return $results;
		}

		// Limit to max_pages.
		$templates = array_slice( $templates, 0, $options['max_pages'] );

		$home_page_id = null;

		foreach ( $templates as $template ) {
			$template_result = $this->process_single_template( $template, $templates_dir, $options );

			if ( is_wp_error( $template_result ) ) {
				$results['errors'][] = array(
					'template' => $template['title'],
					'message'  => $template_result->get_error_message(),
				);
				continue;
			}

			if ( $template_result['action'] === 'created' ) {
				$results['pages_created'][] = $template_result;
			} elseif ( $template_result['action'] === 'updated' ) {
				$results['pages_updated'][] = $template_result;
			} else {
				$results['pages_skipped'][] = $template_result;
			}

			// Track home page for front page setting.
			if ( $options['set_front_page'] && $this->is_home_template( $template ) ) {
				$home_page_id = $template_result['page_id'];
			}
		}

		// Set front page if requested and home page was created.
		if ( $options['set_front_page'] && $home_page_id && ! $options['dry_run'] ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home_page_id );
			$results['front_page'] = $home_page_id;
		}

		$results['summary'] = $this->generate_summary( $results );

		return $results;
	}

	/**
	 * Get prioritized templates from manifest.
	 *
	 * @param array $manifest Parsed manifest data.
	 * @return array Prioritized list of templates.
	 */
	protected function get_prioritized_templates( $manifest ) {
		$templates = array();

		// Check for templates array in manifest.
		if ( isset( $manifest['templates'] ) && is_array( $manifest['templates'] ) ) {
			$templates = $manifest['templates'];
		} elseif ( isset( $manifest['content'] ) && is_array( $manifest['content'] ) ) {
			$templates = $manifest['content'];
		}

		// Filter to only page-type templates.
		$page_templates = array_filter(
			$templates,
			function ( $template ) {
				$type = isset( $template['type'] ) ? strtolower( $template['type'] ) : '';
				return in_array( $type, array( 'page', 'single', 'landing-page', 'landing_page' ), true ) || empty( $type );
			}
		);

		// Priority order: Home, About, Contact, Services, then alphabetical.
		$priority_keywords = array( 'home', 'index', 'front', 'about', 'contact', 'services', 'portfolio', 'blog' );

		usort(
			$page_templates,
			function ( $a, $b ) use ( $priority_keywords ) {
				$title_a   = strtolower( isset( $a['title'] ) ? $a['title'] : '' );
				$title_b   = strtolower( isset( $b['title'] ) ? $b['title'] : '' );
				$priority_a = count( $priority_keywords );
				$priority_b = count( $priority_keywords );

				foreach ( $priority_keywords as $index => $keyword ) {
					if ( strpos( $title_a, $keyword ) !== false && $priority_a === count( $priority_keywords ) ) {
						$priority_a = $index;
					}
					if ( strpos( $title_b, $keyword ) !== false && $priority_b === count( $priority_keywords ) ) {
						$priority_b = $index;
					}
				}

				if ( $priority_a !== $priority_b ) {
					return $priority_a - $priority_b;
				}

				return strcmp( $title_a, $title_b );
			}
		);

		return array_values( $page_templates );
	}

	/**
	 * Check if template is a home/front page template.
	 *
	 * @param array $template Template data.
	 * @return bool
	 */
	protected function is_home_template( $template ) {
		$title = strtolower( isset( $template['title'] ) ? $template['title'] : '' );
		$home_keywords = array( 'home', 'index', 'front', 'homepage', 'frontpage' );

		foreach ( $home_keywords as $keyword ) {
			if ( strpos( $title, $keyword ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process a single template and create/update page.
	 *
	 * @param array  $template      Template data from manifest.
	 * @param string $templates_dir Directory containing template files.
	 * @param array  $options       Processing options.
	 * @return array|WP_Error Result of processing or error.
	 */
	protected function process_single_template( $template, $templates_dir, $options ) {
		$title = isset( $template['title'] ) ? sanitize_text_field( $template['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_title',
				__( 'Template has no title.', 'wp-mcp-ai' )
			);
		}

		// Load template content.
		$template_content = $this->load_template_content( $template, $templates_dir );
		if ( is_wp_error( $template_content ) ) {
			return $template_content;
		}

		// Check for existing page.
		$existing_page = get_page_by_title( $title, OBJECT, 'page' );

		if ( $existing_page && ! $options['overwrite_existing'] ) {
			return array(
				'action'  => 'skipped',
				'page_id' => $existing_page->ID,
				'title'   => $title,
				'reason'  => __( 'Page already exists and overwrite is disabled.', 'wp-mcp-ai' ),
			);
		}

		// Dry run - don't actually create/update.
		if ( $options['dry_run'] ) {
			$action = $existing_page ? 'would_update' : 'would_create';
			return array(
				'action'  => $action,
				'page_id' => $existing_page ? $existing_page->ID : 0,
				'title'   => $title,
			);
		}

		// Prepare page data.
		$page_data = array(
			'post_title'   => $title,
			'post_content' => '', // Elementor uses meta, not post_content.
			'post_status'  => $options['page_status'],
			'post_type'    => 'page',
			'post_author'  => $options['user_id'],
			'meta_input'   => array(
				'_elementor_edit_mode' => 'builder',
				'_elementor_data'      => $template_content,
				'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
			),
		);

		if ( $existing_page ) {
			$page_data['ID'] = $existing_page->ID;
			$page_id = wp_update_post( $page_data, true );
			$action = 'updated';
		} else {
			$page_id = wp_insert_post( $page_data, true );
			$action = 'created';
		}

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Set Elementor template type if available.
		if ( isset( $template['template_type'] ) ) {
			update_post_meta( $page_id, '_elementor_template_type', sanitize_key( $template['template_type'] ) );
		}

		return array(
			'action'    => $action,
			'page_id'   => $page_id,
			'title'     => $title,
			'edit_link' => get_edit_post_link( $page_id, 'raw' ),
			'permalink' => get_permalink( $page_id ),
		);
	}

	/**
	 * Load template content from file.
	 *
	 * @param array  $template      Template data from manifest.
	 * @param string $templates_dir Directory containing template files.
	 * @return string|WP_Error Template content JSON or error.
	 */
	protected function load_template_content( $template, $templates_dir ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		// Try different possible file locations.
		$possible_files = array();

		if ( isset( $template['file'] ) ) {
			$possible_files[] = trailingslashit( $templates_dir ) . $template['file'];
		}

		if ( isset( $template['id'] ) ) {
			$possible_files[] = trailingslashit( $templates_dir ) . $template['id'] . '.json';
			$possible_files[] = trailingslashit( $templates_dir ) . 'templates/' . $template['id'] . '.json';
		}

		$slug = isset( $template['title'] ) ? sanitize_title( $template['title'] ) : '';
		if ( $slug ) {
			$possible_files[] = trailingslashit( $templates_dir ) . $slug . '.json';
			$possible_files[] = trailingslashit( $templates_dir ) . 'templates/' . $slug . '.json';
		}

		// Check if template content is inline.
		if ( isset( $template['content'] ) && is_array( $template['content'] ) ) {
			return wp_json_encode( $template['content'] );
		}

		// Try to find and read template file.
		foreach ( $possible_files as $file_path ) {
			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				$content = $wp_filesystem->get_contents( $file_path );
				if ( $content !== false ) {
					// Validate JSON.
					$decoded = json_decode( $content );
					if ( json_last_error() === JSON_ERROR_NONE ) {
						return $content;
					}
				}
			}
		}

		// If no file found but we have basic template data, create minimal content.
		if ( isset( $template['widgets'] ) || isset( $template['elements'] ) ) {
			$elements = isset( $template['widgets'] ) ? $template['widgets'] : $template['elements'];
			return wp_json_encode( $elements );
		}

		return new WP_Error(
			'wp_mcp_ai_template_file_not_found',
			sprintf(
				/* translators: %s: Template title */
				__( 'Template file not found for: %s', 'wp-mcp-ai' ),
				isset( $template['title'] ) ? $template['title'] : __( 'Unknown', 'wp-mcp-ai' )
			)
		);
	}

	/**
	 * Clean up temporary directory.
	 *
	 * @param string $temp_dir Directory to remove.
	 */
	protected function cleanup_temp_dir( $temp_dir ) {
		if ( ! $temp_dir || ! is_dir( $temp_dir ) ) {
			return;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		if ( $wp_filesystem ) {
			$wp_filesystem->rmdir( $temp_dir, true );
		}
	}

	/**
	 * Generate a summary of the import.
	 *
	 * @param array $results Import results.
	 * @return string Summary message.
	 */
	protected function generate_summary( $results ) {
		$parts = array();

		if ( $results['dry_run'] ) {
			$parts[] = __( '[DRY RUN]', 'wp-mcp-ai' );
		}

		$created_count = count( $results['pages_created'] );
		$updated_count = count( $results['pages_updated'] );
		$skipped_count = count( $results['pages_skipped'] );
		$error_count   = count( $results['errors'] );

		if ( $created_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: Number of pages */
				_n( '%d page created', '%d pages created', $created_count, 'wp-mcp-ai' ),
				$created_count
			);
		}

		if ( $updated_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: Number of pages */
				_n( '%d page updated', '%d pages updated', $updated_count, 'wp-mcp-ai' ),
				$updated_count
			);
		}

		if ( $skipped_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: Number of pages */
				_n( '%d page skipped', '%d pages skipped', $skipped_count, 'wp-mcp-ai' ),
				$skipped_count
			);
		}

		if ( $error_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: Number of errors */
				_n( '%d error', '%d errors', $error_count, 'wp-mcp-ai' ),
				$error_count
			);
		}

		if ( $results['front_page'] ) {
			$parts[] = __( 'front page set', 'wp-mcp-ai' );
		}

		if ( empty( $parts ) ) {
			return __( 'No changes made.', 'wp-mcp-ai' );
		}

		return implode( ', ', $parts ) . '.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates and modifies data.
			'state-changing',       // Modifies database/site state.
			'requires-plugin',      // Requires Elementor plugin.
			'requires-capability',  // Requires manage_options capability.
			'local-only',           // No external API calls.
			'long-running',         // May take significant time for large kits.
			'performance-impact',   // May temporarily affect site performance.
		);
	}
}
