<?php
/**
 * Tests for WP_MCP_AI_Ability_Registrar — bulk tool-to-ability registration.
 *
 * Verifies hook registration, allowlist integrity, category validity,
 * and that only mapped tools are registered as Abilities.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Tests for WP_MCP_AI_Ability_Registrar.
 *
 * @covers WP_MCP_AI_Ability_Registrar
 */
class WP_MCP_AI_Ability_Registrar_Test extends WP_UnitTestCase {

	/**
	 * Clean up abilities registered by the suite.
	 *
	 * The core registry is process-wide, so bulk registrations made while
	 * firing the abilities init action are removed again after each test.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function tearDown(): void {
		if ( function_exists( 'wp_unregister_ability' ) ) {
			foreach ( WP_MCP_AI_Ability_Registrar::TOOL_CATEGORY_MAP as $slug => $category ) {
				unset( $category );
				$ability_name = 'nvoos/' . str_replace( '_', '-', $slug );
				if ( wp_has_ability( $ability_name ) ) {
					wp_unregister_ability( $ability_name );
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Fire the ability registration action with a clean slate.
	 *
	 * The core registries persist process-wide, so allowlist abilities may
	 * already exist (registered by the lazy init in an earlier suite). They
	 * are unregistered first and the action is re-fired with only the bulk
	 * registrar attached, making register_all() run exactly once per call.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private function fire_registration_actions() {
		// Warm the registry first: its lazy first access fires the action and
		// would otherwise re-enter while our manual firing below is running.
		if ( class_exists( 'WP_Abilities_Registry' ) ) {
			WP_Abilities_Registry::get_instance();
		}

		if ( function_exists( 'wp_unregister_ability' ) ) {
			foreach ( WP_MCP_AI_Ability_Registrar::TOOL_CATEGORY_MAP as $slug => $category ) {
				unset( $category );
				$ability_name = 'nvoos/' . str_replace( '_', '-', $slug );
				if ( wp_has_ability( $ability_name ) ) {
					wp_unregister_ability( $ability_name );
				}
			}
		}

		remove_all_filters( 'wp_abilities_api_init' );
		if ( ! has_action( 'wp_abilities_api_init', array( 'WP_MCP_AI_Ability_Registrar', 'register_all' ) ) ) {
			WP_MCP_AI_Ability_Registrar::init();
		}

		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Ensure the registrar hooks into wp_abilities_api_init.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_init_hooks_into_correct_action() {
		WP_MCP_AI_Ability_Registrar::init();

		$this->assertTrue(
			has_action( 'wp_abilities_api_init', array( 'WP_MCP_AI_Ability_Registrar', 'register_all' ) ) > 0,
			'Ability registrar should hook into wp_abilities_api_init.'
		);
	}

	/**
	 * Ensure the tool category map contains no duplicate slugs.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_tool_category_map_has_no_duplicates() {
		$map    = WP_MCP_AI_Ability_Registrar::TOOL_CATEGORY_MAP;
		$slugs  = array_keys( $map );
		$unique = array_unique( $slugs );

		$this->assertSame(
			count( $unique ),
			count( $slugs ),
			'TOOL_CATEGORY_MAP should not contain duplicate tool slugs.'
		);
	}

	/**
	 * Ensure all category values in the map reference registered categories.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_tool_category_map_references_valid_categories() {
		$map              = WP_MCP_AI_Ability_Registrar::TOOL_CATEGORY_MAP;
		$valid_categories = array_keys( WP_MCP_AI_Ability_Category_Registrar::CATEGORIES );

		foreach ( $map as $tool_slug => $category ) {
			$this->assertContains(
				$category,
				$valid_categories,
				"Tool '{$tool_slug}' references category '{$category}' which should be a defined category."
			);
		}
	}

	/**
	 * Ensure every tool slug in the map uses snake_case.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_all_slugs_are_snake_case() {
		$map = WP_MCP_AI_Ability_Registrar::TOOL_CATEGORY_MAP;

		foreach ( array_keys( $map ) as $slug ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z][a-z0-9_]*$/',
				$slug,
				"Tool slug '{$slug}' should be lowercase snake_case."
			);
			$this->assertStringNotContainsString(
				'-',
				$slug,
				"Tool slug '{$slug}' should not contain hyphens."
			);
		}
	}

	/**
	 * Ensure register_all is a no-op when wp_register_ability is unavailable.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_all_returns_early_without_api() {
		if ( function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() is available — guard is not triggered.' );
		}

		// Should not throw any errors.
		WP_MCP_AI_Ability_Registrar::register_all();

		$this->assertTrue( true ); // Reaching here without error is success.
	}

	/**
	 * Ensure register_all fires the wp_mcp_ai_abilities_registered action.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_register_all_fires_registered_action() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$fired = false;
		add_action(
			'wp_mcp_ai_abilities_registered',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->fire_registration_actions();

		$this->assertTrue( $fired, 'wp_mcp_ai_abilities_registered action should fire after registration.' );
	}

	/**
	 * Ensure tools in the map that exist in the registry are registered.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_eligible_tools_are_registered() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->fire_registration_actions();

		// Verify that get_post exists and was registered.
		if ( $registry->get_tool( 'get_post' ) ) {
			$this->assertTrue(
				wp_has_ability( 'nvoos/get-post' ),
				'get_post should be registered as nvoos/get-post.'
			);
		}

		// Verify that get_site_summary exists and was registered.
		if ( $registry->get_tool( 'get_site_summary' ) ) {
			$this->assertTrue(
				wp_has_ability( 'nvoos/get-site-summary' ),
				'get_site_summary should be registered as nvoos/get-site-summary.'
			);
		}
	}

	/**
	 * Ensure tools NOT in the map are NOT registered as abilities.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_non_mapped_tools_are_not_registered() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$this->fire_registration_actions();

		// delete_post is a real tool but NOT in our selective allowlist.
		$this->assertFalse(
			wp_has_ability( 'nvoos/delete-post' ),
			'delete_post should NOT be registered as an ability (not in selective allowlist).'
		);
	}

	/**
	 * Ensure the tool_abilities filter can disable the whole bridge.
	 *
	 * This is the documented kill switch for the change that stops core REST
	 * requests from enumerating the entire tool registry.
	 *
	 * @since 1.2.24
	 * @return void
	 */
	public function test_register_all_respects_kill_switch_filter() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'wp_register_ability() not available.' );
		}

		$fired = false;
		add_action(
			'wp_mcp_ai_abilities_registered',
			function () use ( &$fired ) {
				$fired = true;
			}
		);
		add_filter( 'wp_mcp_ai_register_tool_abilities', '__return_false' );

		$this->fire_registration_actions();

		$this->assertFalse(
			$fired,
			'wp_mcp_ai_abilities_registered should not fire when the filter disables registration.'
		);
		$this->assertFalse(
			wp_has_ability( 'nvoos/get-recent-posts' ),
			'No tool abilities should be registered when the kill switch is active.'
		);

		remove_filter( 'wp_mcp_ai_register_tool_abilities', '__return_false' );
	}

	/**
	 * Ensure the REST route used by the registration gate is normalised.
	 *
	 * Core routes (e.g. /wp/v2/users/me) must not match the Abilities or MCP
	 * namespaces, and both namespaces must match with or without leading and
	 * trailing slashes in the raw query var.
	 *
	 * @since 1.2.24
	 * @return void
	 */
	public function test_current_rest_route_is_normalised() {
		$method = new ReflectionMethod( 'WP_MCP_AI_Ability_Registrar', 'current_rest_route' );
		$method->setAccessible( true );

		$had_original = isset( $GLOBALS['wp']->query_vars['rest_route'] );
		$original     = $had_original ? $GLOBALS['wp']->query_vars['rest_route'] : null;

		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users/me';
		$this->assertSame( '/wp/v2/users/me/', $method->invoke( null ) );

		$GLOBALS['wp']->query_vars['rest_route'] = 'mcp-ai/v1/tools';
		$route                                   = $method->invoke( null );
		$this->assertSame( '/mcp-ai/v1/tools/', $route );
		$this->assertSame( 0, strpos( $route, '/mcp-ai/' ), 'MCP namespace must match the gate.' );

		$GLOBALS['wp']->query_vars['rest_route'] = '/wp-abilities/v1/abilities';
		$route                                   = $method->invoke( null );
		$this->assertSame( 0, strpos( $route, '/wp-abilities/' ), 'Abilities namespace must match the gate.' );

		if ( $had_original ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $original;
		} else {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}
	}
}
