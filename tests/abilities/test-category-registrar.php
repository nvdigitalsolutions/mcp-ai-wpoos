<?php
/**
 * Tests for WP_MCP_AI_Ability_Category_Registrar.
 *
 * Verifies hook registration, category definitions structure, and
 * idempotent registration behaviour.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Tests for WP_MCP_AI_Ability_Category_Registrar.
 *
 * @covers WP_MCP_AI_Ability_Category_Registrar
 */
class WP_MCP_AI_Ability_Category_Registrar_Test extends WP_UnitTestCase {

	/**
	 * Ensure the registrar hooks into the correct action.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_init_hooks_into_correct_action() {
		WP_MCP_AI_Ability_Category_Registrar::init();

		$this->assertTrue(
			has_action( 'wp_abilities_api_categories_init', array( 'WP_MCP_AI_Ability_Category_Registrar', 'register' ) ) > 0,
			'Category registrar should hook into wp_abilities_api_categories_init.'
		);
	}

	/**
	 * Ensure categories array contains the expected keys and structure.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_categories_have_required_fields() {
		$categories = WP_MCP_AI_Ability_Category_Registrar::CATEGORIES;

		$this->assertIsArray( $categories );
		$this->assertNotEmpty( $categories );

		$expected = array( 'nvoos-site', 'nvoos-content', 'nvoos-media', 'nvoos-system', 'nvoos-discovery' );
		foreach ( $expected as $slug ) {
			$this->assertArrayHasKey( $slug, $categories, "Category '{$slug}' should be defined." );
			$this->assertArrayHasKey( 'label', $categories[ $slug ], "Category '{$slug}' should have a label." );
			$this->assertArrayHasKey( 'description', $categories[ $slug ], "Category '{$slug}' should have a description." );
			$this->assertIsString( $categories[ $slug ]['label'] );
			$this->assertIsString( $categories[ $slug ]['description'] );
			$this->assertNotEmpty( $categories[ $slug ]['label'] );
			$this->assertNotEmpty( $categories[ $slug ]['description'] );
		}
	}

	/**
	 * Ensure the register method is a no-op when wp_register_ability_category() is unavailable.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_returns_early_when_function_unavailable() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			$this->markTestSkipped( 'wp_register_ability_category() not available in this environment.' );
		}

		WP_MCP_AI_Ability_Category_Registrar::register();

		foreach ( array_keys( WP_MCP_AI_Ability_Category_Registrar::CATEGORIES ) as $slug ) {
			$this->assertTrue(
				wp_has_ability_category( $slug ),
				"Category '{$slug}' should be registered after register() is called."
			);
		}
	}

	/**
	 * Ensure duplicate registrations do not cause errors.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_is_idempotent() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			$this->markTestSkipped( 'wp_register_ability_category() not available in this environment.' );
		}

		// Register twice — should not throw or cause issues.
		WP_MCP_AI_Ability_Category_Registrar::register();
		WP_MCP_AI_Ability_Category_Registrar::register();

		$this->assertTrue( wp_has_ability_category( 'nvoos-site' ) );
	}
}
