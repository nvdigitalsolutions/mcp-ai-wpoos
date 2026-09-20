<?php
/**
 * Tool for creating tracked proposal/document links on deals.
 *
 * Generates an unguessable token, registers it in the link registry, stores
 * it on the deal, and returns a public tracking URL. When the prospect opens
 * the link, WP_MCP_AI_CRM_Link_Tracker records the open (see that class) —
 * "did the prospect open the proposal?" becomes a first-class sales signal.
 *
 * Inspired by JobNavigator's resume/letter tracer links.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Tracked Link Tool.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_Tool_Create_Tracked_Link implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Registry option key (non-autoloaded).
	 *
	 * @var string
	 */
	const REGISTRY_OPTION = 'wp_mcp_ai_crm_link_registry';

	/**
	 * Maximum tracked links retained per deal.
	 *
	 * @var int
	 */
	const MAX_LINKS_PER_DEAL = 50;

	/**
	 * Determine whether the tool is available.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 3.2.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Create Tracked Link tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_tracked_link';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Tracked Link', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a tracked link for a proposal or document attached to a deal. Records every open with a timestamp on the deal, so proposal engagement becomes a measurable sales signal.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating a tracked proposal or document link on a deal so opens are recorded as sales signals.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading the deal or its link history; use get_deal to fetch the deal record.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_deal', 'list_deals' ),
			'notes'           => __( 'Requires deal_id and an absolute http(s) url. Max 50 links per deal; each open is timestamped by the link tracker.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_id' => array(
					'type'        => 'integer',
					'description' => __( 'Deal ID the link belongs to (required).', 'mcp-ai-wpoos-pro' ),
				),
				'url'     => array(
					'type'        => 'string',
					'description' => __( 'Destination URL the prospect should land on (required).', 'mcp-ai-wpoos-pro' ),
				),
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'Human label for the link (e.g. "Proposal PDF").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_id', 'url' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gateway 1: Sanitize inputs.
		$deal_id = isset( $arguments['deal_id'] ) ? absint( $arguments['deal_id'] ) : 0;
		$url     = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
		$label   = isset( $arguments['label'] ) ? sanitize_text_field( $arguments['label'] ) : '';

		if ( ! $deal_id || 'mcp_ai_deal' !== get_post_type( $deal_id ) ) {
			return new WP_Error(
				'deal_not_found',
				__( 'A valid deal ID is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === $url || ! preg_match( '~^https?://~i', $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'url must be an absolute http(s) URL.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Generate an unguessable token. Lowercased so the tracker's
		// sanitize_key() pass (which lowercases) is lossless on lookup.
		$token = strtolower( wp_generate_password( 24, false, false ) );

		$registry           = get_option( self::REGISTRY_OPTION, array() );
		$registry           = is_array( $registry ) ? $registry : array();
		$registry[ $token ] = array(
			'deal_id'        => $deal_id,
			'url'            => $url,
			'label'          => $label,
			'created_at'     => gmdate( 'c' ),
			'opens'          => 0,
			'last_opened_at' => '',
		);
		update_option( self::REGISTRY_OPTION, $registry, false );

		// Store the link on the deal itself for per-deal visibility.
		$links   = get_post_meta( $deal_id, 'tracked_links', true );
		$links   = is_array( $links ) ? $links : array();
		$links[] = array(
			'token'      => $token,
			'url'        => $url,
			'label'      => $label,
			'created_at' => gmdate( 'c' ),
			'opens'      => 0,
		);
		if ( count( $links ) > self::MAX_LINKS_PER_DEAL ) {
			$links = array_slice( $links, count( $links ) - self::MAX_LINKS_PER_DEAL );
		}
		update_post_meta( $deal_id, 'tracked_links', $links );

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'tracked_link_created',
				'deal',
				$deal_id,
				array(
					'label'  => $label,
					'action' => 'create_link',
				)
			);
		}

		$tracking_url = home_url( '/?nvoos_track=' . rawurlencode( $token ) );

		return $this->format_success_response(
			__( 'Tracked link created.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'      => $deal_id,
				'token'        => $token,
				'tracking_url' => esc_url( $tracking_url ),
				'destination'  => esc_url( $url ),
				'label'        => esc_html( $label ),
			)
		);
	}
}
