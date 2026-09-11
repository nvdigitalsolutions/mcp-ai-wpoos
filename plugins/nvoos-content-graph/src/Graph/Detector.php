<?php
declare(strict_types=1);

namespace NvoosContentGraph\Graph;

use NvoosContentGraph\Settings;
use WP_Post;
use WP_Term;
use WP_User;
use function absint;
use function apply_filters;
use function get_posts;
use function sanitize_key;
use function sanitize_text_field;
use function wp_list_pluck;

/**
 * Content detector for the knowledge graph.
 *
 * Inventories published WordPress content and returns a list of items
 * that need to be (re-)indexed. Supports incremental detection by
 * comparing post_modified against the last-indexed timestamp.
 *
 * @since 1.0.0
 */
class Detector {

	/** @var int Default per-type cap on CCT items. */
	public const DEFAULT_CCT_ITEMS_LIMIT = 1000;

	/** @var string Reason CCT detection was skipped. */
	private static string $lastCctsSkipReason = '';

	/** @var string Reason external detection was skipped. */
	private static string $lastExternalSkipReason = '';

	/** @var array<string,array{status: string, items: int}> Per-slug CCT detection report from the most recent detectCcts() run. */
	private static array $cctTypeReport = array();

	/** @var string Status: CCT type was indexed during the last detection run. */
	public const CCT_STATUS_INDEXED = 'indexed';

	/** @var string Status: CCT type is allowed but its table currently holds no items. */
	public const CCT_STATUS_EMPTY = 'empty';

	/** @var string Status: CCT type is registered but its JetEngine table has not been created yet. */
	public const CCT_STATUS_TABLE_MISSING = 'table_missing';

	/** @var string Status: CCT type has no usable database handler. */
	public const CCT_STATUS_DB_UNAVAILABLE = 'db_unavailable';

	/** @var string Status: CCT type query returned a non-array result. */
	public const CCT_STATUS_QUERY_FAILED = 'query_failed';

	/** @var string Status: CCT type is excluded from indexing via settings. */
	public const CCT_STATUS_EXCLUDED = 'excluded';

	/** @return string */
	public static function getLastCctsSkipReason(): string {
		return self::$lastCctsSkipReason;
	}

	/** @return string */
	public static function getLastExternalSkipReason(): string {
		return self::$lastExternalSkipReason;
	}

	/**
	 * Return the per-slug CCT report produced by the most recent detection run.
	 *
	 * Each entry maps a CCT slug to its status (one of the CCT_STATUS_*
	 * constants) and the number of items detected for it. The report is empty
	 * until {@see detectCcts()} has run at least once; use
	 * {@see inspectCctTypes()} for a lightweight on-demand snapshot instead
	 * (e.g. when rendering the admin Sources tab).
	 *
	 * @since 1.0.7
	 *
	 * @return array<string,array{status: string, items: int}>
	 */
	public static function getCctTypeReport(): array {
		return self::$cctTypeReport;
	}

	/**
	 * Collect all content items that should be represented as nodes.
	 *
	 * @param bool   $incremental When true, only return items newer than last build.
	 * @param string $since       ISO-8601 datetime string (overrides incremental flag).
	 * @return array{posts: WP_Post[], ccts: array, terms: WP_Term[], users: WP_User[], media: WP_Post[], external: array}
	 */
	public static function detect( bool $incremental = false, string $since = '' ): array {
		if ( $incremental && ! $since ) {
			$since = Db::getMeta( 'last_build_completed', '' );
		}

		$posts    = self::detectPosts( $since );
		$ccts     = self::detectCcts( $since );
		$terms    = self::detectTerms( $posts );
		$users    = self::detectUsers( $posts, $ccts );
		$media    = self::detectMedia( $posts );
		$external = array(); // External table detection deferred to pro addon.

		return compact( 'posts', 'ccts', 'terms', 'users', 'media', 'external' );
	}

	// ─── Post detection ────────────────────────────────────────

