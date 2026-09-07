<?php
/**
 * NV oOS Comic Reader — Series & Collections Taxonomy
 *
 * Registers the `nvoos_comic_series` and `nvoos_comic_collection`
 * taxonomies for Media Library attachments, mirroring Komga's series and
 * collections organization model on WordPress primitives.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy registration for comic series and collections.
 *
 * @since 0.4.0
 */
class NV_oOS_Comic_Reader_Taxonomy {

	/**
	 * Series taxonomy slug.
	 *
	 * @var string
	 */
	const SERIES_TAXONOMY = 'nvoos_comic_series';

	/**
	 * Collection taxonomy slug.
	 *
	 * @var string
	 */
	const COLLECTION_TAXONOMY = 'nvoos_comic_collection';

	/**
	 * Term meta key holding the ordered attachment IDs of a collection.
	 *
	 * @var string
	 */
	const COLLECTION_ORDER_META = '_nvoos_collection_order';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 11 );
	}

	/**
	 * Register the series and collection taxonomies for attachments.
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			self::SERIES_TAXONOMY,
			array( 'attachment' ),
			array(
				'labels'            => array(
					'name'          => __( 'Comic Series', 'nvoos-comic-reader' ),
					'singular_name' => __( 'Comic Series', 'nvoos-comic-reader' ),
				),
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::COLLECTION_TAXONOMY,
			array( 'attachment' ),
			array(
				'labels'            => array(
					'name'          => __( 'Comic Collections', 'nvoos-comic-reader' ),
					'singular_name' => __( 'Comic Collection', 'nvoos-comic-reader' ),
				),
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Get the ordered attachment IDs of a collection term.
	 *
	 * @param int $term_id Collection term ID.
	 * @return int[] Ordered attachment IDs.
	 */
	public static function get_collection_items( $term_id ) {
		$order = get_term_meta( $term_id, self::COLLECTION_ORDER_META, true );
		if ( ! is_array( $order ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $order ) ) );
	}

	/**
	 * Store the ordered attachment IDs of a collection term.
	 *
	 * @param int   $term_id  Collection term ID.
	 * @param int[] $item_ids Ordered attachment IDs.
	 * @return void
	 */
	public static function set_collection_items( $term_id, $item_ids ) {
		update_term_meta( $term_id, self::COLLECTION_ORDER_META, array_values( array_map( 'absint', $item_ids ) ) );
	}

	/**
	 * Add a comic to a collection (appends to the ordered list).
	 *
	 * @param int $term_id     Collection term ID.
	 * @param int $attachment_id Comic attachment ID.
	 * @return void
	 */
	public static function add_collection_item( $term_id, $attachment_id ) {
		$items   = self::get_collection_items( $term_id );
		$items[] = (int) $attachment_id;
		self::set_collection_items( $term_id, array_unique( $items ) );

		// Keep the term relationship in sync as well (for term queries).
		wp_set_object_terms( (int) $attachment_id, array( (int) $term_id ), self::COLLECTION_TAXONOMY, true );
	}

	/**
	 * Remove a comic from a collection.
	 *
	 * @param int $term_id       Collection term ID.
	 * @param int $attachment_id Comic attachment ID.
	 * @return void
	 */
	public static function remove_collection_item( $term_id, $attachment_id ) {
		$items = array_values(
			array_filter(
				self::get_collection_items( $term_id ),
				function ( $id ) use ( $attachment_id ) {
					return (int) $id !== (int) $attachment_id;
				}
			)
		);
		self::set_collection_items( $term_id, $items );

		wp_remove_object_terms( (int) $attachment_id, array( (int) $term_id ), self::COLLECTION_TAXONOMY );
	}
}
