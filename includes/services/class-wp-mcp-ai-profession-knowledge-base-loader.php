<?php
/**
 * Profession Knowledge Base Loader Service.
 *
 * Service layer for loading and validating profession knowledge base from JSON files.
 * Follows separation of concerns - handles business logic for loading JSON data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and validates profession knowledge base data from JSON files.
 */
class WP_MCP_AI_Profession_Knowledge_Base_Loader {
	/**
	 * Path to knowledge base directory.
	 *
	 * @var string
	 */
	protected $knowledge_base_path;

	/**
	 * Tool recommender instance.
	 *
	 * @var WP_MCP_AI_Profession_Tool_Recommender|null
	 */
	protected $tool_recommender;

	/**
	 * Constructor.
	 *
	 * @param string                                     $knowledge_base_path Optional path to knowledge base directory.
	 * @param WP_MCP_AI_Profession_Tool_Recommender|null $tool_recommender    Optional tool recommender instance.
	 */
	public function __construct( $knowledge_base_path = null, $tool_recommender = null ) {
		if ( null === $knowledge_base_path ) {
			$this->knowledge_base_path = WP_MCP_AI_PATH . 'includes/knowledge-base/professions/';
		} else {
			$this->knowledge_base_path = trailingslashit( $knowledge_base_path );
		}

		$this->tool_recommender = $tool_recommender;
	}

	/**
	 * Load all profession data from JSON files.
	 *
	 * @return array Array of profession data, or WP_Error on failure.
	 */
	public function load_all() {
		$json_files = $this->get_json_files();

		if ( empty( $json_files ) ) {
			return new WP_Error(
				'no_json_files',
				__( 'No profession knowledge base JSON files found.', 'wp-mcp-ai' )
			);
		}

		$all_professions = array();

		foreach ( $json_files as $file ) {
			$professions = $this->load_from_file( $file );

			if ( is_wp_error( $professions ) ) {
				// Log error but continue with other files.
				error_log( sprintf( 'WP_MCP_AI: Error loading %s: %s', basename( $file ), $professions->get_error_message() ) );
				continue;
			}

			$all_professions = array_merge( $all_professions, $professions );
		}

		return $all_professions;
	}

	/**
	 * Load professions from a specific JSON file.
	 *
	 * @param string $file_path Path to JSON file.
	 * @return array|WP_Error Array of profession data, or WP_Error on failure.
	 */
	public function load_from_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				sprintf( __( 'File not found: %s', 'wp-mcp-ai' ), $file_path )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json_content = file_get_contents( $file_path );

		if ( false === $json_content ) {
			return new WP_Error(
				'file_read_error',
				sprintf( __( 'Could not read file: %s', 'wp-mcp-ai' ), $file_path )
			);
		}

		$data = json_decode( $json_content, true );

		if ( null === $data ) {
			return new WP_Error(
				'json_decode_error',
				sprintf( __( 'Invalid JSON in file: %s', 'wp-mcp-ai' ), $file_path )
			);
		}

		// Validate structure.
		if ( ! isset( $data['professions'] ) || ! is_array( $data['professions'] ) ) {
			return new WP_Error(
				'invalid_structure',
				sprintf( __( 'Invalid JSON structure in file: %s. Missing "professions" array.', 'wp-mcp-ai' ), $file_path )
			);
		}

		// Validate and sanitize each profession.
		$professions = array();
		foreach ( $data['professions'] as $profession ) {
			$validated = $this->validate_profession( $profession );
			if ( ! is_wp_error( $validated ) ) {
				$professions[] = $validated;
			}
		}

