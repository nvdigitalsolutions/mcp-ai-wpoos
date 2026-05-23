<?php
/**
 * Trial Preparation Checklist Tool
 *
 * Generates and manages trial preparation checklists for litigation matters,
 * with practice-area-specific items and completion tracking.
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
 * Generates and manages trial preparation checklists on matter posts.
 */
class WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_trial_preparation_checklist';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Trial Preparation Checklist', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates and manages trial preparation checklists for litigation matters. Supports generating practice-area-specific checklists, retrieving current status, and updating individual items.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Action to perform on the checklist.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'generate', 'get', 'update_item' ),
				),
				'matter_id'     => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the matter.', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Practice area for checklist generation (e.g., personal_injury, contract).', 'mcp-ai-wpoos-pro' ),
				),
				'trial_date'    => array(
					'type'        => 'string',
					'description' => __( 'Scheduled trial date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'item_id'       => array(
					'type'        => 'string',
					'description' => __( 'Checklist item ID for update_item action.', 'mcp-ai-wpoos-pro' ),
				),
				'completed'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the checklist item is completed.', 'mcp-ai-wpoos-pro' ),
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

		$meta_key = '_lf_trial_checklist';

		switch ( $action ) {
			case 'generate':
				return $this->handle_generate( $arguments, $matter_id, $meta_key );

			case 'get':
				return $this->handle_get( $matter_id, $meta_key );

			case 'update_item':
				return $this->handle_update_item( $arguments, $matter_id, $meta_key );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle generating a new trial checklist.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array
	 */
	private function handle_generate( array $arguments, int $matter_id, string $meta_key ) {
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : 'general';
		$trial_date    = isset( $arguments['trial_date'] ) ? sanitize_text_field( $arguments['trial_date'] ) : '';

		// Core checklist items applicable to all practice areas.
		$core_items = array(
			array(
				'category' => __( 'Pre-Trial Motions', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'File motions in limine', 'mcp-ai-wpoos-pro' ),
				'deadline' => '30 days before trial',
			),
			array(
				'category' => __( 'Pre-Trial Motions', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Respond to opposing motions in limine', 'mcp-ai-wpoos-pro' ),
				'deadline' => '21 days before trial',
			),
			array(
				'category' => __( 'Witness Preparation', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Prepare and finalize witness list', 'mcp-ai-wpoos-pro' ),
				'deadline' => '30 days before trial',
			),
			array(
				'category' => __( 'Witness Preparation', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Complete witness preparation sessions', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Witness Preparation', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Subpoena all non-party witnesses', 'mcp-ai-wpoos-pro' ),
				'deadline' => '21 days before trial',
			),
			array(
				'category' => __( 'Exhibits', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Prepare and organize exhibit binders', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Exhibits', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Exchange exhibit lists with opposing counsel', 'mcp-ai-wpoos-pro' ),
				'deadline' => '21 days before trial',
			),
			array(
				'category' => __( 'Exhibits', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Pre-mark all exhibits', 'mcp-ai-wpoos-pro' ),
				'deadline' => '7 days before trial',
			),
			array(
				'category' => __( 'Jury', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Prepare voir dire questions', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Jury', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Draft proposed jury instructions', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Jury', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Prepare verdict form', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Trial Briefs', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Draft opening statement outline', 'mcp-ai-wpoos-pro' ),
				'deadline' => '7 days before trial',
			),
			array(
				'category' => __( 'Trial Briefs', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Draft closing argument outline', 'mcp-ai-wpoos-pro' ),
				'deadline' => '3 days before trial',
			),
			array(
				'category' => __( 'Trial Briefs', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'File trial brief', 'mcp-ai-wpoos-pro' ),
				'deadline' => '14 days before trial',
			),
			array(
				'category' => __( 'Logistics', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Confirm courtroom technology and equipment', 'mcp-ai-wpoos-pro' ),
				'deadline' => '7 days before trial',
			),
			array(
				'category' => __( 'Logistics', 'mcp-ai-wpoos-pro' ),
				'task'     => __( 'Attend pre-trial conference', 'mcp-ai-wpoos-pro' ),
				'deadline' => 'As scheduled by court',
			),
		);

		// Practice-area-specific items.
		$area_items = array(
			'personal_injury' => array(
				array(
					'category' => __( 'Damages', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Obtain updated medical records and bills', 'mcp-ai-wpoos-pro' ),
					'deadline' => '30 days before trial',
				),
				array(
					'category' => __( 'Damages', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Prepare damages summary chart for jury', 'mcp-ai-wpoos-pro' ),
					'deadline' => '14 days before trial',
				),
				array(
					'category' => __( 'Experts', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Confirm medical expert availability and prepare direct examination', 'mcp-ai-wpoos-pro' ),
					'deadline' => '21 days before trial',
				),
			),
			'contract'        => array(
				array(
					'category' => __( 'Documents', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Prepare contract timeline exhibit', 'mcp-ai-wpoos-pro' ),
					'deadline' => '14 days before trial',
				),
				array(
					'category' => __( 'Damages', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Finalize damages calculation with supporting documents', 'mcp-ai-wpoos-pro' ),
					'deadline' => '21 days before trial',
				),
			),
			'employment'      => array(
				array(
					'category' => __( 'Documents', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Compile personnel file and employment records', 'mcp-ai-wpoos-pro' ),
					'deadline' => '30 days before trial',
				),
				array(
					'category' => __( 'Witnesses', 'mcp-ai-wpoos-pro' ),
					'task'     => __( 'Prepare HR representative for testimony', 'mcp-ai-wpoos-pro' ),
					'deadline' => '14 days before trial',
				),
			),
		);

		$specific  = $area_items[ $practice_area ] ?? array();
		$all_items = array_merge( $core_items, $specific );

		// Build checklist with IDs and completion tracking.
		$checklist = array(
			'matter_id'     => $matter_id,
			'practice_area' => $practice_area,
			'trial_date'    => $trial_date,
			'generated_at'  => current_time( 'Y-m-d H:i:s' ),
			'generated_by'  => get_current_user_id(),
			'items'         => array(),
		);

		foreach ( $all_items as $idx => $item ) {
			$checklist['items'][] = array(
				'item_id'      => 'chk_' . ( $idx + 1 ) . '_' . substr( wp_generate_uuid4(), 0, 8 ),
				'category'     => $item['category'],
				'task'         => $item['task'],
				'deadline'     => $item['deadline'],
				'completed'    => false,
				'completed_at' => null,
			);
		}

		update_post_meta( $matter_id, $meta_key, $checklist );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: item count, 2: practice area */
				__( 'Generated trial preparation checklist with %1$d items for %2$s practice area. ', 'mcp-ai-wpoos-pro' ),
				count( $checklist['items'] ),
				$practice_area
			) . self::DISCLAIMER,
			'data'       => $checklist,
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle getting the current checklist status.
	 *
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_get( int $matter_id, string $meta_key ) {
		$checklist = get_post_meta( $matter_id, $meta_key, true );
		if ( empty( $checklist ) || ! is_array( $checklist ) ) {
			return new WP_Error( 'not_found', __( 'No trial checklist found for this matter. Use the generate action first.', 'mcp-ai-wpoos-pro' ) );
		}

		$items       = $checklist['items'] ?? array();
		$total       = count( $items );
		$completed   = 0;
		$by_category = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['completed'] ) ) {
				++$completed;
			}
			$cat = $item['category'] ?? __( 'Uncategorized', 'mcp-ai-wpoos-pro' );
			if ( ! isset( $by_category[ $cat ] ) ) {
				$by_category[ $cat ] = array(
					'total'     => 0,
					'completed' => 0,
				);
			}
			++$by_category[ $cat ]['total'];
			if ( ! empty( $item['completed'] ) ) {
				++$by_category[ $cat ]['completed'];
			}
		}

		$progress = $total > 0 ? round( ( $completed / $total ) * 100, 1 ) : 0;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: completed count, 2: total count, 3: progress percent */
				__( 'Trial checklist: %1$d of %2$d items completed (%3$s%%). ', 'mcp-ai-wpoos-pro' ),
				$completed,
				$total,
				$progress
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'       => $matter_id,
				'practice_area'   => $checklist['practice_area'] ?? '',
				'trial_date'      => $checklist['trial_date'] ?? '',
				'total_items'     => $total,
				'completed_items' => $completed,
				'progress_pct'    => $progress,
				'by_category'     => $by_category,
				'items'           => $items,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Handle updating a single checklist item.
	 *
	 * @param array  $arguments Function arguments.
	 * @param int    $matter_id Matter post ID.
	 * @param string $meta_key  Meta key.
	 * @return array|WP_Error
	 */
	private function handle_update_item( array $arguments, int $matter_id, string $meta_key ) {
		$item_id   = isset( $arguments['item_id'] ) ? sanitize_text_field( $arguments['item_id'] ) : '';
		$completed = isset( $arguments['completed'] ) ? (bool) $arguments['completed'] : false;

		if ( empty( $item_id ) ) {
			return new WP_Error( 'missing_fields', __( 'Item ID is required for updates.', 'mcp-ai-wpoos-pro' ) );
		}

		$checklist = get_post_meta( $matter_id, $meta_key, true );
		if ( empty( $checklist ) || ! is_array( $checklist ) ) {
			return new WP_Error( 'not_found', __( 'No trial checklist found for this matter.', 'mcp-ai-wpoos-pro' ) );
		}

		$found        = false;
		$updated_item = array();
		foreach ( $checklist['items'] as &$item ) {
			if ( ( $item['item_id'] ?? '' ) === $item_id ) {
				$item['completed']    = $completed;
				$item['completed_at'] = $completed ? current_time( 'Y-m-d H:i:s' ) : null;
				$found                = true;
				$updated_item         = $item;
				break;
			}
		}
		unset( $item );

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Checklist item not found.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $matter_id, $meta_key, $checklist );

		// Recalculate progress.
		$total = count( $checklist['items'] );
		$done  = 0;
		foreach ( $checklist['items'] as $i ) {
			if ( ! empty( $i['completed'] ) ) {
				++$done;
			}
		}
		$progress = $total > 0 ? round( ( $done / $total ) * 100, 1 ) : 0;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: item ID, 2: status */
				__( 'Checklist item %1$s marked as %2$s. ', 'mcp-ai-wpoos-pro' ),
				$item_id,
				$completed ? __( 'completed', 'mcp-ai-wpoos-pro' ) : __( 'incomplete', 'mcp-ai-wpoos-pro' )
			) . self::DISCLAIMER,
			'data'       => array(
				'item'         => $updated_item,
				'progress_pct' => $progress,
				'completed'    => $done,
				'total'        => $total,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
