<?php
/**
 * Skill bridge — delegates to the base plugin's skill infrastructure.
 *
 * The skill registry, parser, and pack registry live in the base
 * plugin (`WP_MCP_AI_Skill_Registry`, etc.). This bridge provides
 * namespace-friendly static accessors for Platform code.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Skills
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

/**
 * Static bridge to the base plugin's skill classes.
 */
final class SkillBridge {

	/**
	 * Get the skill registry singleton.
	 *
	 * @return \WP_MCP_AI_Skill_Registry|null
	 */
	public static function registry(): ?object {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			return null;
		}
		return \WP_MCP_AI_Skill_Registry::instance();
	}

	/**
	 * Get the skill pack registry singleton.
	 *
	 * @return \WP_MCP_AI_Skill_Pack_Registry|null
	 */
	public static function packRegistry(): ?object {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			return null;
		}
		return \WP_MCP_AI_Skill_Pack_Registry::instance();
	}

	/**
	 * Get all installed skills as a name→data map.
	 *
	 * @return array<string, array{name: string, description: string, instructions: string}>
	 */
	public static function getAll(): array {
		$registry = self::registry();
		if ( ! $registry ) {
			return array();
		}
		return $registry->get_all_skills();
	}

	/**
	 * Get a single skill by name.
	 *
	 * @param string $name Skill slug.
	 * @return array{name: string, description: string, instructions: string}|null
	 */
	public static function get( string $name ): ?array {
		$registry = self::registry();
		if ( ! $registry ) {
			return null;
		}
		$skill = $registry->get_skill( $name );
		return is_array( $skill ) ? $skill : null;
	}

	/**
	 * Get a lightweight skill index (name + description only).
	 *
	 * @return array<int, array{name: string, description: string}>
	 */
	public static function getIndex(): array {
		$registry = self::registry();
		if ( ! $registry ) {
			return array();
		}
		return $registry->get_skill_index();
	}

	/**
	 * Build a system prompt from selected skill names.
	 *
	 * @param string[] $skill_names Skill slugs.
	 * @return string
	 */
	public static function buildPrompt( array $skill_names ): string {
		$registry = self::registry();
		if ( ! $registry ) {
			return '';
		}
		return $registry->build_skills_prompt( $skill_names );
	}

	/**
	 * Build a progressive-disclosure skill index for system prompts.
	 *
	 * @param string[] $skill_names Skill slugs.
	 * @return string
	 */
	public static function buildIndexPrompt( array $skill_names ): string {
		$registry = self::registry();
		if ( ! $registry ) {
			return '';
		}
		return $registry->build_skills_index_prompt( $skill_names );
	}

	/**
	 * Install bundled skills from the base plugin.
	 *
	 * @return array{installed: int, skipped: int, errors: string[]}
	 */
	public static function installBundled(): array {
		$registry = self::registry();
		if ( ! $registry ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array( __( 'Skill registry not available.', 'nvoos-content-graph-ai-platform' ) ),
			);
		}
		return $registry->install_bundled_skills();
	}

	/**
	 * Get the path to the bundled skills directory.
	 *
	 * @return string
	 */
	public static function getBundledDir(): string {
		$registry = self::registry();
		if ( ! $registry ) {
			return '';
		}
		return $registry->get_bundled_skills_dir();
	}

	private function __construct() {}
}
