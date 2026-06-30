<?php
/**
 * NV oOS Graphify — Content Detector
 *
 * Inventories published WordPress content and returns a list of items
 * that need to be (re-)indexed. Supports incremental detection by
 * comparing post_modified against the last-indexed timestamp.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects WordPress content that needs to be included in the knowledge graph.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Detector {

	/**
	 * Default per-type cap on the number of CCT items pulled into the graph.
	 *
	 * High-volume content types (e.g. agent memories) can otherwise dominate
	 * the graph; this cap keeps build times bounded while still covering
	 * typical content sites. Override via the
	 * `nvoos_graphify_cct_items_limit` filter.
	 *
	 * @since 0.7.0
	 * @var int
	 */
	const DEFAULT_CCT_ITEMS_LIMIT = 1000;

	/**
	 * Reason the last CCT detection pass returned no rows, if any.
	 *
	 * Populated by {@see detect_ccts()} when an early-exit branch is taken
	 * (JetEngine missing, CCT module missing, no content types registered,
	 * etc.). Surfaced in the build summary so admins can see why their
	 * JetEngine CCTs aren't appearing in the graph.
	 *
	 * @since 0.7.x
	 * @var string
	 */
	private static $last_ccts_skip_reason = '';

	/**
	 * Return the reason CCT detection was skipped on the most recent
	 * {@see detect_ccts()} call, if any.
	 *
	 * The reason is reset at the top of each detect_ccts() invocation,
	 * so callers always observe the value from the most recent run.
	 *
	 * @since 0.7.x
	 *
	 * @return string Empty string when CCT detection ran normally.
	 */
	public static function get_last_ccts_skip_reason() {
		return self::$last_ccts_skip_reason;
	}

	/**
	 * Collect all content items that should be represented as nodes.
	 *
	 * @since 0.5.0
	 *
	 * @param bool   $incremental When true, only return items newer than last build.
	 * @param string $since       ISO-8601 datetime string (overrides incremental flag).
	 * @return array {
	 *     @type array $posts  Published posts (all public post types).
	 *     @type array $terms  Taxonomy terms with at least one published post.
	 *     @type array $users  Authors with published content.
	 *     @type array $media  Featured images referenced by posts in scope.
	 * }
	 */
	public static function detect( $incremental = false, $since = '' ) {
		if ( $incremental && ! $since ) {
			$since = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', '' );
		}

		$posts    = self::detect_posts( $since );
		$ccts     = self::detect_ccts( $since );
		$terms    = self::detect_terms( $posts );
		$users    = self::detect_users( $posts, $ccts );
		$media    = self::detect_media( $posts );
		$external = class_exists( 'NV_oOS_Graphify_NV_oOS_Bridge' )
			? self::detect_external_rows( $since )
			: array();

		return compact( 'posts', 'ccts', 'terms', 'users', 'media', 'external' );
	}

	// -------------------------------------------------------------------------
	// Post detection
	// -------------------------------------------------------------------------

	/**
	 * Return published posts across all public post types.
	 *
	 * @since 0.5.0
	 *
	 * @param string $since Optional datetime filter (only posts modified after this).
	 * @return WP_Post[]
	 */
	public static function detect_posts( $since = '' ) {
		$settings   = NV_oOS_Graphify::get_settings();
		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? $settings['post_types']
			: self::get_default_post_types();

		$args = array(
			'post_type'      => array_map( 'sanitize_key', $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'all',
			'no_found_rows'  => true,
		);

		if ( $since ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => sanitize_text_field( $since ),
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Return the default public post types to index.
	 *
	 * Only includes post types registered with `public => true`,
	 * excluding system internal post types. Post types that are
	 * non-public but expose `show_in_rest => true` are intentionally
	 * excluded to prevent leaking non-public content through the
	 * knowledge graph read endpoints.
	 *
	 * Sites can override the list with the
	 * `nvoos_graphify_indexed_post_types` filter.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	public static function get_default_post_types() {
		$candidates = array_keys( get_post_types( array( 'public' => true ), 'names' ) );

		// Built-in WordPress system post types that should never be indexed
		// — internal storage for menus, the block editor, FSE, privacy
		// requests, and customizer changesets.
		$system_blacklist = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
		);

		$post_types = array_values( array_diff( $candidates, $system_blacklist ) );

		/**
		 * Filter the list of post types indexed by the knowledge graph.
		 *
		 * @since 0.7.0
		 *
		 * @param string[] $post_types Sanitised post type slugs.
		 */
		$post_types = apply_filters( 'nvoos_graphify_indexed_post_types', $post_types );

		return array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
	}

	// -------------------------------------------------------------------------
	// Term detection
	// -------------------------------------------------------------------------

	/**
	 * Return terms that are used by at least one post in the supplied array.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @return WP_Term[]
	 */
	public static function detect_terms( array $posts ) {
		if ( empty( $posts ) ) {
			return array();
		}

		$post_ids   = wp_list_pluck( $posts, 'ID' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

		$terms = wp_get_object_terms(
			$post_ids,
			array_values( $taxonomies ),
			array( 'fields' => 'all' )
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Deduplicate by term ID.
		$unique = array();
		foreach ( $terms as $term ) {
			$unique[ $term->term_id ] = $term;
		}
		return array_values( $unique );
	}

	// -------------------------------------------------------------------------
	// User/author detection
	// -------------------------------------------------------------------------

	/**
	 * Return author user objects for the supplied posts and CCT items.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @param array     $ccts  Optional CCT item rows from {@see detect_ccts()}.
	 * @return WP_User[]
	 */
	public static function detect_users( array $posts, array $ccts = array() ) {
		$author_ids = array();

		if ( ! empty( $posts ) ) {
			$author_ids = array_merge(
				$author_ids,
				array_map( 'absint', wp_list_pluck( $posts, 'post_author' ) )
			);
		}

		foreach ( $ccts as $row ) {
			if ( ! empty( $row['item']['cct_author_id'] ) ) {
				$author_ids[] = absint( $row['item']['cct_author_id'] );
			}
		}

		$author_ids = array_unique( array_filter( $author_ids ) );

		$users = array();
		foreach ( $author_ids as $uid ) {
			$user = get_userdata( $uid );
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	// -------------------------------------------------------------------------
	// Media detection
	// -------------------------------------------------------------------------

	/**
	 * Return attachment post objects for featured images used by the supplied posts.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @return WP_Post[]
	 */
	public static function detect_media( array $posts ) {
		if ( empty( $posts ) ) {
			return array();
		}

		$attachment_ids = array();
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumb > 0 ) {
				$attachment_ids[] = $thumb;
			}
		}
		$attachment_ids = array_unique( $attachment_ids );

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$media = get_posts(
			array(
				'post_type'      => 'attachment',
				'post__in'       => $attachment_ids,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		return is_array( $media ) ? $media : array();
	}

	// -------------------------------------------------------------------------
	// JetEngine Custom Content Type (CCT) detection
	// -------------------------------------------------------------------------

	/**
	 * Return JetEngine Custom Content Type items that should be indexed.
	 *
	 * JetEngine CCTs live in dedicated `{prefix}jet_cct_{slug}` tables and
	 * are invisible to {@see get_post_types()} / {@see get_posts()}, so they
	 * have to be enumerated through the JetEngine API.
	 *
	 * Each returned row is shaped:
	 *   array(
	 *     'type' => string  CCT slug (sanitised),
	 *     'name' => string  Human-readable type name,
	 *     'item' => array   Associative-array CCT item (includes `_ID`,
	 *                       `cct_status`, `cct_created`, `cct_modified`,
	 *                       `cct_author_id`, plus user-defined fields).
	 *   )
	 *
	 * @since 0.7.0
	 *
	 * @param string $since Optional ISO-8601 datetime; only items modified
	 *                      after this point are returned (incremental builds).
	 * @return array[]
	 */
	public static function detect_ccts( $since = '' ) {
		self::$last_ccts_skip_reason = '';

		if ( ! function_exists( 'jet_engine' ) ) {
			self::$last_ccts_skip_reason = 'jetengine_not_active';
			return array();
		}

		$engine = jet_engine();
		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'get_module' ) ) {
			self::$last_ccts_skip_reason = 'jetengine_modules_unavailable';
			return array();
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			self::$last_ccts_skip_reason = 'cct_module_inactive';
			return array();
		}

		$module = $module_wrapper->instance;
		if ( empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			self::$last_ccts_skip_reason = 'cct_manager_unavailable';
			return array();
		}

		$types = $module->manager->get_content_types();
		if ( empty( $types ) || ! is_array( $types ) ) {
			self::$last_ccts_skip_reason = 'no_content_types_registered';
			return array();
		}

		/**
		 * Filter the maximum number of items pulled from each CCT type.
		 *
		 * @since 0.7.0
		 *
		 * @param int $limit Maximum items per CCT type.
		 */
		$per_type_limit = (int) apply_filters( 'nvoos_graphify_cct_items_limit', self::DEFAULT_CCT_ITEMS_LIMIT );
		if ( $per_type_limit <= 0 ) {
			$per_type_limit = self::DEFAULT_CCT_ITEMS_LIMIT;
		}

		// Build the indexed-slug allowlist once, before iterating.
		//
		// JetEngine's CCT type objects don't expose `slug` as a public
		// property — it lives in `$type->args['slug']` (with newer versions
		// also surfacing it via `$type->slug`). Reuse the same resolution
		// helper as the iteration loop below so we don't end up with a list
		// of nulls (which would empty the allowlist and skip every CCT,
		// surfacing as the `all_content_types_empty_or_unindexed` reason).
		$default_slugs = array();
		foreach ( $types as $type_key => $type ) {
			$slug = self::resolve_cct_slug( $type, $type_key );
			if ( '' !== $slug ) {
				$default_slugs[] = $slug;
			}
		}
		$default_slugs = array_values( array_unique( $default_slugs ) );

		/**
		 * Filter the list of CCT slugs indexed by the knowledge graph.
		 *
		 * Return an empty array to disable CCT indexing entirely, or a subset
		 * of slugs to index only specific content types.
		 *
		 * @since 0.7.0
		 *
		 * @param string[] $slugs Sanitised CCT slugs.
		 */
		$indexed_slugs = apply_filters( 'nvoos_graphify_indexed_cct_slugs', $default_slugs );
		$indexed_slugs = array_map( 'sanitize_key', (array) $indexed_slugs );

		$rows = array();

		foreach ( $types as $type_key => $type ) {
			$slug = self::resolve_cct_slug( $type, $type_key );
			if ( '' === $slug ) {
				continue;
			}

			if ( ! in_array( $slug, $indexed_slugs, true ) ) {
				continue;
			}

			// Resolve human-readable name.
			$name = '';
			if ( is_object( $type ) && ! empty( $type->name ) ) {
				$name = $type->name;
			} elseif ( is_object( $type ) && ! empty( $type->args ) && ! empty( $type->args['name'] ) ) {
				$name = $type->args['name'];
			} elseif ( is_array( $type ) && ! empty( $type['name'] ) ) {
				$name = $type['name'];
			} elseif ( is_array( $type ) && ! empty( $type['args']['name'] ) ) {
				$name = $type['args']['name'];
			} else {
				$name = $slug;
			}

			$db = is_object( $type ) && ! empty( $type->db ) ? $type->db : null;
			if ( null === $db || ! method_exists( $db, 'query' ) ) {
				continue;
			}

			if ( method_exists( $db, 'set_format_flag' ) ) {
				$db->set_format_flag( ARRAY_A );
			}

			$filter_args = array();
			if ( $since ) {
				// JetEngine's query() supports comparison operators via array
				// values shaped as ['key' => '...', 'value' => '...', 'compare' => '>'].
				$filter_args[] = array(
					'key'     => 'cct_modified',
					'value'   => sanitize_text_field( $since ),
					'compare' => '>',
				);
			}

			$items = $db->query( $filter_args, $per_type_limit, 0 );
			if ( ! is_array( $items ) || empty( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( is_object( $item ) ) {
					$item = (array) $item;
				}
				if ( ! is_array( $item ) || empty( $item['_ID'] ) ) {
					continue;
				}
				$rows[] = array(
					'type' => $slug,
					'name' => $name,
					'item' => $item,
				);
			}
		}

		if ( empty( $rows ) && '' === self::$last_ccts_skip_reason ) {
			self::$last_ccts_skip_reason = 'all_content_types_empty_or_unindexed';
		}

		return $rows;
	}

	/**
	 * Resolve a sanitised CCT slug from a JetEngine content-type entry.
	 *
	 * JetEngine's CCT type instances historically expose the slug only via
	 * `$type->args['slug']` (older builds) and may also expose a public
	 * `$type->slug` property (newer builds). Some integration shims pass the
	 * type as an associative array, and the manager always indexes the
	 * content-types map by slug — so the array key is a reliable last-resort
	 * fallback.
	 *
	 * Centralising this fallback chain ensures the indexed-slug allowlist
	 * built up-front and the per-type iteration agree on the same slug for
	 * the same type, which is what makes the `nvoos_graphify_indexed_cct_slugs`
	 * filter behave predictably.
	 *
	 * @since 0.7.1
	 *
	 * @param object|array $type     JetEngine content-type entry.
	 * @param string|int   $type_key Associative key from the content-types map.
	 * @return string Sanitised slug, or empty string when none could be resolved.
	 */
	private static function resolve_cct_slug( $type, $type_key = '' ) {
		$slug = '';
		if ( is_object( $type ) && ! empty( $type->slug ) ) {
			$slug = $type->slug;
		} elseif ( is_object( $type ) && ! empty( $type->args ) && ! empty( $type->args['slug'] ) ) {
			$slug = $type->args['slug'];
		} elseif ( is_array( $type ) && ! empty( $type['slug'] ) ) {
			$slug = $type['slug'];
		} elseif ( is_array( $type ) && ! empty( $type['args']['slug'] ) ) {
			$slug = $type['args']['slug'];
		} elseif ( is_string( $type_key ) && '' !== $type_key ) {
			$slug = $type_key;
		}

		return sanitize_key( $slug );
	}

	/**
	 * Collect rows from NV oOS-owned custom $wpdb tables.
	 *
	 * Returns a flat array of row descriptors, each shaped:
	 * ```php
	 * array(
	 *   'node_id'    => string  // e.g. `ext_slash_cmd_audit_42`
	 *   'node_type'  => string  // e.g. `ext_slash_cmd_audit`
	 *   'label'      => string  // Human-readable label for the node.
	 *   'content'    => string  // Body text for semantic extraction.
	 *   'properties' => array   // Arbitrary key→value properties stored on the node.
	 *   'fk_edges'   => array[] // Pre-built FK edge descriptors.
	 * )
	 * ```
	 *
	 * The full table registry comes from the `nvoos_graphify_external_tables`
	 * filter (populated by `NV_oOS_Graphify_NV_oOS_Bridge::register_external_tables()`).
	 * Each descriptor supplies a `label_field` / `label_callback` and
	 * `content_field` / `content_callback` for flexible extraction.
	 *
	 * A per-table row cap is enforced via the
	 * `nvoos_graphify_external_table_limit` filter (default 1 000).
	 *
	 * @since 0.8.0
	 *
	 * @param string $since Optional ISO-8601 datetime; when supplied, only rows
	 *                      with a `modified_field` column value > $since are
	 *                      returned (incremental builds).
	 * @return array[]
	 */
	public static function detect_external_rows( $since = '' ) {
		global $wpdb;

		self::$last_external_skip_reason = '';

		/**
		 * Filter the list of external table descriptors to index.
		 *
		 * Each element is an associative array (see method docblock for shape).
		 * Populated by `NV_oOS_Graphify_NV_oOS_Bridge::register_external_tables()`.
		 *
		 * @since 0.8.0
		 *
		 * @param array[] $tables Empty array; bridges append descriptors.
		 */
		$table_descriptors = apply_filters( 'nvoos_graphify_external_tables', array() );

		if ( empty( $table_descriptors ) || ! is_array( $table_descriptors ) ) {
			self::$last_external_skip_reason = 'no_external_tables_registered';
			return array();
		}

		/**
		 * Filter the maximum number of rows pulled per external table.
		 *
		 * @since 0.8.0
		 *
		 * @param int $limit Maximum rows per table (default 1 000).
		 */
		$per_table_limit = (int) apply_filters(
			'nvoos_graphify_external_table_limit',
			NV_oOS_Graphify_NV_oOS_Bridge::DEFAULT_EXTERNAL_TABLE_LIMIT
		);
		if ( $per_table_limit <= 0 ) {
			$per_table_limit = NV_oOS_Graphify_NV_oOS_Bridge::DEFAULT_EXTERNAL_TABLE_LIMIT;
		}

		$rows = array();

		foreach ( $table_descriptors as $descriptor ) {
			if ( empty( $descriptor['table'] ) || empty( $descriptor['primary_key'] ) ) {
				continue;
			}

			$table       = $wpdb->prefix . sanitize_key( $descriptor['table'] );
			$primary_key = sanitize_key( $descriptor['primary_key'] );
			$node_type   = sanitize_key( isset( $descriptor['node_type'] ) ? $descriptor['node_type'] : 'ext_' . sanitize_key( $descriptor['table'] ) );
			$label_field = isset( $descriptor['label_field'] ) ? sanitize_key( (string) $descriptor['label_field'] ) : '';
			$content_fld = isset( $descriptor['content_field'] ) ? sanitize_key( (string) $descriptor['content_field'] ) : '';
			$mod_field   = isset( $descriptor['modified_field'] ) ? sanitize_key( (string) $descriptor['modified_field'] ) : '';
			$fk_defs     = isset( $descriptor['foreign_keys'] ) && is_array( $descriptor['foreign_keys'] )
				? $descriptor['foreign_keys']
				: array();

			// Verify the table exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $table !== $exists ) {
				continue;
			}

			// Build WHERE clause for incremental builds.
			$where_clause = '';
			if ( $since && '' !== $mod_field ) {
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
				$where_clause = $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					" WHERE `{$mod_field}` > %s",
					sanitize_text_field( $since )
				);
			}

			// Determine columns to fetch: PK + label + content + FK locals.
			$columns = array( '`' . $primary_key . '`' );
			if ( '' !== $label_field ) {
				$columns[] = '`' . $label_field . '`';
			}
			if ( '' !== $content_fld ) {
				$columns[] = '`' . $content_fld . '`';
			}
			foreach ( $fk_defs as $fk ) {
				if ( ! empty( $fk['local_column'] ) ) {
					$col = '`' . sanitize_key( $fk['local_column'] ) . '`';
					if ( ! in_array( $col, $columns, true ) ) {
						$columns[] = $col;
					}
				}
			}
			$columns_sql = implode( ', ', $columns );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$db_rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT {$columns_sql} FROM `{$table}`{$where_clause} ORDER BY `{$primary_key}` DESC LIMIT %d",
					$per_table_limit
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $db_rows ) || ! is_array( $db_rows ) ) {
				continue;
			}

			foreach ( $db_rows as $db_row ) {
				$pk_value = isset( $db_row[ $primary_key ] ) ? absint( $db_row[ $primary_key ] ) : 0;
				if ( 0 === $pk_value ) {
					continue;
				}

				$node_id = self::external_node_id( $node_type, $pk_value );

				// Resolve label.
				$label = '';
				if ( ! empty( $descriptor['label_callback'] ) && is_callable( $descriptor['label_callback'] ) ) {
					$label = (string) call_user_func( $descriptor['label_callback'], $db_row );
				} elseif ( '' !== $label_field && ! empty( $db_row[ $label_field ] ) && is_scalar( $db_row[ $label_field ] ) ) {
					$label = (string) $db_row[ $label_field ];
				}
				if ( '' === $label ) {
					/* translators: 1: node type, 2: numeric ID */
					$label = sprintf( __( '%1$s #%2$d', 'nvoos-graphify' ), $node_type, $pk_value );
				}

				// Resolve content.
				$content = '';
				if ( ! empty( $descriptor['content_callback'] ) && is_callable( $descriptor['content_callback'] ) ) {
					$content = (string) call_user_func( $descriptor['content_callback'], $db_row );
				} elseif ( '' !== $content_fld && ! empty( $db_row[ $content_fld ] ) && is_scalar( $db_row[ $content_fld ] ) ) {
					$content = (string) $db_row[ $content_fld ];
				}

				// Build FK edges.
				$fk_edges = array();
				foreach ( $fk_defs as $fk ) {
					if ( empty( $fk['local_column'] ) || empty( $fk['target_type'] ) || empty( $fk['relation'] ) ) {
						continue;
					}
					$local_col = sanitize_key( $fk['local_column'] );
					if ( empty( $db_row[ $local_col ] ) ) {
						continue;
					}
					$target_pk      = absint( $db_row[ $local_col ] );
					$target_node_id = self::external_node_id( sanitize_key( $fk['target_type'] ), $target_pk );
					$fk_edges[]     = array(
						'source_node_id' => $node_id,
						'target_node_id' => $target_node_id,
						'relation'       => sanitize_text_field( $fk['relation'] ),
						'confidence'     => 1.0,
						'provenance'     => 'EXTRACTED',
					);
				}

				// Build node properties from scalar columns.
				$properties = array( 'table' => $descriptor['table'] );
				foreach ( $db_row as $col => $val ) {
					if ( is_scalar( $val ) && '' !== (string) $val ) {
						$properties[ sanitize_key( $col ) ] = (string) $val;
					}
				}

				$rows[] = array(
					'node_id'    => $node_id,
					'node_type'  => $node_type,
					'label'      => $label,
					'content'    => $content,
					'properties' => $properties,
					'fk_edges'   => $fk_edges,
				);
			}
		}

		return $rows;
	}

	/**
	 * Reason the last external-row detection pass returned no rows, if any.
	 *
	 * @since 0.8.0
	 * @var string
	 */
	private static $last_external_skip_reason = '';

	/**
	 * Return the reason external-row detection was skipped on the most recent call.
	 *
	 * @since 0.8.0
	 *
	 * @return string Empty string when detection ran normally.
	 */
	public static function get_last_external_skip_reason() {
		return self::$last_external_skip_reason;
	}

	// -------------------------------------------------------------------------
	// Node ID helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a stable node_id for a post.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id   WordPress post ID.
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function post_node_id( $post_id, $post_type = 'post' ) {
		return 'post_' . absint( $post_id );
	}

	/**
	 * Generate a stable node_id for a term.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function term_node_id( $term_id, $taxonomy ) {
		return 'term_' . absint( $term_id ) . '_' . sanitize_key( $taxonomy );
	}

	/**
	 * Generate a stable node_id for a user.
	 *
	 * @since 0.5.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function user_node_id( $user_id ) {
		return 'user_' . absint( $user_id );
	}

	/**
	 * Generate a stable node_id for an attachment.
	 *
	 * @since 0.5.0
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 * @return string
	 */
	public static function media_node_id( $attachment_id ) {
		return 'media_' . absint( $attachment_id );
	}

	/**
	 * Generate a stable node_id for a JetEngine CCT item.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug    CCT slug.
	 * @param int    $item_id Item ID (the `_ID` column).
	 * @return string
	 */
	public static function cct_node_id( $slug, $item_id ) {
		return 'cct_' . sanitize_key( $slug ) . '_' . absint( $item_id );
	}

	/**
	 * Fetch a single JetEngine CCT item by slug + numeric ID.
	 *
	 * Used by the semantic extractor's cron handler to re-hydrate a CCT
	 * row from a tiny `[ slug, id ]` payload (keeps cron args small and
	 * avoids stale snapshots).
	 *
	 * @since 0.7.1
	 *
	 * @param string $slug    CCT slug.
	 * @param int    $item_id Item `_ID` column value.
	 * @return array|null {
	 *     @type string $type CCT slug (sanitised).
	 *     @type string $name Human-readable type name.
	 *     @type array  $item CCT row (associative array).
	 * } NULL when JetEngine isn't loaded, the slug is unknown, or the row is missing.
	 */
	public static function get_cct_item( $slug, $item_id ) {
		$slug    = sanitize_key( $slug );
		$item_id = absint( $item_id );
		if ( '' === $slug || 0 === $item_id ) {
			return null;
		}
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();
		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'get_module' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		$module = $module_wrapper->instance;
		if ( empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			return null;
		}

		$types = $module->manager->get_content_types();
		if ( empty( $types ) || ! is_array( $types ) ) {
			return null;
		}

		foreach ( $types as $type ) {
			$type_slug = '';
			if ( ! empty( $type->slug ) ) {
				$type_slug = $type->slug;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['slug'] ) ) {
				$type_slug = $type->args['slug'];
			}
			$type_slug = sanitize_key( $type_slug );
			if ( $type_slug !== $slug ) {
				continue;
			}

			if ( empty( $type->db ) || ! method_exists( $type->db, 'query' ) ) {
				return null;
			}

			if ( method_exists( $type->db, 'set_format_flag' ) ) {
				$type->db->set_format_flag( ARRAY_A );
			}

			$rows = $type->db->query(
				array(
					array(
						'key'   => '_ID',
						'value' => $item_id,
					),
				),
				1,
				0
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return null;
			}

			$item = $rows[0];
			if ( is_object( $item ) ) {
				$item = (array) $item;
			}
			if ( ! is_array( $item ) || empty( $item['_ID'] ) ) {
				return null;
			}

			$name = '';
			if ( ! empty( $type->name ) ) {
				$name = $type->name;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['name'] ) ) {
				$name = $type->args['name'];
			} else {
				$name = $slug;
			}

			return array(
				'type' => $slug,
				'name' => $name,
				'item' => $item,
			);
		}

		return null;
	}

	/**
	 * Generate a stable node_id for a custom $wpdb table row.
	 *
	 * @since 0.8.0
	 *
	 * @param string $node_type  Node type string (e.g. `ext_slash_cmd_audit`).
	 * @param int    $primary_key_value Integer primary-key value.
	 * @return string
	 */
	public static function external_node_id( $node_type, $primary_key_value ) {
		return sanitize_key( $node_type ) . '_' . absint( $primary_key_value );
	}

	/**
	 * Generate a stable node_id for a named entity or topic string.
	 *
	 * Uses a short hash so labels with special characters remain safe to store.
	 *
	 * @since 0.5.0
	 *
	 * @param string $label Entity/topic label.
	 * @param string $type  Entity type (entity|topic).
	 * @return string
	 */
	public static function entity_node_id( $label, $type = 'entity' ) {
		return $type . '_' . substr( hash( 'sha256', strtolower( trim( $label ) ) ), 0, 16 );
	}
}
