<?php
/**
 * Health and Wellness Custom Post Types for managing health data.
 *
 * Registers CPTs for members (people & pets), policies, medical records,
 * checkups, prescriptions, and allergies.
 *
 * @package WP_MCP_AI
 */

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
	const MEDICAL_RECORD_POST_TYPE = 'mcp_ai_medical_record';

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
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_health_wellness_page = in_array( $post_type, array(
			self::MEMBER_POST_TYPE,
			self::POLICY_POST_TYPE,
			self::MEDICAL_RECORD_POST_TYPE,
			self::CHECKUP_POST_TYPE,
			self::PRESCRIPTION_POST_TYPE,
			self::ALLERGY_POST_TYPE,
		), true );

		if ( ! $is_health_wellness_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Health and Wellness Management Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Health and Wellness Management System is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Health and Wellness Management System, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
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
					<strong><?php esc_html_e( 'Health and Wellness Management Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Health and Wellness Management System is currently disabled. Enable it to create and manage health records, members, and appointments.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Health and Wellness Management System, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Health and Wellness Management"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
				<strong><?php esc_html_e( 'Health and Wellness Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Health and wellness records can be created and managed both manually here in the WordPress admin and via AI assistant tools.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Privacy & Security:</strong> Health data is sensitive. Ensure proper security measures, access controls, and compliance with healthcare regulations (HIPAA, GDPR) are in place for your deployment.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> AI assistants can create and manage health records using dedicated tools. Always review AI-generated health information with qualified professionals.', 'mcp-ai-wpoos-pro' )
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
					'name'               => _x( 'Members', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Member', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Health & Wellness', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Member', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'member', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Member', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Member', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Member', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Member', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Members', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Members', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No members found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No members found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'               => _x( 'Policies', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Policy', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Policies', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Policy', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'policy', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Policy', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Policy', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Policy', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Policy', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Policies', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Policies', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No policies found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No policies found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'               => _x( 'Medical Records', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Medical Record', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Medical Records', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Medical Record', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'medical record', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Medical Record', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Medical Record', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Medical Record', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Medical Record', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Medical Records', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Medical Records', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No medical records found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No medical records found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'               => _x( 'Checkups', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Checkup', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Checkups', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Checkup', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'checkup', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Checkup', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Checkup', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Checkup', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Checkup', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Checkups', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Checkups', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No checkups found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No checkups found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'               => _x( 'Prescriptions', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Prescription', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Prescriptions', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Prescription', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'prescription', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Prescription', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Prescription', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Prescription', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Prescription', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Prescriptions', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Prescriptions', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No prescriptions found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No prescriptions found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'               => _x( 'Allergies', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Allergy', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Allergies', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Allergy', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'allergy', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Allergy', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Allergy', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Allergy', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Allergy', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'Allergies', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Allergies', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No allergies found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No allergies found in trash', 'mcp-ai-wpoos-pro' ),
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
					'name'          => __( 'Member Types', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Member Type', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Member Types', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Member Types', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Member Type', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Member Type', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Member Type', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Member Type Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Member Types', 'mcp-ai-wpoos-pro' ),
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
			'person' => __( 'Person', 'mcp-ai-wpoos-pro' ),
			'pet'    => __( 'Pet', 'mcp-ai-wpoos-pro' ),
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
					'name'          => __( 'Policy Types', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Policy Type', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Policy Types', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Policy Types', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Policy Type', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Policy Type', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Policy Type', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Policy Type Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Policy Types', 'mcp-ai-wpoos-pro' ),
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
			'health-insurance' => __( 'Health Insurance', 'mcp-ai-wpoos-pro' ),
			'dental-insurance' => __( 'Dental Insurance', 'mcp-ai-wpoos-pro' ),
			'vision-insurance' => __( 'Vision Insurance', 'mcp-ai-wpoos-pro' ),
			'pet-insurance'    => __( 'Pet Insurance', 'mcp-ai-wpoos-pro' ),
			'life-insurance'   => __( 'Life Insurance', 'mcp-ai-wpoos-pro' ),
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
					'name'          => __( 'Record Types', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Record Type', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Record Types', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Record Types', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Record Type', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Record Type', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Record Type', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Record Type Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Record Types', 'mcp-ai-wpoos-pro' ),
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
			'lab-result'    => __( 'Lab Result', 'mcp-ai-wpoos-pro' ),
			'diagnosis'     => __( 'Diagnosis', 'mcp-ai-wpoos-pro' ),
			'treatment'     => __( 'Treatment', 'mcp-ai-wpoos-pro' ),
			'vaccination'   => __( 'Vaccination', 'mcp-ai-wpoos-pro' ),
			'imaging'       => __( 'Imaging', 'mcp-ai-wpoos-pro' ),
			'procedure'     => __( 'Procedure', 'mcp-ai-wpoos-pro' ),
			'hospitalization' => __( 'Hospitalization', 'mcp-ai-wpoos-pro' ),
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
					'name'          => __( 'Allergy Severities', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Allergy Severity', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Allergy Severities', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'All Allergy Severities', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Allergy Severity', 'mcp-ai-wpoos-pro' ),
					'update_item'   => __( 'Update Allergy Severity', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Allergy Severity', 'mcp-ai-wpoos-pro' ),
					'new_item_name' => __( 'New Allergy Severity Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'     => __( 'Severities', 'mcp-ai-wpoos-pro' ),
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
			'mild'     => __( 'Mild', 'mcp-ai-wpoos-pro' ),
			'moderate' => __( 'Moderate', 'mcp-ai-wpoos-pro' ),
			'severe'   => __( 'Severe', 'mcp-ai-wpoos-pro' ),
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
