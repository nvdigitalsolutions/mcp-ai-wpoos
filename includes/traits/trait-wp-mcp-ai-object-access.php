<?php
/**
 * Object Access Validation Trait — Centralized capability checks for
 * WordPress objects accessed by tools.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'WP_MCP_AI_Trait_Object_Access' ) ) {
	/**
	 * Validates that the current user (or token-authenticated user) has
	 * the required capability to read/edit/delete a WordPress object.
	 *
	 * Usage in a tool:
	 *   use WP_MCP_AI_Trait_Object_Access;
	 *
	 *   $check = $this->validate_object_access( $post_id, 'post', $context );
	 *   if ( is_wp_error( $check ) ) { return $check; }
	 */
	trait WP_MCP_AI_Trait_Object_Access {

		/**
		 * Capability map for WordPress object types.
		 *
		 * For each object type, the first matching capability grants access.
		 *
		 * @var array<string, array<string>>
		 */
		private $object_cap_map = array(
			'post'    => array( 'read_post', 'edit_post', 'delete_post', 'edit_others_posts' ),
			'page'    => array( 'read_post', 'edit_post', 'delete_post', 'edit_others_pages' ),
			'user'    => array( 'edit_user', 'list_users' ),
			'term'    => array( 'edit_term', 'assign_term', 'manage_categories' ),
			'comment' => array( 'edit_comment', 'moderate_comments' ),
		);

		/**
		 * Validate that the authenticated user can access a specific object.
		 *
		 * @param int    $object_id   WordPress object ID.
		 * @param string $object_type Object type ('post', 'page', 'user', 'term', 'comment').
		 * @param array  $context     Execution context from the tool.
		 * @return true|WP_Error True if access granted, WP_Error if denied.
		 */
		protected function validate_object_access( $object_id, $object_type, $context = array() ) {
			$object_id   = absint( $object_id );
			$object_type = sanitize_key( $object_type );

			if ( empty( $object_id ) ) {
				return new WP_Error(
					'invalid_object_id',
					__( 'Invalid object ID.', 'mcp-ai-wpoos' )
				);
			}

			// Determine the acting user.
			$user_id = $this->get_acting_user_id( $context );

			// Super-admin bypass.
			if ( is_super_admin( $user_id ) ) {
				return true;
			}

			// Check mapped capabilities.
			if ( isset( $this->object_cap_map[ $object_type ] ) ) {
				foreach ( $this->object_cap_map[ $object_type ] as $cap ) {
					if ( user_can( $user_id, $cap, $object_id ) ) {
						return true;
					}
				}
			}

			// Object type not in map — fall back to a generic check.
			if ( user_can( $user_id, 'edit_posts' ) && 'post' === get_post_type( $object_id ) ) {
				if ( user_can( $user_id, 'read_post', $object_id ) ) {
					return true;
				}
			}

			return new WP_Error(
				'object_access_denied',
				sprintf(
					/* translators: 1=object type, 2=object ID */
					__( 'You do not have permission to access this %1$s (ID: %2$d).', 'mcp-ai-wpoos' ),
					esc_html( $object_type ),
					$object_id
				)
			);
		}

		/**
		 * Determine the acting user ID from the execution context.
		 *
		 * Prefers the token-authenticated user over the current session user.
		 *
		 * @param array $context Execution context.
		 * @return int User ID.
		 */
		private function get_acting_user_id( $context ) {
			if ( ! empty( $context['token_authenticated'] ) && ! empty( $context['user_id'] ) ) {
				return absint( $context['user_id'] );
			}

			return get_current_user_id();
		}

		/**
		 * Bulk-validate access to multiple objects of the same type.
		 *
		 * Returns the first WP_Error encountered, or true if all pass.
		 *
		 * @param array<int> $object_ids  List of object IDs.
		 * @param string     $object_type Object type.
		 * @param array      $context     Execution context.
		 * @return true|WP_Error
		 */
		protected function validate_bulk_object_access( $object_ids, $object_type, $context = array() ) {
			foreach ( $object_ids as $object_id ) {
				$check = $this->validate_object_access( $object_id, $object_type, $context );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			return true;
		}
	}
}
