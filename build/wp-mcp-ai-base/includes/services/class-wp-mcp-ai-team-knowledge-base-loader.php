<?php
/**
 * Team Knowledge Base Loader Service.
 *
 * Service layer for loading and validating team knowledge base from JSON files.
 * Follows separation of concerns - handles business logic for loading JSON data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and validates team knowledge base data from JSON files.
 */
class WP_MCP_AI_Team_Knowledge_Base_Loader {
	/**
	 * Path to knowledge base directory.
	 *
	 * @var string
	 */
	protected $knowledge_base_path;

	/**
	 * Constructor.
	 *
	 * @param string $knowledge_base_path Optional path to knowledge base directory.
	 */
	public function __construct( $knowledge_base_path = null ) {
		if ( null === $knowledge_base_path ) {
			$this->knowledge_base_path = WP_MCP_AI_PATH . 'includes/knowledge-base/teams/';
		} else {
			$this->knowledge_base_path = trailingslashit( $knowledge_base_path );
		}
	}

	/**
	 * Load all team data from JSON files.
	 *
	 * @return array Array of team data, or WP_Error on failure.
	 */
	public function load_all() {
		$json_files = $this->get_json_files();

		if ( empty( $json_files ) ) {
			return new WP_Error(
				'no_json_files',
				__( 'No team knowledge base JSON files found.', 'wp-mcp-ai' )
			);
		}

		$all_teams = array();

		foreach ( $json_files as $file ) {
			$teams = $this->load_from_file( $file );

			if ( is_wp_error( $teams ) ) {
				// Log error but continue with other files.
				error_log( sprintf( 'WP_MCP_AI: Error loading %s: %s', basename( $file ), $teams->get_error_message() ) );
				continue;
			}

			$all_teams = array_merge( $all_teams, $teams );
		}

		return $all_teams;
	}

	/**
	 * Load teams from a specific JSON file.
	 *
	 * @param string $file_path Path to JSON file.
	 * @return array|WP_Error Array of team data, or WP_Error on failure.
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
		if ( ! isset( $data['teams'] ) || ! is_array( $data['teams'] ) ) {
			return new WP_Error(
				'invalid_structure',
				sprintf( __( 'Invalid JSON structure in file: %s. Missing "teams" array.', 'wp-mcp-ai' ), $file_path )
			);
		}

		// Validate and sanitize each team.
		$teams = array();
		foreach ( $data['teams'] as $team ) {
			$validated = $this->validate_team( $team );
			if ( ! is_wp_error( $validated ) ) {
				$teams[] = $validated;
			}
		}

		return $teams;
	}

	/**
	 * Validate and sanitize a team data array.
	 *
	 * @param array $team Team data.
	 * @return array|WP_Error Validated team data, or WP_Error if invalid.
	 */
	protected function validate_team( $team ) {
		// Required fields.
		$required_fields = array( 'title', 'slug', 'members' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $team[ $field ] ) || empty( $team[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf( __( 'Missing required field: %s', 'wp-mcp-ai' ), $field )
				);
			}
		}

		// Validate members array.
		if ( ! is_array( $team['members'] ) || count( $team['members'] ) < 2 ) {
			return new WP_Error(
				'invalid_members',
				__( 'Team must have at least 2 members.', 'wp-mcp-ai' )
			);
		}

		// Sanitize and structure the data.
		$validated = array(
			'title'              => sanitize_text_field( $team['title'] ),
			'slug'               => sanitize_title( $team['slug'] ),
			'description'        => isset( $team['description'] ) ? wp_kses_post( $team['description'] ) : '',
			'members'            => array_map( 'sanitize_text_field', $team['members'] ),
			'default_provider'   => isset( $team['default_provider'] ) ? sanitize_key( $team['default_provider'] ) : '',
			'default_model'      => isset( $team['default_model'] ) ? sanitize_text_field( $team['default_model'] ) : '',
			'default_temperature' => isset( $team['default_temperature'] ) ? floatval( $team['default_temperature'] ) : null,
		);

		return $validated;
	}

	/**
	 * Get all JSON files from the knowledge base directory.
	 *
	 * @return array Array of file paths.
	 */
	protected function get_json_files() {
		if ( ! is_dir( $this->knowledge_base_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir
		$files = glob( $this->knowledge_base_path . '*.json' );

		if ( false === $files ) {
			return array();
		}

		return $files;
	}
}
