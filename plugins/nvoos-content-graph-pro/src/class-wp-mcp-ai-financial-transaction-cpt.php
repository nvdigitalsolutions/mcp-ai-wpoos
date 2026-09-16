<?php
/**
 * Financial toolkit ecosystem port (OpenTerminal lessons sub-cluster).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-financial-transaction-cpt.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base
 * Pro addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined.
 *
 * What this file is: The `mcp_ai_fin_txn` portfolio-transaction CPT.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `mcp-ai-wpoos-pro` → `nvoos-content-graph-pro`; `WP_MCP_AI_PRO_PATH .
 * 'includes/'` path swaps → `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Financial Transaction CPT Class
 *
 * @since 1.1.80
 */
class WP_MCP_AI_Financial_Transaction_CPT {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'mcp_ai_fin_txn';

	/**
	 * Meta keys.
	 */
	const META_TICKER      = '_mcp_ai_txn_ticker';
	const META_SIDE        = '_mcp_ai_txn_side';
	const META_QUANTITY    = '_mcp_ai_txn_quantity';
	const META_PRICE       = '_mcp_ai_txn_price';
	const META_FEE         = '_mcp_ai_txn_fee';
	const META_EXECUTED_AT = '_mcp_ai_txn_executed_at';
	const META_CURRENCY    = '_mcp_ai_txn_currency';

	/**
	 * Initialize the CPT.
	 *
	 * @since 1.1.80
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 1 );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since 1.1.80
	 */
	public static function register_post_type() {
		$labels = array(
			'name'          => __( 'Portfolio Transactions', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Portfolio Transaction', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Transactions', 'nvoos-content-graph-pro' ),
			'not_found'     => __( 'No transactions found', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'author' ),
			'show_in_rest'       => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Save transaction meta on post save.
	 *
	 * @since 1.1.80
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			self::META_TICKER      => array( 'sanitize_text_field', '' ),
			self::META_SIDE        => array( 'sanitize_key', 'buy' ),
			self::META_QUANTITY    => array( 'floatval', 0 ),
			self::META_PRICE       => array( 'floatval', 0 ),
			self::META_FEE         => array( 'floatval', 0 ),
			self::META_EXECUTED_AT => array( 'sanitize_text_field', '' ),
			self::META_CURRENCY    => array( 'sanitize_text_field', 'USD' ),
		);

		foreach ( $fields as $key => $config ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by the tool layer; the CPT has no UI.
				$value = call_user_func( $config[0], wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * Get all transactions for a user, sorted by execution date ascending.
	 *
	 * @since 1.1.80
	 *
	 * @param int $user_id User ID (0 = any author).
	 * @return array<int, array> Transaction records.
	 */
	public static function get_transactions( $user_id = 0 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_number_posts_per_page -- ledger retrieval, capped for safety.
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( $user_id > 0 ) {
			$args['author'] = $user_id;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'          => $post->ID,
				'ticker'      => strtoupper( (string) get_post_meta( $post->ID, self::META_TICKER, true ) ),
				'side'        => (string) get_post_meta( $post->ID, self::META_SIDE, true ),
				'quantity'    => (float) get_post_meta( $post->ID, self::META_QUANTITY, true ),
				'price'       => (float) get_post_meta( $post->ID, self::META_PRICE, true ),
				'fee'         => (float) get_post_meta( $post->ID, self::META_FEE, true ),
				'executed_at' => (string) get_post_meta( $post->ID, self::META_EXECUTED_AT, true ),
				'currency'    => (string) get_post_meta( $post->ID, self::META_CURRENCY, true ),
			);
		}

		return $items;
	}
}
