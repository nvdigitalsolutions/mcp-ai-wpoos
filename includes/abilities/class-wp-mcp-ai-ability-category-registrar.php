<?php
/**
 * Ability Category Registrar — registers NV oOS discovery categories.
 *
 * Categories must be registered before abilities on the dedicated
 * wp_abilities_api_categories_init hook (WP 6.9+).
 *
 * All registrations are guarded by function_exists() for backward
 * compatibility with WordPress < 6.9.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers NV oOS ability categories for AI agent discovery.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Category_Registrar {

	/**
	 * Category definitions.
	 *
	 * @since 2.0.0
	 * @var array<string, array{label: string, description: string}>
	 */
	const CATEGORIES = array(
		'nvoos-site'      => array(
			'label'       => 'Site Information',
			'description' => 'Abilities that report on WordPress site state and configuration.',
		),
		'nvoos-content'   => array(
			'label'       => 'Content & Publishing',
			'description' => 'Abilities for reading and managing WordPress content.',
		),
		'nvoos-media'     => array(
			'label'       => 'Media & Images',
			'description' => 'Abilities for searching, retrieving, and optimizing media.',
		),
		'nvoos-system'    => array(
			'label'       => 'System & Diagnostics',
			'description' => 'Abilities for inspecting server state, cron jobs, and plugin status.',
		),
		'nvoos-discovery' => array(
			'label'       => 'AI Model Discovery',
			'description' => 'Abilities that describe available AI models, providers, and tools.',
		),
	);

	/**
	 * Register all categories on wp_abilities_api_categories_init.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register categories, skipping already-registered ones.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::CATEGORIES as $slug => $args ) {
			if ( wp_has_ability_category( $slug ) ) {
				continue;
			}

			wp_register_ability_category(
				$slug,
				array(
					'label'       => $args['label'],
					'description' => $args['description'],
				)
			);
		}
	}
}
