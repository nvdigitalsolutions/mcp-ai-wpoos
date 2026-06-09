<?php
/**
 * Test: Tenant Data Isolation
 *
 * Verifies that tenant data is properly scoped per subsite
 * and that cross-tenant access is prevented.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for tenant data isolation — verifies per-blog scoping of toolkit flags.
 *
 * @group schedule-anything
 * @group isolation
 */
class Test_Tenant_Isolation extends WP_UnitTestCase {

	/**
	 * Test that toolkit flags are scoped per blog.
	 *
	 * When SA_Toolkit_Manager sets flags on blog A,
	 * blog B should not see those flags.
	 */
	public function test_toolkit_flags_are_blog_scoped() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		// Create two blogs.
		$blog_a = $this->factory->blog->create_and_get();
		$blog_b = $this->factory->blog->create_and_get();

		// Set toolkit flags on blog A.
		switch_to_blog( $blog_a->blog_id );
		SA_Toolkit_Manager::ensure_defaults( 'starter' );
		$flags_a = SA_Toolkit_Manager::get_all_statuses();
		restore_current_blog();

		// Check blog B — should be empty (no defaults set).
		switch_to_blog( $blog_b->blog_id );
		$flags_b = SA_Toolkit_Manager::get_all_statuses();
		restore_current_blog();

		// Blog A should have defaults.
		$enabled_a = array_filter(
			$flags_a,
			function ( $f ) {
				return $f['enabled'];
			}
		);
		$this->assertNotEmpty( $enabled_a, 'Blog A should have enabled toolkits.' );

		// Blog B should be empty (no flags set yet).
		$enabled_b = array_filter(
			$flags_b,
			function ( $f ) {
				return $f['enabled'];
			}
		);
		$this->assertEmpty( $enabled_b, 'Blog B should not inherit Blog A\'s flags.' );
	}

	/**
	 * Test that tier defaults match the expected toolkit count.
	 */
	public function test_tier_defaults_have_correct_counts() {
		$starter_defaults = SA_Toolkit_Manager::get_defaults_for_tier( 'starter' );
		$pro_defaults     = SA_Toolkit_Manager::get_defaults_for_tier( 'professional' );
		$ent_defaults     = SA_Toolkit_Manager::get_defaults_for_tier( 'enterprise' );

		$starter_enabled = count( array_filter( $starter_defaults ) );
		$pro_enabled     = count( array_filter( $pro_defaults ) );
		$ent_enabled     = count( array_filter( $ent_defaults ) );

		$this->assertSame( 5, $starter_enabled, 'Starter tier should have exactly 5 toolkits enabled.' );
		$this->assertSame( 15, $pro_enabled, 'Professional tier should have exactly 15 toolkits enabled.' );
		$this->assertSame( 30, $ent_enabled, 'Enterprise tier should have all 30 toolkits enabled.' );
	}

	/**
	 * Test toggle_toolkit enables and disables correctly.
	 */
	public function test_toggle_toolkit() {
		// Set baseline.
		SA_Toolkit_Manager::ensure_defaults( 'starter' );

		// CRM should be enabled in starter.
		$statuses = SA_Toolkit_Manager::get_all_statuses();
		$crm      = null;
		foreach ( $statuses as $s ) {
			if ( 'crm' === $s['slug'] ) {
				$crm = $s;
				break;
			}
		}
		$this->assertNotNull( $crm, 'CRM should be in the toolkit list.' );
		$this->assertTrue( $crm['enabled'], 'CRM should be enabled in starter tier.' );

		// Toggle CRM off.
		$result = SA_Toolkit_Manager::toggle_toolkit( 'crm', false );
		$this->assertTrue( $result, 'Toggle should succeed.' );

		// Verify CRM is now disabled.
		$statuses = SA_Toolkit_Manager::get_all_statuses();
		foreach ( $statuses as $s ) {
			if ( 'crm' === $s['slug'] ) {
				$this->assertFalse( $s['enabled'], 'CRM should be disabled after toggle.' );
			}
		}

		// Toggle CRM back on.
		SA_Toolkit_Manager::toggle_toolkit( 'crm', true );
		$statuses = SA_Toolkit_Manager::get_all_statuses();
		foreach ( $statuses as $s ) {
			if ( 'crm' === $s['slug'] ) {
				$this->assertTrue( $s['enabled'], 'CRM should be re-enabled after toggle.' );
			}
		}
	}

	/**
	 * Test that toggle_toolkit returns false for invalid toolkit.
	 */
	public function test_toggle_toolkit_invalid_slug() {
		$result = SA_Toolkit_Manager::toggle_toolkit( 'nonexistent_toolkit', true );
		$this->assertFalse( $result );
	}
}
