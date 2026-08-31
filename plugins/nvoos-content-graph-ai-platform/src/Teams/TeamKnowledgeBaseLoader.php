<?php
/**
 * Team Knowledge Base Loader Service.
 *
 * Service layer for loading and validating team knowledge base from JSON files.
 * Follows separation of concerns - handles business logic for loading JSON data.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Teams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and validates team knowledge base data from JSON files.
 */
class TeamKnowledgeBaseLoader {
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
			// Monolith mode: read from the base plugin's own knowledge base.
			// Standalone mode: read from this addon's bundled copy.
			$this->knowledge_base_path = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PATH . 'includes/knowledge-base/teams/'
				: NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'data/knowledge-base/teams/';
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
			return new \WP_Error(
				'no_json_files',
				__( 'No team knowledge base JSON files found.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$all_teams = array();

		foreach ( $json_files as $file ) {
			$teams = $this->load_from_file( $file );

			if ( is_wp_error( $teams ) ) {
				// Log error but continue with other files.
				self::log_event(
					'error',
					sprintf( 'Error loading %s: %s', basename( $file ), $teams->get_error_message() )
				);
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
	 * @return array|\WP_Error Array of team data, or WP_Error on failure.
	 */
	public function load_from_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'file_not_found',
				/* translators: %s: File path */
				sprintf( __( 'File not found: %s', 'nvoos-content-graph-ai-platform' ), $file_path )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin or temp file; WP_Filesystem is not available in this REST/cron/tool execution context.
		$json_content = file_get_contents( $file_path );

		if ( false === $json_content ) {
			return new \WP_Error(
				'file_read_error',
				/* translators: %s: File path */
				sprintf( __( 'Could not read file: %s', 'nvoos-content-graph-ai-platform' ), $file_path )
			);
		}

		$data = json_decode( $json_content, true );

		if ( null === $data ) {
			return new \WP_Error(
				'json_decode_error',
				/* translators: %s: File path */
				sprintf( __( 'Invalid JSON in file: %s', 'nvoos-content-graph-ai-platform' ), $file_path )
			);
		}

		// Validate structure.
		if ( ! isset( $data['teams'] ) || ! is_array( $data['teams'] ) ) {
			return new \WP_Error(
				'invalid_structure',
				/* translators: %s: File path */
				sprintf( __( 'Invalid JSON structure in file: %s. Missing "teams" array.', 'nvoos-content-graph-ai-platform' ), $file_path )
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
	 * @return array|\WP_Error Validated team data, or WP_Error if invalid.
	 */
	protected function validate_team( $team ) {
		// Required fields.
		$required_fields = array( 'title', 'slug', 'members' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $team[ $field ] ) || empty( $team[ $field ] ) ) {
				return new \WP_Error(
					'missing_required_field',
					/* translators: %s: Field name */
					sprintf( __( 'Missing required field: %s', 'nvoos-content-graph-ai-platform' ), $field )
				);
			}
		}

		// Validate members array.
		if ( ! is_array( $team['members'] ) || count( $team['members'] ) < 2 ) {
			return new \WP_Error(
				'invalid_members',
				__( 'Team must have at least 2 members.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Sanitize and structure the data.
		$validated = array(
			'title'               => sanitize_text_field( $team['title'] ),
			'slug'                => sanitize_title( $team['slug'] ),
			'description'         => isset( $team['description'] ) ? wp_kses_post( $team['description'] ) : '',
			'members'             => array_map( 'sanitize_text_field', $team['members'] ),
			'default_provider'    => isset( $team['default_provider'] ) ? sanitize_key( $team['default_provider'] ) : '',
			'default_model'       => isset( $team['default_model'] ) ? sanitize_text_field( $team['default_model'] ) : '',
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- is_dir() used to check arbitrary system directory existence; WP_Filesystem does not expose an is_dir() equivalent for non-site paths.
		$files = glob( $this->knowledge_base_path . '*.json' );

		if ( false === $files ) {
			return array();
		}

		return $files;
	}
}
