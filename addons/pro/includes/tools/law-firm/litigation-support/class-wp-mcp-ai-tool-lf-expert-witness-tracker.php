<?php
/**
 * Expert Witness Tracker Tool
 *
 * Manages expert witness records for litigation matters, supporting add, list,
 * update, and search operations stored as post meta.
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
 * Manages expert witness records as post meta on matter posts.
 */
class WP_MCP_AI_Tool_LF_Expert_Witness_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_expert_witness_tracker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Expert Witness Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages expert witness records for litigation matters. Supports adding, listing, updating, and searching expert witness profiles stored as post meta.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'            => array(
					'type'        => 'string',
					'description' => __( 'Action to perform on expert witness records.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'update', 'search' ),
				),
				'matter_id'         => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the related matter.', 'mcp-ai-wpoos-pro' ),
				),
				'expert_name'       => array(
					'type'        => 'string',
					'description' => __( 'Full name of the expert witness.', 'mcp-ai-wpoos-pro' ),
				),
				'specialty'         => array(
					'type'        => 'string',
					'description' => __( 'Area of expertise (e.g., forensic accounting, medical).', 'mcp-ai-wpoos-pro' ),
				),
				'rate'              => array(
					'type'        => 'number',
					'description' => __( 'Hourly rate for expert services.', 'mcp-ai-wpoos-pro' ),
				),
				'cv_summary'        => array(
					'type'        => 'string',
					'description' => __( 'Brief summary of qualifications and CV highlights.', 'mcp-ai-wpoos-pro' ),
				),
				'testimony_history' => array(
					'type'        => 'string',
					'description' => __( 'Summary of prior testimony or Daubert challenges.', 'mcp-ai-wpoos-pro' ),
				),
				'expert_id'         => array(
					'type'        => 'string',
					'description' => __( 'Unique expert ID for update operations.', 'mcp-ai-wpoos-pro' ),
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

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( empty( $action ) ) {
			return new WP_Error( 'missing_required', __( 'Action is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$meta_key = '_lf_expert_witnesses';

		switch ( $action ) {
			case 'add':
				return $this->handle_add( $arguments, $matter_id, $meta_key );

			case 'list':
				return $this->handle_list( $matter_id, $meta_key );

			case 'update':
				return $this->handle_update( $arguments, $matter_id, $meta_key );

			case 'search':
				return $this->handle_search( $arguments, $matter_id, $meta_key );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle adding a new expert witness.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_add( array $arguments, int $matter_id, string $meta_key ) {
		if ( $matter_id <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required to add an expert.', 'mcp-ai-wpoos-pro' ) );
		}
		$post = get_post( $matter_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$expert_name       = isset( $arguments['expert_name'] ) ? sanitize_text_field( $arguments['expert_name'] ) : '';
		$specialty         = isset( $arguments['specialty'] ) ? sanitize_text_field( $arguments['specialty'] ) : '';
		$rate              = isset( $arguments['rate'] ) ? floatval( $arguments['rate'] ) : 0;
		$cv_summary        = isset( $arguments['cv_summary'] ) ? sanitize_textarea_field( $arguments['cv_summary'] ) : '';
		$testimony_history = isset( $arguments['testimony_history'] ) ? sanitize_textarea_field( $arguments['testimony_history'] ) : '';

		if ( empty( $expert_name ) ) {
			return new WP_Error( 'missing_fields', __( 'Expert name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$experts = get_post_meta( $matter_id, $meta_key, true );
		if ( ! is_array( $experts ) ) {
			$experts = array();
		}

		$expert_id = 'exp_' . wp_generate_uuid4();
		$entry     = array(
			'expert_id'         => $expert_id,
			'expert_name'       => $expert_name,
			'specialty'         => $specialty,
			'rate'              => round( $rate, 2 ),
			'cv_summary'        => $cv_summary,
			'testimony_history' => $testimony_history,
			'date_added'        => current_time( 'Y-m-d H:i:s' ),
			'added_by'          => get_current_user_id(),
			'status'            => 'active',
		);

		$experts[] = $entry;
		update_post_meta( $matter_id, $meta_key, $experts );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: expert name */
				__( 'Expert witness %1$s added to matter. ', 'mcp-ai-wpoos-pro' ),
				$expert_name
			) . self::DISCLAIMER,
			'data'       => array(
				'expert_id'     => $expert_id,
				'entry'         => $entry,
				'total_experts' => count( $experts ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle listing all expert witnesses for a matter.
	 *
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_list( int $matter_id, string $meta_key ) {
		if ( $matter_id <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required to list experts.', 'mcp-ai-wpoos-pro' ) );
		}
		$post = get_post( $matter_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$experts = get_post_meta( $matter_id, $meta_key, true );
		if ( ! is_array( $experts ) ) {
			$experts = array();
		}

		$specialty_counts = array();
		$total_rate       = 0;
		foreach ( $experts as $expert ) {
			$s                      = $expert['specialty'] ?? 'unspecified';
			$specialty_counts[ $s ] = ( $specialty_counts[ $s ] ?? 0 ) + 1;
			$total_rate            += $expert['rate'] ?? 0;
		}
		$avg_rate = count( $experts ) > 0 ? round( $total_rate / count( $experts ), 2 ) : 0;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: total experts */
				__( 'Found %1$d expert witnesses for this matter. ', 'mcp-ai-wpoos-pro' ),
				count( $experts )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'        => $matter_id,
				'total_experts'    => count( $experts ),
				'experts'          => $experts,
				'specialty_counts' => $specialty_counts,
				'average_rate'     => $avg_rate,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle updating an expert witness record.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_update( array $arguments, int $matter_id, string $meta_key ) {
		if ( $matter_id <= 0 ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required for updates.', 'mcp-ai-wpoos-pro' ) );
		}
		$expert_id = isset( $arguments['expert_id'] ) ? sanitize_text_field( $arguments['expert_id'] ) : '';
		if ( empty( $expert_id ) ) {
			return new WP_Error( 'missing_fields', __( 'Expert ID is required for updates.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $matter_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$experts = get_post_meta( $matter_id, $meta_key, true );
		if ( ! is_array( $experts ) ) {
			$experts = array();
		}

		$found        = false;
		$updated_item = array();
		$updatable    = array( 'expert_name', 'specialty', 'cv_summary', 'testimony_history' );
		foreach ( $experts as &$expert ) {
			if ( ( $expert['expert_id'] ?? '' ) === $expert_id ) {
				foreach ( $updatable as $field ) {
					if ( isset( $arguments[ $field ] ) ) {
						$expert[ $field ] = sanitize_text_field( $arguments[ $field ] );
					}
				}
				if ( isset( $arguments['rate'] ) ) {
					$expert['rate'] = round( floatval( $arguments['rate'] ), 2 );
				}
				$expert['date_modified'] = current_time( 'Y-m-d H:i:s' );
				$found                   = true;
				$updated_item            = $expert;
				break;
			}
		}
		unset( $expert );

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Expert witness not found.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $matter_id, $meta_key, $experts );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: expert ID */
				__( 'Expert witness %1$s updated. ', 'mcp-ai-wpoos-pro' ),
				$expert_id
			) . self::DISCLAIMER,
			'data'       => array(
				'expert_id' => $expert_id,
				'entry'     => $updated_item,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle searching expert witnesses.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID (0 searches all).
	 * @param string $meta_key  Meta key.
	 * @return array
	 */
	private function handle_search( array $arguments, int $matter_id, string $meta_key ) {
		$name_query = isset( $arguments['expert_name'] ) ? strtolower( sanitize_text_field( $arguments['expert_name'] ) ) : '';
		$specialty  = isset( $arguments['specialty'] ) ? strtolower( sanitize_text_field( $arguments['specialty'] ) ) : '';

		$all_experts = array();

		if ( $matter_id > 0 ) {
			$experts = get_post_meta( $matter_id, $meta_key, true );
			if ( is_array( $experts ) ) {
				foreach ( $experts as $e ) {
					$e['matter_id'] = $matter_id;
					$all_experts[]  = $e;
				}
			}
		} else {
			// Search across all matters with expert witnesses.
			global $wpdb;
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
			if ( $meta_rows ) {
				foreach ( $meta_rows as $row ) {
					$experts = maybe_unserialize( $row->meta_value );
					if ( is_array( $experts ) ) {
						foreach ( $experts as $e ) {
							$e['matter_id'] = (int) $row->post_id;
							$all_experts[]  = $e;
						}
					}
				}
			}
		}

		$results = array();
		foreach ( $all_experts as $expert ) {
			$match = true;
			if ( ! empty( $name_query ) && false === strpos( strtolower( $expert['expert_name'] ?? '' ), $name_query ) ) {
				$match = false;
			}
			if ( ! empty( $specialty ) && false === strpos( strtolower( $expert['specialty'] ?? '' ), $specialty ) ) {
				$match = false;
			}
			if ( $match ) {
				$results[] = $expert;
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: result count */
				__( 'Found %1$d expert witnesses matching search criteria. ', 'mcp-ai-wpoos-pro' ),
				count( $results )
			) . self::DISCLAIMER,
			'data'       => array(
				'results_count' => count( $results ),
				'results'       => $results,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
