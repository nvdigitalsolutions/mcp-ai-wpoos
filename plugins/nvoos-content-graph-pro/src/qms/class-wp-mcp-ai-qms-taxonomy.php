<?php
/**
 * WP_MCP_AI_QMS_Taxonomy (ecosystem port - Wave F2, document-generation QMS/admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/qms/class-wp-mcp-ai-qms-taxonomy.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QMS Document Type taxonomy.
 */
class WP_MCP_AI_QMS_Taxonomy {

	const TAXONOMY = 'mcp_ai_qms_doc_type';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 11 );
		add_action( 'init', array( __CLASS__, 'seed_terms' ), 12 );
	}

	/**
	 * Object types this taxonomy applies to.
	 *
	 * @return array<int,string>
	 */
	public static function get_object_types() {
		return array( 'mcp_ai_doc_tpl', 'mcp_ai_doc_record' );
	}

	/**
	 * Register the taxonomy.
	 */
	public static function register() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		register_taxonomy(
			self::TAXONOMY,
			self::get_object_types(),
			array(
				'labels'            => array(
					'name'          => __( 'QMS Document Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'QMS Document Type', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Document Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Seed the default terms.
	 */
	public static function seed_terms() {
		if ( ! WP_MCP_AI_QMS_Capabilities::is_enabled() ) {
			return;
		}
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		$defaults = array(
			'policy'           => __( 'Policy', 'nvoos-content-graph-pro' ),
			'procedure'        => __( 'Procedure', 'nvoos-content-graph-pro' ),
			'work-instruction' => __( 'Work Instruction', 'nvoos-content-graph-pro' ),
			'form'             => __( 'Form', 'nvoos-content-graph-pro' ),
			'record'           => __( 'Record', 'nvoos-content-graph-pro' ),
			'external'         => __( 'External', 'nvoos-content-graph-pro' ),
		);
		foreach ( $defaults as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			}
		}
	}
}
