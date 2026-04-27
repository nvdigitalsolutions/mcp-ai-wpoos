<?php
/**
 * QMS Document Type Taxonomy.
 *
 * Implements the ISO 9001:2015 Clause 7.5.3 requirement to identify documents
 * by type and to distinguish documents of external origin (clause 7.5.3 b).
 *
 * Default terms: Policy, Procedure, Work Instruction, Form, Record, External.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

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
					'name'          => __( 'QMS Document Types', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'QMS Document Type', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Document Types', 'mcp-ai-wpoos-pro' ),
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
			'policy'           => __( 'Policy', 'mcp-ai-wpoos-pro' ),
			'procedure'        => __( 'Procedure', 'mcp-ai-wpoos-pro' ),
			'work-instruction' => __( 'Work Instruction', 'mcp-ai-wpoos-pro' ),
			'form'             => __( 'Form', 'mcp-ai-wpoos-pro' ),
			'record'           => __( 'Record', 'mcp-ai-wpoos-pro' ),
			'external'         => __( 'External', 'mcp-ai-wpoos-pro' ),
		);
		foreach ( $defaults as $slug => $label ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			}
		}
	}
}
