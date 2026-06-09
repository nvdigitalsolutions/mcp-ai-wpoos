<?php
/**
 * Schedule Anything — Multisite Tenant Provisioner
 *
 * Creates new tenant workspaces when a user signs up via Stripe.
 * Handles subsite creation, plugin activation, toolkit seeding,
 * preset installation, and Cloud Worker registration.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant workspace provisioner.
 *
 * @since 0.1.0
 */
class SA_Multisite_Provisioner {

	/**
	 * Generate a unique subdomain from user input.
	 *
	 * Normalizes the input, strips noise words, truncates to 30 chars,
	 * and appends a numeric suffix on collision.
	 *
	 * @since 0.1.0
	 *
	 * @param string $input Company name, email prefix, or desired slug.
	 * @return string Unique subdomain slug.
	 */
	public static function generate_subdomain( $input ) {
		// Normalize: lowercase, replace non-alphanumeric with hyphens.
		$base = sanitize_title( $input );

		// Strip common noise words from company names.
		$noise = array( 'llc', 'inc', 'corp', 'ltd', 'company', 'co', 'the', 'and' );
		$parts = array_diff( explode( '-', $base ), $noise );

		// Rebuild, removing empty segments.
		$parts = array_filter( $parts );
		$base  = implode( '-', $parts );

		if ( empty( $base ) ) {
			$base = 'workspace';
		}

		// Truncate to 30 characters (subdomain length limits).
		$base = substr( $base, 0, 30 );

		// Check uniqueness against existing WordPress blogs.
		$candidate = $base;
		$suffix    = 1;

		while ( get_blog_id_from_url( $candidate ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		/**
		 * Filter the generated subdomain before creation.
		 *
		 * @since 0.1.0
		 *
		 * @param string $candidate The generated subdomain.
		 * @param string $input     The original input.
		 */
		return apply_filters( 'sa_generated_subdomain', $candidate, $input );
	}

	/**
	 * Provision a new tenant workspace.
	 *
	 * Creates a Multisite subsite, network-activates plugins,
	 * seeds toolkit flags and presets, and returns workspace details.
	 *
	 * @since 0.1.0
	 *
	 * @param array $tenant_data {
	 *     Tenant provisioning data.
	 *
	 *     @type string $slug                Desired subdomain slug (company name or email prefix).
	 *     @type string $tier                Subscription tier: 'starter', 'professional', 'enterprise'.
	 *     @type string $stripe_customer_id  Stripe customer ID.
	 *     @type string $admin_email         Tenant admin email address.
	 *     @type string $admin_name          Tenant admin display name.
	 *     @type string $company_name        Optional. Full company name for the site title.
	 * }
	 * @return array|WP_Error {
	 *     Provisioning result on success.
	 *
	 *     @type int    $blog_id      The new subsite ID.
	 *     @type string $site_url     Full site URL.
	 *     @type string $subdomain    The generated subdomain.
	 *     @type string $login_token  One-time login token for cross-domain SSO.
	 *     @type int    $admin_user_id The tenant admin user ID.
	 * }
	 */
	public static function provision( array $tenant_data ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'sa_not_multisite',
				__( 'Schedule Anything Platform requires WordPress Multisite.', 'schedule-anything' )
			);
		}

		// Validate required fields.
		$required = array( 'slug', 'tier', 'stripe_customer_id', 'admin_email', 'admin_name' );
		foreach ( $required as $field ) {
			if ( empty( $tenant_data[ $field ] ) ) {
				return new WP_Error(
					'sa_missing_field',
					/* translators: %s: field name */
					sprintf( __( 'Required field "%s" is missing.', 'schedule-anything' ), $field )
				);
			}
		}

		// Validate tier.
		$valid_tiers = array( 'starter', 'professional', 'enterprise' );
		$tier        = sanitize_key( $tenant_data['tier'] );
		if ( ! in_array( $tier, $valid_tiers, true ) ) {
			return new WP_Error(
				'sa_invalid_tier',
				/* translators: %s: tier name */
				sprintf( __( 'Invalid tier "%s". Must be starter, professional, or enterprise.', 'schedule-anything' ), $tier )
			);
		}

		// Generate unique subdomain.
		$subdomain = self::generate_subdomain( $tenant_data['slug'] );

		// Generate site title.
		$site_title = ! empty( $tenant_data['company_name'] )
			? sanitize_text_field( $tenant_data['company_name'] )
			/* translators: %s: admin display name */
			: sprintf( __( '%s Workspace', 'schedule-anything' ), $tenant_data['admin_name'] );

		// Create the subsite.
		$blog_id = wpmu_create_blog(
			self::get_network_domain(),
			'/' . $subdomain . '/',
			$site_title,
			$tenant_data['admin_email'],
			array( 'public' => 1 )
		);

		if ( is_wp_error( $blog_id ) ) {
			return new WP_Error(
				'sa_provision_failed',
				$blog_id->get_error_message()
			);
		}

		// Switch to new blog context for seeding.
		switch_to_blog( $blog_id );

		// Seed toolkit flags for this tier.
		if ( class_exists( 'SA_Toolkit_Manager' ) ) {
			$flags    = SA_Toolkit_Manager::get_defaults_for_tier( $tier );
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			update_option( 'wp_mcp_ai_settings', array_merge( $settings, $flags ) );
		}

		// Install tier-appropriate schedule presets.
		$installed_presets = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			$preset_ids = SA_Toolkit_Manager::get_presets_for_tier( $tier );
			foreach ( $preset_ids as $preset_id ) {
				$result = WP_MCP_AI_Pro_Schedule_Presets::install_preset( $preset_id, 1 );
				if ( ! is_wp_error( $result ) ) {
					$installed_presets[] = $preset_id;
				}
			}
		}