	/**
	 * Return published posts across configured post types.
	 *
	 * @param string $since Optional datetime filter.
	 * @return WP_Post[]
	 */
	public static function detectPosts( string $since = '' ): array {
		$allSettings = Settings::all();
		$postTypes   = isset( $allSettings['post_types'] ) && is_array( $allSettings['post_types'] )
			? $allSettings['post_types']
			: self::getDefaultPostTypes();

		// @todo Batch this query for sites with 10 000+ posts.  The
		// query is gated by no_found_rows and a date_query on incremental
		// builds, but a full rebuild on a large site can still OOM.
		// A 500-post chunk size with a looped offset would be safer.
		$args = array(
			'post_type'      => array_map( 'sanitize_key', $postTypes ),
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

		$results = get_posts( $args );
		return is_array( $results ) ? $results : array();
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
	 * @return string[]
	 */
	public static function getDefaultPostTypes(): array {
		$candidates = array_keys( get_post_types( array( 'public' => true ), 'names' ) );

		$systemBlacklist = array(
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

		$postTypes = array_values( array_diff( $candidates, $systemBlacklist ) );

		/** @var string[] */
		$postTypes = apply_filters( 'nvoos_content_graph_indexed_post_types', $postTypes );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $postTypes ) ) );
	}

	// ─── Term detection ────────────────────────────────────────

	/** @param WP_Post[] $posts @return WP_Term[] */
	public static function detectTerms( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}

		$postIds    = wp_list_pluck( $posts, 'ID' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$terms      = wp_get_object_terms( $postIds, array_values( $taxonomies ), array( 'fields' => 'all' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$unique = array();
		foreach ( $terms as $term ) {
			$unique[ $term->term_id ] = $term;
		}
		return array_values( $unique );
	}

	// ─── User/author detection ─────────────────────────────────

	/** @param WP_Post[] $posts @param array $ccts @return WP_User[] */
	public static function detectUsers( array $posts, array $ccts = array() ): array {
		$authorIds = array();

		if ( ! empty( $posts ) ) {
			$authorIds = array_merge( $authorIds, array_map( 'absint', wp_list_pluck( $posts, 'post_author' ) ) );
		}
		foreach ( $ccts as $row ) {
			if ( ! empty( $row['item']['cct_author_id'] ) ) {
				$authorIds[] = absint( $row['item']['cct_author_id'] );
			}
		}
		$authorIds = array_unique( array_filter( $authorIds ) );

		$users = array();
		foreach ( $authorIds as $uid ) {
			$user = get_userdata( $uid );
			if ( $user instanceof \WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	// ─── Media detection ───────────────────────────────────────

	/** @param WP_Post[] $posts @return WP_Post[] */
	public static function detectMedia( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}

		$attachmentIds = array();
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumb > 0 ) {
				$attachmentIds[] = $thumb;
			}
		}
		$attachmentIds = array_unique( $attachmentIds );

		if ( empty( $attachmentIds ) ) {
			return array();
		}

		$media = get_posts(
			array(
				'post_type'      => 'attachment',
				'post__in'       => $attachmentIds,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		return is_array( $media ) ? $media : array();
	}

	// ─── JetEngine CCT detection ───────────────────────────────

	/**
	 * Enumerate the JetEngine Custom Content Types registered on the site.
	 *
	 * CCTs live in dedicated `{prefix}jet_cct_{slug}` tables and are
	 * invisible to {@see get_post_types()} / {@see get_posts()}, so they
	 * have to be enumerated through the JetEngine API. Also powers the
	 * Sources tab checkbox grid, which shares the same enumeration.
	 *
	 * Sets {@see self::$lastCctsSkipReason} on every unavailable path.
	 *
	 * @return array<int,array{slug: string, name: string, db: object|null}>
	 */
	public static function getCctTypes(): array {
		self::$lastCctsSkipReason = '';

		if ( ! function_exists( 'jet_engine' ) ) {
			self::$lastCctsSkipReason = 'jetengine_not_active';
			return array();
		}

		$engine = jet_engine();
		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'get_module' ) ) {
			self::$lastCctsSkipReason = 'jetengine_modules_unavailable';
			return array();
		}

		$moduleWrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $moduleWrapper ) || empty( $moduleWrapper->instance ) ) {
			self::$lastCctsSkipReason = 'cct_module_inactive';
			return array();
		}

		$module = $moduleWrapper->instance;
		if ( empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			self::$lastCctsSkipReason = 'cct_manager_unavailable';
			return array();
		}

		$types = $module->manager->get_content_types();
		if ( empty( $types ) || ! is_array( $types ) ) {
			self::$lastCctsSkipReason = 'no_content_types_registered';
			return array();
		}

		$list = array();
		foreach ( $types as $typeKey => $type ) {
			$slug = self::resolveCctSlug( $type, $typeKey );
			if ( '' === $slug ) {
				continue;
			}
			$list[] = array(
				'slug' => $slug,
				'name' => self::resolveCctName( $type, $slug ),
				'db'   => ( is_object( $type ) && ! empty( $type->db ) ) ? $type->db : null,
			);
		}

		return $list;
	}

