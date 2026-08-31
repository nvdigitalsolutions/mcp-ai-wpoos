<?php
/**
 * Agent Skill Pack registry.
 *
 * A *skill pack* is a curated, named collection of related Agent Skills
 * (e.g. "WordPress Developer Pack", "Document Authoring Pack"). Packs let
 * an administrator install or describe several skills as a single unit
 * instead of managing each SKILL.md individually.
 *
 * This class only models the pack metadata and triggers installation of the
 * pack's member skills. Assignment to assistants continues to use the
 * existing per-skill checkbox UI in the Skills meta box.
 *
 * @package WP_MCP_AI
 * @since   1.11.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry for skill packs.
 *
 * @since 1.11.0
 */
class SkillPackRegistry {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Resolved pack list (lazy).
	 *
	 * @var array|null
	 */
	private $packs = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.11.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the cached registry — primarily for tests.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Private constructor.
	 *
	 * @since 1.11.0
	 */
	private function __construct() {}

	/**
	 * Default skill packs shipped with the base plugin.
	 *
	 * Skill slugs match the directories under `includes/bundled-skills/`.
	 * Packs reference only skills that are bundled with the *base* plugin so
	 * that `install_pack()` succeeds out of the box without the Pro add-on.
	 *
	 * @since 1.11.0
	 * @return array<string,array<string,mixed>>
	 */
	private function default_packs() {
		return array(
			'wordpress-developer' => array(
				'slug'        => 'wordpress-developer',
				'name'        => __( 'WordPress Developer Pack', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'A curated set of skills for WordPress plugin and theme developers — Abilities API, Action Scheduler, HTML API, i18n auditing, plugin architecture, secure coding, and the REST API.', 'nvoos-content-graph-ai-platform' ),
				'skills'      => array(
					'wp-abilities-api',
					'wp-action-scheduler',
					'wp-html-api',
					'wp-i18n-audit',
					'wp-plugin-architecture',
					'wp-plugin-assets-loading',
					'wp-rest-api',
					'wp-security-audit',
					'wp-security-deep',
				),
			),
			'document-authoring'  => array(
				'slug'        => 'document-authoring',
				'name'        => __( 'Document Authoring Pack', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Generate and edit polished documents — Word (DOCX), PDF, and PowerPoint (PPTX), plus collaborative document drafting and internal communications.', 'nvoos-content-graph-ai-platform' ),
				'skills'      => array(
					'docx',
					'pdf',
					'pptx',
					'doc-coauthoring',
					'internal-comms',
				),
			),
			'ui-ux-design'        => array(
				'slug'        => 'ui-ux-design',
				'name'        => __( 'UI/UX Design Pack', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Professional design intelligence for building polished UI/UX — AI-powered design system generation with 67 UI styles, 99 UX guidelines, pre-delivery checklists, and industry-specific reasoning rules. Also includes frontend design and canvas design skills.', 'nvoos-content-graph-ai-platform' ),
				'skills'      => array(
					'ui-ux-pro-max',
					'frontend-design',
					'canvas-design',
				),
			),
		);
	}

	/**
	 * Get all registered packs.
	 *
	 * Third parties can register or modify packs by hooking the
	 * `wp_mcp_ai_skill_packs` filter. Each entry must be an associative
	 * array containing at least a `slug` and a `skills` array.
	 *
	 * @since 1.11.0
	 * @return array<string,array<string,mixed>> Packs keyed by slug.
	 */
	public function get_packs() {
		if ( null !== $this->packs ) {
			return $this->packs;
		}

		$packs = $this->default_packs();

		/**
		 * Filter the list of skill packs.
		 *
		 * @since 1.11.0
		 * @param array $packs Associative array of packs keyed by slug.
		 */
		$packs = apply_filters( 'wp_mcp_ai_skill_packs', $packs );

		$this->packs = $this->normalise_packs( $packs );

		return $this->packs;
	}

	/**
	 * Get a single pack by slug.
	 *
	 * @since 1.11.0
	 * @param string $slug Pack slug.
	 * @return array<string,mixed>|null Pack metadata, or null when unknown.
	 */
	public function get_pack( $slug ) {
		$slug  = sanitize_key( (string) $slug );
		$packs = $this->get_packs();

		return isset( $packs[ $slug ] ) ? $packs[ $slug ] : null;
	}

	/**
	 * Install all member skills of a pack from the bundled-skills directories.
	 *
	 * Already-installed skills are skipped (matching the behaviour of
	 * `SkillRegistry::install_bundled_skills_from_dir()`). Skills
	 * that are not present in any bundled-skills directory are reported as
	 * errors.
	 *
	 * @since 1.11.0
	 * @param string $slug Pack slug.
	 * @return array{installed:int,skipped:int,errors:array<int,string>}|WP_Error
	 */
	public function install_pack( $slug ) {
		$pack = $this->get_pack( $slug );
		if ( ! $pack ) {
			return new \WP_Error(
				'wp_mcp_ai_skill_pack_unknown',
				/* translators: %s: pack slug */
				sprintf( __( 'Unknown skill pack: %s', 'nvoos-content-graph-ai-platform' ), $slug )
			);
		}

		$members = isset( $pack['skills'] ) && is_array( $pack['skills'] ) ? $pack['skills'] : array();
		if ( empty( $members ) ) {
			return array(
				'installed' => 0,
				'skipped'   => 0,
				'errors'    => array(),
			);
		}

		$registry = SkillRegistry::instance();

		$installed = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $members as $skill_name ) {
			$skill_name = sanitize_key( (string) $skill_name );
			if ( '' === $skill_name ) {
				continue;
			}

			// Already installed — skip without touching the disk.
			if ( $registry->get_skill( $skill_name ) ) {
				++$skipped;
				continue;
			}

			$result = $registry->install_bundled_skill_by_name( $skill_name );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: skill name, 2: error message */
					__( 'Failed to install %1$s: %2$s', 'nvoos-content-graph-ai-platform' ),
					$skill_name,
					$result->get_error_message()
				);
				continue;
			}

			++$installed;
		}