		// Ensure the tenant admin user exists.
		$admin_email = sanitize_email( $tenant_data['admin_email'] );
		$user        = get_user_by( 'email', $admin_email );

		if ( ! $user ) {
			// Create the user if they don't exist.
			$random_password = wp_generate_password( 18, true, true );
			$user_id         = wpmu_create_user(
				self::email_to_username( $admin_email ),
				$random_password,
				$admin_email
			);

			if ( is_wp_error( $user_id ) ) {
				restore_current_blog();
				return new WP_Error(
					'sa_user_create_failed',
					$user_id->get_error_message()
				);
			}

			// Update display name.
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( $tenant_data['admin_name'] ),
				)
			);

			$user = get_user_by( 'id', $user_id );
		}

		// Elevate user to tenant admin on this subsite.
		add_user_to_blog( $blog_id, $user->ID, 'sa_tenant_admin' );

		// Generate one-time login token for cross-domain SSO.
		$login_token = wp_generate_password( 32, false );
		set_transient(
			'sa_otl_' . $login_token,
			array(
				'blog_id'    => $blog_id,
				'user_id'    => $user->ID,
				'created_at' => time(),
				'consumed'   => false,
			),
			5 * MINUTE_IN_SECONDS
		);

		// Store tenant metadata as a blog option.
		update_option(
			'sa_tenant_meta',
			array(
				'tier'                => $tier,
				'stripe_customer_id'  => sanitize_text_field( $tenant_data['stripe_customer_id'] ),
				'created_at'          => time(),
				'provisioned_version' => SA_PLATFORM_VERSION,
			)
		);

		restore_current_blog();

		// Build the login URL.
		$login_url = add_query_arg(
			array(
				'sa_otl_token' => $login_token,
				'sa_otl_nonce' => wp_create_nonce( 'sa_otl_' . $login_token ),
			),
			get_site_url( $blog_id, 'wp-login.php' )
		);

		/**
		 * Action fired after a tenant workspace is provisioned.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $blog_id           The new subsite ID.
		 * @param string $subdomain         The tenant subdomain.
		 * @param string $tier              Subscription tier.
		 * @param int    $user_id           Tenant admin user ID.
		 * @param array  $installed_presets Installed preset IDs.
		 */
		do_action( 'sa_tenant_provisioned', $blog_id, $subdomain, $tier, $user->ID, $installed_presets );

		return array(
			'blog_id'           => $blog_id,
			'site_url'          => get_site_url( $blog_id ),
			'subdomain'         => $subdomain,
			'login_token'       => $login_token,
			'login_url'         => $login_url,
			'admin_user_id'     => $user->ID,
			'installed_presets' => $installed_presets,
		);
	}

	/**
	 * Offboard a tenant workspace.
	 *
	 * Exports tenant data, schedules deletion after a grace period,
	 * and removes the tenant from the router.
	 *
	 * @since 0.1.0
	 *
	 * @param int $blog_id The blog ID to offboard.
	 * @return array|WP_Error Offboarding result.
	 */
	public static function offboard( $blog_id ) {
		$blog_id = absint( $blog_id );

		if ( ! get_blog_details( $blog_id ) ) {
			return new WP_Error(
				'sa_blog_not_found',
				__( 'The specified tenant workspace was not found.', 'schedule-anything' )
			);
		}

		switch_to_blog( $blog_id );

		// Generate data export.
		$export = self::generate_tenant_export( $blog_id );

		// Get tenant admin email for notification.
		$tenant_meta = get_option( 'sa_tenant_meta', array() );
		$admin_email = get_option( 'admin_email' );

		// Mark as pending deletion (72-hour grace period).
		update_option(
			'sa_pending_deletion',
			array(
				'scheduled_at' => time() + ( 72 * HOUR_IN_SECONDS ),
				'export_url'   => $export['url'] ?? '',
			)
		);

		restore_current_blog();

		/**
		 * Action fired when a tenant is offboarded.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $blog_id     The blog ID.
		 * @param array  $export      Export data.
		 * @param string $admin_email Tenant admin email.
		 */
		do_action( 'sa_tenant_offboarded', $blog_id, $export, $admin_email );

		return array(
			'blog_id'            => $blog_id,
			'status'             => 'pending_deletion',
			'deletion_scheduled' => time() + ( 72 * HOUR_IN_SECONDS ),
			'export_generated'   => ! empty( $export['url'] ),
		);
	}

	/**
	 * Get the network domain for subsite creation.
	 *
	 * @since 0.1.0
	 *
	 * @return string Network domain.
	 */
	private static function get_network_domain() {
		$network = get_network();

		return $network ? $network->domain : wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Convert an email address to a valid WordPress username.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return string Sanitized username.
	 */
	private static function email_to_username( $email ) {
		$parts    = explode( '@', $email );
		$username = sanitize_user( $parts[0], true );

		// Ensure uniqueness.
		if ( username_exists( $username ) ) {
			$username .= '-' . wp_rand( 1000, 9999 );
		}

		return $username;
	}

	/**
	 * Generate a data export for a tenant.
	 *
	 * @since 0.1.0
	 *
	 * @param int $blog_id The blog ID (unused, placeholder method).
	 * @return array Export metadata including URL.
	 */
	private static function generate_tenant_export( $blog_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// This is a placeholder — in production, this would generate
		// a full XML + JSON export of all tenant content, upload it
		// to a secure location, and return a download URL.

		return array(
			'generated_at' => time(),
			'url'          => '',
			'size_bytes'   => 0,
			'formats'      => array( 'xml', 'json' ),
		);
	}
}
