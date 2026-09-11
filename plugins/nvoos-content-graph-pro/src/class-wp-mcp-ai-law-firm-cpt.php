<?php
/**
 * includes/class-wp-mcp-ai-law-firm-cpt.php (ecosystem port — Wave F4, law-firm data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-law-firm-cpt.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Law Firm Toolkit CPT Class
 *
 * Registers and manages all Law Firm CPTs with meta fields,
 * taxonomies for practice area, matter status, document type, and billing type.
 */
class WP_MCP_AI_Law_Firm_CPT {

	/**
	 * Matter post type slug.
	 */
	const MATTER_POST_TYPE = 'mcp_ai_lf_matter';

	/**
	 * Client post type slug.
	 */
	const CLIENT_POST_TYPE = 'mcp_ai_lf_client';

	/**
	 * Document post type slug.
	 */
	const DOCUMENT_POST_TYPE = 'mcp_ai_lf_document';

	/**
	 * Time Entry post type slug.
	 */
	const TIME_ENTRY_POST_TYPE = 'mcp_ai_lf_time_entry';

	/**
	 * Trust Transaction post type slug.
	 */
	const TRUST_TXN_POST_TYPE = 'mcp_ai_lf_trust_txn';

	/**
	 * Initialize the CPTs.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::MATTER_POST_TYPE, array( __CLASS__, 'save_matter_meta' ), 10, 2 );
		add_action( 'save_post_' . self::CLIENT_POST_TYPE, array( __CLASS__, 'save_client_meta' ), 10, 2 );
		add_action( 'save_post_' . self::DOCUMENT_POST_TYPE, array( __CLASS__, 'save_document_meta' ), 10, 2 );
		add_action( 'save_post_' . self::TIME_ENTRY_POST_TYPE, array( __CLASS__, 'save_time_entry_meta' ), 10, 2 );
		add_action( 'save_post_' . self::TRUST_TXN_POST_TYPE, array( __CLASS__, 'save_trust_txn_meta' ), 10, 2 );
	}

	/**
	 * Register all Law Firm post types.
	 */
	public static function register_post_types() {
		// ── Matter ────────────────────────────────────────────────────
		$matter_labels = array(
			'name'                  => __( 'Matters', 'nvoos-content-graph-pro' ),
			'singular_name'         => __( 'Matter', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Law Firm', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add Matter', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Matter', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Matter', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Matter', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Matter', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Matters', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No matters found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No matters found in trash', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Matters', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Add to matter', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this matter', 'nvoos-content-graph-pro' ),
		);

		register_post_type(
			self::MATTER_POST_TYPE,
			array(
				'labels'             => $matter_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-portfolio',
				'menu_position'      => 58,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'lf-matters',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);

		// ── Client ────────────────────────────────────────────────────
		$client_labels = array(
			'name'                  => __( 'Clients', 'nvoos-content-graph-pro' ),
			'singular_name'         => __( 'Client', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Clients', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add Client', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Client', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Client', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Client', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Client', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Clients', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No clients found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No clients found in trash', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Clients', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Add to client', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this client', 'nvoos-content-graph-pro' ),
		);

		register_post_type(
			self::CLIENT_POST_TYPE,
			array(
				'labels'             => $client_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MATTER_POST_TYPE,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'lf-clients',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);

		// ── Document ──────────────────────────────────────────────────
		$document_labels = array(
			'name'                  => __( 'Documents', 'nvoos-content-graph-pro' ),
			'singular_name'         => __( 'Document', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Documents', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add Document', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Document', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Document', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Document', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Document', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Documents', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No documents found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No documents found in trash', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Documents', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Add to document', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this document', 'nvoos-content-graph-pro' ),
		);

		register_post_type(
			self::DOCUMENT_POST_TYPE,
			array(
				'labels'             => $document_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MATTER_POST_TYPE,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'editor', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'lf-documents',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);

		// ── Time Entry ────────────────────────────────────────────────
		$time_entry_labels = array(
			'name'                  => __( 'Time Entries', 'nvoos-content-graph-pro' ),
			'singular_name'         => __( 'Time Entry', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Time Entries', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add Time Entry', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Time Entry', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Time Entry', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Time Entry', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Time Entry', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Time Entries', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No time entries found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No time entries found in trash', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Time Entries', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Add to time entry', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this time entry', 'nvoos-content-graph-pro' ),
		);

		register_post_type(
			self::TIME_ENTRY_POST_TYPE,
			array(
				'labels'             => $time_entry_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MATTER_POST_TYPE,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'lf-time-entries',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);

		// ── Trust Transaction ─────────────────────────────────────────
		$trust_txn_labels = array(
			'name'                  => __( 'Trust Transactions', 'nvoos-content-graph-pro' ),
			'singular_name'         => __( 'Trust Transaction', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Trust Transactions', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add Transaction', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Trust Transaction', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Trust Transaction', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Trust Transaction', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Trust Transaction', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Trust Transactions', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No trust transactions found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No trust transactions found in trash', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Trust Transactions', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Add to transaction', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this transaction', 'nvoos-content-graph-pro' ),
		);

		register_post_type(
			self::TRUST_TXN_POST_TYPE,
			array(
				'labels'             => $trust_txn_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MATTER_POST_TYPE,
				'query_var'          => true,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'lf-trust-transactions',
				'rest_namespace'     => 'mcp-ai/v1',
			)
		);
	}

	/**
	 * Register taxonomies for Law Firm CPTs.
	 */
	public static function register_taxonomies() {
		// ── Practice Area taxonomy ────────────────────────────────────
		$practice_area_labels = array(
			'name'          => __( 'Practice Areas', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Practice Area', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Practice Areas', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Practice Areas', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Practice Area', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Practice Area', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Practice Area', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Practice Area Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Practice Areas', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'lf_practice_area',
			array( self::MATTER_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $practice_area_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		// Seed default practice areas.
		$default_practice_areas = array(
			'Corporate'             => __( 'Corporate law, M&A, governance', 'nvoos-content-graph-pro' ),
			'Litigation'            => __( 'Civil and commercial litigation', 'nvoos-content-graph-pro' ),
			'Real Estate'           => __( 'Real estate transactions and disputes', 'nvoos-content-graph-pro' ),
			'Intellectual Property' => __( 'Patents, trademarks, copyrights, trade secrets', 'nvoos-content-graph-pro' ),
			'Employment'            => __( 'Employment and labor law', 'nvoos-content-graph-pro' ),
			'Family Law'            => __( 'Divorce, custody, adoption', 'nvoos-content-graph-pro' ),
			'Criminal Defense'      => __( 'Criminal defense and white-collar crime', 'nvoos-content-graph-pro' ),
			'Bankruptcy'            => __( 'Bankruptcy and restructuring', 'nvoos-content-graph-pro' ),
			'Tax'                   => __( 'Tax planning, disputes, compliance', 'nvoos-content-graph-pro' ),
			'Estate Planning'       => __( 'Wills, trusts, estate administration', 'nvoos-content-graph-pro' ),
			'Immigration'           => __( 'Immigration and visa petitions', 'nvoos-content-graph-pro' ),
			'Personal Injury'       => __( 'Personal injury and medical malpractice', 'nvoos-content-graph-pro' ),
			'Environmental'         => __( 'Environmental and regulatory compliance', 'nvoos-content-graph-pro' ),
			'Healthcare'            => __( 'Healthcare regulation and compliance', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_practice_areas as $name => $description ) {
			if ( ! term_exists( $name, 'lf_practice_area' ) ) {
				wp_insert_term(
					$name,
					'lf_practice_area',
					array( 'description' => $description )
				);
			}
		}

		// ── Matter Status taxonomy ────────────────────────────────────
		$status_labels = array(
			'name'          => __( 'Matter Statuses', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Matter Status', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Matter Statuses', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Matter Statuses', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Matter Status', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Matter Status', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Matter Status', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Matter Status Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Matter Statuses', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'lf_matter_status',
			array( self::MATTER_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $status_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		$default_statuses = array(
			'Prospect' => __( 'Potential matter under evaluation', 'nvoos-content-graph-pro' ),
			'Active'   => __( 'Currently active matter', 'nvoos-content-graph-pro' ),
			'Pending'  => __( 'Awaiting court action or client decision', 'nvoos-content-graph-pro' ),
			'Closed'   => __( 'Matter resolved and closed', 'nvoos-content-graph-pro' ),
			'Archived' => __( 'Archived for record retention', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_statuses as $name => $description ) {
			if ( ! term_exists( $name, 'lf_matter_status' ) ) {
				wp_insert_term(
					$name,
					'lf_matter_status',
					array( 'description' => $description )
				);
			}
		}

		// ── Document Type taxonomy ────────────────────────────────────
		$doc_type_labels = array(
			'name'          => __( 'Document Types', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Document Type', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Document Types', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Document Types', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Document Type', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Document Type', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Document Type', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Document Type Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Document Types', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'lf_document_type',
			array( self::DOCUMENT_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $doc_type_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		$default_doc_types = array(
			'Pleading'           => __( 'Complaints, answers, motions', 'nvoos-content-graph-pro' ),
			'Contract'           => __( 'Agreements, amendments, assignments', 'nvoos-content-graph-pro' ),
			'Correspondence'     => __( 'Letters, emails, memos', 'nvoos-content-graph-pro' ),
			'Discovery'          => __( 'Interrogatories, depositions, document requests', 'nvoos-content-graph-pro' ),
			'Court Order'        => __( 'Judicial orders, rulings, judgments', 'nvoos-content-graph-pro' ),
			'Brief'              => __( 'Legal briefs and memoranda of law', 'nvoos-content-graph-pro' ),
			'Engagement Letter'  => __( 'Client engagement and retainer letters', 'nvoos-content-graph-pro' ),
			'Corporate Document' => __( 'Articles, bylaws, resolutions', 'nvoos-content-graph-pro' ),
			'Evidence'           => __( 'Exhibits, declarations, affidavits', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_doc_types as $name => $description ) {
			if ( ! term_exists( $name, 'lf_document_type' ) ) {
				wp_insert_term(
					$name,
					'lf_document_type',
					array( 'description' => $description )
				);
			}
		}

		// ── Billing Type taxonomy ─────────────────────────────────────
		$billing_type_labels = array(
			'name'          => __( 'Billing Types', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Billing Type', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Billing Types', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Billing Types', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Billing Type', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Billing Type', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Billing Type', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Billing Type Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Billing Types', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'lf_billing_type',
			array( self::TIME_ENTRY_POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $billing_type_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'show_in_rest'      => true,
			)
		);

		$default_billing_types = array(
			'Billable'     => __( 'Standard billable time', 'nvoos-content-graph-pro' ),
			'Non-Billable' => __( 'Administrative or internal time', 'nvoos-content-graph-pro' ),
			'Pro Bono'     => __( 'Pro bono publico work', 'nvoos-content-graph-pro' ),
			'Contingent'   => __( 'Contingency fee arrangement', 'nvoos-content-graph-pro' ),
			'Flat Fee'     => __( 'Fixed / flat fee arrangement', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_billing_types as $name => $description ) {
			if ( ! term_exists( $name, 'lf_billing_type' ) ) {
				wp_insert_term(
					$name,
					'lf_billing_type',
					array( 'description' => $description )
				);
			}
		}
	}

	/**
	 * Add meta boxes for all Law Firm CPTs.
	 */
	public static function add_meta_boxes() {
		// Matter meta box.
		add_meta_box(
			'mcp_ai_lf_matter_details',
			__( 'Matter Details', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_matter_details_metabox' ),
			self::MATTER_POST_TYPE,
			'normal',
			'high'
		);

		// Client meta box.
		add_meta_box(
			'mcp_ai_lf_client_details',
			__( 'Client Details', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_client_details_metabox' ),
			self::CLIENT_POST_TYPE,
			'normal',
			'high'
		);

		// Document meta box.
		add_meta_box(
			'mcp_ai_lf_document_details',
			__( 'Document Details', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_document_details_metabox' ),
			self::DOCUMENT_POST_TYPE,
			'normal',
			'high'
		);

		// Time Entry meta box.
		add_meta_box(
			'mcp_ai_lf_time_entry_details',
			__( 'Time Entry Details', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_time_entry_details_metabox' ),
			self::TIME_ENTRY_POST_TYPE,
			'normal',
			'high'
		);

		// Trust Transaction meta box.
		add_meta_box(
			'mcp_ai_lf_trust_txn_details',
			__( 'Trust Transaction Details', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_trust_txn_details_metabox' ),
			self::TRUST_TXN_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render matter details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_matter_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_lf_matter_details', 'mcp_ai_lf_matter_nonce' );

		$fields = array(
			'_lf_case_number'      => get_post_meta( $post->ID, '_lf_case_number', true ),
			'_lf_court'            => get_post_meta( $post->ID, '_lf_court', true ),
			'_lf_judge'            => get_post_meta( $post->ID, '_lf_judge', true ),
			'_lf_jurisdiction'     => get_post_meta( $post->ID, '_lf_jurisdiction', true ),
			'_lf_filed_date'       => get_post_meta( $post->ID, '_lf_filed_date', true ),
			'_lf_client_id'        => get_post_meta( $post->ID, '_lf_client_id', true ),
			'_lf_opposing_counsel' => get_post_meta( $post->ID, '_lf_opposing_counsel', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="lf_case_number"><?php esc_html_e( 'Case Number', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_case_number" name="lf_case_number" value="<?php echo esc_attr( $fields['_lf_case_number'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Court-assigned case or docket number', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_court"><?php esc_html_e( 'Court', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_court" name="lf_court" value="<?php echo esc_attr( $fields['_lf_court'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g., U.S. District Court, Southern District of New York', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_judge"><?php esc_html_e( 'Judge', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="text" id="lf_judge" name="lf_judge" value="<?php echo esc_attr( $fields['_lf_judge'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_jurisdiction"><?php esc_html_e( 'Jurisdiction', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select id="lf_jurisdiction" name="lf_jurisdiction">
						<option value=""><?php esc_html_e( '— Select —', 'nvoos-content-graph-pro' ); ?></option>
						<?php
						$jurisdictions = array(
							'federal'        => 'Federal',
							'state'          => 'State',
							'administrative' => 'Administrative',
							'arbitration'    => 'Arbitration',
						);
						foreach ( $jurisdictions as $val => $label ) :
							?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fields['_lf_jurisdiction'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_filed_date"><?php esc_html_e( 'Filed Date', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="date" id="lf_filed_date" name="lf_filed_date" value="<?php echo esc_attr( $fields['_lf_filed_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_id"><?php esc_html_e( 'Linked Client', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<?php
					$clients = get_posts(
						array(
							'post_type'      => self::CLIENT_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="lf_client_id" name="lf_client_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $clients as $client ) : ?>
							<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $fields['_lf_client_id'], $client->ID ); ?>>
								<?php echo esc_html( $client->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_opposing_counsel"><?php esc_html_e( 'Opposing Counsel', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="text" id="lf_opposing_counsel" name="lf_opposing_counsel" value="<?php echo esc_attr( $fields['_lf_opposing_counsel'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render client details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_client_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_lf_client_details', 'mcp_ai_lf_client_nonce' );

		$fields = array(
			'_lf_client_email'   => get_post_meta( $post->ID, '_lf_client_email', true ),
			'_lf_client_phone'   => get_post_meta( $post->ID, '_lf_client_phone', true ),
			'_lf_client_address' => get_post_meta( $post->ID, '_lf_client_address', true ),
			'_lf_client_type'    => get_post_meta( $post->ID, '_lf_client_type', true ),
			'_lf_client_entity'  => get_post_meta( $post->ID, '_lf_client_entity', true ),
			'_lf_client_notes'   => get_post_meta( $post->ID, '_lf_client_notes', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="lf_client_email"><?php esc_html_e( 'Email', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="email" id="lf_client_email" name="lf_client_email" value="<?php echo esc_attr( $fields['_lf_client_email'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_phone"><?php esc_html_e( 'Phone', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="text" id="lf_client_phone" name="lf_client_phone" value="<?php echo esc_attr( $fields['_lf_client_phone'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_address"><?php esc_html_e( 'Address', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><textarea id="lf_client_address" name="lf_client_address" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_client_address'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="lf_client_type"><?php esc_html_e( 'Client Type', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select id="lf_client_type" name="lf_client_type">
						<option value=""><?php esc_html_e( '— Select —', 'nvoos-content-graph-pro' ); ?></option>
						<?php
						$client_types = array(
							'individual' => 'Individual',
							'business'   => 'Business',
							'government' => 'Government',
							'nonprofit'  => 'Non-Profit',
						);
						foreach ( $client_types as $val => $label ) :
							?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fields['_lf_client_type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_client_entity"><?php esc_html_e( 'Entity Name', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_client_entity" name="lf_client_entity" value="<?php echo esc_attr( $fields['_lf_client_entity'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Business or organization name (if applicable)', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_client_notes"><?php esc_html_e( 'Notes', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><textarea id="lf_client_notes" name="lf_client_notes" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_client_notes'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render document details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_document_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_lf_document_details', 'mcp_ai_lf_document_nonce' );

		$fields = array(
			'_lf_doc_matter_id' => get_post_meta( $post->ID, '_lf_doc_matter_id', true ),
			'_lf_doc_version'   => get_post_meta( $post->ID, '_lf_doc_version', true ),
			'_lf_doc_date'      => get_post_meta( $post->ID, '_lf_doc_date', true ),
			'_lf_doc_notes'     => get_post_meta( $post->ID, '_lf_doc_notes', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="lf_doc_matter_id"><?php esc_html_e( 'Linked Matter', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<?php
					$matters = get_posts(
						array(
							'post_type'      => self::MATTER_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="lf_doc_matter_id" name="lf_doc_matter_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_doc_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_doc_version"><?php esc_html_e( 'Version', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="text" id="lf_doc_version" name="lf_doc_version" value="<?php echo esc_attr( $fields['_lf_doc_version'] ); ?>" class="small-text" placeholder="1.0" /></td>
			</tr>
			<tr>
				<th><label for="lf_doc_date"><?php esc_html_e( 'Document Date', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="date" id="lf_doc_date" name="lf_doc_date" value="<?php echo esc_attr( $fields['_lf_doc_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_doc_notes"><?php esc_html_e( 'Notes', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><textarea id="lf_doc_notes" name="lf_doc_notes" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_doc_notes'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render time entry details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_time_entry_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_lf_time_entry_details', 'mcp_ai_lf_time_entry_nonce' );

		$fields = array(
			'_lf_te_matter_id'   => get_post_meta( $post->ID, '_lf_te_matter_id', true ),
			'_lf_te_hours'       => get_post_meta( $post->ID, '_lf_te_hours', true ),
			'_lf_te_rate'        => get_post_meta( $post->ID, '_lf_te_rate', true ),
			'_lf_te_utbms_code'  => get_post_meta( $post->ID, '_lf_te_utbms_code', true ),
			'_lf_te_description' => get_post_meta( $post->ID, '_lf_te_description', true ),
			'_lf_te_date'        => get_post_meta( $post->ID, '_lf_te_date', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="lf_te_matter_id"><?php esc_html_e( 'Linked Matter', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<?php
					$matters = get_posts(
						array(
							'post_type'      => self::MATTER_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="lf_te_matter_id" name="lf_te_matter_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_te_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_te_hours"><?php esc_html_e( 'Hours', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="number" id="lf_te_hours" name="lf_te_hours" value="<?php echo esc_attr( $fields['_lf_te_hours'] ); ?>" step="0.1" min="0" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_te_rate"><?php esc_html_e( 'Rate ($/hr)', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="number" id="lf_te_rate" name="lf_te_rate" value="<?php echo esc_attr( $fields['_lf_te_rate'] ); ?>" step="1" min="0" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_te_utbms_code"><?php esc_html_e( 'UTBMS Code', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_te_utbms_code" name="lf_te_utbms_code" value="<?php echo esc_attr( $fields['_lf_te_utbms_code'] ); ?>" class="small-text" placeholder="L110" maxlength="4" />
					<p class="description"><?php esc_html_e( 'Uniform Task-Based Management System code (e.g., L110)', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_te_description"><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><textarea id="lf_te_description" name="lf_te_description" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_te_description'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="lf_te_date"><?php esc_html_e( 'Date', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="date" id="lf_te_date" name="lf_te_date" value="<?php echo esc_attr( $fields['_lf_te_date'] ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render trust transaction details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_trust_txn_details_metabox( $post ) {
		wp_nonce_field( 'mcp_ai_lf_trust_txn_details', 'mcp_ai_lf_trust_txn_nonce' );

		$fields = array(
			'_lf_txn_matter_id'    => get_post_meta( $post->ID, '_lf_txn_matter_id', true ),
			'_lf_txn_client_id'    => get_post_meta( $post->ID, '_lf_txn_client_id', true ),
			'_lf_txn_amount'       => get_post_meta( $post->ID, '_lf_txn_amount', true ),
			'_lf_txn_type'         => get_post_meta( $post->ID, '_lf_txn_type', true ),
			'_lf_txn_date'         => get_post_meta( $post->ID, '_lf_txn_date', true ),
			'_lf_txn_check_number' => get_post_meta( $post->ID, '_lf_txn_check_number', true ),
			'_lf_txn_description'  => get_post_meta( $post->ID, '_lf_txn_description', true ),
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="lf_txn_matter_id"><?php esc_html_e( 'Linked Matter', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<?php
					$matters = get_posts(
						array(
							'post_type'      => self::MATTER_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="lf_txn_matter_id" name="lf_txn_matter_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_txn_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_txn_client_id"><?php esc_html_e( 'Linked Client', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<?php
					$clients = get_posts(
						array(
							'post_type'      => self::CLIENT_POST_TYPE,
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select id="lf_txn_client_id" name="lf_txn_client_id" class="regular-text">
						<option value=""><?php esc_html_e( '— None —', 'nvoos-content-graph-pro' ); ?></option>
						<?php foreach ( $clients as $client ) : ?>
							<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $fields['_lf_txn_client_id'], $client->ID ); ?>>
								<?php echo esc_html( $client->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_txn_amount"><?php esc_html_e( 'Amount ($)', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="number" id="lf_txn_amount" name="lf_txn_amount" value="<?php echo esc_attr( $fields['_lf_txn_amount'] ); ?>" step="0.01" min="0" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_type"><?php esc_html_e( 'Transaction Type', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select id="lf_txn_type" name="lf_txn_type">
						<option value=""><?php esc_html_e( '— Select —', 'nvoos-content-graph-pro' ); ?></option>
						<?php
						$txn_types = array(
							'deposit'      => 'Deposit',
							'disbursement' => 'Disbursement',
							'transfer'     => 'Transfer',
						);
						foreach ( $txn_types as $val => $label ) :
							?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fields['_lf_txn_type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_txn_date"><?php esc_html_e( 'Transaction Date', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="date" id="lf_txn_date" name="lf_txn_date" value="<?php echo esc_attr( $fields['_lf_txn_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_check_number"><?php esc_html_e( 'Check Number', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><input type="text" id="lf_txn_check_number" name="lf_txn_check_number" value="<?php echo esc_attr( $fields['_lf_txn_check_number'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_description"><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></label></th>
				<td><textarea id="lf_txn_description" name="lf_txn_description" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_txn_description'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save matter meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_matter_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_lf_matter_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_lf_matter_nonce'] ) ), 'mcp_ai_lf_matter_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Text fields.
		$text_fields = array( 'lf_case_number', 'lf_court', 'lf_judge', 'lf_jurisdiction', 'lf_filed_date', 'lf_opposing_counsel' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Client ID (absint).
		if ( isset( $_POST['lf_client_id'] ) ) {
			update_post_meta( $post_id, '_lf_client_id', absint( $_POST['lf_client_id'] ) );
		}
	}

	/**
	 * Save client meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_client_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_lf_client_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_lf_client_nonce'] ) ), 'mcp_ai_lf_client_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Text fields.
		$text_fields = array( 'lf_client_phone', 'lf_client_type', 'lf_client_entity' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Email.
		if ( isset( $_POST['lf_client_email'] ) ) {
			update_post_meta( $post_id, '_lf_client_email', sanitize_email( wp_unslash( $_POST['lf_client_email'] ) ) );
		}

		// Textarea fields.
		if ( isset( $_POST['lf_client_address'] ) ) {
			update_post_meta( $post_id, '_lf_client_address', sanitize_textarea_field( wp_unslash( $_POST['lf_client_address'] ) ) );
		}
		if ( isset( $_POST['lf_client_notes'] ) ) {
			update_post_meta( $post_id, '_lf_client_notes', sanitize_textarea_field( wp_unslash( $_POST['lf_client_notes'] ) ) );
		}
	}

	/**
	 * Save document meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_document_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_lf_document_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_lf_document_nonce'] ) ), 'mcp_ai_lf_document_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Matter ID (absint).
		if ( isset( $_POST['lf_doc_matter_id'] ) ) {
			update_post_meta( $post_id, '_lf_doc_matter_id', absint( $_POST['lf_doc_matter_id'] ) );
		}

		// Text fields.
		$text_fields = array( 'lf_doc_version', 'lf_doc_date' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Notes.
		if ( isset( $_POST['lf_doc_notes'] ) ) {
			update_post_meta( $post_id, '_lf_doc_notes', sanitize_textarea_field( wp_unslash( $_POST['lf_doc_notes'] ) ) );
		}
	}

	/**
	 * Save time entry meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_time_entry_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_lf_time_entry_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_lf_time_entry_nonce'] ) ), 'mcp_ai_lf_time_entry_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Matter ID (absint).
		if ( isset( $_POST['lf_te_matter_id'] ) ) {
			update_post_meta( $post_id, '_lf_te_matter_id', absint( $_POST['lf_te_matter_id'] ) );
		}

		// Numeric fields.
		$numeric_fields = array( 'lf_te_hours', 'lf_te_rate' );
		foreach ( $numeric_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, floatval( $_POST[ $field ] ) );
			}
		}

		// Text fields.
		$text_fields = array( 'lf_te_utbms_code', 'lf_te_date' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Description.
		if ( isset( $_POST['lf_te_description'] ) ) {
			update_post_meta( $post_id, '_lf_te_description', sanitize_textarea_field( wp_unslash( $_POST['lf_te_description'] ) ) );
		}
	}

	/**
	 * Save trust transaction meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_trust_txn_meta( $post_id, $post ) {
		if ( ! isset( $_POST['mcp_ai_lf_trust_txn_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcp_ai_lf_trust_txn_nonce'] ) ), 'mcp_ai_lf_trust_txn_details' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// ID fields (absint).
		if ( isset( $_POST['lf_txn_matter_id'] ) ) {
			update_post_meta( $post_id, '_lf_txn_matter_id', absint( $_POST['lf_txn_matter_id'] ) );
		}
		if ( isset( $_POST['lf_txn_client_id'] ) ) {
			update_post_meta( $post_id, '_lf_txn_client_id', absint( $_POST['lf_txn_client_id'] ) );
		}

		// Amount (float).
		if ( isset( $_POST['lf_txn_amount'] ) ) {
			update_post_meta( $post_id, '_lf_txn_amount', floatval( $_POST['lf_txn_amount'] ) );
		}

		// Text fields.
		$text_fields = array( 'lf_txn_type', 'lf_txn_date', 'lf_txn_check_number' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Description.
		if ( isset( $_POST['lf_txn_description'] ) ) {
			update_post_meta( $post_id, '_lf_txn_description', sanitize_textarea_field( wp_unslash( $_POST['lf_txn_description'] ) ) );
		}
	}
}
