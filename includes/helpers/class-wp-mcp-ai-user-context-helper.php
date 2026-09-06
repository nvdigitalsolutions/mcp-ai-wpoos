<?php
/**
 * User-context switch helper.
 *
 * Centralises every privileged `wp_set_current_user()` call inside the base
 * plugin so that a single, audited code path enforces the same checks before
 * the global current-user state is mutated:
 *
 *   1. The supplied identifier resolves to an integer greater than zero.
 *   2. A WordPress user with that identifier exists (`get_userdata()`).
 *   3. On multisite installs, the user belongs to the current blog (unless
 *      the caller explicitly opts out — for example WP-CLI commands that
 *      run network-wide).
 *
 * If any of those checks fails the helper short-circuits and returns
 * `false` without touching the global current-user state. Callers that
 * need to abort their own privileged work can therefore branch on the
 * return value rather than relying on a follow-up `current_user_can()`
 * check after the switch.
 *
 * The helper does **not** attempt to elevate or bypass capabilities —
 * every WP REST `permission_callback`, every tool capability gate, and
 * every nonce verification continue to run after the switch. Its sole
 * purpose is to make sure we never leave the request running as a user
 * that does not exist or does not belong to the current site.
 *
 * @package WP_MCP_AI
 * @since   1.1.16
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_User_Context_Helper' ) ) {

	/**
	 * Provides validated wrappers around `wp_set_current_user()`.
	 */
	class WP_MCP_AI_User_Context_Helper {

		/**
		 * Validate a user identifier and switch the current-user context to
		 * it when (and only when) the validation passes.
		 *
		 * Returning `false` means the global current-user state was **not**
		 * modified. Callers should treat this as authorisation failure for
		 * any privileged operation that follows.
		 *
		 * @since 1.1.16
		 *
		 * @param int   $user_id Candidate WordPress user identifier. Any
		 *                       falsy or negative value is rejected.
		 * @param array $args {
		 *     Optional. Behavioural overrides.
		 *
		 *     @type bool $require_blog_membership Default `true`. When the
		 *           install is multisite, refuse to switch unless the user
		 *           is registered against the current blog. WP-CLI / cron
		 *           callers that legitimately run network-wide should set
		 *           this to `false`.
		 *     @type bool $skip_if_already_current Default `true`. Skip the
		 *           switch entirely when `get_current_user_id()` already
		 *           equals `$user_id`. The helper still returns `true` in
		 *           that case because the desired post-condition holds.
		 * }
		 * @return bool True when the global current-user is now `$user_id`
		 *              (either because we switched, or it was already that
		 *              user). False when validation failed and the global
		 *              state is unchanged.
		 */
		public static function safe_set_current_user( $user_id, array $args = array() ) {
			// Validate the identifier as a positive integer first — absint()
			// would flip negative values (e.g. -1 → 1) and silently switch to
			// the wrong account.
			$user_id = filter_var( $user_id, FILTER_VALIDATE_INT );

			if ( false === $user_id || $user_id <= 0 ) {
				return false;
			}

			$defaults = array(
				'require_blog_membership' => true,
				'skip_if_already_current' => true,
			);
			$args     = array_merge( $defaults, $args );

			if ( ! empty( $args['skip_if_already_current'] ) && get_current_user_id() === $user_id ) {
				return true;
			}

			// `get_userdata()` returns false for unknown / deleted users,
			// guarding against stale identifiers persisted in transients,
			// queued jobs, or replayed proxy headers.
			$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
			if ( ! ( $user instanceof WP_User ) || empty( $user->ID ) ) {
				return false;
			}

			if ( ! empty( $args['require_blog_membership'] ) && function_exists( 'is_multisite' ) && is_multisite() ) {
				if ( function_exists( 'is_user_member_of_blog' ) && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
					return false;
				}
			}

			wp_set_current_user( $user_id );

			return true;
		}
	}
}
