<?php
/**
 * REST controller for the console Records feature (Proposal 084 Phase 4,
 * W11 — CRM command center pilot).
 *
 * Serves the per-entity configuration the generic Records page consumes
 * (GET /mcp-ai-pro/v1/records/schema) and exposes each entity's private
 * `_*` meta keys through the core wp/v2 REST so the `wp-core` storage
 * strategy from the Phase 4 storage audit works end-to-end:
 *   - GET  /mcp-ai-pro/v1/records/schema — entity configs (labels, columns)
 *   - data read/write stays on core REST: wp/v2/{post_type} (meta included)
 *
 * Entity config is built from WP_MCP_AI_Pro_CPT_Meta_Schema — the PHP
 * schema registry stays the single source of truth; this controller only
 * maps entity keys to post types and labels.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records REST controller — schema endpoint + meta-in-REST registration.
 */
class WP_MCP_AI_Pro_Records_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/records';

	/**
	 * Record entities exposed to the console (W11/W12: the CRM + PM
	 * entities and professions — their meta schemas live in
	 * WP_MCP_AI_Pro_CPT_Meta_Schema). `group` partitions the Records page
	 * per command center (the nav registers one route per group).
	 *
	 * @var array<string, array<string, string>>
	 */
	const RECORD_ENTITIES = array(
		'company'    => array(
			'post_type' => 'mcp_ai_company',
			'label'     => 'Companies',
			'singular'  => 'Company',
			'icon'      => '🏢',
			'group'     => 'crm',
		),
		'customer'   => array(
			'post_type' => 'mcp_ai_customer',
			'label'     => 'Customers',
			'singular'  => 'Customer',
			'icon'      => '🧑',
			'group'     => 'crm',
		),
		'deal'       => array(
			'post_type' => 'mcp_ai_deal',
			'label'     => 'Deals',
			'singular'  => 'Deal',
			'icon'      => '🤝',
			'group'     => 'crm',
		),
		'lead'       => array(
			'post_type' => 'mcp_ai_lead',
			'label'     => 'Leads',
			'singular'  => 'Lead',
			'icon'      => '🔍',
			'group'     => 'crm',
		),
		'ticket'     => array(
			'post_type' => 'mcp_ai_ticket',
			'label'     => 'Tickets',
			'singular'  => 'Ticket',
			'icon'      => '🎫',
			'group'     => 'crm',
		),
		'project'    => array(
			'post_type' => 'mcp_ai_project',
			'label'     => 'Projects',
			'singular'  => 'Project',
			'icon'      => '📁',
			'group'     => 'pm',
		),
		'task'       => array(
			'post_type' => 'mcp_ai_task',
			'label'     => 'Tasks',
			'singular'  => 'Task',
			'icon'      => '✅',
			'group'     => 'pm',
		),
		'event'      => array(
			'post_type' => 'mcp_ai_event',
			'label'     => 'Events',
			'singular'  => 'Event',
			'icon'      => '📅',
			'group'     => 'pm',
		),
		'profession' => array(
			'post_type' => 'mcp_ai_profession',
			'label'     => 'Professions',
			'singular'  => 'Profession',
			'icon'      => '👔',
			'group'     => 'professions',
		),
	);

	/**
	 * Boot the controller (idempotent).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		// Priority 20: after the CRM CPTs register themselves on init (10).
		add_action( 'init', array( $controller, 'register_meta_in_rest' ), 20 );
	}

	/**
	 * Register the schema + research routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<entity>[a-z0-9_-]+)/research',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'research' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'entity' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'query'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission check — the records schema is administrator-only (the
	 * console surfaces are manage_options-gated).
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /records/schema — entity configs for the generic Records page.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_schema( WP_REST_Request $request ) {
		// The schema is static per entity catalogue; the request carries no
		// parameters yet. Kept in the signature for the REST callback API.
		unset( $request );

		$entities = array();

		foreach ( self::RECORD_ENTITIES as $key => $config ) {
			$post_type = $config['post_type'];
			if ( ! post_type_exists( $post_type ) || ! post_type_supports( $post_type, 'title' ) ) {
				continue;
			}

			$columns = array();
			if ( class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
				$columns = $this->build_columns( WP_MCP_AI_Pro_CPT_Meta_Schema::get( $post_type ) );
			}

			$entities[ $key ] = array(
				'key'      => $key,
				'postType' => $post_type,
				'restBase' => $post_type,
				'label'    => $config['label'],
				'singular' => $config['singular'],
				'icon'     => $config['icon'],
				'group'    => isset( $config['group'] ) ? sanitize_key( $config['group'] ) : 'records',
				'columns'  => $columns,
			);
		}

		return rest_ensure_response( array( 'entities' => $entities ) );
	}

	/**
	 * Build the ordered column list for one entity from its meta schema.
	 *
	 * @since 2.4.0
	 * @param array $meta_schema Meta schema from WP_MCP_AI_Pro_CPT_Meta_Schema.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_columns( $meta_schema ) {
		$columns = array();

		if ( ! is_array( $meta_schema ) ) {
			return $columns;
		}

		foreach ( $meta_schema as $key => $field ) {
			if ( ! is_array( $field ) || empty( $field['meta_key'] ) ) {
				continue;
			}

			$column = array(
				'key'   => sanitize_key( $field['meta_key'] ),
				'label' => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key,
				'type'  => isset( $field['type'] ) && is_string( $field['type'] )
					? sanitize_key( $field['type'] )
					: 'string',
			);

			if ( isset( $field['enum'] ) && is_array( $field['enum'] ) ) {
				$column['enum'] = array_map( 'sanitize_text_field', $field['enum'] );
			}

			$columns[] = $column;
		}

		return $columns;
	}

	/**
	 * Expose every schema meta key through the core wp/v2 REST.
	 *
	 * Without this, wp/v2/{post_type} responses carry no `meta` (the
	 * `_*`-prefixed keys are private), and the audit's wp-core strategy
	 * could not render anything but title/content. Sanitizers mirror the
	 * schema types.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_meta_in_rest() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			return;
		}

		foreach ( self::RECORD_ENTITIES as $config ) {
			$post_type = $config['post_type'];
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			$meta_schema = WP_MCP_AI_Pro_CPT_Meta_Schema::get( $post_type );
			foreach ( $meta_schema as $field ) {
				if ( ! is_array( $field ) || empty( $field['meta_key'] ) ) {
					continue;
				}
				// Skip keys the owning CPT already registered (e.g. the
				// profession CPT exposes its own meta in REST) — re-registering
				// would trip the register_meta doing-it-wrong notice.
				if ( registered_meta_key_exists( 'post', $field['meta_key'], $post_type ) ) {
					continue;
				}
				$this->register_meta_key( $post_type, $field );
			}
		}
	}

	/**
	 * Register a single meta key with REST exposure and a type-matched
	 * sanitizer.
	 *
	 * @since 2.4.0
	 * @param string $post_type Post type slug.
	 * @param array  $field     Schema field definition.
	 * @return void
	 */
	private function register_meta_key( $post_type, $field ) {
		$meta_key = sanitize_key( $field['meta_key'] );
		$type     = isset( $field['type'] ) && is_string( $field['type'] )
			? sanitize_key( $field['type'] )
			: 'string';

		switch ( $type ) {
			case 'integer':
				$rest_type    = 'integer';
				$sanitizer    = 'absint';
				$show_in_rest = true;
				break;
			case 'number':
				$rest_type    = 'number';
				$sanitizer    = 'floatval';
				$show_in_rest = true;
				break;
			case 'boolean':
				$rest_type    = 'boolean';
				$sanitizer    = 'rest_sanitize_boolean';
				$show_in_rest = true;
				break;
			case 'array':
				$rest_type = 'array';
				$sanitizer = array( $this, 'sanitize_meta_deep' );
				// WP 5.3+ requires an items schema for REST-exposed arrays.
				$show_in_rest = array(
					'schema' => array(
						'items' => array( 'type' => 'string' ),
					),
				);
				break;
			case 'object':
				$rest_type    = 'object';
				$sanitizer    = array( $this, 'sanitize_meta_deep' );
				$show_in_rest = true;
				break;
			default:
				$rest_type    = 'string';
				$sanitizer    = 'sanitize_text_field';
				$show_in_rest = true;
		}

		register_post_meta(
			$post_type,
			$meta_key,
			array(
				'type'              => $rest_type,
				'single'            => true,
				'show_in_rest'      => $show_in_rest,
				'sanitize_callback' => $sanitizer,
			)
		);
	}

	/**
	 * POST /records/{entity}/research — research a query and map the
	 * report onto the entity's schema fields (no record is created).
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function research( WP_REST_Request $request ) {
		$entity_key = $request->get_param( 'entity' );
		$query      = trim( (string) $request->get_param( 'query' ) );

		// The args `required` gate serves dispatch; these guards keep direct
		// calls honest (and give the PHPUnit suite deterministic paths).
		if ( ! isset( self::RECORD_ENTITIES[ $entity_key ] ) ) {
			return new WP_Error(
				'records_unknown_entity',
				__( 'Unknown record entity.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $query ) {
			return new WP_Error(
				'records_missing_query',
				__( 'A research query is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}
		$query = substr( $query, 0, 200 );

		$config    = self::RECORD_ENTITIES[ $entity_key ];
		$post_type = $config['post_type'];
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'records_entity_unavailable',
				__( 'The entity is not available on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		// Entity config for the extractor (labels + column types).
		$entity_config = array(
			'label'   => $config['label'],
			'columns' => class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' )
				? $this->build_columns( WP_MCP_AI_Pro_CPT_Meta_Schema::get( $post_type ) )
				: array(),
		);

		// Step 1 — the base deep-research tool is the product's research
		// engine (provider chains, retries, caching) — no reimplementation.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'deep_research' );
		if ( ! $tool ) {
			return new WP_Error(
				'records_research_unavailable',
				__( 'Deep research is not available on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$result = $tool->execute(
			array(
				'topic'                => sprintf(
					/* translators: 1: entity label, 2: query. */
					__( '%1$s: %2$s', 'mcp-ai-wpoos-pro' ),
					$config['label'],
					$query
				),
				'depth'                => 'basic',
				'include_sources'      => false,
				'include_site_content' => false,
				'run_mode'             => 'immediate',
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$report = isset( $result['report'] ) && is_string( $result['report'] ) ? $result['report'] : '';
		if ( '' === $report ) {
			return new WP_Error(
				'records_empty_report',
				__( 'The research pass produced no report.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 502 )
			);
		}

		// Step 2 — map the report onto the entity's schema fields.
		$extractor_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-records-research-extractor.php';
		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_Research_Extractor' ) && file_exists( $extractor_file ) ) {
			require_once $extractor_file;
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_Research_Extractor' ) ) {
			return new WP_Error(
				'records_extractor_unavailable',
				__( 'The research extractor is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$extractor = new WP_MCP_AI_Pro_Records_Research_Extractor();
		$fields    = $extractor->extract_fields( $entity_config, $query, $report );
		if ( is_wp_error( $fields ) ) {
			return new WP_Error(
				$fields->get_error_code(),
				$fields->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'entity' => $entity_key,
				'query'  => $query,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Deep sanitizer for array/object meta: stringify leaves, sanitize keys.
	 *
	 * @since 2.4.0
	 * @param mixed $value Raw meta value.
	 * @return mixed
	 */
	public function sanitize_meta_deep( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( $key ) ] = $this->sanitize_meta_deep( $item );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}

		return '';
	}
}
