<?php
/**
 * Food & Beverage Management Toolkit — Native CPT Storage Trait.
 *
 * Shared storage mechanics for the five Phase 9 native CPTs (sale, purchase,
 * stock count, waste, booking): standardised registration arguments, row
 * upsert keyed on the spec's idempotency columns, and the before/after log
 * hooks (`wp_mcp_ai_fnb_before_<type>_log` / `wp_mcp_ai_fnb_after_<type>_log`).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native-storage helpers shared by the F&B CPT classes.
 *
 * @since 1.6.0
 */
trait WP_MCP_AI_Fnb_Native_Cpt_Storage {

	/**
	 * Register a native-storage CPT with the toolkit's standard arguments.
	 *
	 * The CPTs are visible in the admin UI (linked from the F&B Management
	 * menu) but stay out of the front end, REST and search.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $singular  Singular label.
	 * @param string $plural    Plural label.
	 * @return true|WP_Error True when registered (or already registered).
	 */
	protected static function register_cpt( $post_type, $singular, $plural ) {
		if ( post_type_exists( $post_type ) ) {
			return true;
		}

		$labels = array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			'add_new'       => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => /* translators: %s: singular label */ sprintf( __( 'Add New %s', 'mcp-ai-wpoos-pro' ), $singular ),
			'edit_item'     => /* translators: %s: singular label */ sprintf( __( 'Edit %s', 'mcp-ai-wpoos-pro' ), $singular ),
			'search_items'  => /* translators: %s: plural label */ sprintf( __( 'Search %s', 'mcp-ai-wpoos-pro' ), $plural ),
			'not_found'     => /* translators: %s: plural label */ sprintf( __( 'No %s found.', 'mcp-ai-wpoos-pro' ), $plural ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			// Proposal 084 Phase 4d W14: the console Records feature
			// consumes F&B entities via wp/v2.
			'show_in_rest'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'author', 'custom-fields' ),
			'exclude_from_search' => true,
		);

		/**
		 * Filter the F&B native-storage CPT registration arguments.
		 *
		 * @param array  $args      Arguments passed to register_post_type().
		 * @param string $post_type Post type slug.
		 */
		$args = apply_filters( 'wp_mcp_ai_fnb_native_cpt_args', $args, $post_type );

		$result = register_post_type( $post_type, $args );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Upsert a canonical row into a native-storage CPT.
	 *
	 * Idempotent: rows matching every key column are updated in place instead
	 * of duplicated. The before/after log hooks fire for both actions.
	 *
	 * @param string $post_type   Post type slug.
	 * @param string $table_id    Spec table ID (drives the canonical column list).
	 * @param array  $row         Canonical-keyed row values. Optional `raw` key: JSON header => value map of the full source row, preserved under `_fnb_raw` meta.
	 * @param string $title       Post title for new rows.
	 * @param string $date        Date value for post_date (Y-m-d or empty).
	 * @param array  $key_columns Canonical keys that make a row unique.
	 * @param string $hook_suffix Hook suffix (<type> in the log hook names).
	 * @return array|WP_Error array{action: 'insert'|'update', post_id: int}
	 */
	protected static function upsert_row( $post_type, $table_id, $row, $title, $date, $key_columns, $hook_suffix ) {
		$existing_id = self::find_row( $post_type, $row, $key_columns );
		$action      = null === $existing_id ? 'insert' : 'update';

		/**
		 * Fires before a native-storage row is written.
		 *
		 * @since 1.6.0
		 *
		 * @param array    $row    Canonical-keyed row values.
		 * @param string   $action insert|update.
		 * @param int|null $existing_id Existing post ID when updating.
		 */
		do_action( 'wp_mcp_ai_fnb_before_' . $hook_suffix . '_log', $row, $action, $existing_id );

		$post_data = array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => $title,
		);

		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$post_data['post_date'] = $date . ' 12:00:00';
		}

		$post_id = null === $existing_id ? wp_insert_post( $post_data, true ) : $existing_id;
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();
		foreach ( $canonical as $canonical_key ) {
			update_post_meta( $post_id, '_fnb_' . $canonical_key, isset( $row[ $canonical_key ] ) ? (string) $row[ $canonical_key ] : '' );
		}

		// Preserve the full raw source row (header => value JSON) supplied by
		// the import bridge, so non-canonical source columns survive the
		// import. Never overwritten with an empty value.
		if ( isset( $row['raw'] ) && is_string( $row['raw'] ) && '' !== $row['raw'] ) {
			update_post_meta( $post_id, '_fnb_raw', $row['raw'] );
		}

		/**
		 * Fires after a native-storage row is written.
		 *
		 * @since 1.6.0
		 *
		 * @param int    $post_id Post ID of the written row.
		 * @param array  $row     Canonical-keyed row values.
		 * @param string $action  insert|update.
		 */
		do_action( 'wp_mcp_ai_fnb_after_' . $hook_suffix . '_log', $post_id, $row, $action );

		return array(
			'action'  => $action,
			'post_id' => $post_id,
		);
	}

	/**
	 * Locate an existing row by its key columns.
	 *
	 * @param string $post_type   Post type slug.
	 * @param array  $row         Canonical-keyed row values.
	 * @param array  $key_columns Canonical keys that make a row unique.
	 * @return int|null Existing post ID or null.
	 */
	protected static function find_row( $post_type, $row, $key_columns ) {
		$meta_query = array();
		foreach ( $key_columns as $key ) {
			$meta_query[] = array(
				'key'   => '_fnb_' . $key,
				'value' => isset( $row[ $key ] ) ? (string) $row[ $key ] : '',
			);
		}
		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		return empty( $posts ) ? null : (int) $posts[0];
	}
}
