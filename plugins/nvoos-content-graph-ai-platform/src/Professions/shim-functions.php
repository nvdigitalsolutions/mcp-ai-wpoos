<?php
/**
 * Global function shims for the Professions subsystem.
 *
 * Defines the base plugin's global function surface
 * (wp_mcp_ai_get_profession_service, wp_mcp_ai_get_profession_dataset_recommendations,
 * wp_mcp_ai_get_all_profession_dataset_mappings) in STANDALONE mode only.
 *
 * Loaded exclusively from ProfessionService::register()'s standalone branch:
 * in monolith mode the base plugin owns (and may lazily define) these
 * functions, so defining them here could collide with the base copies.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
	/**
	 * Get profession service instance.
	 *
	 * @since 2.0.0
	 *
	 * @return \NvoosContentGraphAiPlatform\Professions\ProfessionService
	 */
	function wp_mcp_ai_get_profession_service() {
		return \NvoosContentGraphAiPlatform\Professions\ProfessionService::instance();
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_profession_dataset_recommendations' ) ) {
	/**
	 * Get dataset recommendations for a profession slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array Array of dataset information.
	 */
	function wp_mcp_ai_get_profession_dataset_recommendations( $profession_slug ) {
		return \NvoosContentGraphAiPlatform\Professions\DatasetMappings::recommendations( $profession_slug );
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_all_profession_dataset_mappings' ) ) {
	/**
	 * Get all profession to dataset mappings.
	 *
	 * @since 2.0.0
	 *
	 * @return array Associative array of profession_slug => datasets.
	 */
	function wp_mcp_ai_get_all_profession_dataset_mappings() {
		return \NvoosContentGraphAiPlatform\Professions\DatasetMappings::all();
	}
}
