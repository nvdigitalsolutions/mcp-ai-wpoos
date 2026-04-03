<?php
/**
 * Interface: Capability Checker
 *
 * Abstracts WordPress `current_user_can` / `user_can` so that application-layer
 * and tool classes can perform capability checks without depending on WordPress
 * functions directly.
 *
 * Implement this interface in
 * `includes/infrastructure/wp/class-wp-mcp-ai-wp-capability-checker.php`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction for checking user capabilities.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_Capability_Checker {

	/**
	 * Check whether the currently authenticated user has a given capability.
	 *
	 * Equivalent to WordPress `current_user_can( $capability, ...$args )`.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional extra arguments passed to the capability check.
	 * @return bool
	 */
	public function current_user_can( $capability, ...$args );

	/**
	 * Check whether a specific user has a given capability.
	 *
	 * Equivalent to WordPress `user_can( $user, $capability, ...$args )`.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional extra arguments.
	 * @return bool
	 */
	public function user_can( $user_id, $capability, ...$args );
}
