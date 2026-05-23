<?php
/**
 * Evidence Catalog Manager Tool
 *
 * Manages an evidence catalog for litigation matters, supporting add, list,
 * update, and search operations on exhibit records stored as post meta.
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
 * Manages evidence catalog entries as post meta on matter posts.
 */
class WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

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
		return 'lf_evidence_catalog_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Evidence Catalog Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages an evidence catalog for litigation matters. Supports adding, listing, updating, and searching exhibit records stored as post meta on matter posts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Action to perform on the evidence catalog.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'update', 'search' ),
				),
				'matter_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the matter.', 'mcp-ai-wpoos-pro' ),
				),
				'exhibit_number' => array(
					'type'        => 'string',
					'description' => __( 'Exhibit number or identifier (e.g., "Exhibit A").', 'mcp-ai-wpoos-pro' ),
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'Description of the evidence item.', 'mcp-ai-wpoos-pro' ),
				),
				'evidence_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of evidence.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'documentary', 'testimonial', 'physical', 'digital', 'demonstrative' ),
				),
				'source'         => array(
					'type'        => 'string',
					'description' => __( 'Source of the evidence item.', 'mcp-ai-wpoos-pro' ),
				),
				'date_obtained'  => array(
					'type'        => 'string',
					'description' => __( 'Date the evidence was obtained (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'evidence_id'    => array(
					'type'        => 'string',
					'description' => __( 'Unique evidence ID for update operations.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
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

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( empty( $action ) || $matter_id <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Action and matter_id are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $matter_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$meta_key = '_lf_evidence_catalog';
		$catalog  = get_post_meta( $matter_id, $meta_key, true );
		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}

		switch ( $action ) {
			case 'add':
				return $this->handle_add( $arguments, $matter_id, $catalog, $meta_key );

			case 'list':
				return $this->handle_list( $matter_id, $catalog );

			case 'update':
				return $this->handle_update( $arguments, $matter_id, $catalog, $meta_key );

			case 'search':
				return $this->handle_search( $arguments, $matter_id, $catalog );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle adding a new evidence entry.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param array  $catalog   Current catalog.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_add( array $arguments, int $matter_id, array $catalog, string $meta_key ) {
		$exhibit_number = isset( $arguments['exhibit_number'] ) ? sanitize_text_field( $arguments['exhibit_number'] ) : '';
		$description    = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$evidence_type  = isset( $arguments['evidence_type'] ) ? sanitize_text_field( $arguments['evidence_type'] ) : 'documentary';
		$source         = isset( $arguments['source'] ) ? sanitize_text_field( $arguments['source'] ) : '';
		$date_obtained  = isset( $arguments['date_obtained'] ) ? sanitize_text_field( $arguments['date_obtained'] ) : '';

		if ( empty( $exhibit_number ) || empty( $description ) ) {
			return new WP_Error( 'missing_fields', __( 'Exhibit number and description are required for adding evidence.', 'mcp-ai-wpoos-pro' ) );
		}

		$evidence_id = 'ev_' . wp_generate_uuid4();
		$entry       = array(
			'evidence_id'    => $evidence_id,
			'exhibit_number' => $exhibit_number,
			'description'    => $description,
			'evidence_type'  => $evidence_type,
			'source'         => $source,
			'date_obtained'  => $date_obtained,
			'date_added'     => current_time( 'Y-m-d H:i:s' ),
			'added_by'       => get_current_user_id(),
		);

		$catalog[] = $entry;
		update_post_meta( $matter_id, $meta_key, $catalog );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: exhibit number */
				__( 'Evidence item %1$s added to catalog. ', 'mcp-ai-wpoos-pro' ),
				$exhibit_number
			) . self::DISCLAIMER,
			'data'       => array(
				'evidence_id' => $evidence_id,
				'entry'       => $entry,
				'total_items' => count( $catalog ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle listing all evidence entries.
	 *
	 * @param int   $matter_id Matter post ID.
	 * @param array $catalog   Current catalog.
	 * @return array
	 */
	private function handle_list( int $matter_id, array $catalog ) {
		$type_counts = array();
		foreach ( $catalog as $item ) {
			$t                 = $item['evidence_type'] ?? 'unknown';
			$type_counts[ $t ] = ( $type_counts[ $t ] ?? 0 ) + 1;
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: total items */
				__( 'Evidence catalog contains %1$d items. ', 'mcp-ai-wpoos-pro' ),
				count( $catalog )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'   => $matter_id,
				'total_items' => count( $catalog ),
				'items'       => $catalog,
				'type_counts' => $type_counts,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle updating an existing evidence entry.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param array  $catalog   Current catalog.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_update( array $arguments, int $matter_id, array $catalog, string $meta_key ) {
		$evidence_id = isset( $arguments['evidence_id'] ) ? sanitize_text_field( $arguments['evidence_id'] ) : '';
		if ( empty( $evidence_id ) ) {
			return new WP_Error( 'missing_fields', __( 'Evidence ID is required for updates.', 'mcp-ai-wpoos-pro' ) );
		}

		$found        = false;
		$updated_item = array();
		$updatable    = array( 'exhibit_number', 'description', 'evidence_type', 'source', 'date_obtained' );
		foreach ( $catalog as &$item ) {
			if ( ( $item['evidence_id'] ?? '' ) === $evidence_id ) {
				foreach ( $updatable as $field ) {
					if ( isset( $arguments[ $field ] ) ) {
						$item[ $field ] = sanitize_text_field( $arguments[ $field ] );
					}
				}
				$item['date_modified'] = current_time( 'Y-m-d H:i:s' );
				$found                 = true;
				$updated_item          = $item;
				break;
			}
		}
		unset( $item );

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Evidence item not found.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $matter_id, $meta_key, $catalog );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: evidence ID */
				__( 'Evidence item %1$s updated. ', 'mcp-ai-wpoos-pro' ),
				$evidence_id
			) . self::DISCLAIMER,
			'data'       => array(
				'evidence_id' => $evidence_id,
				'entry'       => $updated_item,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle searching evidence entries.
	 *
	 * @param array $arguments Function arguments.
	 * @param int   $matter_id Matter post ID.
	 * @param array $catalog   Current catalog.
	 * @return array
	 */
	private function handle_search( array $arguments, int $matter_id, array $catalog ) {
		$query         = isset( $arguments['description'] ) ? strtolower( sanitize_text_field( $arguments['description'] ) ) : '';
		$evidence_type = isset( $arguments['evidence_type'] ) ? sanitize_text_field( $arguments['evidence_type'] ) : '';
		$source        = isset( $arguments['source'] ) ? strtolower( sanitize_text_field( $arguments['source'] ) ) : '';

		$results = array();
		foreach ( $catalog as $item ) {
			$match = true;
			if ( ! empty( $query ) ) {
				$desc = strtolower( $item['description'] ?? '' );
				$exh  = strtolower( $item['exhibit_number'] ?? '' );
				if ( false === strpos( $desc, $query ) && false === strpos( $exh, $query ) ) {
					$match = false;
				}
			}
			if ( ! empty( $evidence_type ) && ( $item['evidence_type'] ?? '' ) !== $evidence_type ) {
				$match = false;
			}
			if ( ! empty( $source ) && false === strpos( strtolower( $item['source'] ?? '' ), $source ) ) {
				$match = false;
			}
			if ( $match ) {
				$results[] = $item;
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: result count, 2: total items */
				__( 'Found %1$d of %2$d evidence items matching search criteria. ', 'mcp-ai-wpoos-pro' ),
				count( $results ),
				count( $catalog )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'     => $matter_id,
				'results_count' => count( $results ),
				'results'       => $results,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
