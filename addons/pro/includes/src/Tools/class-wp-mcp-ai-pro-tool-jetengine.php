<?php
/**
 * JetEngine Tool - Pro add-on tool for JetEngine CCT operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for JetEngine CCT operations.
 *
 * Provides operations for JetEngine Custom Content Types including:
 * - Listing CCT items
 * - Getting item details
 * - Creating/updating items
 *
 * Requires JetEngine plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if JetEngine is active.
	 */
	public static function is_available() {
		return class_exists( 'Jet_Engine' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'JetEngine tool requires JetEngine to be installed and activated.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine CCT', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query and manage JetEngine Custom Content Type (CCT) items. Use this tool — NOT create_post — when you need to create, read, update, or delete records in any JetEngine CCT such as vital_signs, channel_messages, or any other CCT slug. Supports full CRUD and schema discovery: list_types, get_schema, list_items, get_item, create_item, update_item, delete_item. Always call get_schema first to discover available field names and types before creating or updating items.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: list_types, get_schema, list_items, get_item, create_item, update_item, delete_item. Use get_schema to discover all field names and types for a CCT before creating or updating items.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_types', 'get_schema', 'list_items', 'get_item', 'create_item', 'update_item', 'delete_item' ),
					'default'     => 'list_types',
				),
				'cct_slug' => array(
					'type'        => 'string',
					'description' => __( 'CCT type slug.', 'mcp-ai-wpoos-pro' ),
				),
				'item_id'  => array(
					'type'        => 'integer',
					'description' => __( 'CCT item ID for get/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to return. Default: 10. Max: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'fields'   => array(
					'type'        => 'object',
					'description' => __( 'Field values for create/update operations.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',              // Pro tier tool.
			'read-only',        // list/get operations.
			'write',            // create/update/delete operations.
			'requires-plugin',  // Requires JetEngine.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Get the extended tool definition for orchestration.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'jetengine_cct',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_manager', 'health_advisor', 'data_manager' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if JetEngine is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'jetengine_not_active',
				__( 'JetEngine is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_types';

		switch ( $action ) {
			case 'list_types':
				return $this->list_types();
			case 'get_schema':
				return $this->get_schema( $arguments );
			case 'list_items':
				return $this->list_items( $arguments );
			case 'get_item':
				return $this->get_item( $arguments );
			case 'create_item':
				return $this->create_item( $arguments, $context );
			case 'update_item':
				return $this->update_item( $arguments, $context );
			case 'delete_item':
				return $this->delete_item( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * List available CCT types.
	 *
	 * Handles environments where JetEngine returns partially-initialised type
	 * objects whose slug/name are stored inside the args array rather than as
	 * direct properties, and silently skips anonymous/system types that have
	 * no identifiable slug.
	 *
	 * @return array
	 */
	protected function list_types() {
		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return array( 'types' => array() );
		}

		if ( ! method_exists( $module->instance->manager, 'get_content_types' ) ) {
			return array( 'types' => array() );
		}

		$types  = $module->instance->manager->get_content_types();
		$result = array();

		foreach ( $types as $type ) {
			// Resolve slug — direct property first, then args array.
			$slug = '';
			if ( ! empty( $type->slug ) ) {
				$slug = $type->slug;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['slug'] ) ) {
				$slug = $type->args['slug'];
			}

			// Skip anonymous / system types that have no identifiable slug.
			if ( '' === $slug ) {
				continue;
			}

			// Resolve name — direct property, args array, then slug as fallback.
			$name = '';
			if ( ! empty( $type->name ) ) {
				$name = $type->name;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['name'] ) ) {
				$name = $type->args['name'];
			} else {
				$name = $slug;
			}

			// Resolve numeric ID — try 'id' first, then '_ID' (raw DB column name).
			$id = null;
			if ( isset( $type->id ) && null !== $type->id ) {
				$id = $type->id;
			} elseif ( isset( $type->_ID ) ) {
				$id = $type->_ID;
			}

			// Resolve field count from whichever property is populated.
			$field_count = 0;
			if ( isset( $type->fields ) && is_array( $type->fields ) ) {
				$field_count = count( $type->fields );
			} elseif ( isset( $type->meta_fields ) && is_array( $type->meta_fields ) ) {
				$field_count = count( $type->meta_fields );
			}

			$result[] = array(
				'id'     => $id,
				'slug'   => $slug,
				'name'   => $name,
				'fields' => $field_count,
			);
		}

		return array( 'types' => $result );
	}

	/**
	 * Get the field schema for a CCT type.
	 *
	 * Returns the full list of field definitions (name, type, required, options,
	 * description, default) so that the AI assistant knows which field names and
	 * types are available before calling create_item or update_item.
	 *
	 * When JetEngine's manager has the type loaded in memory, fields are read
	 * directly from the type object.  For CCTs registered programmatically (such
	 * as vitals_log) this method falls back to calling the protected static
	 * get_fields_schema() / get_meta_fields() method on the corresponding CCT
	 * class via reflection.
	 *
	 * @param array $arguments Tool arguments (must include 'cct_slug').
	 * @return array|WP_Error Schema array on success, WP_Error on failure.
	 */
	protected function get_schema( $arguments ) {
		if ( empty( $arguments['cct_slug'] ) ) {
			return new WP_Error(
				'missing_cct_slug',
				__( 'CCT slug is required for get_schema action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$slug = sanitize_key( $arguments['cct_slug'] );

		if ( function_exists( 'jet_engine' ) ) {
			$module = jet_engine()->modules->get_module( 'custom-content-types' );

			if ( $module && $module->instance ) {
				$type = $this->get_cct_type( $module->instance, $slug );

				if ( $type ) {
					$raw_fields = array();

					if ( isset( $type->meta_fields ) && is_array( $type->meta_fields ) && ! empty( $type->meta_fields ) ) {
						$raw_fields = $type->meta_fields;
					} elseif ( isset( $type->fields ) && is_array( $type->fields ) && ! empty( $type->fields ) ) {
						$raw_fields = $type->fields;
					}

					if ( ! empty( $raw_fields ) ) {
						return $this->format_fields_schema( $slug, $raw_fields );
					}
				}
			}
		}

		// JetEngine manager could not locate the type — fall back to the
		// programmatic CCT class for known slugs.
		return $this->get_schema_from_cct_class( $slug );
	}

	/**
	 * Retrieve field schema from a programmatic CCT class for known CCT slugs.
	 *
	 * Uses ReflectionClass to call the protected static get_fields_schema() or
	 * get_meta_fields() method on the corresponding CCT class, covering all CCTs
	 * that ship with this plugin.
	 *
	 * @param string $slug CCT slug.
	 * @return array|WP_Error Formatted schema array, or WP_Error when unavailable.
	 */
	protected function get_schema_from_cct_class( $slug ) {
		$known = array(
			'vitals_log'          => 'WP_MCP_AI_JetEngine_Vitals_Log_CCT',
			'vital_signs'         => 'WP_MCP_AI_JetEngine_Vitals_CCT',
			'quizzes'             => 'WP_MCP_AI_JetEngine_Quizzes_CCT',
			'webchat_messages'    => 'WP_MCP_AI_JetEngine_Webchat_Messages_CCT',
			'assistants'          => 'WP_MCP_AI_JetEngine_Assistants_CCT',
			'quiz_submissions'    => 'WP_MCP_AI_JetEngine_Submissions_CCT',
			'ai_usage_logs'       => 'WP_MCP_AI_JetEngine_Usage_Logs_CCT',
			'ai_peers'            => 'WP_MCP_AI_JetEngine_AI_Peers_CCT',
			'ai_chat_transcripts' => 'WP_MCP_AI_JetEngine_CCT',
		);

		if ( ! isset( $known[ $slug ] ) || ! class_exists( $known[ $slug ] ) ) {
			return new WP_Error(
				'cct_not_found',
				/* translators: %s: CCT slug */
				sprintf( __( 'CCT type "%s" not found.', 'mcp-ai-wpoos-pro' ), $slug )
			);
		}

		$class      = $known[ $slug ];
		$raw_fields = null;

		foreach ( array( 'get_fields_schema', 'get_meta_fields' ) as $method_name ) {
			try {
				$reflection = new ReflectionClass( $class );
				if ( ! $reflection->hasMethod( $method_name ) ) {
					continue;
				}
				$method = $reflection->getMethod( $method_name );
				$method->setAccessible( true );
				$raw_fields = $method->invoke( null ); // Static method; no instance needed.
				break;
			} catch ( Exception $e ) {
				error_log( 'WP_MCP_AI JetEngine get_schema reflection failed for ' . $class . '::' . $method_name . '(): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}
		}

		if ( null === $raw_fields || ! is_array( $raw_fields ) ) {
			return new WP_Error(
				'schema_unavailable',
				__( 'Could not retrieve schema for this CCT.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->format_fields_schema( $slug, $raw_fields );
	}

	/**
	 * Normalise raw JetEngine field definitions into a consistent schema array.
	 *
	 * Each entry in the returned 'fields' array contains:
	 *   - name        (string)  Field slug used as the key in create/update 'fields'.
	 *   - title       (string)  Human-readable label.
	 *   - type        (string)  Field type: text, number, textarea, select, etc.
	 *   - required    (bool)    Whether the field is required.
	 *   - description (string)  Usage description.
	 *   - default     (mixed)   Default value (empty string when unset).
	 *   - options     (array)   Only present for select fields; each entry has 'key' and 'label'.
	 *
	 * @param string $slug       CCT slug.
	 * @param array  $raw_fields Raw field definitions from JetEngine or a CCT class.
	 * @return array Associative array with keys 'cct_slug', 'field_count', 'fields'.
	 */
	protected function format_fields_schema( $slug, array $raw_fields ) {
		$fields = array();

		foreach ( $raw_fields as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}

			$formatted = array(
				'name'        => $field['name'],
				'title'       => isset( $field['title'] ) ? $field['title'] : $field['name'],
				'type'        => isset( $field['type'] ) ? $field['type'] : 'text',
				'required'    => ! empty( $field['is_required'] ),
				'description' => isset( $field['description'] ) ? $field['description'] : '',
				'default'     => isset( $field['default_val'] ) ? $field['default_val'] : '',
			);

			if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				$formatted['options'] = array_map(
					function ( $opt ) {
						if ( is_array( $opt ) ) {
							return array(
								'key'   => isset( $opt['key'] ) ? $opt['key'] : '',
								'label' => isset( $opt['value'] ) ? $opt['value'] : ( isset( $opt['key'] ) ? $opt['key'] : '' ),
							);
						}
						return array(
							'key'   => (string) $opt,
							'label' => (string) $opt,
						);
					},
					$field['options']
				);
			}

			$fields[] = $formatted;
		}

		return array(
			'cct_slug'    => $slug,
			'field_count' => count( $fields ),
			'fields'      => $fields,
		);
	}

	/**
	 * List items for a CCT type.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function list_items( $arguments ) {
		if ( empty( $arguments['cct_slug'] ) ) {
			return new WP_Error(
				'missing_cct_slug',
				__( 'CCT slug is required for list_items action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$items = $type->db->query(
			array(
				'limit'  => $per_page,
				'offset' => $offset,
			)
		);

		$total = $type->db->count();

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => ceil( $total / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * Get a single CCT item.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_item( $arguments ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for get_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item = $type->db->get_item( absint( $arguments['item_id'] ) );

		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $item;
	}

	/**
	 * Create a new CCT item.
	 *
	 * For CCTs that ship with a dedicated programmatic insert class (e.g.
	 * vitals_log) the direct database path is always attempted first.  This
	 * is necessary because JetEngine's type->db->insert() is designed for
	 * form submissions and may read field values from $_POST/$_REQUEST
	 * internally rather than from the supplied array, which causes blank
	 * records to be created when called programmatically.  The reliable
	 * $wpdb->insert() path in the dedicated class is preferred in all cases.
	 *
	 * When the direct path does not handle the requested slug (returns the
	 * 'cct_not_found' error code) the method falls back to JetEngine's type
	 * API, covering CCTs that have no dedicated programmatic class.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) ) {
			return new WP_Error(
				'missing_cct_slug',
				__( 'CCT slug is required for create_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to create CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// For CCTs with a dedicated programmatic insert class (e.g. vitals_log),
		// always try the direct $wpdb->insert() path first.  JetEngine's
		// type->db->insert() is unreliable for programmatic writes and can
		// silently discard the supplied field values, producing blank records.
		$direct_result = $this->create_item_direct( $arguments );
		if ( ! is_wp_error( $direct_result ) ) {
			return $direct_result;
		}

		// create_item_direct() returns 'cct_not_found' for slugs it cannot handle.
		// Any other error code (e.g. 'create_failed') means a handler was found but
		// the insert failed — surface that error rather than attempting JetEngine's
		// unreliable path.
		if ( 'cct_not_found' !== $direct_result->get_error_code() ) {
			return $direct_result;
		}

		// No dedicated handler for this slug — fall through to JetEngine's type API.
		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return $direct_result;
		}

		$fields = $this->normalize_fields_argument( $arguments );

		$item_id = $type->db->insert( $fields );

		if ( ! $item_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create CCT item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $type->db->get_item( $item_id );
	}

	/**
	 * Attempt a direct database insert for CCT slugs that ship with a
	 * dedicated programmatic CCT class (vitals_log).
	 *
	 * Using $wpdb->insert() directly is the only reliable way to persist
	 * programmatic field values for these CCTs.  JetEngine's handler
	 * create_item() reads from $_POST/$_REQUEST internally and ignores the
	 * supplied data array when invoked outside of a form submission context.
	 *
	 * @param array $arguments Tool arguments (must include 'cct_slug' and optionally 'fields').
	 * @return array|WP_Error  Minimal item array on success, WP_Error on failure.
	 */
	protected function create_item_direct( $arguments ) {
		$slug   = sanitize_key( $arguments['cct_slug'] );
		$fields = $this->normalize_fields_argument( $arguments );

		if ( 'vitals_log' !== $slug ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_vitals_log_direct_path_available() ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found and vitals_log table does not exist.', 'mcp-ai-wpoos-pro' )
			);
		}

		$member_id = isset( $fields['member_id'] ) ? absint( $fields['member_id'] ) : 0;
		$item_id   = WP_MCP_AI_JetEngine_Vitals_Log_CCT::insert( $member_id, $fields );

		if ( ! $item_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create vitals_log item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'_ID'    => $item_id,
			'cct'    => $slug,
			'fields' => $fields,
		);
	}

	/**
	 * Check whether the vitals_log direct-path (using $wpdb) is available.
	 *
	 * Returns true when the dedicated CCT class is loaded and its database
	 * table exists, meaning a direct $wpdb->insert()/$wpdb->update() can be
	 * performed without going through JetEngine's form-submission handler.
	 *
	 * Centralising this two-part predicate avoids repeating it in both
	 * create_item_direct() and update_item().  The slug check ('vitals_log')
	 * is intentionally left to each caller so this method remains reusable
	 * if additional CCTs gain dedicated programmatic classes in the future.
	 *
	 * @return bool True when the vitals_log table is present and the class is loaded.
	 */
	protected function is_vitals_log_direct_path_available() {
		return class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) &&
			WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists();
	}

	/**
	 * Update an existing CCT item.
	 *
	 * For CCTs with a dedicated programmatic class (vitals_log), the direct
	 * $wpdb->update() path is always preferred.  This mirrors the same
	 * rationale as create_item(): JetEngine's type->db->update() may ignore
	 * the supplied field array when invoked outside of a form submission
	 * context.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for update_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to update CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$fields  = $this->normalize_fields_argument( $arguments );

		// For vitals_log, use the reliable direct $wpdb->update() path.
		if ( 'vitals_log' === sanitize_key( $arguments['cct_slug'] ) && $this->is_vitals_log_direct_path_available() ) {

			if ( empty( $fields ) ) {
				return new WP_Error(
					'missing_fields',
					__( 'No fields provided for update_item action.', 'mcp-ai-wpoos-pro' )
				);
			}

			$updated = WP_MCP_AI_JetEngine_Vitals_Log_CCT::update_fields( $item_id, $fields );

			if ( ! $updated ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update vitals_log item.', 'mcp-ai-wpoos-pro' )
				);
			}

			return array(
				'_ID'    => $item_id,
				'cct'    => 'vitals_log',
				'fields' => $fields,
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $type->db->update( $fields, array( '_ID' => $item_id ) );

		if ( ! $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update CCT item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $type->db->get_item( $item_id );
	}

	/**
	 * Delete a CCT item permanently.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_item( $arguments, $context ) {
		if ( empty( $arguments['cct_slug'] ) || empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'CCT slug and item ID are required for delete_item action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to delete CCT items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );

		if ( ! $module || ! $module->instance ) {
			return new WP_Error(
				'cct_not_available',
				__( 'Custom Content Types module is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $this->get_cct_type( $module->instance, $arguments['cct_slug'] );

		if ( ! $type ) {
			return new WP_Error(
				'cct_not_found',
				__( 'CCT type not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );

		// Verify item exists before deleting.
		$item = $type->db->get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error(
				'item_not_found',
				__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $type->db->delete( array( '_ID' => $item_id ) );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete CCT item.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success' => true,
			'deleted' => true,
			'item_id' => $item_id,
			'message' => sprintf(
				/* translators: 1: CCT slug, 2: item ID */
				__( 'CCT item %1$s #%2$d deleted successfully.', 'mcp-ai-wpoos-pro' ),
				sanitize_key( $arguments['cct_slug'] ),
				$item_id
			),
		);
	}

	/**
	 * Normalize the 'fields' entry from tool arguments into a PHP array.
	 *
	 * WordPress REST API decodes the top-level 'arguments' object as an
	 * associative array (json_decode with $assoc=true), but nested JSON
	 * objects may survive as stdClass instances depending on the calling
	 * path (e.g. MCP server clients or custom integrations that pass
	 * arguments differently).  This helper ensures the 'fields' value is
	 * always a plain PHP array before it is used in database operations.
	 *
	 * @param array $arguments Tool arguments as received by execute().
	 * @return array Normalised fields array (empty array when absent or invalid).
	 */
	protected function normalize_fields_argument( array $arguments ) {
		if ( ! isset( $arguments['fields'] ) ) {
			return array();
		}

		$fields = $arguments['fields'];

		if ( is_array( $fields ) ) {
			return $fields;
		}

		if ( is_object( $fields ) ) {
			return (array) $fields;
		}

		return array();
	}

	/**
	 * Resolve a CCT type object by slug using the correct JetEngine API.
	 *
	 * JetEngine exposes content types through `manager->get_content_types()`.
	 * Older code incorrectly called `get_type()` / `get_types()` which do not
	 * exist on the Manager class and produce a fatal error.  This helper always
	 * uses `get_content_types()` and guards against future API changes with a
	 * `method_exists()` check.
	 *
	 * When the type is not initially found this method attempts to trigger its
	 * programmatic registration (if a matching CCT class exists) and retries
	 * the lookup once — this covers environments where the type was registered
	 * via `add_action( 'init', ... )` but the manager hasn't reloaded it yet.
	 *
	 * @param object $module CCT module instance (i.e. `$module_wrapper->instance`), which
	 *                       exposes a `manager` property with a `get_content_types()` method.
	 * @param string $slug   CCT slug to look up.
	 * @return object|null   CCT type object, or null when not found or API unavailable.
	 */
	protected function get_cct_type( $module, $slug ) {
		if ( empty( $module->manager ) ||
			! method_exists( $module->manager, 'get_content_types' ) ) {
			return null;
		}

		$type = $module->manager->get_content_types( sanitize_key( $slug ) );

		if ( is_object( $type ) ) {
			return $type;
		}

		// Type not found in manager — try to trigger programmatic registration
		// for known CCT slugs and reload the manager.
		$this->maybe_register_known_cct( $slug );

		// Reload content types if the data DB supports it.
		if ( ! empty( $module->manager->data ) && ! empty( $module->manager->data->db ) &&
			method_exists( $module->manager->data->db, 'query_raw' ) ) {
			try {
				$module->manager->data->db->query_raw( 'post_types' );
			} catch ( Exception $e ) {
				// Manager reload is best-effort; log and continue.
				error_log( 'WP_MCP_AI JetEngine manager reload failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$type = $module->manager->get_content_types( sanitize_key( $slug ) );
		return is_object( $type ) ? $type : null;
	}

	/**
	 * Trigger programmatic registration for known CCT slugs that ship with
	 * dedicated CCT classes but may not yet be loaded in the JetEngine manager.
	 *
	 * @param string $slug CCT slug.
	 * @return void
	 */
	protected function maybe_register_known_cct( $slug ) {
		switch ( sanitize_key( $slug ) ) {
			case 'vitals_log':
				if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) ) {
					WP_MCP_AI_JetEngine_Vitals_Log_CCT::maybe_register_cct();
				}
				break;
			case 'ai_chat_transcripts':
				if ( class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
					WP_MCP_AI_JetEngine_CCT::maybe_register_cct();
				}
				break;
		}
	}
}
