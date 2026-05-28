<?php
/**
 * Tool for listing leads in the CRM system.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to list and filter leads in the CRM system.
 *
 * Supports filtering by lifecycle stage, lead score range, and contact owner.
 * Returns paginated results with score labels derived from the CRM engine.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_List_Leads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private $data_store;

	/**
	 * Determine whether the CRM toolkit is enabled.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The List Leads tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'crm', 'leads' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_leads';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List and filter leads in the CRM system. Supports filtering by lifecycle stage, lead score range, and contact owner. Returns paginated results with score labels (cold/warm/hot).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Filter by lifecycle stage (e.g. lead, mql, sal, sql, opportunity, customer).', 'mcp-ai-wpoos-pro' ),
				),
				'lead_score_min'  => array(
					'type'        => 'integer',
					'description' => __( 'Minimum lead score (0–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'lead_score_max'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum lead score (0–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by WordPress user ID of the contact owner.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of results per page.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'            => array(
					'type'        => 'integer',
					'description' => __( 'Page number (1-based).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'orderby'         => array(
					'type'        => 'string',
					'description' => __( 'Field to order results by.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'id', 'email', 'first_name', 'last_name', 'lead_score', 'lifecycle_stage', 'created_at', 'updated_at' ),
					'default'     => 'created_at',
				),
				'order'           => array(
					'type'        => 'string',
					'description' => __( 'Sort direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
				),
			),
			'additionalProperties' => false,
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
		if ( class_exists( 'WP_MCP_AI_CRM_Capabilities' ) ) {
			$map = WP_MCP_AI_CRM_Capabilities::get_map();
			return isset( $map['view_lead'] ) ? $map['view_lead'] : 'edit_posts';
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-capability',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 2.3.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'sdr', 'account_executive', 'crm_viewer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_crm_toolkit_disabled',
				__( 'CRM Toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->data_store ) {
			return new WP_Error(
				'wp_mcp_ai_crm_data_store_unavailable',
				__( 'CRM data store is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list leads.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// --- Gate 1: Sanitise at entry ---

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( 100, max( 1, $per_page ) );

		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$page = max( 1, $page );

		$orderby         = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'created_at';
		$allowed_orderby = array( 'id', 'email', 'first_name', 'last_name', 'lead_score', 'lifecycle_stage', 'created_at', 'updated_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}

		$order = isset( $arguments['order'] ) ? strtoupper( sanitize_text_field( $arguments['order'] ) ) : 'DESC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Build query args.
		$query_args = array(
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		// Apply filters.
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$stage = sanitize_key( $arguments['lifecycle_stage'] );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $stage ) ) {
				$query_args['lifecycle_stage'] = $stage;
			}
		}

		if ( isset( $arguments['lead_score_min'] ) ) {
			$query_args['lead_score_min'] = max( 0, min( 100, absint( $arguments['lead_score_min'] ) ) );
		}

		if ( isset( $arguments['lead_score_max'] ) ) {
			$query_args['lead_score_max'] = max( 0, min( 100, absint( $arguments['lead_score_max'] ) ) );
		}

		if ( ! empty( $arguments['contact_owner'] ) ) {
			$query_args['contact_owner'] = absint( $arguments['contact_owner'] );
		}

		// Query via data store.
		$result = $this->data_store->query_items( $query_args );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_query_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Enrich results with score labels.
		$leads = is_array( $result ) ? $result : array();
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			foreach ( $leads as &$lead ) {
				if ( is_array( $lead ) ) {
					$score               = isset( $lead['lead_score'] ) ? $lead['lead_score'] : null;
					$lead['score_label'] = WP_MCP_AI_CRM_Engine::score_label( $score );
				}
			}
			unset( $lead );
		}

		$count = count( $leads );

		// Record PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'leads_listed',
				'lead',
				'',
				array(
					'count'   => $count,
					'action'  => 'list',
					'filters' => wp_json_encode( $query_args ),
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of leads found */
				_n(
					'Found %d lead.',
					'Found %d leads.',
					$count,
					'mcp-ai-wpoos-pro'
				),
				$count
			),
			array(
				'leads'        => $leads,
				'count'        => $count,
				'page'         => $page,
				'per_page'     => $per_page,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
