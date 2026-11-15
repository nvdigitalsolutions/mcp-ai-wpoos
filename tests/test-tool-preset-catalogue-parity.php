<?php
/**
 * Parity between the generated core preset catalogue and the WordPress one.
 *
 * `lib/core` cannot read the WordPress catalogue at runtime, so the tool lists
 * are copied into `ToolPresetCatalogue` by
 * `bin/generate-tool-preset-catalogue.php`. A copy drifts unless something
 * fails when it does — this test is that something.
 *
 * @package WP_MCP_AI
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Preset catalogue parity tests.
 *
 * @since 1.6.0
 */
class Test_Tool_Preset_Catalogue_Parity extends WP_UnitTestCase {

	/**
	 * Fail with an instruction rather than a bare "class not found".
	 *
	 * @return bool
	 */
	private function has_core_catalogue() {
		if ( class_exists( '\Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue' ) ) {
			return true;
		}

		$this->markTestSkipped( 'lib/core is absent (wp.org base build) — nothing to compare against.' );

		return false;
	}

	/**
	 * Every WordPress preset must exist in core, under the same slug.
	 *
	 * @since 1.6.0
	 */
	public function test_preset_slugs_match() {
		if ( ! $this->has_core_catalogue() ) {
			return;
		}

		$wp   = WP_MCP_AI_Tool_Presets_Helper::get_presets();
		$core = \Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue::presets();

		$this->assertSame(
			array_keys( $wp ),
			array_keys( $core ),
			'Preset slugs differ. Regenerate: php bin/generate-tool-preset-catalogue.php'
		);
	}

	/**
	 * Each preset's tool list must match exactly, order included.
	 *
	 * Order is compared deliberately: the catalogue is generated, so any
	 * difference at all means the file is stale.
	 *
	 * @since 1.6.0
	 */
	public function test_tool_lists_match() {
		if ( ! $this->has_core_catalogue() ) {
			return;
		}

		$wp   = WP_MCP_AI_Tool_Presets_Helper::get_presets();
		$core = \Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue::presets();

		foreach ( $wp as $slug => $preset ) {
			$wp_tools = isset( $preset['tools'] ) ? array_values( (array) $preset['tools'] ) : array();

			$this->assertArrayHasKey( $slug, $core, "Preset {$slug} is missing from the core catalogue." );
			$this->assertSame(
				$wp_tools,
				$core[ $slug ]['tools'],
				"Tool list for {$slug} differs. Regenerate: php bin/generate-tool-preset-catalogue.php"
			);
		}
	}

	/**
	 * The English label is copied verbatim; translations stay in WordPress.
	 *
	 * @since 1.6.0
	 */
	public function test_labels_match_the_untranslated_name() {
		if ( ! $this->has_core_catalogue() ) {
			return;
		}

		$wp   = WP_MCP_AI_Tool_Presets_Helper::get_presets();
		$core = \Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue::presets();

		foreach ( $wp as $slug => $preset ) {
			$this->assertSame(
				(string) $preset['name'],
				$core[ $slug ]['label'],
				"Label for {$slug} differs."
			);
		}
	}

	/**
	 * The index the router consumes must agree with the catalogue.
	 *
	 * @since 1.6.0
	 */
	public function test_the_index_covers_every_catalogue_member() {
		if ( ! $this->has_core_catalogue() ) {
			return;
		}

		$core  = \Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue::presets();
		$index = \Nvoos\Core\Domain\Service\Harness\ToolPresetCatalogue::index();

		foreach ( $core as $slug => $preset ) {
			foreach ( $preset['tools'] as $tool ) {
				$this->assertContains(
					$slug,
					$index[ $tool ] ?? array(),
					"{$tool} is listed in {$slug} but the index does not say so."
				);
			}
		}
	}
}
