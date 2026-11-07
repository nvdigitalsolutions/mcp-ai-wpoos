<?php
/**
 * Manage Sending Warmup — start, pause, resume or extend the ramp.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warmup management.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Manage_Sending_Warmup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'manage_sending_warmup';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Manage Sending Warmup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Start, pause, resume or extend the mailbox warmup ramp and return the current day, cap and status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'When a new sending domain or mailbox is added, or when the ramp must pause/resume.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Checking DNS; use check_sending_domain. A full readiness snapshot; use get_sending_readiness.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'check_sending_domain', 'get_sending_readiness' ),
			'notes'           => __( 'Pausing sets the daily cold cap to zero; replies are still allowed by the send-window policy.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action' => array(
					'type'    => 'string',
					'enum'    => array( 'start', 'pause', 'resume', 'extend', 'status' ),
					'default' => 'status',
				),
				'date'   => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d). Defaults to today (UTC).', 'mcp-ai-wpoos-pro' ),
				),
				'days'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 90,
					'description' => __( 'Days to add when extending the minimum warmup window.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Requires Base Pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Arguments.
	 * @param array $context   Context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		if ( ! class_exists( 'WP_MCP_AI_CRM_Deliverability' ) ) {
			return new WP_Error( 'engine_missing', __( 'Deliverability engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$action  = sanitize_key( $arguments['action'] ?? 'status' );
		$stored  = get_option( WP_MCP_AI_CRM_Deliverability::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$changed = false;

		switch ( $action ) {
			case 'start':
				$date = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
				$ts   = $date ? strtotime( $date . ' 00:00:00 UTC' ) : false;
				if ( ! $ts ) {
					$date = gmdate( 'Y-m-d' );
				}
				$stored['warmup_start_date'] = $date;
				$stored['warmup_paused']     = false;
				$changed                     = true;
				break;

			case 'pause':
				$stored['warmup_paused'] = true;
				$changed                 = true;
				break;

			case 'resume':
				$stored['warmup_paused'] = false;
				$changed                 = true;
				break;

			case 'extend':
				$days                      = isset( $arguments['days'] ) ? max( 1, min( 90, absint( $arguments['days'] ) ) ) : 7;
				$current                   = isset( $stored['warmup_min_days'] ) ? absint( $stored['warmup_min_days'] ) : (int) WP_MCP_AI_CRM_Deliverability::defaults()['warmup_min_days'];
				$stored['warmup_min_days'] = min( 120, $current + $days );
				$changed                   = true;
				break;

			case 'status':
				break;

			default:
				return new WP_Error( 'invalid_action', __( 'Unknown warmup action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $changed ) {
			update_option( WP_MCP_AI_CRM_Deliverability::OPTION, $stored, false );
			WP_MCP_AI_CRM_Deliverability::flush_cache();

			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record( 'sending_warmup_' . $action, 'settings', WP_MCP_AI_CRM_Deliverability::OPTION, $stored );
			}
		}

		$settings = WP_MCP_AI_CRM_Deliverability::get_settings();
		$day      = WP_MCP_AI_CRM_Deliverability::warmup_day();

		return array(
			'success'  => true,
			'message'  => __( 'Warmup updated.', 'mcp-ai-wpoos-pro' ),
			'action'   => $action,
			'day'      => $day,
			'min_days' => (int) $settings['warmup_min_days'],
			'paused'   => ! empty( $settings['warmup_paused'] ),
			'cap'      => WP_MCP_AI_CRM_Deliverability::daily_cap_for_mailbox(),
		);
	}
}