		/**
		 * Fires after a skill pack install attempt completes.
		 *
		 * @since 1.11.0
		 * @param string $slug      Pack slug.
		 * @param int    $installed Number of skills newly installed.
		 * @param int    $skipped   Number of skills already present.
		 * @param array  $errors    Per-skill error messages.
		 */
		do_action( 'wp_mcp_ai_skill_pack_installed', $pack['slug'], $installed, $skipped, $errors );

		return array(
			'installed' => $installed,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * Normalise raw pack definitions into a predictable shape.
	 *
	 * Drops entries with missing/invalid slugs and ensures `skills` is an
	 * array of sanitised slugs. Unknown keys are preserved so third-party
	 * packs can carry their own metadata.
	 *
	 * @since 1.11.0
	 * @param array $packs Raw packs.
	 * @return array
	 */
	private function normalise_packs( $packs ) {
		if ( ! is_array( $packs ) ) {
			return array();
		}

		$out = array();
		foreach ( $packs as $key => $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}

			$slug = isset( $pack['slug'] ) ? sanitize_key( (string) $pack['slug'] ) : sanitize_key( (string) $key );
			if ( '' === $slug ) {
				continue;
			}

			$skills = array();
			if ( isset( $pack['skills'] ) && is_array( $pack['skills'] ) ) {
				foreach ( $pack['skills'] as $s ) {
					$s = sanitize_key( (string) $s );
					if ( '' !== $s ) {
						$skills[] = $s;
					}
				}
				$skills = array_values( array_unique( $skills ) );
			}

			$pack['slug']        = $slug;
			$pack['skills']      = $skills;
			$pack['name']        = isset( $pack['name'] ) ? (string) $pack['name'] : $slug;
			$pack['description'] = isset( $pack['description'] ) ? (string) $pack['description'] : '';

			$out[ $slug ] = $pack;
		}

		return $out;
	}
}
