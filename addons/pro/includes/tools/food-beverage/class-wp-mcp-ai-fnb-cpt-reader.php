<?php
/**
 * Food & Beverage Management Toolkit — Native CPT Reader.
 *
 * Phase 9 reader adapter: resolves the five transactional spec tables from
 * their native-storage CPTs so the metric engine and report builder run
 * unchanged against WordPress-backed rows.
 *
 * Row format matches the reader contract but with one deliberate difference:
 * `columns` are the table's canonical keys (not the raw sheet headers), so
 * `WP_MCP_AI_Fnb_Data_Source::resolve_column()` maps them 1:1 and the whole
 * pipeline (metrics, search, reports) works identically to the Drive/CSV
 * backends.
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

require_once __DIR__ . '/class-wp-mcp-ai-fnb-table-reader.php';

/**
 * WP_Query + post-meta reader for the native-storage tables.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Cpt_Reader implements WP_MCP_AI_Fnb_Table_Reader {

	/**
	 * Spec table ID → native CPT map (the transactional tables).
	 *
	 * @var array<string,string>
	 */
	private static $native_tables = array(
		'6.0'  => 'mcp_ai_fnb_sale',
		'8.0'  => 'mcp_ai_fnb_purchase',
		'9.0'  => 'mcp_ai_fnb_stock',
		'10.0' => 'mcp_ai_fnb_waste',
		'16.0' => 'mcp_ai_fnb_booking',
	);

	/**
	 * Maximum rows read per table (safety cap for very large imports).
	 *
	 * @var int
	 */
	const MAX_ROWS = 25000;

	/**
	 * Whether a spec table is backed by native storage.
	 *
	 * @param string $table_id Spec table ID.
	 * @return bool
	 */
	public static function is_native_table( $table_id ) {
		return isset( self::$native_tables[ $table_id ] );
	}

	/**
	 * The CPT slug backing a native table.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string Post type slug or empty string when not native.
	 */
	public static function post_type_for( $table_id ) {
		return isset( self::$native_tables[ $table_id ] ) ? self::$native_tables[ $table_id ] : '';
	}

	/**
	 * All native table IDs with their CPT slugs and labels.
	 *
	 * @return array<int,array{id:string,post_type:string}>
	 */
	public static function native_tables() {
		$out = array();
		foreach ( self::$native_tables as $id => $post_type ) {
			$out[] = array(
				'id'        => $id,
				'post_type' => $post_type,
			);
		}

		return $out;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error array{columns: string[], rows: array<int,array>}
	 */
	public function read_table( $table_id ) {
		if ( ! self::is_native_table( $table_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_not_native_table',
				/* translators: %s: table id */
				sprintf( __( 'F&B table "%s" is not backed by native storage.', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		$post_type = self::post_type_for( $table_id );
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_cpt_not_registered',
				__( 'F&B native storage CPT is not registered — enable native storage on the Food & Beverage settings page.', 'mcp-ai-wpoos-pro' )
			);
		}

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();

		$rows   = array();
		$offset = 0;
		$batch  = 500;
		$total  = 0;

		while ( $total < self::MAX_ROWS ) {
			$posts = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'numberposts'      => $batch,
					'offset'           => $offset,
					'orderby'          => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'order'            => 'ASC',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post_id ) {
				$row = array();
				foreach ( $canonical as $canonical_key ) {
					$row[] = (string) get_post_meta( $post_id, '_fnb_' . $canonical_key, true );
				}
				$rows[] = $row;
			}

			$total = count( $rows );
			if ( count( $posts ) < $batch ) {
				break;
			}
			$offset += $batch;
		}

		return array(
			'columns' => $canonical,
			'rows'    => $rows,
			'source'  => 'fnb_native_cpt',
		);
	}
}
