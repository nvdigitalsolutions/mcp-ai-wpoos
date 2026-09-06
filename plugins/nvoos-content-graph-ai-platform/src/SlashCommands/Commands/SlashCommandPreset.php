<?php
/**
 * /preset slash command.
 *
 * Lists, inspects, applies, and shows the active orchestration preset
 * via WP_MCP_AI_Orchestration_Preset_Service.
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
 * Class SlashCommandPreset
 *
 * @since 2.1.0
 */
class SlashCommandPreset {
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
	 * Execute the /preset command.
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

		// Capability gate — edit_posts to list/show, manage_options to apply.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /preset.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Orchestration preset service is not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json     = isset( $flags['json'] );
		$apply_id    = isset( $flags['apply'] ) ? sanitize_text_field( $flags['apply'] ) : '';
		$show_id     = isset( $flags['show'] ) ? sanitize_text_field( $flags['show'] ) : '';
		$show_active = isset( $flags['active'] );

		// --apply requires manage_options.
		if ( $apply_id ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new \WP_Error( 'insufficient_capability', __( 'manage_options capability is required to apply a preset.', 'nvoos-content-graph-ai-platform' ) );
			}
			return $this->apply_preset( $apply_id );
		}

		// --active: show current preset.
		if ( $show_active ) {
			return $this->show_active_preset( $as_json );
		}

		// --show: show single preset config.
		if ( $show_id ) {
			return $this->show_preset( $show_id, $as_json );
		}

		// Default: list presets. // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Prose comment, not commented-out code.
		return $this->list_presets( $as_json );
	}

	/**
	 * Apply a preset.
	 *
	 * @param string $preset_id Preset identifier.
	 * @return array|WP_Error
	 */
	private function apply_preset( $preset_id ) {
		$result = \WP_MCP_AI_Orchestration_Preset_Service::apply_preset( $preset_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: preset ID */
				__( 'Orchestration preset `%s` has been applied.', 'nvoos-content-graph-ai-platform' ),
				esc_html( $preset_id )
			),
			'data'    => array( 'preset_id' => $preset_id ),
		);
	}

	/**
	 * Show the active preset.
	 *
	 * @param bool $as_json Return JSON format.
	 * @return array
	 */
	private function show_active_preset( $as_json ) {
		$active = \WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $active, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => is_array( $active ) ? $active : array( 'active' => $active ),
			);
		}

		if ( ! $active ) {
			return array(
				'success' => true,
				'message' => __( '_No active preset. Using defaults._', 'nvoos-content-graph-ai-platform' ),
				'data'    => array(),
			);
		}

		$name = is_array( $active ) ? ( isset( $active['name'] ) ? (string) $active['name'] : '' ) : (string) $active;
		$id   = is_array( $active ) ? ( isset( $active['id'] ) ? (string) $active['id'] : '' ) : '';

		$msg = sprintf(
			"## Active Preset\n\n**Name:** %s\n**ID:** `%s`\n",
			esc_html( $name ),
			esc_html( $id )
		);

		if ( is_array( $active ) ) {
			foreach ( $active as $key => $val ) {
				if ( 'name' === $key || 'id' === $key ) {
					continue;
				}
				if ( is_scalar( $val ) ) {
					$msg .= sprintf( "**%s:** %s\n", esc_html( (string) $key ), esc_html( (string) $val ) );
				}
			}
		}

		return array(
			'success' => true,
			'message' => $msg,
			'data'    => is_array( $active ) ? $active : array( 'active' => $active ),
		);
	}

	/**
	 * Show details for a single preset.
	 *
	 * @param string $preset_id Preset identifier.
	 * @param bool   $as_json   Return JSON format.
	 * @return array|WP_Error
	 */
	private function show_preset( $preset_id, $as_json ) {
		$presets = \WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		if ( ! is_array( $presets ) ) {
			return new \WP_Error( 'presets_unavailable', __( 'Could not load presets.', 'nvoos-content-graph-ai-platform' ) );
		}

		$found = null;
		foreach ( $presets as $preset ) {
			if ( is_array( $preset ) && isset( $preset['id'] ) && (string) $preset['id'] === $preset_id ) {
				$found = $preset;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'preset_not_found',
				sprintf(
					/* translators: %s: preset ID */
					__( 'Preset "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $preset_id )
				)
			);
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $found,
			);
		}

		$name = isset( $found['name'] ) ? esc_html( (string) $found['name'] ) : esc_html( $preset_id );
		$desc = isset( $found['description'] ) ? esc_html( (string) $found['description'] ) : '—';

		$out  = "## Preset: `{$preset_id}`\n\n";
		$out .= "**Name:** {$name}\n\n";
		$out .= "**Description:** {$desc}\n\n";
		$out .= "**Settings:**\n\n";

		foreach ( $found as $key => $val ) {
			if ( 'id' === $key || 'name' === $key || 'description' === $key ) {
				continue;
			}
			if ( is_scalar( $val ) ) {
				$out .= sprintf( "- **%s:** %s\n", esc_html( (string) $key ), esc_html( (string) $val ) );
			}
		}

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $found,
		);
	}

	/**
	 * List all presets as a Markdown table.
	 *
	 * @param bool $as_json Return JSON format.
	 * @return array
	 */
	private function list_presets( $as_json ) {
		$presets = \WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		if ( ! is_array( $presets ) ) {
			$presets = array();
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $presets,
			);
		}

		if ( empty( $presets ) ) {
			return array(
				'success' => true,
				'message' => __( '_No presets found._', 'nvoos-content-graph-ai-platform' ),
				'data'    => array(),
			);
		}

		$active    = \WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();
		$active_id = '';
		if ( is_array( $active ) && isset( $active['id'] ) ) {
			$active_id = (string) $active['id'];
		}

		$out  = sprintf( "## Orchestration Presets (%d)\n\n", count( $presets ) );
		$out .= "| ID | Name | Description | Active |\n";
		$out .= "|----|------|-------------|--------|\n";

		foreach ( $presets as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$pid    = isset( $preset['id'] ) ? esc_html( (string) $preset['id'] ) : '—';
			$pname  = isset( $preset['name'] ) ? esc_html( (string) $preset['name'] ) : '—';
			$pdesc  = isset( $preset['description'] ) ? esc_html( (string) $preset['description'] ) : '—';
			$is_act = ( $pid === $active_id ) ? '✅' : '';
			$out   .= "| `{$pid}` | {$pname} | {$pdesc} | {$is_act} |\n";
		}

		$out .= "\n_Use `/preset --show=<id>` for details or `/preset --apply=<id>` to activate._\n";

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $presets,
		);
	}
}
