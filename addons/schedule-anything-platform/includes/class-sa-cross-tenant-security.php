<?php
/**
 * Schedule Anything — Cross-Tenant Session Security
 *
 * Prevents cross-tenant access: if a user authenticated on Site A
 * somehow reaches Site B (stale cookie, direct URL, browser cache),
 * redirect them to their correct workspace.
 *
 * Pattern from production WordPress Multisite SaaS (SampleHQ):
 * "A must-use plugin validates that the authenticated user has a role
 * on the current site. This prevents the most common cross-tenant
 * access vector in multisite environments."
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-tenant session security validator.
 *
 * @since 0.1.0
 */
class SA_Cross_Tenant_Security {

	/**
	 * Initialize the security checks.
	 *
	 * Runs on 'init' at priority 1 — before any other plugin code.
	 * Skips admin, AJAX, and cron requests to avoid breaking
	 * legitimate cross-site operations.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		// Only enforce on the front-end.
		// Admin, AJAX, and cron can operate cross-site legitimately.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Only relevant in Multisite.
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'validate_session' ), 1 );
	}

	/**
	 * Validate that the current user belongs to the current site.
	 *
	 * If the user is logged in but is not a member of the current blog,
	 * redirect them to their primary blog. If they have no blogs at all,
	 * log them out and redirect to the home page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function validate_session() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$current_blog = get_current_blog_id();

		// Super admins can access any site.
		if ( is_super_admin( $user_id ) ) {
			return;
		}

		// Check if the user is a member of this blog.
		if ( is_user_member_of_blog( $user_id, $current_blog ) ) {
			return;
		}

		// User does not belong to this tenant.
		// Find their primary blog and redirect there.
		$user_blogs = get_blogs_of_user( $user_id );

		if ( ! empty( $user_blogs ) ) {
			$primary_blog = reset( $user_blogs );

			/**
			 * Filter the redirect URL when a cross-tenant access is detected.
			 *
			 * @since 0.1.0
			 *
			 * @param string $redirect_url The URL to redirect to.
			 * @param int    $user_id      The user ID.
			 * @param int    $current_blog The blog ID they attempted to access.
			 * @param object $primary_blog The user's primary blog object.
			 */
			$redirect_url = apply_filters(
				'sa_cross_tenant_redirect_url',
				$primary_blog->siteurl,
				$user_id,
				$current_blog,
				$primary_blog
			);

			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		// User has no blogs — log them out.
		wp_logout();

		/**
		 * Filter the redirect URL when a user with no blogs is logged out.
		 *
		 * @since 0.1.0
		 *
		 * @param string $redirect_url Default: home_url().
		 * @param int    $user_id      The user ID.
		 * @param int    $current_blog The blog ID they attempted to access.
		 */
		$redirect_url = apply_filters(
			'sa_cross_tenant_logout_redirect_url',
			home_url(),
			$user_id,
			$current_blog
		);

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}
}
