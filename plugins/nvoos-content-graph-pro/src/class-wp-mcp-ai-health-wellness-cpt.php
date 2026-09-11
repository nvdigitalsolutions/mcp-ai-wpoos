<?php
/**
 * includes/class-wp-mcp-ai-health-wellness-cpt.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
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
 * Registers and manages the Health and Wellness custom post types.
 */
class WP_MCP_AI_Health_Wellness_CPT {
	/**
	 * Member post type slug.
	 *
	 * @var string
	 */
	const MEMBER_POST_TYPE = 'mcp_ai_member';

	/**
	 * Policy post type slug.
	 *
	 * @var string
	 */
	const POLICY_POST_TYPE = 'mcp_ai_policy';

	/**
	 * Medical Record post type slug.
	 *
	 * @var string
	 */
	const MEDICAL_RECORD_POST_TYPE = 'mcp_ai_med_record';

	/**
	 * Checkup post type slug.
	 *
	 * @var string
	 */
	const CHECKUP_POST_TYPE = 'mcp_ai_checkup';

	/**
	 * Prescription post type slug.
	 *
	 * @var string
	 */
	const PRESCRIPTION_POST_TYPE = 'mcp_ai_prescription';

	/**
	 * Allergy post type slug.
	 *
	 * @var string
	 */
	const ALLERGY_POST_TYPE = 'mcp_ai_allergy';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		// When Pro addon is active (WP_MCP_AI_PRO_VERSION defined), features should work even in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			// Still show notice if accessing health wellness pages.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if health wellness management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_health_wellness_management'] ) ) {
			// Show notice if trying to access health wellness pages when disabled.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
	}

	/**
	 * Show admin notice when health wellness management is disabled but user tries to access pages.
	 */
	public static function show_disabled_notice() {
		// Only show on health wellness-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on a health wellness post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type               = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_health_wellness_page = in_array(
			$post_type,
			array(
				self::MEMBER_POST_TYPE,
				self::POLICY_POST_TYPE,
				self::MEDICAL_RECORD_POST_TYPE,
				self::CHECKUP_POST_TYPE,
				self::PRESCRIPTION_POST_TYPE,
				self::ALLERGY_POST_TYPE,
			),
			true
		);

		if ( ! $is_health_wellness_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Health and Wellness Management Not Available', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Health and Wellness Management System is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'nvoos-content-graph-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Health and Wellness Management System, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'nvoos-content-graph-pro' ),
							'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_health_wellness_management'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Health and Wellness Management Disabled', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Health and Wellness Management System is currently disabled. Enable it to create and manage health records, members, and appointments.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Health and Wellness Management System, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Health and Wellness Management"</strong>, and save your changes.', 'nvoos-content-graph-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Show informational notice on health wellness edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on health wellness edit screens.
		if ( ! $screen ) {
			return;
		}

		$health_wellness_screens = array(
			self::MEMBER_POST_TYPE,
			'edit-' . self::MEMBER_POST_TYPE,
			self::POLICY_POST_TYPE,
			'edit-' . self::POLICY_POST_TYPE,
			self::MEDICAL_RECORD_POST_TYPE,
			'edit-' . self::MEDICAL_RECORD_POST_TYPE,
			self::CHECKUP_POST_TYPE,
			'edit-' . self::CHECKUP_POST_TYPE,
			self::PRESCRIPTION_POST_TYPE,
			'edit-' . self::PRESCRIPTION_POST_TYPE,
			self::ALLERGY_POST_TYPE,
			'edit-' . self::ALLERGY_POST_TYPE,
		);

		if ( ! in_array( $screen->id, $health_wellness_screens, true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_health_wellness_management'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info health-wellness-info-notice">
			<p>
				<strong><?php esc_html_e( 'Health and Wellness Management', 'nvoos-content-graph-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Health and wellness records can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'nvoos-content-graph-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Privacy & Security:</strong> Health data is sensitive. Ensure proper security measures, access controls, and compliance with healthcare regulations (HIPAA, GDPR) are in place for your deployment.', 'nvoos-content-graph-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and manage health records using dedicated tools. Always review AI-generated health information with qualified professionals.', 'nvoos-content-graph-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register health and wellness custom post types.
	 */
	public static function register_post_types() {
		// Register Member CPT (people & pets).
		register_post_type(
			self::MEMBER_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Members', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Member', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Health & Wellness', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Member', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'member', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Member', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Member', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Member', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Member', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Members', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Members', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No members found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No members found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'author' ),
				'menu_icon'          => 'dashicons-groups',
				'menu_position'      => 30,
			)
		);

		// Register Policy CPT.
		register_post_type(
			self::POLICY_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Policies', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Policy', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Policies', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Policy', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'policy', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Policy', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Policy', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Policy', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Policy', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Policies', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Policies', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No policies found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No policies found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MEMBER_POST_TYPE,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-shield',
			)
		);

		// Register Medical Record CPT.
		register_post_type(
			self::MEDICAL_RECORD_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Medical Records', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Medical Record', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Medical Records', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Medical Record', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'medical record', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Medical Record', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Medical Record', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Medical Record', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Medical Record', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Medical Records', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Medical Records', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No medical records found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No medical records found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MEMBER_POST_TYPE,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-clipboard',
			)
		);

		// Register Checkup CPT.
		register_post_type(
			self::CHECKUP_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Checkups', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Checkup', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Checkups', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Checkup', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'checkup', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Checkup', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Checkup', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Checkup', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Checkup', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Checkups', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Checkups', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No checkups found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No checkups found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MEMBER_POST_TYPE,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-calendar-alt',
			)
		);

		// Register Prescription CPT.
		register_post_type(
			self::PRESCRIPTION_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Prescriptions', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Prescription', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Prescriptions', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Prescription', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'prescription', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Prescription', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Prescription', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Prescription', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Prescription', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Prescriptions', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Prescriptions', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No prescriptions found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No prescriptions found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MEMBER_POST_TYPE,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-media-document',
			)
		);

		// Register Allergy CPT.
		register_post_type(
			self::ALLERGY_POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Allergies', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Allergy', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Allergies', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Allergy', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'allergy', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Allergy', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Allergy', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Allergy', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Allergy', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Allergies', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Allergies', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No allergies found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No allergies found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::MEMBER_POST_TYPE,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-warning',
			)
		);
	}

	/**
	 * Register taxonomies for health and wellness.
	 */
	public static function register_taxonomies() {
		// Register Member Type taxonomy.
		register_taxonomy(
			'mcp_ai_member_type',
			self::MEMBER_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Member Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Member Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Member Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Member Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Member Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Member Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Member Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Member Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Member Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default member types.
		$default_member_types = array(
			'person' => __( 'Person', 'nvoos-content-graph-pro' ),
			'pet'    => __( 'Pet', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_member_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_member_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_member_type', array( 'slug' => $slug ) );
			}
		}

		// Register Policy Type taxonomy.
		register_taxonomy(
			'mcp_ai_policy_type',
			self::POLICY_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Policy Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Policy Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Policy Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Policy Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Policy Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Policy Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Policy Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Policy Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Policy Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default policy types.
		$default_policy_types = array(
			'health-insurance' => __( 'Health Insurance', 'nvoos-content-graph-pro' ),
			'dental-insurance' => __( 'Dental Insurance', 'nvoos-content-graph-pro' ),
			'vision-insurance' => __( 'Vision Insurance', 'nvoos-content-graph-pro' ),
			'pet-insurance'    => __( 'Pet Insurance', 'nvoos-content-graph-pro' ),
			'life-insurance'   => __( 'Life Insurance', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_policy_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_policy_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_policy_type', array( 'slug' => $slug ) );
			}
		}

		// Register Record Type taxonomy.
		register_taxonomy(
			'mcp_ai_record_type',
			self::MEDICAL_RECORD_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Record Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Record Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Record Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Record Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Record Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Record Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Record Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Record Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Record Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default record types.
		$default_record_types = array(
			'lab-result'      => __( 'Lab Result', 'nvoos-content-graph-pro' ),
			'diagnosis'       => __( 'Diagnosis', 'nvoos-content-graph-pro' ),
			'treatment'       => __( 'Treatment', 'nvoos-content-graph-pro' ),
			'vaccination'     => __( 'Vaccination', 'nvoos-content-graph-pro' ),
			'imaging'         => __( 'Imaging', 'nvoos-content-graph-pro' ),
			'procedure'       => __( 'Procedure', 'nvoos-content-graph-pro' ),
			'hospitalization' => __( 'Hospitalization', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_record_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_record_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_record_type', array( 'slug' => $slug ) );
			}
		}

		// Register Allergy Severity taxonomy.
		register_taxonomy(
			'mcp_ai_allergy_severity',
			self::ALLERGY_POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Allergy Severities', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Allergy Severity', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Allergy Severities', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Allergy Severities', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Allergy Severity', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Allergy Severity', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Allergy Severity', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Allergy Severity Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Severities', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default allergy severities.
		$default_severities = array(
			'mild'     => __( 'Mild', 'nvoos-content-graph-pro' ),
			'moderate' => __( 'Moderate', 'nvoos-content-graph-pro' ),
			'severe'   => __( 'Severe', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_severities as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_allergy_severity' ) ) {
				wp_insert_term( $name, 'mcp_ai_allergy_severity', array( 'slug' => $slug ) );
			}
		}
	}
}

// Initialize the Health and Wellness CPT.
WP_MCP_AI_Health_Wellness_CPT::init();