	/**
	 * Return JetEngine Custom Content Type items that should be indexed.
	 *
	 * Every registered CCT is indexed by default; unchecking a CCT on the
	 * Sources tab stores its slug in the `excluded_cct_slugs` setting and
	 * removes it from the allowlist here.
	 *
	 * @param string $since Optional ISO-8601 datetime for incremental builds.
	 * @return array<int,array{type: string, name: string, item: array}>
	 */
	public static function detectCcts( string $since = '' ): array {
		self::$cctTypeReport = array();

		$types = self::getCctTypes();
		if ( empty( $types ) ) {
			return array();
		}

		$perTypeLimit = (int) apply_filters( 'nvoos_content_graph_cct_items_limit', self::DEFAULT_CCT_ITEMS_LIMIT );
		if ( $perTypeLimit <= 0 ) {
			$perTypeLimit = self::DEFAULT_CCT_ITEMS_LIMIT;
		}

		$defaultSlugs = array_values( array_unique( wp_list_pluck( $types, 'slug' ) ) );

		$allSettings = Settings::all();
		$excluded    = isset( $allSettings['excluded_cct_slugs'] ) && is_array( $allSettings['excluded_cct_slugs'] )
			? $allSettings['excluded_cct_slugs'] : array();
		$allowed     = array_values( array_diff( $defaultSlugs, array_map( 'sanitize_key', $excluded ) ) );

		/** @var string[] */
		$indexedSlugs = apply_filters( 'nvoos_content_graph_indexed_cct_slugs', $allowed );
		$indexedSlugs = array_map( 'sanitize_key', (array) $indexedSlugs );

		$rows = array();

		foreach ( $types as $type ) {
			$slug = $type['slug'];
			if ( '' === $slug ) {
				continue;
			}

			if ( ! in_array( $slug, $indexedSlugs, true ) ) {
				self::$cctTypeReport[ $slug ] = array(
					'status' => self::CCT_STATUS_EXCLUDED,
					'items'  => 0,
				);
				continue;
			}

			$name = $type['name'];
			$db   = $type['db'];
			if ( null === $db || ! method_exists( $db, 'query' ) ) {
				self::$cctTypeReport[ $slug ] = array(
					'status' => self::CCT_STATUS_DB_UNAVAILABLE,
					'items'  => 0,
				);
				continue;
			}

			// JetEngine creates CCT tables lazily: a registered type whose
			// table has not been created yet must be reported — not queried
			// into a wpdb "table doesn't exist" error.
			if ( method_exists( $db, 'is_table_exists' ) && ! $db->is_table_exists() ) {
				self::$cctTypeReport[ $slug ] = array(
					'status' => self::CCT_STATUS_TABLE_MISSING,
					'items'  => 0,
				);
				continue;
			}

			if ( method_exists( $db, 'set_format_flag' ) ) {
				$db->set_format_flag( ARRAY_A );
			}

			$filterArgs = array();
			if ( $since ) {
				$filterArgs[] = array(
					'key'     => 'cct_modified',
					'value'   => sanitize_text_field( $since ),
					'compare' => '>',
				);
			}

			$items = $db->query( $filterArgs, $perTypeLimit, 0 );
			if ( ! is_array( $items ) ) {
				self::$cctTypeReport[ $slug ] = array(
					'status' => self::CCT_STATUS_QUERY_FAILED,
					'items'  => 0,
				);
				continue;
			}

			if ( empty( $items ) ) {
				self::$cctTypeReport[ $slug ] = array(
					'status' => self::CCT_STATUS_EMPTY,
					'items'  => 0,
				);
				continue;
			}

			$indexedCount = 0;
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
				++$indexedCount;
			}

			self::$cctTypeReport[ $slug ] = array(
				'status' => self::CCT_STATUS_INDEXED,
				'items'  => $indexedCount,
			);
		}

		if ( empty( $rows ) && '' === self::$lastCctsSkipReason ) {
			self::$lastCctsSkipReason = 'all_content_types_empty_or_unindexed';
		}

