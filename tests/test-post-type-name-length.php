<?php
/**
 * Tests for post type name length validation.
 *
 * WordPress requires post type names to be between 1 and 20 characters in length.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test post type name lengths.
 */
class Test_Post_Type_Name_Length extends WP_UnitTestCase {
	/**
	 * Test that all registered post types have valid names.
	 *
	 * WordPress requires post type names to be between 1 and 20 characters.
	 */
	public function test_post_type_names_within_length_limit() {
		// Plugin and core post types are registered during the bootstrap's
		// real `init` fire; re-firing `do_action( 'init' )` here re-runs
		// WooCommerce block/integration registrations and raises
		// "already registered" incorrect-usage notices.
		$post_types = get_post_types( array(), 'objects' );

		$invalid_post_types = array();

		foreach ( $post_types as $post_type_name => $post_type_object ) {
			$length = strlen( $post_type_name );

			// WordPress requires post type names to be between 1 and 20 characters.
			if ( $length < 1 || $length > 20 ) {
				$invalid_post_types[ $post_type_name ] = $length;
			}
		}

		$this->assertEmpty(
			$invalid_post_types,
			sprintf(
				'The following post types have invalid name lengths (must be 1-20 characters): %s',
				print_r( $invalid_post_types, true )
			)
		);
	}

	/**
	 * Test that plugin-specific post types have valid names.
	 *
	 * This test specifically checks the post types registered by this plugin.
	 */
	public function test_plugin_post_types_within_length_limit() {
		// Post types are registered during the bootstrap `init` fire; re-firing
		// the action re-registers WooCommerce integrations and fails the test.
		$plugin_prefixes = array( 'mcp_', 'mcp_ai_' );

		$post_types         = get_post_types( array(), 'objects' );
		$invalid_post_types = array();
		$plugin_post_types  = array();

		foreach ( $post_types as $post_type_name => $post_type_object ) {
			// Check if this is a plugin post type.
			$is_plugin_post_type = false;
			foreach ( $plugin_prefixes as $prefix ) {
				if ( 0 === strpos( $post_type_name, $prefix ) ) {
					$is_plugin_post_type = true;
					break;
				}
			}

			if ( ! $is_plugin_post_type ) {
				continue;
			}

			$plugin_post_types[] = $post_type_name;
			$length              = strlen( $post_type_name );

			// WordPress requires post type names to be between 1 and 20 characters.
			if ( $length < 1 || $length > 20 ) {
				$invalid_post_types[ $post_type_name ] = $length;
			}
		}

		// Ensure we found some plugin post types.
		$this->assertNotEmpty(
			$plugin_post_types,
			'No plugin post types were found. Expected at least one post type with mcp_ or mcp_ai_ prefix.'
		);

		// Ensure all plugin post types have valid names.
		$this->assertEmpty(
			$invalid_post_types,
			sprintf(
				'The following plugin post types have invalid name lengths (must be 1-20 characters): %s',
				print_r( $invalid_post_types, true )
			)
		);
	}
}
