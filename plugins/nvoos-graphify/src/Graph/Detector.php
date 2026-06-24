<?php
declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Settings;
use WP_Post;
use WP_Term;
use WP_User;
use function absint;
use function apply_filters;
use function get_posts;
use function sanitize_key;
use function sanitize_text_field;

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

	/** @return string */
	public static function getLastCctsSkipReason(): string {
		return self::$lastCctsSkipReason;
	}

	/** @return string */
	public static function getLastExternalSkipReason(): string {
		return self::$lastExternalSkipReason;
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
	 * Includes any post type registered with `public => true` OR
	 * `show_in_rest => true`, excluding system internal post types.
	 *
	 * @return string[]
	 */
	public static function getDefaultPostTypes(): array {
		$candidates = array_unique(
			array_merge(
				array_keys( get_post_types( array( 'public' => true ), 'names' ) ),
				array_keys( get_post_types( array( 'show_in_rest' => true ), 'names' ) )
			)
		);

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
		$postTypes = apply_filters( 'nvoos_graphify_indexed_post_types', $postTypes );
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
	 * Return JetEngine Custom Content Type items that should be indexed.
	 *
	 * @param string $since Optional ISO-8601 datetime for incremental builds.
	 * @return array<int,array{type: string, name: string, item: array}>
	 */
	public static function detectCcts( string $since = '' ): array {
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

		$perTypeLimit = (int) apply_filters( 'nvoos_graphify_cct_items_limit', self::DEFAULT_CCT_ITEMS_LIMIT );
		if ( $perTypeLimit <= 0 ) {
			$perTypeLimit = self::DEFAULT_CCT_ITEMS_LIMIT;
		}

		$defaultSlugs = array();
		foreach ( $types as $typeKey => $type ) {
			$slug = self::resolveCctSlug( $type, $typeKey );
			if ( '' !== $slug ) {
				$defaultSlugs[] = $slug;
			}
		}
		$defaultSlugs = array_values( array_unique( $defaultSlugs ) );

		/** @var string[] */
		$indexedSlugs = apply_filters( 'nvoos_graphify_indexed_cct_slugs', $defaultSlugs );
		$indexedSlugs = array_map( 'sanitize_key', (array) $indexedSlugs );

		$rows = array();

		foreach ( $types as $typeKey => $type ) {
			$slug = self::resolveCctSlug( $type, $typeKey );
			if ( '' === $slug || ! in_array( $slug, $indexedSlugs, true ) ) {
				continue;
			}

			$name = self::resolveCctName( $type, $slug );

			$db = is_object( $type ) && ! empty( $type->db ) ? $type->db : null;
			if ( null === $db || ! method_exists( $db, 'query' ) ) {
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

		if ( empty( $rows ) && '' === self::$lastCctsSkipReason ) {
			self::$lastCctsSkipReason = 'all_content_types_empty_or_unindexed';
		}

		return $rows;
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
