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

		$posts = self::detect_posts( $since );
		$ccts  = self::detect_ccts( $since );
		$terms = self::detect_terms( $posts );
		$users = self::detect_users( $posts, $ccts );
		$media = self::detect_media( $posts );

		return compact( 'posts', 'ccts', 'terms', 'users', 'media' );
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
	 * Includes any post type registered with `public => true` OR
	 * `show_in_rest => true`. JetEngine-registered CPTs are frequently
	 * configured as REST-only (admin-managed data), so relying solely on
	 * the `public` flag would silently exclude them. The WordPress core
	 * "system" post types (revisions, navigation menu items, block
	 * patterns, FSE templates, etc.) are excluded.
	 *
	 * Sites can override the list with the
	 * `nvoos_graphify_indexed_post_types` filter.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	public static function get_default_post_types() {
		$candidates = array_unique(
			array_merge(
				array_keys( get_post_types( array( 'public' => true ), 'names' ) ),
				array_keys( get_post_types( array( 'show_in_rest' => true ), 'names' ) )
			)
		);

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
		if ( ! function_exists( 'jet_engine' ) ) {
			return array();
		}

		$engine = jet_engine();
		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'get_module' ) ) {
			return array();
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return array();
		}

		$module = $module_wrapper->instance;
		if ( empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			return array();
		}

		$types = $module->manager->get_content_types();
		if ( empty( $types ) || ! is_array( $types ) ) {
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
		$default_slugs = array_map( 'sanitize_key', wp_list_pluck( $types, 'slug' ) );
		$default_slugs = array_values( array_filter( $default_slugs ) );

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

		foreach ( $types as $type ) {
			$slug = '';
			if ( ! empty( $type->slug ) ) {
				$slug = $type->slug;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['slug'] ) ) {
				$slug = $type->args['slug'];
			}
			$slug = sanitize_key( $slug );
			if ( '' === $slug ) {
				continue;
			}

			if ( ! in_array( $slug, $indexed_slugs, true ) ) {
				continue;
			}

			// Resolve human-readable name.
			$name = '';
			if ( ! empty( $type->name ) ) {
				$name = $type->name;
			} elseif ( ! empty( $type->args ) && ! empty( $type->args['name'] ) ) {
				$name = $type->args['name'];
			} else {
				$name = $slug;
			}

			if ( empty( $type->db ) || ! method_exists( $type->db, 'query' ) ) {
				continue;
			}

			if ( method_exists( $type->db, 'set_format_flag' ) ) {
				$type->db->set_format_flag( ARRAY_A );
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

			$items = $type->db->query( $filter_args, $per_type_limit, 0 );
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

		return $rows;
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
