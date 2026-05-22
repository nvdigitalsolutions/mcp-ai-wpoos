<?php
/**
 * Law Firm Toolkit Custom Post Types
 *
 * Manages Matter, Client, Document, Time Entry, and Trust Transaction entities
 * for the Law Firm Toolkit. Follows ABA Model Rules and IOLTA standards for
 * field structure. Supports manual data entry and AI-powered tool integration.
 *
 * Entity Model:
 *   Matter (mcp_ai_lf_matter) → linked to → Client (mcp_ai_lf_client)
 *   Document (mcp_ai_lf_document) → attached to → Matter
 *   Time Entry (mcp_ai_lf_time_entry) → billed to → Matter
 *   Trust Transaction (mcp_ai_lf_trust_txn) → linked to → Matter + Client
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

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
			'name'                  => __( 'Matters', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Matter', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Law Firm', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Matter', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Matter', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Matter', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Matter', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Matter', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Matters', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No matters found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No matters found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Matters', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to matter', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this matter', 'mcp-ai-wpoos-pro' ),
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
			'name'                  => __( 'Clients', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Client', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Clients', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Client', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Client', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Client', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Client', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Client', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Clients', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No clients found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No clients found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Clients', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to client', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this client', 'mcp-ai-wpoos-pro' ),
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
			'name'                  => __( 'Documents', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Document', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Documents', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Document', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Document', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Document', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Document', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Document', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Documents', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No documents found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No documents found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Documents', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to document', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this document', 'mcp-ai-wpoos-pro' ),
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
			'name'                  => __( 'Time Entries', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Time Entry', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Time Entries', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Time Entry', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Time Entry', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Time Entry', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Time Entry', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Time Entry', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Time Entries', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No time entries found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No time entries found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Time Entries', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to time entry', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this time entry', 'mcp-ai-wpoos-pro' ),
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
			'name'                  => __( 'Trust Transactions', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => __( 'Trust Transaction', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => __( 'Trust Transactions', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add Transaction', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Trust Transaction', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Trust Transaction', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Trust Transaction', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Trust Transaction', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Trust Transactions', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No trust transactions found', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No trust transactions found in trash', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Trust Transactions', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => __( 'Add to transaction', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this transaction', 'mcp-ai-wpoos-pro' ),
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
			'name'          => __( 'Practice Areas', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Practice Area', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Practice Areas', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Practice Areas', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Practice Area', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Practice Area', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Practice Area', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Practice Area Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Practice Areas', 'mcp-ai-wpoos-pro' ),
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
			'Corporate'             => __( 'Corporate law, M&A, governance', 'mcp-ai-wpoos-pro' ),
			'Litigation'            => __( 'Civil and commercial litigation', 'mcp-ai-wpoos-pro' ),
			'Real Estate'           => __( 'Real estate transactions and disputes', 'mcp-ai-wpoos-pro' ),
			'Intellectual Property' => __( 'Patents, trademarks, copyrights, trade secrets', 'mcp-ai-wpoos-pro' ),
			'Employment'            => __( 'Employment and labor law', 'mcp-ai-wpoos-pro' ),
			'Family Law'            => __( 'Divorce, custody, adoption', 'mcp-ai-wpoos-pro' ),
			'Criminal Defense'      => __( 'Criminal defense and white-collar crime', 'mcp-ai-wpoos-pro' ),
			'Bankruptcy'            => __( 'Bankruptcy and restructuring', 'mcp-ai-wpoos-pro' ),
			'Tax'                   => __( 'Tax planning, disputes, compliance', 'mcp-ai-wpoos-pro' ),
			'Estate Planning'       => __( 'Wills, trusts, estate administration', 'mcp-ai-wpoos-pro' ),
			'Immigration'           => __( 'Immigration and visa petitions', 'mcp-ai-wpoos-pro' ),
			'Personal Injury'       => __( 'Personal injury and medical malpractice', 'mcp-ai-wpoos-pro' ),
			'Environmental'         => __( 'Environmental and regulatory compliance', 'mcp-ai-wpoos-pro' ),
			'Healthcare'            => __( 'Healthcare regulation and compliance', 'mcp-ai-wpoos-pro' ),
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
			'name'          => __( 'Matter Statuses', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Matter Status', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Matter Statuses', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Matter Statuses', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Matter Status', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Matter Status', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Matter Status', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Matter Status Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Matter Statuses', 'mcp-ai-wpoos-pro' ),
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
			'Prospect' => __( 'Potential matter under evaluation', 'mcp-ai-wpoos-pro' ),
			'Active'   => __( 'Currently active matter', 'mcp-ai-wpoos-pro' ),
			'Pending'  => __( 'Awaiting court action or client decision', 'mcp-ai-wpoos-pro' ),
			'Closed'   => __( 'Matter resolved and closed', 'mcp-ai-wpoos-pro' ),
			'Archived' => __( 'Archived for record retention', 'mcp-ai-wpoos-pro' ),
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
			'name'          => __( 'Document Types', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Document Type', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Document Types', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Document Types', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Document Type', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Document Type', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Document Type', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Document Type Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Document Types', 'mcp-ai-wpoos-pro' ),
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
			'Pleading'           => __( 'Complaints, answers, motions', 'mcp-ai-wpoos-pro' ),
			'Contract'           => __( 'Agreements, amendments, assignments', 'mcp-ai-wpoos-pro' ),
			'Correspondence'     => __( 'Letters, emails, memos', 'mcp-ai-wpoos-pro' ),
			'Discovery'          => __( 'Interrogatories, depositions, document requests', 'mcp-ai-wpoos-pro' ),
			'Court Order'        => __( 'Judicial orders, rulings, judgments', 'mcp-ai-wpoos-pro' ),
			'Brief'              => __( 'Legal briefs and memoranda of law', 'mcp-ai-wpoos-pro' ),
			'Engagement Letter'  => __( 'Client engagement and retainer letters', 'mcp-ai-wpoos-pro' ),
			'Corporate Document' => __( 'Articles, bylaws, resolutions', 'mcp-ai-wpoos-pro' ),
			'Evidence'           => __( 'Exhibits, declarations, affidavits', 'mcp-ai-wpoos-pro' ),
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
			'name'          => __( 'Billing Types', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Billing Type', 'mcp-ai-wpoos-pro' ),
			'search_items'  => __( 'Search Billing Types', 'mcp-ai-wpoos-pro' ),
			'all_items'     => __( 'All Billing Types', 'mcp-ai-wpoos-pro' ),
			'edit_item'     => __( 'Edit Billing Type', 'mcp-ai-wpoos-pro' ),
			'update_item'   => __( 'Update Billing Type', 'mcp-ai-wpoos-pro' ),
			'add_new_item'  => __( 'Add New Billing Type', 'mcp-ai-wpoos-pro' ),
			'new_item_name' => __( 'New Billing Type Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Billing Types', 'mcp-ai-wpoos-pro' ),
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
			'Billable'     => __( 'Standard billable time', 'mcp-ai-wpoos-pro' ),
			'Non-Billable' => __( 'Administrative or internal time', 'mcp-ai-wpoos-pro' ),
			'Pro Bono'     => __( 'Pro bono publico work', 'mcp-ai-wpoos-pro' ),
			'Contingent'   => __( 'Contingency fee arrangement', 'mcp-ai-wpoos-pro' ),
			'Flat Fee'     => __( 'Fixed / flat fee arrangement', 'mcp-ai-wpoos-pro' ),
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
			__( 'Matter Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_matter_details_metabox' ),
			self::MATTER_POST_TYPE,
			'normal',
			'high'
		);

		// Client meta box.
		add_meta_box(
			'mcp_ai_lf_client_details',
			__( 'Client Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_client_details_metabox' ),
			self::CLIENT_POST_TYPE,
			'normal',
			'high'
		);

		// Document meta box.
		add_meta_box(
			'mcp_ai_lf_document_details',
			__( 'Document Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_document_details_metabox' ),
			self::DOCUMENT_POST_TYPE,
			'normal',
			'high'
		);

		// Time Entry meta box.
		add_meta_box(
			'mcp_ai_lf_time_entry_details',
			__( 'Time Entry Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_time_entry_details_metabox' ),
			self::TIME_ENTRY_POST_TYPE,
			'normal',
			'high'
		);

		// Trust Transaction meta box.
		add_meta_box(
			'mcp_ai_lf_trust_txn_details',
			__( 'Trust Transaction Details', 'mcp-ai-wpoos-pro' ),
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
				<th><label for="lf_case_number"><?php esc_html_e( 'Case Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_case_number" name="lf_case_number" value="<?php echo esc_attr( $fields['_lf_case_number'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Court-assigned case or docket number', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_court"><?php esc_html_e( 'Court', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_court" name="lf_court" value="<?php echo esc_attr( $fields['_lf_court'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g., U.S. District Court, Southern District of New York', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_judge"><?php esc_html_e( 'Judge', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="lf_judge" name="lf_judge" value="<?php echo esc_attr( $fields['_lf_judge'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_jurisdiction"><?php esc_html_e( 'Jurisdiction', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="lf_jurisdiction" name="lf_jurisdiction">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
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
				<th><label for="lf_filed_date"><?php esc_html_e( 'Filed Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="date" id="lf_filed_date" name="lf_filed_date" value="<?php echo esc_attr( $fields['_lf_filed_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_id"><?php esc_html_e( 'Linked Client', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $clients as $client ) : ?>
							<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $fields['_lf_client_id'], $client->ID ); ?>>
								<?php echo esc_html( $client->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_opposing_counsel"><?php esc_html_e( 'Opposing Counsel', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
				<th><label for="lf_client_email"><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="email" id="lf_client_email" name="lf_client_email" value="<?php echo esc_attr( $fields['_lf_client_email'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_phone"><?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="lf_client_phone" name="lf_client_phone" value="<?php echo esc_attr( $fields['_lf_client_phone'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_client_address"><?php esc_html_e( 'Address', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><textarea id="lf_client_address" name="lf_client_address" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_client_address'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="lf_client_type"><?php esc_html_e( 'Client Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="lf_client_type" name="lf_client_type">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
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
				<th><label for="lf_client_entity"><?php esc_html_e( 'Entity Name', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_client_entity" name="lf_client_entity" value="<?php echo esc_attr( $fields['_lf_client_entity'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Business or organization name (if applicable)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_client_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
				<th><label for="lf_doc_matter_id"><?php esc_html_e( 'Linked Matter', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_doc_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_doc_version"><?php esc_html_e( 'Version', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="lf_doc_version" name="lf_doc_version" value="<?php echo esc_attr( $fields['_lf_doc_version'] ); ?>" class="small-text" placeholder="1.0" /></td>
			</tr>
			<tr>
				<th><label for="lf_doc_date"><?php esc_html_e( 'Document Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="date" id="lf_doc_date" name="lf_doc_date" value="<?php echo esc_attr( $fields['_lf_doc_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_doc_notes"><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
				<th><label for="lf_te_matter_id"><?php esc_html_e( 'Linked Matter', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_te_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_te_hours"><?php esc_html_e( 'Hours', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="lf_te_hours" name="lf_te_hours" value="<?php echo esc_attr( $fields['_lf_te_hours'] ); ?>" step="0.1" min="0" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_te_rate"><?php esc_html_e( 'Rate ($/hr)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="lf_te_rate" name="lf_te_rate" value="<?php echo esc_attr( $fields['_lf_te_rate'] ); ?>" step="1" min="0" class="small-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_te_utbms_code"><?php esc_html_e( 'UTBMS Code', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" id="lf_te_utbms_code" name="lf_te_utbms_code" value="<?php echo esc_attr( $fields['_lf_te_utbms_code'] ); ?>" class="small-text" placeholder="L110" maxlength="4" />
					<p class="description"><?php esc_html_e( 'Uniform Task-Based Management System code (e.g., L110)', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="lf_te_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><textarea id="lf_te_description" name="lf_te_description" rows="3" class="large-text"><?php echo esc_textarea( $fields['_lf_te_description'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="lf_te_date"><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
				<th><label for="lf_txn_matter_id"><?php esc_html_e( 'Linked Matter', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $matters as $matter ) : ?>
							<option value="<?php echo esc_attr( $matter->ID ); ?>" <?php selected( $fields['_lf_txn_matter_id'], $matter->ID ); ?>>
								<?php echo esc_html( $matter->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_txn_client_id"><?php esc_html_e( 'Linked Client', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
						<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $clients as $client ) : ?>
							<option value="<?php echo esc_attr( $client->ID ); ?>" <?php selected( $fields['_lf_txn_client_id'], $client->ID ); ?>>
								<?php echo esc_html( $client->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="lf_txn_amount"><?php esc_html_e( 'Amount ($)', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="number" id="lf_txn_amount" name="lf_txn_amount" value="<?php echo esc_attr( $fields['_lf_txn_amount'] ); ?>" step="0.01" min="0" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_type"><?php esc_html_e( 'Transaction Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select id="lf_txn_type" name="lf_txn_type">
						<option value=""><?php esc_html_e( '— Select —', 'mcp-ai-wpoos-pro' ); ?></option>
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
				<th><label for="lf_txn_date"><?php esc_html_e( 'Transaction Date', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="date" id="lf_txn_date" name="lf_txn_date" value="<?php echo esc_attr( $fields['_lf_txn_date'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_check_number"><?php esc_html_e( 'Check Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td><input type="text" id="lf_txn_check_number" name="lf_txn_check_number" value="<?php echo esc_attr( $fields['_lf_txn_check_number'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="lf_txn_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label></th>
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
