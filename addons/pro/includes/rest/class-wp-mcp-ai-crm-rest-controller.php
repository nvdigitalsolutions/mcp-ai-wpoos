<?php
/**
 * CRM REST Controller
 *
 * Exposes CRM contacts, deals, and pipeline data via REST API for
 * the Toolkit Shell SPA (table/kanban views) and external integrations.
 *
 * Routes:
 *   GET  /mcp-ai-pro/v1/crm/contacts
 *   GET  /mcp-ai-pro/v1/crm/contacts/{id}
 *   GET  /mcp-ai-pro/v1/crm/deals
 *   GET  /mcp-ai-pro/v1/crm/deals/{id}
 *   GET  /mcp-ai-pro/v1/crm/pipeline  (aggregated pipeline stats)
 *   GET  /mcp-ai-pro/v1/crm/kpis      (key performance indicators)
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.24
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM REST Controller.
 */
class WP_MCP_AI_CRM_REST_Controller {

	/**
	 * REST namespace — matches toolkit-spa manifest.
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Singleton.
	 *
	 * @var WP_MCP_AI_CRM_REST_Controller|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return WP_MCP_AI_CRM_REST_Controller
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook setup.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Contacts — collection & single.
		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/contacts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contacts' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/contacts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_contact' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Deals — collection & single.
		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/deals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_deals' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/deals/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_deal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Pipeline — aggregated view.
		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/pipeline',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pipeline' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// KPIs — high-level metrics.
		register_rest_route(
			self::REST_NAMESPACE,
			'/crm/kpis',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_kpis' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission check: require edit_posts capability.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access CRM data.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Get collection query args shared by contacts and deals.
	 *
	 * @return array
	 */
	private function get_collection_args() {
		return array(
			'page'     => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'default'           => 'date',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * GET /crm/contacts — list contacts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_contacts( $request ) {
		$args = array(
			'post_type'      => array( 'mcp_ai_lead' ),
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
			'orderby'        => $request->get_param( 'orderby' ),
			'order'          => $request->get_param( 'order' ),
		);

		if ( $request->get_param( 'search' ) ) {
			$args['s'] = $request->get_param( 'search' );
		}

		$query   = new WP_Query( $args );
		$items   = array();
		$post_ids = wp_list_pluck( $query->posts, 'ID' );

		// Batch-load all post meta to avoid N+1 queries.
		$meta_cache = array();
		foreach ( $post_ids as $pid ) {
			$meta_cache[ $pid ] = get_post_meta( $pid );
		}

		foreach ( $query->posts as $post ) {
			$meta  = $meta_cache[ $post->ID ] ?? array();
			$items[] = array(
				'id'         => $post->ID,
				'full_name'  => $post->post_title,
				'email'      => $this->meta_val( $meta, 'email' ),
				'phone'      => $this->meta_val( $meta, 'phone' ),
				'company'    => $this->meta_val( $meta, 'company' ),
				'stage'      => $this->meta_val( $meta, 'lead_status', 'new' ),
				'owner_id'   => (int) $this->meta_val( $meta, 'contact_owner', 0 ),
				'tags'       => '',
				'created_at' => $post->post_date_gmt,
			);
		}

		$response = rest_ensure_response(
			array(
				'items'      => $items,
				'total'      => (int) $query->found_posts,
				'page'       => (int) $args['paged'],
				'per_page'   => (int) $args['posts_per_page'],
			)
		);
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * GET /crm/contacts/{id} — single contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_contact( $request ) {
		$post = get_post( $request->get_param( 'id' ) );

		if ( ! $post || 'mcp_ai_lead' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$meta = get_post_meta( $post->ID );

		return rest_ensure_response(
			array(
				'id'         => $post->ID,
				'full_name'  => $post->post_title,
				'email'      => $this->meta_val( $meta, 'email' ),
				'phone'      => $this->meta_val( $meta, 'phone' ),
				'company'    => $this->meta_val( $meta, 'company' ),
				'stage'      => $this->meta_val( $meta, 'lead_status', 'new' ),
				'owner_id'   => (int) $this->meta_val( $meta, 'contact_owner', 0 ),
				'tags'       => '',
				'created_at' => $post->post_date_gmt,
			)
		);
	}

	/**
	 * GET /crm/deals — list deals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_deals( $request ) {
		$args = array(
			'post_type'      => 'mcp_ai_deal',
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
			'orderby'        => $request->get_param( 'orderby' ),
			'order'          => $request->get_param( 'order' ),
		);

		if ( $request->get_param( 'search' ) ) {
			$args['s'] = $request->get_param( 'search' );
		}

		$query   = new WP_Query( $args );
		$items   = array();
		$post_ids = wp_list_pluck( $query->posts, 'ID' );

		$meta_cache = array();
		foreach ( $post_ids as $pid ) {
			$meta_cache[ $pid ] = get_post_meta( $pid );
		}

		foreach ( $query->posts as $post ) {
			$meta  = $meta_cache[ $post->ID ] ?? array();
			$items[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'amount'     => (float) $this->meta_val( $meta, 'deal_amount', 0 ),
				'currency'   => $this->meta_val( $meta, 'deal_currency', 'USD' ),
				'stage'      => $this->meta_val( $meta, 'deal_stage', 'prospecting' ),
				'contact_id' => (int) $this->meta_val( $meta, 'contact_id', 0 ),
				'close_date' => $this->meta_val( $meta, 'close_date' ),
				'notes'      => '',
			);
		}

		$response = rest_ensure_response(
			array(
				'items'      => $items,
				'total'      => (int) $query->found_posts,
				'page'       => (int) $args['paged'],
				'per_page'   => (int) $args['posts_per_page'],
			)
		);
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * GET /crm/deals/{id} — single deal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_deal( $request ) {
		$post = get_post( $request->get_param( 'id' ) );

		if ( ! $post || 'mcp_ai_deal' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Deal not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$meta = get_post_meta( $post->ID );

		return rest_ensure_response(
			array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'amount'     => (float) $this->meta_val( $meta, 'deal_amount', 0 ),
				'currency'   => $this->meta_val( $meta, 'deal_currency', 'USD' ),
				'stage'      => $this->meta_val( $meta, 'deal_stage', 'prospecting' ),
				'contact_id' => (int) $this->meta_val( $meta, 'contact_id', 0 ),
				'close_date' => $this->meta_val( $meta, 'close_date' ),
				'notes'      => '',
			)
		);
	}

	/**
	 * GET /crm/pipeline — aggregated pipeline view by stage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pipeline( $request ) {
		$stages = array(
			'prospecting',
			'qualification',
			'needs_analysis',
			'value_proposition',
			'decision_makers',
			'perception_analysis',
			'proposal',
			'negotiation',
			'closed_won',
			'closed_lost',
		);

		$result = array();

		foreach ( $stages as $stage ) {
			$posts = get_posts(
				array(
					'post_type'      => 'mcp_ai_deal',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_key'       => 'deal_stage',
					'meta_value'     => $stage,
					'fields'         => 'ids',
				)
			);

			$total_value = 0;
			foreach ( $posts as $post_id ) {
				$total_value += (float) get_post_meta( $post_id, 'deal_amount', true );
			}

			$result[] = array(
				'stage' => $stage,
				'count' => count( $posts ),
				'value' => $total_value,
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /crm/kpis — key performance indicators.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_kpis( $request ) {
		$lead_count    = wp_count_posts( 'mcp_ai_lead' )->publish ?? 0;
		$deal_count    = wp_count_posts( 'mcp_ai_deal' )->publish ?? 0;
		$company_count = wp_count_posts( 'mcp_ai_company' )->publish ?? 0;

		// Weighted pipeline value.
		$deals = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$pipeline_total  = 0;
		$weighted_total  = 0;
		$won_total       = 0;

		foreach ( $deals as $deal_id ) {
			$amount      = (float) get_post_meta( $deal_id, 'deal_amount', true );
			$probability = (float) get_post_meta( $deal_id, 'deal_probability', true );
			$stage       = get_post_meta( $deal_id, 'deal_stage', true );

			$pipeline_total += $amount;
			$weighted_total += $amount * max( 0, min( 1, $probability ) );

			if ( 'closed_won' === $stage ) {
				$won_total += $amount;
			}
		}

		// Conversion rate.
		$conversion_rate = $deal_count > 0 ? round( ( $won_total > 0 ? 1 : 0 ) * 100 ) : 0;

		// Activity count (last 30 days).
		$recent_activities = get_posts(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'after' => '30 days ago' ),
				),
			)
		);

		return rest_ensure_response(
			array(
				'total_leads'       => (int) $lead_count,
				'total_deals'       => (int) $deal_count,
				'total_companies'   => (int) $company_count,
				'pipeline_value'    => $pipeline_total,
				'weighted_value'    => $weighted_total,
				'won_value'         => $won_total,
				'conversion_rate'   => $conversion_rate,
				'recent_activities' => count( $recent_activities ),
			)
		);
	}

	/**
	 * Safely extract a meta value from the batch-loaded meta array.
	 *
	 * @param array  $meta    Raw get_post_meta() result.
	 * @param string $key     Meta key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	private function meta_val( $meta, $key, $default = '' ) {
		if ( isset( $meta[ $key ][0] ) ) {
			return $meta[ $key ][0];
		}
		return $default;
	}
}
