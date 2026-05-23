<?php
/**
 * Clause Library Manager Tool
 *
 * Manages a reusable library of legal clauses for document assembly.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages a clause library stored in WordPress options for reuse across documents.
 */
class WP_MCP_AI_Tool_LF_Clause_Library_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	const OPTION_KEY = 'wp_mcp_ai_lf_clause_library';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_clause_library_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Clause Library Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages a reusable library of legal clauses for document assembly. Supports adding, searching, listing, and deleting clauses by type, practice area, and tags.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform on the clause library.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'search', 'list', 'delete' ),
				),
				'clause_text'   => array(
					'type'        => 'string',
					'description' => __( 'The clause text content (required for add).', 'mcp-ai-wpoos-pro' ),
				),
				'clause_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type/category of clause (e.g., "indemnification", "confidentiality").', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Practice area this clause belongs to.', 'mcp-ai-wpoos-pro' ),
				),
				'clause_id'     => array(
					'type'        => 'string',
					'description' => __( 'Unique clause identifier (required for delete).', 'mcp-ai-wpoos-pro' ),
				),
				'tags'          => array(
					'type'        => 'array',
					'description' => __( 'Tags for categorizing the clause.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$valid_actions = array( 'add', 'search', 'list', 'delete' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid action. Use: add, search, list, or delete.', 'mcp-ai-wpoos-pro' ) );
		}

		$library = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $library ) ) {
			$library = array();
		}

		switch ( $action ) {
			case 'add':
				return $this->add_clause( $library, $arguments, $uid );

			case 'search':
				return $this->search_clauses( $library, $arguments );

			case 'list':
				return $this->list_clauses( $library, $arguments );

			case 'delete':
				return $this->delete_clause( $library, $arguments );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Add a clause to the library.
	 *
	 * @param array $library    Current library.
	 * @param array $arguments  Tool arguments.
	 * @param int   $uid        User ID.
	 * @return array|WP_Error
	 */
	private function add_clause( array $library, array $arguments, int $uid ) {
		$clause_text   = isset( $arguments['clause_text'] ) ? sanitize_textarea_field( $arguments['clause_text'] ) : '';
		$clause_type   = isset( $arguments['clause_type'] ) ? sanitize_text_field( $arguments['clause_type'] ) : '';
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$tags          = array();

		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			foreach ( $arguments['tags'] as $tag ) {
				$tags[] = sanitize_text_field( $tag );
			}
		}

		if ( empty( $clause_text ) ) {
			return new WP_Error( 'missing_required', __( 'Clause text is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$clause_id = 'clause_' . wp_generate_uuid4();

		$library[ $clause_id ] = array(
			'id'            => $clause_id,
			'text'          => $clause_text,
			'type'          => $clause_type,
			'practice_area' => $practice_area,
			'tags'          => $tags,
			'created_by'    => $uid,
			'created_at'    => current_time( 'Y-m-d H:i:s' ),
		);

		update_option( self::OPTION_KEY, $library, false );

		return array(
			'success'    => true,
			'message'    => __( 'Clause added to library. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'clause_id' => $clause_id,
				'clause'    => $library[ $clause_id ],
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Search clauses by type, practice area, or tags.
	 *
	 * @param array $library   Current library.
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function search_clauses( array $library, array $arguments ): array {
		$clause_type   = isset( $arguments['clause_type'] ) ? sanitize_text_field( $arguments['clause_type'] ) : '';
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$search_text   = isset( $arguments['clause_text'] ) ? strtolower( sanitize_text_field( $arguments['clause_text'] ) ) : '';

		$results = array();
		foreach ( $library as $clause ) {
			$match = true;
			if ( $clause_type && ( $clause['type'] ?? '' ) !== $clause_type ) {
				$match = false;
			}
			if ( $practice_area && ( $clause['practice_area'] ?? '' ) !== $practice_area ) {
				$match = false;
			}
			if ( $search_text && false === strpos( strtolower( $clause['text'] ?? '' ), $search_text ) ) {
				$match = false;
			}
			if ( $match ) {
				$results[] = $clause;
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of results */
				__( 'Found %d matching clauses. ', 'mcp-ai-wpoos-pro' ),
				count( $results )
			) . self::DISCLAIMER,
			'data'       => array(
				'results'       => $results,
				'total_results' => count( $results ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * List all clauses, optionally filtered.
	 *
	 * @param array $library   Current library.
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function list_clauses( array $library, array $arguments ): array {
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';

		$clauses = array_values( $library );
		if ( $practice_area ) {
			$clauses = array_values(
				array_filter(
					$clauses,
					function ( $c ) use ( $practice_area ) {
						return ( $c['practice_area'] ?? '' ) === $practice_area;
					}
				)
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: total clauses */
				__( 'Library contains %d clauses. ', 'mcp-ai-wpoos-pro' ),
				count( $clauses )
			) . self::DISCLAIMER,
			'data'       => array(
				'clauses'       => $clauses,
				'total_clauses' => count( $clauses ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Delete a clause from the library.
	 *
	 * @param array $library   Current library.
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function delete_clause( array $library, array $arguments ) {
		$clause_id = isset( $arguments['clause_id'] ) ? sanitize_text_field( $arguments['clause_id'] ) : '';

		if ( empty( $clause_id ) ) {
			return new WP_Error( 'missing_required', __( 'Clause ID is required for deletion.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! isset( $library[ $clause_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Clause not found.', 'mcp-ai-wpoos-pro' ) );
		}

		unset( $library[ $clause_id ] );
		update_option( self::OPTION_KEY, $library, false );

		return array(
			'success'    => true,
			'message'    => __( 'Clause deleted from library. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array( 'deleted_clause_id' => $clause_id ),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
