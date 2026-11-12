<?php
/**
 * Profession catalogue version and cache epoch (090-g Phase 6).
 *
 * The catalogue is CPT-backed, so the version is a counter bumped on every
 * save and delete. Consumers key their caches by it; a bump must therefore be
 * automatic — no manual purge.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the profession catalogue version.
 */
class WP_MCP_AI_Profession_Catalogue_Version_Test extends WP_UnitTestCase {

	/**
	 * The version never drops below one, so it can be used in a cache key.
	 */
	public function test_version_is_at_least_one() {
		$this->assertGreaterThanOrEqual( 1, WP_MCP_AI_Profession_CPT::catalogue_version() );
	}

	/**
	 * Saving a profession invalidates the cache epoch.
	 */
	public function test_saving_a_profession_bumps_the_version() {
		$before = WP_MCP_AI_Profession_CPT::catalogue_version();

		wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_title'  => 'Cache Probe',
				'post_name'   => 'cache-probe',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( $before, WP_MCP_AI_Profession_CPT::catalogue_version() );
	}

	/**
	 * Deleting a profession invalidates the cache epoch.
	 */
	public function test_deleting_a_profession_bumps_the_version() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_title'  => 'Cache Probe',
				'post_name'   => 'cache-probe',
				'post_status' => 'publish',
			)
		);

		$before = WP_MCP_AI_Profession_CPT::catalogue_version();

		wp_delete_post( $post_id, true );

		$this->assertGreaterThan( $before, WP_MCP_AI_Profession_CPT::catalogue_version() );
	}

	/**
	 * Deleting another post type must not touch the profession epoch.
	 */
	public function test_deleting_another_post_type_does_not_bump_the_version() {
		$post_id = self::factory()->post->create();

		$before = WP_MCP_AI_Profession_CPT::catalogue_version();

		wp_delete_post( $post_id, true );

		$this->assertSame( $before, WP_MCP_AI_Profession_CPT::catalogue_version() );
	}

	/**
	 * The adapter reports the same epoch the CPT publishes.
	 */
	public function test_the_adapter_reports_the_cpt_epoch() {
		$adapter = new Nvoos\WordPress\Adapter\ProfessionRepository();

		$this->assertSame(
			(string) WP_MCP_AI_Profession_CPT::catalogue_version(),
			$adapter->catalogueVersion()
		);
	}
}