		return $professions;
	}

	/**
	 * Validate and sanitize a profession data array.
	 *
	 * @param array $profession Profession data.
	 * @return array|WP_Error Validated profession data, or WP_Error if invalid.
	 */
	protected function validate_profession( $profession ) {
		// Required fields.
		$required_fields = array( 'title', 'slug', 'category' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $profession[ $field ] ) || empty( $profession[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf( __( 'Missing required field: %s', 'wp-mcp-ai' ), $field )
				);
			}
		}

		// Extract category and slug for tool recommendations.
		$category = isset( $profession['category'] ) ? sanitize_key( $profession['category'] ) : 'other';
		$slug     = isset( $profession['slug'] ) ? sanitize_title( $profession['slug'] ) : '';

		// Get default tools from JSON and enhance with recommendations.
		$json_tools = isset( $profession['default_tools'] ) && is_array( $profession['default_tools'] )
			? array_map( 'sanitize_key', $profession['default_tools'] )
			: array();

		$default_tools = $this->enhance_default_tools( $json_tools, $slug, $category );

		// Sanitize and structure the data.
		$validated = array(
			'title'                => sanitize_text_field( $profession['title'] ),
			'slug'                 => $slug,
			'description'          => isset( $profession['description'] ) ? wp_kses_post( $profession['description'] ) : '',
			'category'             => $category,
			'role_description'     => isset( $profession['role_description'] ) ? wp_kses_post( $profession['role_description'] ) : '',
			'expertise'            => isset( $profession['expertise'] ) && is_array( $profession['expertise'] )
				? array_map( 'sanitize_text_field', $profession['expertise'] )
				: array(),
			'warnings'             => isset( $profession['warnings'] ) && is_array( $profession['warnings'] )
				? array_map( 'sanitize_text_field', $profession['warnings'] )
				: array(),
			'knowledge_base'       => isset( $profession['knowledge_base'] ) ? wp_kses_post( $profession['knowledge_base'] ) : '',
			'default_tools'        => $default_tools,
			'supported_mime_types' => $this->get_supported_mimes_for_category( $category ),
		);

		return $validated;
	}

	/**
	 * Enhance default tools using the tool recommender.
	 *
	 * Combines tools from JSON with recommended tools for the profession.
	 * JSON tools take precedence to preserve any custom selections.
	 *
	 * @param array  $json_tools Tools from JSON file.
	 * @param string $slug       Profession slug.
	 * @param string $category   Profession category.
	 * @return array Enhanced array of tool slugs.
	 */
	protected function enhance_default_tools( $json_tools, $slug, $category ) {
		// If JSON already has tools and it's more than the basic 3, use those.
		if ( ! empty( $json_tools ) && count( $json_tools ) > 3 ) {
			return $json_tools;
		}

		// Get tool recommender instance.
		$recommender = $this->get_tool_recommender();
		if ( ! $recommender ) {
			// Fallback to JSON tools if recommender not available.
			return $json_tools;
		}

		// Get recommended tools for this profession.
		$recommended_tools = $recommender->get_recommended_tools( $slug, $category );

		// If we have JSON tools, merge them with recommendations (JSON tools first).
		if ( ! empty( $json_tools ) ) {
			$enhanced_tools = array_unique( array_merge( $json_tools, $recommended_tools ) );
		} else {
			$enhanced_tools = $recommended_tools;
		}

		return $enhanced_tools;
	}

	/**
	 * Get or initialize the tool recommender.
	 *
	 * @return WP_MCP_AI_Profession_Tool_Recommender|null Tool recommender instance or null if unavailable.
	 */
	protected function get_tool_recommender() {
		if ( null !== $this->tool_recommender ) {
			return $this->tool_recommender;
		}

		// Try to initialize the recommender.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Tool_Recommender' ) ) {
			return null;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return null;
		}

		try {
			$tool_registry           = WP_MCP_AI_Tool_Registry::get_instance();
			$this->tool_recommender = new WP_MCP_AI_Profession_Tool_Recommender( $tool_registry );
			return $this->tool_recommender;
		} catch ( Exception $e ) {
			// Log the exception for debugging.
			error_log(
				sprintf(
					'WP_MCP_AI: Failed to initialize tool recommender: %s in %s:%d',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
			// If something fails, return null and fallback to JSON tools.
			return null;
		}
	}

	/**
	 * Get list of JSON files in knowledge base directory.
	 *
	 * @return array Array of file paths.
	 */
	protected function get_json_files() {
		if ( ! is_dir( $this->knowledge_base_path ) ) {
			return array();
		}

		$files = glob( $this->knowledge_base_path . '*.json' );

		return is_array( $files ) ? $files : array();
	}

	/**
	 * Load professions from a specific category.
	 *
	 * @param string $category Category slug (e.g., 'healthcare-medicine').
	 * @return array|WP_Error Array of profession data, or WP_Error on failure.
	 */
	public function load_category( $category ) {
		$file_path = $this->knowledge_base_path . sanitize_file_name( $category ) . '.json';

		return $this->load_from_file( $file_path );
	}

	/**
	 * Get list of available categories.
	 *
	 * @return array Array of category slugs.
	 */
	public function get_categories() {
		$files      = $this->get_json_files();
		$categories = array();

		foreach ( $files as $file ) {
			$basename     = basename( $file, '.json' );
			$categories[] = $basename;
		}

		return $categories;
	}

	/**
	 * Get supported MIME types for a profession category.
	 *
	 * @param string $category Profession category.
	 * @return array Array of MIME type strings.
	 */
	protected function get_supported_mimes_for_category( $category ) {
		$base_mimes = array( 'text/plain' );

		switch ( $category ) {
			case 'advisory':
			case 'financial':
			case 'legal':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					)
				);

			case 'creative':
				return array_merge(
					$base_mimes,
					array(
						'image/jpeg',
						'image/png',
						'image/webp',
						'application/pdf',
					)
				);

			case 'technical':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'text/csv',
					)
				);

			case 'healthcare':
				return array_merge(
					$base_mimes,
					array(
						'application/pdf',
						'image/jpeg',
						'image/png',
					)
				);

			default:
				return array_merge(
					$base_mimes,
					array( 'application/pdf' )
				);
		}
	}
}
