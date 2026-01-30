<?php
/**
 * Regulatory Registration Toolkit Initialization
 *
 * Loads the Regulatory Registration Custom Post Type class which handles registration
 * and management of products, registrations, documents, countries, and requirements
 * for multi-country regulatory compliance (Sri Lanka NMRA, UAE, Saudi SFDA, etc.).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Regulatory Registration CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-regulatory-registration-cpt.php';

// Load admin pages when in admin area.
if ( is_admin() ) {
	// Check if regulatory registration toolkit is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_regulatory_registration_toolkit'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load Toolkit Settings Page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php';

		// Load Product research page.
		// Note: Product Settings page removed as it's redundant with Toolkit Settings.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-reg-product-research-page.php';

		// Load Registration tracking dashboard and research page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-registration-dashboard-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-registration-research-page.php';

		// Load Document management and research pages.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-reg-document-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-reg-document-research-page.php';

		// Load Country requirements configuration page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-reg-country-config-page.php';

		// Load Excel migration import page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-reg-migration-page.php';
	}
}

/**
 * Enqueue regulatory registration admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_regulatory_registration_admin_styles( $hook ) {
	// Only load on regulatory registration edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array(
		$screen->post_type,
		array(
			'mcp_ai_reg_product',
			'mcp_ai_registration',
			'mcp_ai_reg_document',
			'mcp_ai_reg_country',
			'mcp_ai_reg_requirement',
		),
		true
	) ) {
		return;
	}

	// Check if regulatory registration toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-regulatory-registration.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-regulatory-registration-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-regulatory-registration.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_regulatory_registration_admin_styles' );

/**
 * Add default product categories on toolkit activation.
 */
function wp_mcp_ai_reg_add_default_categories() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
		return;
	}

	// Check if categories already exist.
	$existing = get_terms(
		array(
			'taxonomy'   => 'mcp_ai_reg_category',
			'hide_empty' => false,
		)
	);

	if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
		return; // Categories already exist.
	}

	// Add default categories.
	$default_categories = array(
		'Skincare'  => 'Products for skin health and beauty',
		'Haircare'  => 'Products for hair care and styling',
		'Makeup'    => 'Cosmetic makeup products',
		'Perfumes'  => 'Fragrances and perfumes',
		'Cosmetics' => 'General cosmetic products',
	);

	foreach ( $default_categories as $name => $description ) {
		if ( ! term_exists( $name, 'mcp_ai_reg_category' ) ) {
			wp_insert_term(
				$name,
				'mcp_ai_reg_category',
				array(
					'description' => $description,
				)
			);
		}
	}
}
add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_categories' );

/**
 * Add default registration statuses on toolkit activation.
 */
function wp_mcp_ai_reg_add_default_statuses() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
		return;
	}

	// Check if statuses already exist.
	$existing = get_terms(
		array(
			'taxonomy'   => 'mcp_ai_reg_status',
			'hide_empty' => false,
		)
	);

	if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
		return; // Statuses already exist.
	}

	// Add default statuses based on industry best practices.
	$default_statuses = array(
		'Draft'                => 'Initial registration draft',
		'Pending Documents'    => 'Waiting for required documents',
		'Ready for Submission' => 'All documents ready, awaiting submission',
		'Submitted'            => 'Application submitted to authority',
		'Under Review'         => 'Under review by regulatory authority',
		'Approved'             => 'Registration approved',
		'Rejected'             => 'Registration rejected',
		'On Hold'              => 'Registration on hold',
		'Renewal Due'          => 'Registration renewal required',
	);

	foreach ( $default_statuses as $name => $description ) {
		if ( ! term_exists( $name, 'mcp_ai_reg_status' ) ) {
			wp_insert_term(
				$name,
				'mcp_ai_reg_status',
				array(
					'description' => $description,
				)
			);
		}
	}
}
add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_statuses' );

/**
 * Add default document types on toolkit activation.
 */
function wp_mcp_ai_reg_add_default_document_types() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
		return;
	}

	// Check if document types already exist.
	$existing = get_terms(
		array(
			'taxonomy'   => 'mcp_ai_doc_type',
			'hide_empty' => false,
		)
	);

	if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
		return; // Document types already exist.
	}

	// Add default document types based on common regulatory requirements.
	$default_doc_types = array(
		'LOA'                      => 'Letter of Authorization',
		'Manufacturer Declaration' => 'Manufacturer declaration document',
		'Artwork'                  => 'Product artwork and labeling',
		'Formula Certificate'      => 'Product formula certificate',
		'Certificate of Analysis'  => 'Certificate of Analysis (CoA)',
		'Free Sale Certificate'    => 'Certificate of Free Sale',
		'Sample Import License'    => 'License for importing product samples',
		'MSDS'                     => 'Material Safety Data Sheet',
		'GMP Certificate'          => 'Good Manufacturing Practice certificate',
		'ISO Certificate'          => 'ISO certification document',
		'Registration Certificate' => 'Official registration certificate',
		'Payment Receipt'          => 'Payment receipt or proof',
		'INCI List'                => 'International Nomenclature Cosmetic Ingredient list',
	);

	foreach ( $default_doc_types as $name => $description ) {
		if ( ! term_exists( $name, 'mcp_ai_doc_type' ) ) {
			wp_insert_term(
				$name,
				'mcp_ai_doc_type',
				array(
					'description' => $description,
				)
			);
		}
	}
}
add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_document_types' );

/**
 * Add default countries on toolkit activation.
 */
function wp_mcp_ai_reg_add_default_countries() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
		return;
	}

	// Check if option is already set to prevent duplicate insertion.
	if ( get_option( 'wp_mcp_ai_reg_default_countries_added', false ) ) {
		return;
	}

	// Add default countries based on PRD requirements.
	$default_countries = array(
		array(
			'name'      => 'Sri Lanka',
			'code'      => 'LK',
			'authority' => 'NMRA (National Medicines Regulatory Authority)',
		),
		array(
			'name'      => 'United Arab Emirates',
			'code'      => 'AE',
			'authority' => 'MOHAP / Dubai Municipality',
		),
		array(
			'name'      => 'Saudi Arabia',
			'code'      => 'SA',
			'authority' => 'SFDA (Saudi Food and Drug Authority)',
		),
		array(
			'name'      => 'Qatar',
			'code'      => 'QA',
			'authority' => 'Ministry of Public Health',
		),
		array(
			'name'      => 'Kuwait',
			'code'      => 'KW',
			'authority' => 'Ministry of Health',
		),
		array(
			'name'      => 'Oman',
			'code'      => 'OM',
			'authority' => 'Ministry of Health',
		),
		array(
			'name'      => 'India',
			'code'      => 'IN',
			'authority' => 'CDSCO (Central Drugs Standard Control Organisation)',
		),
	);

	foreach ( $default_countries as $country ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $country['name'],
				'post_content' => sprintf( 'Regulatory Authority: %s', $country['authority'] ),
				'post_type'    => 'mcp_ai_reg_country',
				'post_status'  => 'publish',
				'meta_input'   => array(
					'country_code'         => $country['code'],
					'regulatory_authority' => $country['authority'],
				),
			)
		);
	}

	// Mark that default countries have been added.
	update_option( 'wp_mcp_ai_reg_default_countries_added', true );
}
add_action( 'admin_init', 'wp_mcp_ai_reg_add_default_countries' );
