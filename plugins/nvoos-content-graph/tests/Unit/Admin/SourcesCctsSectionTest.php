<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Admin;

use NvoosContentGraph\Admin\Sections\SourcesCctsSection;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/helpers/jetengine-cct-stubs.php';

/**
 * Unit tests for the Sources — Custom Content Types (JetEngine) section.
 *
 * Covers metadata, the `nvoos_cct_include` sanitization contract
 * (including the hidden-marker semantics), and the render output.
 *
 * @since 1.0.7
 */
class SourcesCctsSectionTest extends WP_UnitTestCase {

	/**
	 * The section under test.
	 *
	 * @var SourcesCctsSection
	 */
	private SourcesCctsSection $section;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->section = new SourcesCctsSection();
	}

	/**
	 * Reset the shared JetEngine stub between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		nvoos_cg_test_reset_jetengine();
		parent::tearDown();
	}

	/**
	 * Install a two-CCT JetEngine stub.
	 *
	 * @return void
	 */
	private function installTwoCcts(): void {
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
				),
				array(
					'slug' => 'ai_chat_agent_memories',
					'name' => 'Agent Memories',
				),
			)
		);
	}

	/**
	 * Section metadata: renders between the CPT and external-table grids
	 * on the Sources tab.
	 *
	 * @return void
	 */
	public function test_section_metadata(): void {
		$this->assertSame( 'sources_ccts', $this->section->get_id() );
		$this->assertSame( 'sources', $this->section->get_tab() );
		$this->assertSame( 15, $this->section->get_priority() );
		$this->assertNotEmpty( $this->section->get_title() );
		$this->assertNotEmpty( $this->section->get_description() );
		$this->assertSame( array(), $this->section->get_fields() );
	}

	/**
	 * Without the grid marker (JetEngine inactive / grid not rendered),
	 * existing exclusions are preserved untouched.
	 *
	 * @return void
	 */
	public function test_sanitize_without_marker_preserves_exclusions(): void {
		$out = $this->section->sanitize( array() );

		$this->assertArrayNotHasKey( 'excluded_cct_slugs', $out );
	}

	/**
	 * An empty marker (user unchecked every CCT) excludes everything.
	 *
	 * @return void
	 */
	public function test_sanitize_with_empty_marker_excludes_everything(): void {
		$this->installTwoCcts();

		$out = $this->section->sanitize( array( 'nvoos_cct_include' => '' ) );

		$this->assertSame(
			array( 'ai_chat_transcripts', 'ai_chat_agent_memories' ),
			$out['excluded_cct_slugs']
		);
	}

	/**
	 * Checked slugs stay indexed; unchecked slugs land in the exclusion list.
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_checked_slugs(): void {
		$this->installTwoCcts();

		$out = $this->section->sanitize(
			array(
				'nvoos_cct_include' => array( 'ai_chat_transcripts' => '1' ),
			)
		);

		$this->assertSame( array( 'ai_chat_agent_memories' ), $out['excluded_cct_slugs'] );
	}

	/**
	 * Checking every CCT leaves the exclusion list empty.
	 *
	 * @return void
	 */
	public function test_sanitize_all_checked_excludes_nothing(): void {
		$this->installTwoCcts();

		$out = $this->section->sanitize(
			array(
				'nvoos_cct_include' => array(
					'ai_chat_transcripts'    => '1',
					'ai_chat_agent_memories' => '1',
				),
			)
		);

		$this->assertSame( array(), $out['excluded_cct_slugs'] );
	}

	/**
	 * Unknown submitted slugs cannot self-include: they are not registered
	 * CCTs, so they stay excluded.
	 *
	 * @return void
	 */
	public function test_sanitize_unknown_slugs_are_not_self_included(): void {
		$this->installTwoCcts();

		$out = $this->section->sanitize(
			array(
				'nvoos_cct_include' => array( 'nonsense' => '1' ),
			)
		);

		$this->assertSame(
			array( 'ai_chat_transcripts', 'ai_chat_agent_memories' ),
			$out['excluded_cct_slugs']
		);
	}

	/**
	 * Registered CCTs render as checked checkbox rows.
	 *
	 * @return void
	 */
	public function test_render_shows_registered_ccts(): void {
		$this->installTwoCcts();

		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Chat Transcripts', $html );
		$this->assertStringContainsString( 'ai_chat_transcripts', $html );
		$this->assertStringContainsString( 'nvoos_cct_include', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
		$this->assertStringContainsString( 'Included by default', $html );
	}

	/**
	 * The grid annotates CCTs whose JetEngine table is missing or empty, so
	 * site owners can tell why a checked CCT is not yet in the graph.
	 *
	 * @return void
	 */
	public function test_render_annotates_unavailable_ccts(): void {
		nvoos_cg_test_install_jetengine_cct(
			array(
				array(
					'slug' => 'ai_chat_transcripts',
					'name' => 'Chat Transcripts',
				),
				array(
					'slug'         => 'ghost_type',
					'name'         => 'Ghost Type',
					'table_exists' => false,
				),
			)
		);

		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'no items yet', $html );
		$this->assertStringContainsString( 'table not created yet', $html );
	}

	/**
	 * Without registered CCTs the grid degrades to a notice.
	 *
	 * @return void
	 */
	public function test_render_shows_notice_when_no_ccts_registered(): void {
		nvoos_cg_test_install_jetengine_cct( array() );

		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No JetEngine Custom Content Types are registered', $html );
	}
}
