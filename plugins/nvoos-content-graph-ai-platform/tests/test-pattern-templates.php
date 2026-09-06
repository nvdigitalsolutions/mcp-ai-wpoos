<?php
/**
 * Pattern workflow templates port tests (Wave E1, sub-cluster 5).
 *
 * Characterization suite for the ported `PatternConstants` +
 * `PatternWorkflowTemplates`: byte-identical eight pattern slugs, the
 * pattern catalog/validity/description helpers, the eight-template
 * catalog with their role/step shapes, template lookups,
 * customize_template contract (custom-role merge + filter, no
 * mutation), the toolkit-recommendation path with a fake toolkit
 * registry, and the per-mode toolkit-registry seam. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\PatternConstants;
use NvoosContentGraphAiPlatform\Workflows\PatternWorkflowTemplates;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Fake toolkit registry.
 */
class FakeTemplateToolkitRegistry {

	/**
	 * Toolkit info map.
	 *
	 * @var array
	 */
	public static $toolkits = array();

	/**
	 * Get toolkit info.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array|null
	 */
	public function get_toolkit( $toolkit_slug ) {
		return isset( self::$toolkits[ $toolkit_slug ] ) ? self::$toolkits[ $toolkit_slug ] : null;
	}
}

/**
 * Seam forcing the fake toolkit registry + exposing the per-mode resolution.
 */
class TemplatesSeam extends PatternWorkflowTemplates {

	/**
	 * The fake registry.
	 *
	 * @return object|null
	 */
	protected function get_toolkit_registry() {
		return new FakeTemplateToolkitRegistry();
	}

	/**
	 * Probe the real per-mode toolkit-registry resolution.
	 *
	 * @return object|null
	 */
	public function probe_toolkit_registry() {
		return parent::get_toolkit_registry();
	}
}

/**
 * @group workflows
 */
