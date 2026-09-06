<?php
/**
 * Shared helpers for abilities test suites.
 *
 * WordPress 6.9+ requires abilities and their categories to be registered
 * while their dedicated init actions are firing, so suites bridge tools
 * through those actions instead of calling wp_register_ability() directly.
 *
 * Core's registries also lazily fire those actions on first get_instance()
 * access, so the helpers warm the registries before any manual firing to
 * prevent a re-entrant action firing inside a registration callback.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! trait_exists( 'WP_MCP_AI_Ability_Test_Bootstrap' ) ) {
	/**
	 * Trait WP_MCP_AI_Ability_Test_Bootstrap
	 *
	 * @since 2.0.0
	 */
	trait WP_MCP_AI_Ability_Test_Bootstrap {

		/**
		 * Warm the category registry (idempotent).
		 *
		 * First access fires wp_abilities_api_categories_init lazily, which
		 * registers every category (NV oOS and other plugins) exactly once.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function bootstrap_ability_categories() {
			if ( class_exists( 'WP_Ability_Categories_Registry' ) ) {
				WP_Ability_Categories_Registry::get_instance();
			}
		}

		/**
		 * Warm the ability registry (idempotent).
		 *
		 * First access fires wp_abilities_api_init lazily with the full hook
		 * set (the bulk registrar included). Warming before the manual firing
		 * below prevents the lazy firing from re-entering while a test
		 * registration callback is already running.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function bootstrap_ability_registry() {
			if ( class_exists( 'WP_Abilities_Registry' ) ) {
				WP_Abilities_Registry::get_instance();
			}
		}

		/**
		 * Register a tool as an ability through wp_abilities_api_init.
		 *
		 * Pre-existing callbacks are removed first so this suite's mock
		 * registrations cannot collide with the bulk registrar; the
		 * WP_UnitTestCase hook snapshot restores them at tearDown.
		 *
		 * @since 2.0.0
		 *
		 * @param WP_MCP_AI_Tool_Interface $tool     Tool instance to bridge.
		 * @param string                   $category Ability category slug.
		 * @return WP_Ability|null|false Registration result.
		 */
		protected function register_ability_via_api( WP_MCP_AI_Tool_Interface $tool, $category ) {
			// Warm both registries so the lazy init actions have already fired
			// and the manual firing below cannot re-enter through
			// WP_Abilities_Registry::get_instance().
			$this->bootstrap_ability_categories();
			$this->bootstrap_ability_registry();

			remove_all_filters( 'wp_abilities_api_init' );

			$result = null;
			add_action(
				'wp_abilities_api_init',
				function () use ( $tool, $category, &$result ) {
					$result = WP_MCP_AI_Ability_Bridge::register( $tool, $category );
				},
				99
			);

			do_action( 'wp_abilities_api_init' );

			remove_all_filters( 'wp_abilities_api_init' );

			return $result;
		}

		/**
		 * Remove abilities and mock tools registered by a test.
		 *
		 * Keeps the process-wide core registry and the tool registry clean so
		 * later suites do not collide with mock registrations.
		 *
		 * @since 2.0.0
		 *
		 * @param array $ability_names Ability identifiers to remove.
		 * @param array $tool_slugs    Mock tool slugs to remove from the registry.
		 * @return void
		 */
		protected function clean_up_ability_registrations( array $ability_names, array $tool_slugs ) {
			if ( function_exists( 'wp_unregister_ability' ) ) {
				foreach ( $ability_names as $name ) {
					wp_unregister_ability( $name );
				}
			}

			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			foreach ( $tool_slugs as $slug ) {
				$registry->unregister_tool( $slug );
			}
		}
	}
}
