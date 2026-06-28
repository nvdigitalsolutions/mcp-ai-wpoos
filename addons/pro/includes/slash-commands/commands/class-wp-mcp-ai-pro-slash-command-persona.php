<?php
/**
 * Persona Pro Slash Command
 *
 * Profession / Persona switcher for NV oOS Pro.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persona Command Class
 *
 * Args: $args[0] = profession slug (optional — loads that profession if provided)
 *
 * Flags:
 *   --list          List available professions (default when no slug provided)
 *   --show=<slug>   Show profession details
 *   --json          JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Persona {

	/**
	 * Execute persona command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );

		// Require edit_posts.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure Profession Service classes exist.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Service' ) || ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Profession Service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$service = $this->get_profession_service();

		if ( ! $service ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Could not instantiate Profession Service.', 'mcp-ai-wpoos-pro' )
			);
		}

		// --show=<slug>: show profession details.
		if ( isset( $flags['show'] ) ) {
			$slug = sanitize_key( $flags['show'] );
			return $this->show_profession( $service, $slug, $as_json );
		}

		// Slug argument provided: load and activate profession.
		if ( ! empty( $args[0] ) ) {
			$slug = sanitize_key( $args[0] );
			return $this->load_profession( $service, $slug, $context, $as_json );
		}

		// Default: list all professions.
		return $this->list_professions( $service, $as_json );
	}

	/**
	 * Get Profession Service instance.
	 *
	 * Tries DI container first, then direct instantiation.
	 *
	 * @return WP_MCP_AI_Profession_Service|null
	 */
	private function get_profession_service() {
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			try {
				$service = wp_mcp_ai_container()->get( 'profession_service' );
				if ( $service instanceof WP_MCP_AI_Profession_Service ) {
					return $service;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to direct instantiation.
			}
		}

		try {
			return new WP_MCP_AI_Profession_Service( new WP_MCP_AI_Profession_Repository() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * List all available professions.
	 *
	 * @param WP_MCP_AI_Profession_Service $service  Profession service.
	 * @param bool                         $as_json  JSON output.
	 * @return string|array|WP_Error
	 */
	private function list_professions( $service, $as_json ) {
		$professions = $service->get_all_professions();

		if ( is_wp_error( $professions ) ) {
			return $professions;
		}

		$professions = is_array( $professions ) ? $professions : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Professions retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $professions,
			);
		}

		if ( empty( $professions ) ) {
			return __( 'No professions found.', 'mcp-ai-wpoos-pro' );
		}

		$output = '## ' . __( 'Available Professions / Personas', 'mcp-ai-wpoos-pro' ) . "\n\n";
		foreach ( $professions as $profession ) {
			$slug    = is_array( $profession ) ? ( $profession['slug'] ?? '' ) : $profession;
			$label   = is_array( $profession ) ? ( $profession['name'] ?? $profession['label'] ?? $slug ) : $profession;
			$desc    = is_array( $profession ) && isset( $profession['description'] ) ? ' — ' . esc_html( $profession['description'] ) : '';
			$output .= '- **`' . esc_html( $slug ) . '`** ' . esc_html( $label ) . $desc . "\n";
		}

		$output .= "\n_Usage: `/persona <slug>` to activate a profession._\n";

		return $output;
	}

	/**
	 * Show details for a single profession.
	 *
	 * @param WP_MCP_AI_Profession_Service $service  Profession service.
	 * @param string                       $slug     Profession slug.
	 * @param bool                         $as_json  JSON output.
	 * @return string|array|WP_Error
	 */
	private function show_profession( $service, $slug, $as_json ) {
		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', __( 'Profession slug required. Usage: --show=<slug>', 'mcp-ai-wpoos-pro' ) );
		}

		$profession = $service->get_profession( $slug );

		if ( is_wp_error( $profession ) ) {
			return $profession;
		}

		if ( ! $profession ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: profession slug */
					__( 'Profession "%s" not found.', 'mcp-ai-wpoos-pro' ),
					esc_html( $slug )
				)
			);
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Profession retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $profession,
			);
		}

		$name = is_array( $profession ) ? ( $profession['name'] ?? $profession['label'] ?? $slug ) : $slug;
		$desc = is_array( $profession ) && isset( $profession['description'] ) ? esc_html( $profession['description'] ) : '–';

		$output  = '## Profession: ' . esc_html( $name ) . "\n\n";
		$output .= '- **Slug:** ' . esc_html( $slug ) . "\n";
		$output .= "- **Description:** {$desc}\n";

		if ( is_array( $profession ) ) {
			foreach ( $profession as $key => $value ) {
				if ( in_array( $key, array( 'slug', 'name', 'label', 'description' ), true ) ) {
					continue;
				}
				$output .= '- **' . esc_html( ucfirst( $key ) ) . ':** ' . esc_html( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) . "\n";
			}
		}

		return $output;
	}

	/**
	 * Load / activate a profession by slug.
	 *
	 * @param WP_MCP_AI_Profession_Service $service  Profession service.
	 * @param string                       $slug     Profession slug.
	 * @param array                        $context  Execution context.
	 * @param bool                         $as_json  JSON output.
	 * @return string|array|WP_Error
	 */
	private function load_profession( $service, $slug, $context, $as_json ) {
		$profession = $service->get_profession( $slug );

		if ( is_wp_error( $profession ) ) {
			return $profession;
		}

		if ( ! $profession ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: profession slug */
					__( 'Profession "%s" not found. Use --list to see available professions.', 'mcp-ai-wpoos-pro' ),
					esc_html( $slug )
				)
			);
		}

		// Fire signal hook for Pro layer to handle the persona switch.
		do_action( 'wp_mcp_ai_persona_loaded', $profession, $context );

		$name = is_array( $profession ) ? ( $profession['name'] ?? $profession['label'] ?? $slug ) : $slug;

		if ( $as_json ) {
			return array(
				'success' => true,
				'action'  => 'persona_loaded',
				'message' => sprintf(
					/* translators: %s: profession name */
					__( 'Persona "%s" loaded.', 'mcp-ai-wpoos-pro' ),
					$name
				),
				'data'    => $profession,
			);
		}

		return sprintf(
			/* translators: %s: profession name */
			__( '✅ Persona "%s" loaded. The assistant will now adopt this persona.', 'mcp-ai-wpoos-pro' ),
			esc_html( $name )
		);
	}
}