class Test_Pattern_Templates extends \WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_customize_workflow_template' );
		parent::tearDown();
	}

	// ─── PatternConstants ─────────────────────────────────────────────

	public function test_pattern_slugs_are_byte_identical(): void {
		$this->assertSame( 'orchestrator', PatternConstants::PATTERN_ORCHESTRATOR );
		$this->assertSame( 'sequential', PatternConstants::PATTERN_SEQUENTIAL );
		$this->assertSame( 'peer_to_peer', PatternConstants::PATTERN_PEER_TO_PEER );
		$this->assertSame( 'skill_router', PatternConstants::PATTERN_SKILL_ROUTER );
		$this->assertSame( 'layered_defense', PatternConstants::PATTERN_LAYERED_DEFENSE );
		$this->assertSame( 'event_driven', PatternConstants::PATTERN_EVENT_DRIVEN );
		$this->assertSame( 'hierarchical', PatternConstants::PATTERN_HIERARCHICAL );
		$this->assertSame( 'experimentation', PatternConstants::PATTERN_EXPERIMENTATION );
	}

	public function test_pattern_catalog_helpers(): void {
		$all = PatternConstants::get_all_patterns();
		$this->assertCount( 8, $all );
		$this->assertSame( PatternConstants::PATTERN_ORCHESTRATOR, $all[0] );
		$this->assertSame( PatternConstants::PATTERN_EXPERIMENTATION, $all[7] );

		$this->assertTrue( PatternConstants::is_valid_pattern( 'sequential' ) );
		$this->assertFalse( PatternConstants::is_valid_pattern( 'nope' ) );

		$this->assertSame( 'Linear agent chain processing', PatternConstants::get_pattern_description( 'sequential' ) );
		$this->assertNull( PatternConstants::get_pattern_description( 'nope' ) );
	}

	// ─── Template catalog ─────────────────────────────────────────────

	public function test_catalog_has_all_eight_templates(): void {
		$templates = ( new PatternWorkflowTemplates() )->get_all_templates();

		$this->assertSame( array_values( PatternConstants::get_all_patterns() ), array_keys( $templates ) );

		foreach ( $templates as $slug => $template ) {
			$this->assertArrayHasKey( 'name', $template );
			$this->assertSame( $slug, $template['pattern'] );
			$this->assertArrayHasKey( 'description', $template );
			$this->assertNotEmpty( $template['roles'] );
			$this->assertNotEmpty( $template['workflow'] );
		}
	}

	public function test_orchestrator_template_shape(): void {
		$template = ( new PatternWorkflowTemplates() )->get_workflow_template( 'orchestrator' );

		$this->assertSame( 'Orchestrator Workflow', $template['name'] );
		$this->assertSame( array( 'coordinator', 'worker_1', 'worker_2', 'worker_3' ), $template['roles'] );
		$this->assertCount( 3, $template['workflow'] );
		$this->assertSame( 'plan', $template['workflow'][0]['name'] );
		$this->assertSame( 'coordinate', $template['workflow'][0]['type'] );
		$this->assertSame( 'coordinator', $template['workflow'][0]['role'] );
		$this->assertTrue( $template['workflow'][0]['critical'] );
		$this->assertSame( 'parallel', $template['workflow'][1]['type'] );
		$this->assertSame( array( 'worker_1', 'worker_2', 'worker_3' ), $template['workflow'][1]['roles'] );
		$this->assertSame( 'aggregate', $template['workflow'][2]['name'] );
	}

	public function test_template_lookup_returns_null_for_unknown(): void {
		$templates = new PatternWorkflowTemplates();

		$this->assertNull( $templates->get_workflow_template( 'does_not_exist' ) );
	}

	// ─── Customization ────────────────────────────────────────────────

	public function test_customize_template_merges_roles_and_applies_filter(): void {
		$templates = new PatternWorkflowTemplates();
		$base      = $templates->get_workflow_template( 'sequential' );

		$customized = $templates->customize_template(
			$base,
			array( 'custom_roles' => array( 'qa_agent' ) )
		);

		$this->assertContains( 'qa_agent', $customized['roles'] );
		// The base template is not mutated.
		$this->assertNotContains( 'qa_agent', $base['roles'] );

		// The customization filter can rewrite the template.
		add_filter(
			'wp_mcp_ai_customize_workflow_template',
			function ( $template, $original ) {
				$template['name'] = 'Filtered: ' . $original['name'];
				return $template;
			},
			10,
			2
		);

		$filtered = $templates->customize_template( $base, array() );
		$this->assertSame( 'Filtered: Sequential Pipeline Workflow', $filtered['name'] );
	}

	// ─── Toolkit recommendation ───────────────────────────────────────

	public function test_recommended_template_requires_pattern_registry(): void {
		$templates                             = new TemplatesSeam();
		FakeTemplateToolkitRegistry::$toolkits = array(
			'crm_toolkit' => array( 'primary_pattern' => 'sequential' ),
		);

		// No pattern registry → null regardless of the toolkit.
		$this->assertNull( $templates->get_recommended_template_for_toolkit( 'crm_toolkit' ) );
	}

	public function test_recommended_template_resolves_primary_pattern(): void {
		$templates                             = new TemplatesSeam( new \stdClass() ); // Pattern registry present.
		FakeTemplateToolkitRegistry::$toolkits = array(
			'crm_toolkit'  => array( 'primary_pattern' => 'sequential' ),
			'bare_toolkit' => array( 'no_pattern' => true ),
		);

		$recommended = $templates->get_recommended_template_for_toolkit( 'crm_toolkit' );
		$this->assertSame( 'Sequential Pipeline Workflow', $recommended['name'] );

		// Missing toolkit + toolkit without a primary pattern → null.
		$this->assertNull( $templates->get_recommended_template_for_toolkit( 'missing_toolkit' ) );
		$this->assertNull( $templates->get_recommended_template_for_toolkit( 'bare_toolkit' ) );
	}

	// ─── Per-mode seam ────────────────────────────────────────────────

	public function test_toolkit_registry_seam_resolves_per_install_mode(): void {
		$templates = new TemplatesSeam();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Registry', $templates->probe_toolkit_registry() );
		} else {
			$this->assertNull( $templates->probe_toolkit_registry() );
		}
	}
}
