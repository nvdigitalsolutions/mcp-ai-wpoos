<?php
/**
 * NV oOS Skote — Pro Bridge
 *
 * Detects the NV oOS Pro addon and prepares the SPA to surface Pro features
 * (Workflow Builder, Tool Registry, HITL inbox, observability cards). The
 * actual wiring lives in the REST workflows controller; this class is the
 * single place to add cross-cutting adjustments such as adding Pro-only
 * fields to the localized payload.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro integration bridge.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_Pro_Bridge {

	/**
	 * Initialise hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'nvoos_skote_localized_payload', array( __CLASS__, 'add_pro_payload' ), 10, 2 );
	}

	/**
	 * Add Pro-only fields to the SPA payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array $payload Default payload.
	 * @param array $context Mount context.
	 *
	 * @return array
	 */
	public static function add_pro_payload( $payload, $context ) {
		unset( $context );
		if ( ! NV_oOS_Skote::is_pro_active() ) {
			return $payload;
		}
		$payload['pro'] = array(
			'workflowBuilder' => admin_url( 'admin.php?page=wp-mcp-ai-workflow-builder' ),
			'orchestration'   => admin_url( 'admin.php?page=wp-mcp-ai-orchestration' ),
		);
		return $payload;
	}
}