		return $rows;
	}

	/**
	 * Return a lightweight per-CCT status snapshot for the admin Sources tab.
	 *
	 * Unlike {@see detectCcts()} this never pulls rows: it only checks
	 * whether the JetEngine table exists and counts its items, so it is safe
	 * to run while rendering the settings page. Each entry maps a CCT slug to
	 * its status (one of the CCT_STATUS_* constants) and item count, mirroring
	 * the shape of {@see getCctTypeReport()}.
	 *
	 * @since 1.0.7
	 *
	 * @return array<string,array{status: string, items: int}>
	 */
	public static function inspectCctTypes(): array {
		$report = array();

		$types = self::getCctTypes();
		if ( empty( $types ) ) {
			return $report;
		}

		$defaultSlugs = array_values( array_unique( wp_list_pluck( $types, 'slug' ) ) );

		$allSettings = Settings::all();
		$excluded    = isset( $allSettings['excluded_cct_slugs'] ) && is_array( $allSettings['excluded_cct_slugs'] )
			? $allSettings['excluded_cct_slugs'] : array();
		$allowed     = array_values( array_diff( $defaultSlugs, array_map( 'sanitize_key', $excluded ) ) );

		/** @var string[] */
		$indexedSlugs = apply_filters( 'nvoos_content_graph_indexed_cct_slugs', $allowed );
		$indexedSlugs = array_map( 'sanitize_key', (array) $indexedSlugs );

		foreach ( $types as $type ) {
			$slug = $type['slug'];
			if ( '' === $slug ) {
				continue;
			}

			if ( ! in_array( $slug, $indexedSlugs, true ) ) {
				$report[ $slug ] = array(
					'status' => self::CCT_STATUS_EXCLUDED,
					'items'  => 0,
				);
				continue;
			}

			$db = $type['db'];
			if ( null === $db || ! method_exists( $db, 'query' ) ) {
				$report[ $slug ] = array(
					'status' => self::CCT_STATUS_DB_UNAVAILABLE,
					'items'  => 0,
				);
				continue;
			}

			if ( method_exists( $db, 'is_table_exists' ) && ! $db->is_table_exists() ) {
				$report[ $slug ] = array(
					'status' => self::CCT_STATUS_TABLE_MISSING,
					'items'  => 0,
				);
				continue;
			}

			$count           = method_exists( $db, 'count' ) ? (int) $db->count() : 0;
			$report[ $slug ] = array(
				'status' => $count > 0 ? self::CCT_STATUS_INDEXED : self::CCT_STATUS_EMPTY,
				'items'  => $count,
			);
		}

		return $report;
	}

	/** @param object|array $type @param string|int $typeKey @return string */
	private static function resolveCctSlug( $type, $typeKey = '' ): string {
		$slug = '';
		if ( is_object( $type ) && ! empty( $type->slug ) ) {
			$slug = $type->slug;
		} elseif ( is_object( $type ) && ! empty( $type->args ) && ! empty( $type->args['slug'] ) ) {
			$slug = $type->args['slug'];
		} elseif ( is_array( $type ) && ! empty( $type['slug'] ) ) {
			$slug = $type['slug'];
		} elseif ( is_array( $type ) && ! empty( $type['args']['slug'] ) ) {
			$slug = $type['args']['slug'];
		} elseif ( is_string( $typeKey ) && '' !== $typeKey ) {
			$slug = $typeKey;
		}
		return sanitize_key( $slug );
	}

	/** @param object|array $type @param string $slug @return string */
	private static function resolveCctName( $type, string $slug ): string {
		if ( is_object( $type ) && ! empty( $type->name ) ) {
			return $type->name;
		}
		if ( is_object( $type ) && ! empty( $type->args ) && ! empty( $type->args['name'] ) ) {
			return $type->args['name'];
		}
		if ( is_array( $type ) && ! empty( $type['name'] ) ) {
			return $type['name'];
		}
		if ( is_array( $type ) && ! empty( $type['args']['name'] ) ) {
			return $type['args']['name'];
		}
		return $slug;
	}

	// ─── Node ID helpers ───────────────────────────────────────

	/** @return string */
	public static function postNodeId( int $postId, string $postType = 'post' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- public API, future extensibility
		return 'post_' . absint( $postId );
	}

	/** @return string */
	public static function termNodeId( int $termId, string $taxonomy ): string {
		return 'term_' . absint( $termId ) . '_' . sanitize_key( $taxonomy );
	}

	/** @return string */
	public static function userNodeId( int $userId ): string {
		return 'user_' . absint( $userId );
	}

	/** @return string */
	public static function mediaNodeId( int $attachmentId ): string {
		return 'media_' . absint( $attachmentId );
	}

	/** @return string */
	public static function cctNodeId( string $slug, int $itemId ): string {
		return 'cct_' . sanitize_key( $slug ) . '_' . absint( $itemId );
	}

	/** @return string */
	public static function entityNodeId( string $label, string $type = 'entity' ): string {
		return $type . '_' . substr( hash( 'sha256', strtolower( trim( $label ) ) ), 0, 16 );
	}
}
