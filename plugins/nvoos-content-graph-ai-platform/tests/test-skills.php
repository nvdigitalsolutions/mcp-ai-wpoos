<?php
/**
 * Skills ported-class tests (extraction Wave B).
 *
 * Verifies the port of the skill registry, parser, and pack registry
 * (src/Skills/) preserves the public behaviour of the base plugin's skill
 * classes (mcp-ai-wpoos/includes/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Skills\SkillBridge;
use NvoosContentGraphAiPlatform\Skills\SkillPackRegistry;
use NvoosContentGraphAiPlatform\Skills\SkillParser;
use NvoosContentGraphAiPlatform\Skills\SkillRegistry;
use NvoosContentGraphAiPlatform\Skills\SkillService;

/**
 * @group skills
 */
class Test_Platform_Skills extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		SkillRegistry::reset();
		delete_option( SkillRegistry::OPTION_SKILL_INDEX );
		SkillPackRegistry::reset();
	}

	public function test_option_key_matches_base(): void {
		// Data stability: the skill index option must stay byte-identical.
		$this->assertSame( 'wp_mcp_ai_skill_index', SkillRegistry::OPTION_SKILL_INDEX );
	}

	public function test_parser_parses_skilmd_frontmatter(): void {
		$parser = new SkillParser();

		$content  = "---\n";
		$content .= "name: web-research\n";
		$content .= "description: Research topics on the web\n";
		$content .= "compatibility: core\n";
		$content .= "---\n";
		$content .= "Use the web_search tool and cite sources.\n";

		$skill = $parser->parse( $content );

		$this->assertIsArray( $skill );
		$this->assertSame( 'web-research', $skill['name'] );
		$this->assertSame( 'Research topics on the web', $skill['description'] );
		$this->assertStringContainsString( 'web_search', $skill['instructions'] );
	}

	public function test_parser_rejects_invalid_content(): void {
		$parser = new SkillParser();

		$this->assertWPError( $parser->parse( 'no frontmatter here' ) );
	}

	public function test_registry_installs_and_retrieves_skill(): void {
		$registry = SkillRegistry::instance();

		$content  = "---\nname: test-skill\n";
		$content .= "description: A test skill\n";
		$content .= "---\n";
		$content .= "Do the test thing.\n";

		$result = $registry->install_skill( $content );
		$this->assertNotWPError( $result );

		$skill = $registry->get_skill( 'test-skill' );
		$this->assertIsArray( $skill );
		$this->assertSame( 'A test skill', $skill['description'] );

		// Clean up the uploaded skill.
		$registry->uninstall_skill( 'test-skill' );
	}

	public function test_bundled_skills_dir_resolution(): void {
		$registry = SkillRegistry::instance();
		$dir      = $registry->get_bundled_skills_dir();

		$this->assertNotEmpty( $dir );

		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// Standalone matrix: must resolve to the addon's bundled copy.
			$this->assertStringContainsString( 'data/bundled-skills', $dir );
			$this->assertDirectoryExists( $dir );
		}
	}

	public function test_pack_registry_lists_packs(): void {
		$packs = SkillPackRegistry::instance()->get_packs();

		$this->assertIsArray( $packs );
		$this->assertNotEmpty( $packs );
	}

	public function test_bridge_resolves_ported_registry(): void {
		$registry = SkillBridge::registry();

		$this->assertInstanceOf( SkillRegistry::class, $registry );
		$this->assertInstanceOf( SkillPackRegistry::class, SkillBridge::packRegistry() );
	}

	public function test_skill_service_register_is_safe_in_both_modes(): void {
		// Monolith mode: admin UI only (base owns deferred install).
		// Standalone mode: additionally hooks the deferred install wiring.
		SkillService::instance()->register();

		$this->assertTrue( true );
	}
}
