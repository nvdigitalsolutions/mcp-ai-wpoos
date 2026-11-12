<?php
/**
 * Parity suite: the Google/OpenAI media tools and the capability registry must
 * describe the same models and the same limits.
 *
 * Companion to the two Higgsfield parity suites. These three tools had no
 * per-model const before Proposal 141 — their schema literals were the only
 * declaration — so this suite plays two roles:
 *
 *  - it pins the consts and registry together (const ↔ registry, both ways);
 *  - it hardcodes the expected capability values, which is the reviewable
 *    anchor for "deriving changed nothing".
 *
 * The Veo tool additionally distinguishes SELECTABLE models (omni, veo-3.1,
 * veo-2.0) from CAPABILITY models (veo-3.1, veo-2.0): it routes to Omni for the
 * `omni` model but passes aspect_ratio through unclamped, so including Omni's
 * wider aspect list would turn a schema value into a provider-side error.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Google/OpenAI media tool ↔ registry parity.
 */
class Test_Media_Tool_Catalog_Parity extends WP_UnitTestCase {

	/**
	 * Veo tool.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video
	 */
	protected $veo;

	/**
	 * Omni tool.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Omni_Video
	 */
	protected $omni;

	/**
	 * Sora tool.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Sora_Video
	 */
	protected $sora;

	/**
	 * Load dependencies and reset the registry cache.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-omni-video.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';

		WP_MCP_AI_Media_Model_Catalog::reset_cache();

		$this->veo  = new WP_MCP_AI_Tool_Generate_Veo_Video();
		$this->omni = new WP_MCP_AI_Tool_Generate_Omni_Video();
		$this->sora = new WP_MCP_AI_Tool_Generate_Sora_Video();
	}

	/**
	 * Restore filters and cache.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_media_model_catalog_path' );
		WP_MCP_AI_Media_Model_Catalog::reset_cache();
		parent::tear_down();
	}

	/**
	 * Point the registry at a missing file for the duration of one test.
	 *
	 * @return void
	 */
	private function remove_registry() {
		add_filter(
			'wp_mcp_ai_media_model_catalog_path',
			static function () {
				return __DIR__ . '/fixtures/does-not-exist.json';
			}
		);

		WP_MCP_AI_Media_Model_Catalog::reset_cache();
	}

	/**
	 * Registry ids assigned to a tool.
	 *
	 * @param string $slug Tool slug.
	 * @return string[]
	 */
	private function registered_for( $slug ) {
		return array_keys( WP_MCP_AI_Media_Model_Catalog::get_models( array( 'tool' => $slug ) ) );
	}

	/**
	 * The registry's `tools` field makes a model reachable from more than one
	 * tool without changing its owner.
	 */
	public function test_registry_tools_field_is_honoured() {
		$omni = WP_MCP_AI_Media_Model_Catalog::get_model( 'omni' );

		$this->assertNotNull( $omni );
		$this->assertSame( 'generate_omni_video', $omni['tool'], 'omni must keep generate_omni_video as its owner' );

		$this->assertSame( array( 'omni' ), array_values( $this->registered_for( 'generate_omni_video' ) ) );
		$this->assertContains( 'omni', $this->registered_for( 'generate_veo_video' ) );
	}

	/**
	 * Every model the Veo tool can select is registered for it.
	 */
	public function test_veo_models_are_registered() {
		$registered = $this->registered_for( 'generate_veo_video' );

		$this->assertSame( array( 'omni', 'veo-3.1', 'veo-2.0' ), $this->veo->get_supported_models() );

		foreach ( $this->veo->get_supported_models() as $model ) {
			$this->assertContains( $model, $registered, "Veo tool selects {$model} but the registry does not list it" );
		}
	}

	/**
	 * Omni is selectable but must not contribute capabilities.
	 *
	 * This is the widening guard: Omni supports 16:9 / 9:16, the native Veo path
	 * does not, and this tool forwards aspect_ratio unclamped.
	 */
	public function test_veo_capability_models_exclude_omni() {
		$this->assertSame( array( 'veo-3.1', 'veo-2.0' ), $this->veo->get_capability_models() );
		$this->assertNotContains( 'omni', $this->veo->get_capability_models() );

		$this->assertNotContains( '16:9', $this->veo->get_allowed_aspect_ratios() );
		$this->assertNotContains( '9:16', $this->veo->get_allowed_aspect_ratios() );
	}

