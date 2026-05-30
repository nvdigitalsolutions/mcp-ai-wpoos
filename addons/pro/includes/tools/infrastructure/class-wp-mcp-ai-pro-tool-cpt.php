<?php
/**
 * Toolkit CPT Tool – Generic CPT management for pro toolkit Custom Post Types.
 *
 * Mirrors the JetEngine CCT tool interface but operates on WordPress Custom
 * Post Types rather than JetEngine Custom Content Types. Works without any
 * third-party dependencies.
 *
 * Supported actions: list_types, get_schema, list_items, get_item,
 * create_item, update_item, delete_item, bulk_create.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic tool for querying and managing pro toolkit CPT entries.
 *
 * @since 3.6.0
 */
class WP_MCP_AI_Pro_Tool_CPT implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Top-level parameter keys that belong to the tool's own interface and
	 * must not be treated as CPT field values.
	 */
	const TOOL_PARAM_KEYS = array( 'action', 'post_type', 'item_id', 'per_page', 'page', 'search', 'filters', 'fields', 'items', 'orderby', 'order' );

	/**
	 * WordPress internal / system post types that this tool must never touch.
	 */
	const PROTECTED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'oembed_cache',
		'wp_pattern_category',
	);

	/**
	 * Standard post field keys accepted inside the 'fields' object.
	 * All other keys are treated as post meta.
	 */
	const POST_FIELD_KEYS = array( 'title', 'content', 'excerpt', 'status', 'date', 'author_id' );

	/**
	 * Allowed meta_query 'compare' operators.
	 */
	const ALLOWED_COMPARE = array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS' );

	/**
	 * Allowed meta_query 'type' values.
	 */
	const ALLOWED_META_TYPES = array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' );

	/**
	 * Noisy WordPress-internal post meta keys excluded from get_item output.
	 */
	const NOISY_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
		'_wp_attached_file',
		'_wp_attachment_metadata',
	);

	// -------------------------------------------------------------------------
	// Tool interface
	// -------------------------------------------------------------------------

	/**
	 * Check whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'toolkit_cpt';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Toolkit CPT', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Query and manage pro toolkit Custom Post Type (CPT) entries directly. Use this tool — instead of get_post / get_recent_posts — when you need to create, read, update, delete, or search records in any pro toolkit CPT such as mcp_ai_prescription, mcp_ai_member, mcp_ai_med_record, mcp_ai_allergy, mcp_ai_checkup, mcp_ai_policy, mcp_ai_place, mcp_ai_project, mcp_ai_task, mcp_ai_event, mcp_ai_company, and many more. Supports full CRUD, bulk import, meta-aware filtering, and schema discovery. Workflow: 1) call get_schema to discover all available meta field keys and types; 2) call list_items with filters to locate specific records and retrieve their post IDs; 3) call get_item, update_item, or delete_item using the returned ID.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'    => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_types (list all accessible CPTs), get_schema (field definitions for a CPT), list_items (paginated search with filters), get_item (full record by ID), create_item, update_item, delete_item, bulk_create. Always call get_schema before creating or updating to learn the correct meta_key names.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_types', 'get_schema', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item', 'bulk_create' ),
					'default'     => 'list_types',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'CPT post type slug, e.g. mcp_ai_prescription, mcp_ai_member, mcp_ai_place, mcp_ai_project. Required for all actions except list_types.', 'mcp-ai-wpoos-pro' ),
				),
				'item_id'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID for get_item, update_item, and delete_item.', 'mcp-ai-wpoos-pro' ),
				),
				'fields'    => array(
					'type'                 => 'object',
					'description'          => __( 'Field values for create_item or update_item. Standard post keys: title, content, excerpt, status, date, author_id. All other keys are saved as post meta using the exact key name from get_schema (e.g. _prescription_member_id, _prescription_dosage, _place_address). Example: {"title": "Lisinopril 10mg", "_prescription_member_id": 42, "_prescription_dosage": "10mg", "_prescription_frequency": "once daily"}.', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => true,
				),
				'items'     => array(
					'type'        => 'array',
					'description' => __( 'Array of field objects for bulk_create. Each element has the same structure as the "fields" parameter for create_item.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Full-text search query for list_items (searches post title and content).', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'filters'   => array(
					'type'        => 'array',
					'description' => __( 'Meta query filters for list_items. Each filter: {key: "_meta_key", value: "val", compare: "=" (optional, default =), type: "CHAR" (optional)}. Allowed compare values: =, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, EXISTS, NOT EXISTS. Example: [{"key": "_prescription_member_id", "value": "42"}, {"key": "_prescription_status", "value": "active"}].', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'key'     => array( 'type' => 'string' ),
							'value'   => array(
								'anyOf' => array(
									array( 'type' => 'string' ),
									array( 'type' => 'number' ),
									array( 'type' => 'boolean' ),
									array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									array( 'type' => 'null' ),
								),
							),
							'compare' => array( 'type' => 'string' ),
							'type'    => array( 'type' => 'string' ),
						),
						'required'   => array( 'key' ),
					),
				),
				'orderby'   => array(
					'type'        => 'string',
					'description' => __( 'Sort field for list_items: date, title, modified, ID, menu_order, rand. Default: date.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'date', 'title', 'modified', 'ID', 'menu_order', 'rand' ),
					'default'     => 'date',
				),
				'order'     => array(
					'type'        => 'string',
					'description' => __( 'Sort direction for list_items: ASC or DESC. Default: DESC.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Items per page for list_items. Default: 10. Max: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number for list_items. Default: 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'local-only',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'toolkit_cpt',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_manager', 'health_advisor', 'data_manager' ),
			'risk_level'            => 'standard',
		);
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_types';

		switch ( $action ) {
			case 'list_types':
				return $this->list_types( $context );
			case 'get_schema':
				return $this->get_schema( $arguments );
			case 'list_items':
				return $this->list_items( $arguments, $context );
			case 'get_item':
				return $this->get_item( $arguments, $context );
			case 'create_item':
				return $this->create_item( $arguments, $context );
			case 'bulk_create':
				return $this->bulk_create( $arguments, $context );
			case 'update_item':
				return $this->update_item( $arguments, $context );
			case 'delete_item':
				return $this->delete_item( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action. Allowed values: list_types, get_schema, list_items, get_item, create_item, update_item, delete_item, bulk_create.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action implementations
	// -------------------------------------------------------------------------

	/**
	 * List all non-internal registered CPTs with their metadata.
	 *
	 * @param array $context Execution context.
	 * @return array
	 */
	protected function list_types( $context ) {
		$all_types = array_merge(
			get_post_types( array( 'public' => false ), 'objects' ),
			get_post_types( array( 'public' => true ), 'objects' )
		);

		$result = array();

		foreach ( $all_types as $post_type => $pto ) {
			if ( in_array( $post_type, self::PROTECTED_POST_TYPES, true ) ) {
				continue;
			}

			// Skip built-in WordPress post types (post, page) – users can use base
			// tools for those. Only list CPTs registered by plugins/themes.
			if ( $pto->_builtin ) {
				continue;
			}

			$schema     = apply_filters( 'wp_mcp_ai_post_type_meta_schema', array(), $post_type );
			$taxonomies = get_object_taxonomies( $post_type );

			$result[] = array(
				'post_type'     => $post_type,
				'label'         => $pto->label,
				'description'   => $pto->description,
				'public'        => $pto->public,
				'show_in_rest'  => $pto->show_in_rest,
				'schema_fields' => count( $schema ),
				'taxonomies'    => $taxonomies,
			);
		}

		return array(
			'types' => $result,
			'count' => count( $result ),
		);
	}

	/**
	 * Return the meta field schema for a CPT.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type').
	 * @return array|WP_Error
	 */
	protected function get_schema( $arguments ) {
		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$pto         = get_post_type_object( $post_type );
		$meta_schema = apply_filters( 'wp_mcp_ai_post_type_meta_schema', array(), $post_type );
		$taxonomies  = get_object_taxonomies( $post_type, 'objects' );
		$supports    = array();
		foreach ( array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions', 'author', 'page-attributes', 'custom-fields' ) as $feature ) {
			if ( post_type_supports( $post_type, $feature ) ) {
				$supports[] = $feature;
			}
		}

		$fields = array();
		// Standard post fields always available.
		$fields[] = array(
			'key'         => 'title',
			'label'       => __( 'Title', 'mcp-ai-wpoos-pro' ),
			'type'        => 'string',
			'description' => __( 'Post title (maps to post_title).', 'mcp-ai-wpoos-pro' ),
			'is_meta'     => false,
		);
		$fields[] = array(
			'key'         => 'content',
			'label'       => __( 'Content', 'mcp-ai-wpoos-pro' ),
			'type'        => 'string',
			'description' => __( 'Post content / body (maps to post_content).', 'mcp-ai-wpoos-pro' ),
			'is_meta'     => false,
		);
		$fields[] = array(
			'key'         => 'excerpt',
			'label'       => __( 'Excerpt', 'mcp-ai-wpoos-pro' ),
			'type'        => 'string',
			'description' => __( 'Post excerpt / summary (maps to post_excerpt).', 'mcp-ai-wpoos-pro' ),
			'is_meta'     => false,
		);
		$fields[] = array(
			'key'         => 'status',
			'label'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
			'type'        => 'string',
			'description' => __( 'Post status: publish, draft, private, trash. Default: publish.', 'mcp-ai-wpoos-pro' ),
			'is_meta'     => false,
		);

		// Schema-defined meta fields.
		foreach ( $meta_schema as $field_def ) {
			$meta_key = isset( $field_def['meta_key'] ) ? $field_def['meta_key'] : '';
			if ( '' === $meta_key ) {
				continue;
			}

			$field_entry = array(
				'key'         => $meta_key,
				'label'       => isset( $field_def['label'] ) ? $field_def['label'] : $meta_key,
				'type'        => isset( $field_def['type'] ) ? $field_def['type'] : 'string',
				'description' => isset( $field_def['description'] ) ? $field_def['description'] : '',
				'is_meta'     => true,
			);

			if ( isset( $field_def['enum'] ) && is_array( $field_def['enum'] ) ) {
				$field_entry['enum'] = $field_def['enum'];
			}

			$fields[] = $field_entry;
		}

		// Taxonomy fields.
		$tax_fields = array();
		foreach ( $taxonomies as $tax_slug => $tax ) {
			$tax_fields[] = array(
				'taxonomy'     => $tax_slug,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
				'description'  => $tax->description,
			);
		}

		return array(
			'post_type'   => $post_type,
			'label'       => $pto->label,
			'supports'    => $supports,
			'field_count' => count( $fields ),
			'fields'      => $fields,
			'taxonomies'  => $tax_fields,
			'usage_note'  => __( 'Use the exact "key" values in the "fields" parameter when calling create_item or update_item. Meta fields (is_meta=true) are saved as post meta. Standard fields (is_meta=false) are saved to the post record directly.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List / search items in a CPT.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function list_items( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$per_page = isset( $arguments['per_page'] ) ? min( max( 1, absint( $arguments['per_page'] ) ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
		$search   = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$orderby  = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'date';
		$order    = isset( $arguments['order'] ) && 'ASC' === strtoupper( $arguments['order'] ) ? 'ASC' : 'DESC';

		// Validate orderby against allowed values.
		if ( ! in_array( $orderby, array( 'date', 'title', 'modified', 'ID', 'menu_order', 'rand' ), true ) ) {
			$orderby = 'date';
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Build meta_query from filters.
		$meta_query = $this->build_meta_query( $arguments );
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		// Determine which meta keys to include in the compact list view.
		$schema_keys = $this->get_schema_meta_keys( $post_type );
		$has_schema  = ! empty( $schema_keys );

		$items = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$items[] = $this->format_list_item( $post_id, $post_type, $schema_keys, $has_schema );
			}
			wp_reset_postdata();
		}

		return array(
			'items'       => $items,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get a single CPT item by post ID with full meta.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type' and 'item_id').
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function get_item( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['item_id'] ) ) {
			return new WP_Error( 'missing_item_id', __( 'item_id is required for get_item action.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post ) {
			return new WP_Error( 'item_not_found', __( 'CPT item not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $post->post_type !== $post_type ) {
			return new WP_Error(
				'post_type_mismatch',
				/* translators: 1: post ID (integer), 2: actual post type of the found post, 3: expected post type provided by the caller. */
				sprintf( __( 'Item %1$d has post type "%2$s", not "%3$s".', 'mcp-ai-wpoos-pro' ), $item_id, $post->post_type, $post_type )
			);
		}

		return $this->format_full_item( $post );
	}

	/**
	 * Create a new CPT item.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type' and 'fields').
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_item( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$fields = $this->extract_fields( $arguments );

		// Build wp_insert_post arguments.
		$post_arr = array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_author' => $current_user_id,
		);

		if ( isset( $fields['title'] ) ) {
			$post_arr['post_title'] = sanitize_text_field( $fields['title'] );
			unset( $fields['title'] );
		}

		if ( isset( $fields['content'] ) ) {
			$post_arr['post_content'] = wp_kses_post( $fields['content'] );
			unset( $fields['content'] );
		}

		if ( isset( $fields['excerpt'] ) ) {
			$post_arr['post_excerpt'] = sanitize_text_field( $fields['excerpt'] );
			unset( $fields['excerpt'] );
		}

		if ( isset( $fields['status'] ) ) {
			$post_arr['post_status'] = sanitize_key( $fields['status'] );
			unset( $fields['status'] );
		}

		if ( isset( $fields['date'] ) ) {
			$post_arr['post_date'] = sanitize_text_field( $fields['date'] );
			unset( $fields['date'] );
		}

		if ( isset( $fields['author_id'] ) ) {
			$post_arr['post_author'] = absint( $fields['author_id'] );
			unset( $fields['author_id'] );
		}

		$post_id = wp_insert_post( $post_arr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save remaining fields as post meta.
		foreach ( $fields as $meta_key => $meta_value ) {
			$safe_key = $this->sanitize_meta_key( $meta_key );
			if ( '' !== $safe_key ) {
				update_post_meta( $post_id, $safe_key, $meta_value );
			}
		}

		$post = get_post( $post_id );

		return array(
			'success'   => true,
			'message'   => __( 'CPT item created successfully.', 'mcp-ai-wpoos-pro' ),
			'item_id'   => $post_id,
			'post_type' => $post_type,
			'item'      => $this->format_full_item( $post ),
		);
	}

	/**
	 * Batch-create multiple CPT items in a single call.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type' and 'items').
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function bulk_create( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		if ( empty( $arguments['items'] ) || ! is_array( $arguments['items'] ) ) {
			return new WP_Error( 'missing_items', __( 'The "items" parameter is required for bulk_create and must be an array of field objects.', 'mcp-ai-wpoos-pro' ) );
		}

		$created = array();
		$failed  = array();

		foreach ( $arguments['items'] as $index => $item_fields ) {
			if ( ! is_array( $item_fields ) && ! is_object( $item_fields ) ) {
				$failed[] = array(
					'index' => $index,
					'error' => __( 'Each item must be a field object.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$item_args = array(
				'post_type' => $post_type,
				'fields'    => is_object( $item_fields ) ? (array) $item_fields : $item_fields,
			);

			$result = $this->create_item( $item_args, $context );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'index' => $index,
					'error' => $result->get_error_message(),
				);
			} else {
				$created[] = array(
					'index'   => $index,
					'item_id' => $result['item_id'],
				);
			}
		}

		return array(
			'post_type'     => $post_type,
			'total'         => count( $arguments['items'] ),
			'created_count' => count( $created ),
			'failed_count'  => count( $failed ),
			'created'       => $created,
			'failed'        => $failed,
		);
	}

	/**
	 * Update an existing CPT item.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type', 'item_id', and 'fields').
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_item( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['item_id'] ) ) {
			return new WP_Error( 'missing_item_id', __( 'item_id is required for update_item action.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post ) {
			return new WP_Error( 'item_not_found', __( 'CPT item not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $post->post_type !== $post_type ) {
			return new WP_Error(
				'post_type_mismatch',
				/* translators: 1: post ID, 2: actual post type, 3: expected post type. */
				sprintf( __( 'Item %1$d has post type "%2$s", not "%3$s".', 'mcp-ai-wpoos-pro' ), $item_id, $post->post_type, $post_type )
			);
		}

		// Author check: must be the post author or have edit_others_posts.
		if ( absint( $post->post_author ) !== $current_user_id && ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this item.', 'mcp-ai-wpoos-pro' ) );
		}

		$fields   = $this->extract_fields( $arguments );
		$post_arr = array( 'ID' => $item_id );
		$updated  = false;

		if ( isset( $fields['title'] ) ) {
			$post_arr['post_title'] = sanitize_text_field( $fields['title'] );
			$updated                = true;
			unset( $fields['title'] );
		}

		if ( isset( $fields['content'] ) ) {
			$post_arr['post_content'] = wp_kses_post( $fields['content'] );
			$updated                  = true;
			unset( $fields['content'] );
		}

		if ( isset( $fields['excerpt'] ) ) {
			$post_arr['post_excerpt'] = sanitize_text_field( $fields['excerpt'] );
			$updated                  = true;
			unset( $fields['excerpt'] );
		}

		if ( isset( $fields['status'] ) ) {
			$post_arr['post_status'] = sanitize_key( $fields['status'] );
			$updated                 = true;
			unset( $fields['status'] );
		}

		if ( isset( $fields['date'] ) ) {
			$post_arr['post_date'] = sanitize_text_field( $fields['date'] );
			$updated               = true;
			unset( $fields['date'] );
		}

		if ( isset( $fields['author_id'] ) ) {
			$post_arr['post_author'] = absint( $fields['author_id'] );
			$updated                 = true;
			unset( $fields['author_id'] );
		}

		if ( $updated ) {
			$result = wp_update_post( $post_arr, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update meta fields.
		foreach ( $fields as $meta_key => $meta_value ) {
			$safe_key = $this->sanitize_meta_key( $meta_key );
			if ( '' !== $safe_key ) {
				update_post_meta( $item_id, $safe_key, $meta_value );
			}
		}

		$updated_post = get_post( $item_id );

		return array(
			'success'   => true,
			'message'   => __( 'CPT item updated successfully.', 'mcp-ai-wpoos-pro' ),
			'item_id'   => $item_id,
			'post_type' => $post_type,
			'item'      => $this->format_full_item( $updated_post ),
		);
	}

	/**
	 * Permanently delete a CPT item.
	 *
	 * @param array $arguments Tool arguments (must include 'post_type' and 'item_id').
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_item( $arguments, $context ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete CPT items.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['item_id'] ) ) {
			return new WP_Error( 'missing_item_id', __( 'item_id is required for delete_item action.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = $this->resolve_post_type( $arguments );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post ) {
			return new WP_Error( 'item_not_found', __( 'CPT item not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $post->post_type !== $post_type ) {
			return new WP_Error(
				'post_type_mismatch',
				/* translators: 1: post ID, 2: actual post type, 3: expected post type. */
				sprintf( __( 'Item %1$d has post type "%2$s", not "%3$s".', 'mcp-ai-wpoos-pro' ), $item_id, $post->post_type, $post_type )
			);
		}

		// Author check: must own the item or have delete_others_posts.
		if ( absint( $post->post_author ) !== $current_user_id && ! user_can( $current_user_id, 'delete_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this item.', 'mcp-ai-wpoos-pro' ) );
		}

		$title  = $post->post_title;
		$result = wp_delete_post( $item_id, true );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete CPT item.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'   => true,
			'message'   => sprintf(
				/* translators: 1: post title, 2: post ID. */
				__( 'Deleted "%1$s" (ID %2$d) permanently.', 'mcp-ai-wpoos-pro' ),
				$title,
				$item_id
			),
			'item_id'   => $item_id,
			'post_type' => $post_type,
		);
	}

	// -------------------------------------------------------------------------
	// Helper methods
	// -------------------------------------------------------------------------

	/**
	 * Resolve and validate the post_type argument.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error Validated post type slug or WP_Error.
	 */
	protected function resolve_post_type( $arguments ) {
		if ( empty( $arguments['post_type'] ) ) {
			return new WP_Error( 'missing_post_type', __( 'post_type is required. Call list_types to see available CPTs.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_type = sanitize_key( $arguments['post_type'] );

		if ( in_array( $post_type, self::PROTECTED_POST_TYPES, true ) ) {
			return new WP_Error(
				'protected_post_type',
				/* translators: %s: post type slug. */
				sprintf( __( 'Post type "%s" is a protected internal type and cannot be managed via this tool.', 'mcp-ai-wpoos-pro' ), $post_type )
			);
		}

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'post_type_not_found',
				/* translators: %s: post type slug. */
				sprintf( __( 'Post type "%s" is not registered. Call list_types to see available CPTs.', 'mcp-ai-wpoos-pro' ), $post_type )
			);
		}

		return $post_type;
	}

	/**
	 * Extract the 'fields' value from arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Flat key/value field map.
	 */
	protected function extract_fields( $arguments ) {
		if ( isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ) {
			return $arguments['fields'];
		}
		if ( isset( $arguments['fields'] ) && is_object( $arguments['fields'] ) ) {
			return (array) $arguments['fields'];
		}
		return array();
	}

	/**
	 * Sanitize a meta key.
	 *
	 * Allows alphanumeric characters, underscores, and hyphens.
	 * Leading underscores (used for "protected" meta) are preserved.
	 *
	 * @param string $key Raw key.
	 * @return string Sanitized key, or empty string if invalid.
	 */
	protected function sanitize_meta_key( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}
		// Allow alphanumeric and underscores only (including leading underscore for
		// protected meta keys). Hyphens are excluded because they can cause issues
		// with some WordPress meta query operations.
		$clean = preg_replace( '/[^a-zA-Z0-9_]/', '', $key );
		return (string) $clean;
	}

	/**
	 * Build a WordPress meta_query array from the 'filters' argument.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array WordPress meta_query array (may be empty).
	 */
	protected function build_meta_query( $arguments ) {
		if ( empty( $arguments['filters'] ) || ! is_array( $arguments['filters'] ) ) {
			return array();
		}

		$meta_query = array( 'relation' => 'AND' );

		foreach ( $arguments['filters'] as $filter ) {
			if ( ! is_array( $filter ) && ! is_object( $filter ) ) {
				continue;
			}
			$filter = is_object( $filter ) ? (array) $filter : $filter;

			$key = isset( $filter['key'] ) ? $this->sanitize_meta_key( $filter['key'] ) : '';
			if ( '' === $key ) {
				continue;
			}

			$compare = isset( $filter['compare'] ) ? strtoupper( $filter['compare'] ) : '=';
			if ( ! in_array( $compare, self::ALLOWED_COMPARE, true ) ) {
				$compare = '=';
			}

			$type = isset( $filter['type'] ) ? strtoupper( $filter['type'] ) : 'CHAR';
			if ( ! in_array( $type, self::ALLOWED_META_TYPES, true ) ) {
				$type = 'CHAR';
			}

			$entry = array(
				'key'     => $key,
				'compare' => $compare,
				'type'    => $type,
			);

			// EXISTS / NOT EXISTS do not require a value.
			if ( ! in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$entry['value'] = isset( $filter['value'] ) ? $filter['value'] : '';
			}

			$meta_query[] = $entry;
		}

		return count( $meta_query ) > 1 ? $meta_query : array();
	}

	/**
	 * Get the list of meta keys defined in the schema for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array List of meta key strings.
	 */
	protected function get_schema_meta_keys( $post_type ) {
		$schema = apply_filters( 'wp_mcp_ai_post_type_meta_schema', array(), $post_type );
		$keys   = array();
		foreach ( $schema as $field_def ) {
			if ( ! empty( $field_def['meta_key'] ) ) {
				$keys[] = $field_def['meta_key'];
			}
		}
		return $keys;
	}

	/**
	 * Format a post as a compact list item (used by list_items).
	 *
	 * Includes all schema-defined meta fields. Falls back to all meta when
	 * no schema is registered for the post type.
	 *
	 * @param int    $post_id     WordPress post ID.
	 * @param string $post_type   Post type slug.
	 * @param array  $schema_keys Meta keys from the CPT schema.
	 * @param bool   $has_schema  Whether a schema is defined for this CPT.
	 * @return array Formatted item.
	 */
	protected function format_list_item( $post_id, $post_type, $schema_keys, $has_schema ) {
		$post = get_post( $post_id );

		$item = array(
			'id'       => $post_id,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
		);

		if ( $has_schema ) {
			// Return only the schema-defined meta fields for a compact listing.
			foreach ( $schema_keys as $key ) {
				$item[ $key ] = get_post_meta( $post_id, $key, true );
			}
		} else {
			// No schema — return all meta.
			$all_meta = get_post_meta( $post_id );
			foreach ( $all_meta as $key => $values ) {
				if ( in_array( $key, self::NOISY_META_KEYS, true ) ) {
					continue;
				}
				$item[ $key ] = maybe_unserialize( $values[0] );
			}
		}

		return $item;
	}

	/**
	 * Format a post as a complete item with all meta (used by get_item / create_item / update_item).
	 *
	 * @param WP_Post $post WordPress post object.
	 * @return array Formatted item.
	 */
	protected function format_full_item( $post ) {
		$item = array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'content'   => $post->post_content,
			'excerpt'   => $post->post_excerpt,
			'status'    => $post->post_status,
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'author_id' => (int) $post->post_author,
			'meta'      => array(),
		);

		$all_meta = get_post_meta( $post->ID );
		foreach ( $all_meta as $key => $values ) {
			if ( in_array( $key, self::NOISY_META_KEYS, true ) ) {
				continue;
			}
			$item['meta'][ $key ] = maybe_unserialize( $values[0] );
		}

		return $item;
	}
}
