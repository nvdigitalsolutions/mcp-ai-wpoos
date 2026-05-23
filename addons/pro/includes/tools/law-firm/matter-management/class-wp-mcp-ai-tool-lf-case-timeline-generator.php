<?php
/**
 * Case Timeline Generator Tool
 *
 * Generates a chronological timeline of events for a legal matter.
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
 * Generates chronological case timelines from matter data.
 */
class WP_MCP_AI_Tool_LF_Case_Timeline_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_case_timeline_generator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Case Timeline Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates a chronological timeline of events for a matter by aggregating deadlines, filings, communications, and tasks into a unified view.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The matter ID to generate a timeline for.', 'mcp-ai-wpoos-pro' ),
				),
				'include_deadlines' => array(
					'type'        => 'boolean',
					'description' => __( 'Include court deadlines in the timeline.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_filings'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include filings and documents in the timeline.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'matter_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$matter_id         = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$include_deadlines = ! isset( $arguments['include_deadlines'] ) || ! empty( $arguments['include_deadlines'] );
		$include_filings   = ! isset( $arguments['include_filings'] ) || ! empty( $arguments['include_filings'] );

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$events = array();

		// Matter creation event.
		$created = get_post_meta( $matter_id, '_lf_created_date', true );
		if ( $created ) {
			$events[] = array(
				'date'        => $created,
				'type'        => 'matter_created',
				'description' => __( 'Matter opened', 'mcp-ai-wpoos-pro' ),
				'details'     => $matter->post_title,
			);
		}

		// Deadlines.
		if ( $include_deadlines ) {
			$deadlines = get_post_meta( $matter_id, '_lf_deadlines', true );
			if ( is_array( $deadlines ) ) {
				foreach ( $deadlines as $dl ) {
					$events[] = array(
						'date'        => $dl['date'] ?? '',
						'type'        => 'deadline',
						'description' => $dl['description'] ?? '',
						'details'     => array(
							'priority'  => $dl['priority'] ?? 'medium',
							'completed' => ! empty( $dl['completed'] ),
							'rule_type' => $dl['rule_type'] ?? '',
						),
					);
				}
			}
		}

		// Filings / documents.
		if ( $include_filings ) {
			$filings = get_post_meta( $matter_id, '_lf_filings', true );
			if ( is_array( $filings ) ) {
				foreach ( $filings as $filing ) {
					$events[] = array(
						'date'        => $filing['date'] ?? '',
						'type'        => 'filing',
						'description' => $filing['description'] ?? '',
						'details'     => $filing,
					);
				}
			}
		}

		// Tasks.
		$tasks = get_post_meta( $matter_id, '_lf_tasks', true );
		if ( is_array( $tasks ) ) {
			foreach ( $tasks as $task ) {
				$task_date = $task['due_date'] ?? ( $task['created_at'] ?? '' );
				if ( $task_date ) {
					$events[] = array(
						'date'        => $task_date,
						'type'        => 'task',
						'description' => $task['description'] ?? '',
						'details'     => array(
							'status'   => ! empty( $task['completed'] ) ? 'completed' : 'pending',
							'priority' => $task['priority'] ?? 'medium',
						),
					);
				}
			}
		}

		// Communications linked to this matter.
		$client_id = get_post_meta( $matter_id, '_lf_client_id', true );
		if ( $client_id ) {
			$comms = get_post_meta( absint( $client_id ), '_lf_communications', true );
			if ( is_array( $comms ) ) {
				foreach ( $comms as $comm ) {
					if ( ! empty( $comm['matter_id'] ) && absint( $comm['matter_id'] ) === $matter_id ) {
						$events[] = array(
							'date'        => $comm['date'] ?? '',
							'type'        => 'communication',
							'description' => $comm['summary'] ?? '',
							'details'     => array(
								'comm_type'    => $comm['type'] ?? '',
								'participants' => $comm['participants'] ?? array(),
							),
						);
					}
				}
			}
		}

		// Sort chronologically.
		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of timeline events */
				__( 'Timeline generated with %d events. ', 'mcp-ai-wpoos-pro' ),
				count( $events )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'       => $matter_id,
				'matter_title'    => $matter->post_title,
				'timeline_events' => $events,
				'event_count'     => count( $events ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