	/**
	 * The Veo schema publishes the derived values.
	 */
	public function test_veo_schema() {
		$schema = $this->veo->get_parameters_schema();

		$this->assertSame( array( 'omni', 'veo-3.1', 'veo-2.0' ), $schema['properties']['model']['enum'] );
		$this->assertSame( array( '1:1', '2:3', '3:2', 'auto' ), $schema['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '720p', '1080p' ), $schema['properties']['resolution']['enum'] );
		$this->assertSame( 5, $schema['properties']['duration']['minimum'] );
		$this->assertSame( 8, $schema['properties']['duration']['maximum'] );
	}

	/**
	 * The Omni schema publishes the derived values.
	 */
	public function test_omni_schema() {
		$schema = $this->omni->get_parameters_schema();

		$this->assertSame( array( '1:1', '2:3', '3:2', '16:9', '9:16' ), $schema['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '720p', '1080p' ), $schema['properties']['resolution']['enum'] );
		$this->assertSame( 4, $schema['properties']['duration']['minimum'] );
		$this->assertSame( 10, $schema['properties']['duration']['maximum'] );
		$this->assertSame( 5, $schema['properties']['reference_images']['maxItems'] );
	}

	/**
	 * Every model the Sora tool can select is registered for it.
	 */
	public function test_sora_models_are_registered() {
		$registered = $this->registered_for( 'generate_sora_video' );

		$this->assertSame( array( 'sora-2', 'sora-2-pro' ), $this->sora->get_supported_models() );

		foreach ( $this->sora->get_supported_models() as $model ) {
			$this->assertContains( $model, $registered, "Sora tool selects {$model} but the registry does not list it" );
		}
	}

	/**
	 * The Sora schema publishes the derived values.
	 */
	public function test_sora_schema() {
		$schema = $this->sora->get_parameters_schema();

		$this->assertSame( array( 'sora-2', 'sora-2-pro' ), $schema['properties']['model']['enum'] );
		$this->assertSame( array( '16:9', '9:16', '1:1' ), $schema['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '480p', '720p', '1080p' ), $schema['properties']['size']['enum'] );
		$this->assertSame( 5, $schema['properties']['duration']['minimum'] );
		$this->assertSame( 60, $schema['properties']['duration']['maximum'] );
	}

	/**
	 * With no registry every derived value falls back to the pre-141 literal,
	 * so deleting the data file cannot narrow or widen a live tool contract.
	 */
	public function test_missing_registry_preserves_every_schema_value() {
		$this->remove_registry();

		$veo = $this->veo->get_parameters_schema();
		$this->assertSame( array( 'omni', 'veo-3.1', 'veo-2.0' ), $veo['properties']['model']['enum'] );
		$this->assertSame( array( '1:1', '2:3', '3:2', 'auto' ), $veo['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '720p', '1080p' ), $veo['properties']['resolution']['enum'] );
		$this->assertSame( 5, $veo['properties']['duration']['minimum'] );
		$this->assertSame( 8, $veo['properties']['duration']['maximum'] );

		$omni = $this->omni->get_parameters_schema();
		$this->assertSame( array( '1:1', '2:3', '3:2', '16:9', '9:16' ), $omni['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '720p', '1080p' ), $omni['properties']['resolution']['enum'] );
		$this->assertSame( 4, $omni['properties']['duration']['minimum'] );
		$this->assertSame( 10, $omni['properties']['duration']['maximum'] );
		$this->assertSame( 5, $omni['properties']['reference_images']['maxItems'] );

		$sora = $this->sora->get_parameters_schema();
		$this->assertSame( array( 'sora-2', 'sora-2-pro' ), $sora['properties']['model']['enum'] );
		$this->assertSame( array( '16:9', '9:16', '1:1' ), $sora['properties']['aspect_ratio']['enum'] );
		$this->assertSame( array( '480p', '720p', '1080p' ), $sora['properties']['size']['enum'] );
		$this->assertSame( 5, $sora['properties']['duration']['minimum'] );
		$this->assertSame( 60, $sora['properties']['duration']['maximum'] );
	}
}
