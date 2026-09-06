<?php
/**
 * /skills slash command.
 *
 * Lists, inspects, and installs skill packs via the Skill Pack Registry.
 * Falls back to scanning includes/bundled-skills/ when the registry is
 * not available.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandSkills
 *
 * @since 2.1.0
 */
class SlashCommandSkills {
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
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute the /skills command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /skills.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json      = isset( $flags['json'] );
		$install_slug = isset( $flags['install'] ) ? sanitize_key( $flags['install'] ) : '';
		$show_slug    = isset( $flags['show'] ) ? sanitize_key( $flags['show'] ) : '';

		// --install requires manage_options.
		if ( $install_slug ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new \WP_Error( 'insufficient_capability', __( 'manage_options capability is required to install skill packs.', 'nvoos-content-graph-ai-platform' ) );
			}
			return $this->install_pack( $install_slug );
		}

		// --show: show pack details.
		if ( $show_slug ) {
			return $this->show_pack( $show_slug, $as_json );
		}

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Prose comment, not commented-out code.
		// Default: list.
		return $this->list_packs( $as_json );
	}

	/**
	 * Install a skill pack.
	 *
	 * @param string $slug Pack slug.
	 * @return array|WP_Error
	 */
	private function install_pack( $slug ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			return new \WP_Error( 'service_unavailable', __( 'Skill Pack Registry is not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		$registry = \WP_MCP_AI_Skill_Pack_Registry::instance();
		$result   = $registry->install_pack( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: skill pack slug */
				__( 'Skill pack `%s` installed successfully.', 'nvoos-content-graph-ai-platform' ),
				esc_html( $slug )
			),
			'data'    => is_array( $result ) ? $result : array( 'slug' => $slug ),
		);
	}

	/**
	 * Show details for a single skill pack.
	 *
	 * @param string $slug    Pack slug.
	 * @param bool   $as_json Return JSON format.
	 * @return array|WP_Error
	 */
	private function show_pack( $slug, $as_json ) {
		$pack = null;

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			$registry = \WP_MCP_AI_Skill_Pack_Registry::instance();
			$pack     = $registry->get_pack( $slug );
		}

		if ( ! $pack ) {
			// Try bundled skills fallback.
			$skill_file = $this->get_bundled_skill_path( $slug );
			if ( $skill_file ) {
				$pack = array(
					'slug'        => $slug,
					'name'        => $slug,
					'description' => __( 'Bundled skill.', 'nvoos-content-graph-ai-platform' ),
					'source'      => 'bundled',
					'skill_file'  => $skill_file,
				);
			}
		}

		if ( ! $pack ) {
			return new \WP_Error(
				'pack_not_found',
				sprintf(
					/* translators: %s: skill pack slug */
					__( 'Skill pack "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $slug )
				)
			);
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $pack,
			);
		}

		$name   = isset( $pack['name'] ) ? esc_html( (string) $pack['name'] ) : esc_html( $slug );
		$desc   = isset( $pack['description'] ) ? esc_html( (string) $pack['description'] ) : '—';
		$status = isset( $pack['status'] ) ? esc_html( (string) $pack['status'] ) : '—';
		$skills = isset( $pack['skills'] ) && is_array( $pack['skills'] ) ? $pack['skills'] : array();

		$out  = "## Skill Pack: `{$slug}`\n\n";
		$out .= "**Name:** {$name}\n";
		$out .= "**Description:** {$desc}\n";
		$out .= "**Status:** {$status}\n";

		if ( ! empty( $skills ) ) {
			$out .= "\n**Included Skills:**\n";
			foreach ( $skills as $skill ) {
				$skill_name = is_array( $skill ) ? ( isset( $skill['name'] ) ? (string) $skill['name'] : '—' ) : (string) $skill;
				$out       .= '- ' . esc_html( $skill_name ) . "\n";
			}
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $pack,
		);
	}

	/**
	 * List all available skill packs (registry + bundled fallback).
	 *
	 * @param bool $as_json Return JSON format.
	 * @return array
	 */
	private function list_packs( $as_json ) {
		$packs = array();

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			$registry = \WP_MCP_AI_Skill_Pack_Registry::instance();
			$packs    = $registry->get_packs();
			if ( ! is_array( $packs ) ) {
				$packs = array();
			}
		}

		// Merge bundled skills as individual entries if not already listed.
		$bundled = $this->get_bundled_skills();
		foreach ( $bundled as $bundled_slug ) {
			$already = false;
			foreach ( $packs as $p ) {
				$p_slug = is_array( $p ) ? ( isset( $p['slug'] ) ? (string) $p['slug'] : '' ) : (string) $p;
				if ( $p_slug === $bundled_slug ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$packs[] = array(
					'slug'   => $bundled_slug,
					'name'   => $bundled_slug,
					'status' => 'bundled',
				);
			}
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $packs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $packs,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_list( $packs ),
			'data'    => $packs,
		);
	}

	/**
	 * Render skill packs as a Markdown list.
	 *
	 * @param array $packs Packs list.
	 * @return string
	 */
	private function render_list( array $packs ) {
		if ( empty( $packs ) ) {
			return __( '_No skill packs found._', 'nvoos-content-graph-ai-platform' );
		}

		$out = sprintf(
			"## Skill Packs (%d)\n\n",
			count( $packs )
		);

		foreach ( $packs as $pack ) {
			$slug   = is_array( $pack ) ? ( isset( $pack['slug'] ) ? (string) $pack['slug'] : '—' ) : (string) $pack;
			$name   = is_array( $pack ) && isset( $pack['name'] ) ? (string) $pack['name'] : $slug;
			$status = is_array( $pack ) && isset( $pack['status'] ) ? (string) $pack['status'] : 'available';
			$desc   = is_array( $pack ) && isset( $pack['description'] ) ? (string) $pack['description'] : '';

			$status_badge = ( 'installed' === $status || 'bundled' === $status ) ? '✅' : '⬜';
			$out         .= sprintf( '- %s **%s** (`%s`)', $status_badge, esc_html( $name ), esc_html( $slug ) );
			if ( $desc ) {
				$out .= ' — ' . esc_html( $desc );
			}
			$out .= "\n";
		}

		$out .= "\n_Use `/skills --show=<slug>` for details or `/skills --install=<slug>` to install._\n";

		return $out;
	}

	/**
	 * Return slugs of bundled skills by scanning the directory.
	 *
	 * @return array
	 */
	private function get_bundled_skills() {
		$slugs    = array();
		$base_dir = defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_PATH . 'includes/bundled-skills/' : '';

		if ( ! $base_dir || ! is_dir( $base_dir ) ) {
			return $slugs;
		}

		$entries = scandir( $base_dir );
		if ( ! is_array( $entries ) ) {
			return $slugs;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$skill_file = $base_dir . $entry . '/SKILL.md';
			if ( is_file( $skill_file ) ) {
				$slugs[] = sanitize_key( $entry );
			}
		}

		return $slugs;
	}

	/**
	 * Get the SKILL.md path for a bundled skill slug, or null.
	 *
	 * @param string $slug Skill slug.
	 * @return string|null
	 */
	private function get_bundled_skill_path( $slug ) {
		$base_dir = defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_PATH . 'includes/bundled-skills/' : '';
		if ( ! $base_dir ) {
			return null;
		}
		$path = $base_dir . sanitize_key( $slug ) . '/SKILL.md';
		return file_exists( $path ) ? $path : null;
	}
}
