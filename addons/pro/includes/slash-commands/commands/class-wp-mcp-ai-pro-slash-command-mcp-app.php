<?php
/**
 * MCP App Pro Slash Command
 *
 * Manage MCP App connections per assistant.
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
 * MCP App Command Class
 *
 * Flags:
 *   --list                List MCP apps for the current assistant (default)
 *   --assistant-id=<n>    Override assistant ID (falls back to context)
 *   --test=<label>        Test connection for a specific app
 *   --discover=<label>    Discover tools for a specific app
 *   --json                JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Mcp_App {

	/**
	 * Execute mcp-app command.
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

		// Require manage_options.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires manage_options capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure MCP App Registry is available.
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'MCP App Registry service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$assistant_id = absint( $flags['assistant-id'] ?? $context['assistant_id'] ?? 0 );
		$registry     = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps         = $registry->get_apps( $assistant_id );
		$apps         = is_array( $apps ) ? $apps : array();

		// --test=<label>: test connection for a specific app.
		if ( isset( $flags['test'] ) ) {
			$label      = sanitize_text_field( $flags['test'] );
			$app_config = $this->find_app_by_label( $apps, $label );
			if ( ! $app_config ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %s: app label */
						__( 'No MCP app found with label "%s".', 'mcp-ai-wpoos-pro' ),
						esc_html( $label )
					)
				);
			}
			$result = $registry->test_connection( $app_config );
			if ( $as_json ) {
				return array(
					'success' => true,
					'message' => __( 'Connection test completed.', 'mcp-ai-wpoos-pro' ),
					'data'    => $result,
				);
			}
			$ok = ! empty( $result['success'] ) ? '✅ Connected' : '❌ Failed';
			return sprintf(
				/* translators: %1$s: app label, %2$s: result string */
				__( 'MCP App "%1$s": %2$s', 'mcp-ai-wpoos-pro' ),
				esc_html( $label ),
				$ok
			);
		}

		// --discover=<label>: discover tools for a specific app.
		if ( isset( $flags['discover'] ) ) {
			$label      = sanitize_text_field( $flags['discover'] );
			$app_config = $this->find_app_by_label( $apps, $label );
			if ( ! $app_config ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %s: app label */
						__( 'No MCP app found with label "%s".', 'mcp-ai-wpoos-pro' ),
						esc_html( $label )
					)
				);
			}
			$tools = $registry->discover_tools( $app_config );
			if ( $as_json ) {
				return array(
					'success' => true,
					'message' => __( 'Tools discovered.', 'mcp-ai-wpoos-pro' ),
					'data'    => $tools,
				);
			}
			if ( empty( $tools ) ) {
				return sprintf(
					/* translators: %s: app label */
					__( 'No tools discovered for MCP app "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $label )
				);
			}
			$output = sprintf(
				/* translators: %s: app label */
				'## ' . __( 'Tools for MCP App "%s"', 'mcp-ai-wpoos-pro' ) . "\n\n",
				esc_html( $label )
			);
			foreach ( (array) $tools as $tool ) {
				$tool_name = is_array( $tool ) ? ( $tool['name'] ?? $tool['slug'] ?? '–' ) : $tool;
				$output   .= '- ' . esc_html( $tool_name ) . "\n";
			}
			return $output;
		}

		// Default: list apps.
		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'MCP apps retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => array(
					'assistant_id' => $assistant_id,
					'apps'         => $apps,
				),
			);
		}

		if ( empty( $apps ) ) {
			return $assistant_id
				? sprintf(
					/* translators: %d: assistant ID */
					__( 'No MCP apps configured for assistant %d.', 'mcp-ai-wpoos-pro' ),
					$assistant_id
				)
				: __( 'No MCP apps found. Specify --assistant-id=<n>.', 'mcp-ai-wpoos-pro' );
		}

		$output = '## ' . __( 'MCP Apps', 'mcp-ai-wpoos-pro' );
		if ( $assistant_id ) {
			$output .= sprintf(
				/* translators: %d: assistant ID */
				' (' . __( 'Assistant %d', 'mcp-ai-wpoos-pro' ) . ')',
				$assistant_id
			);
		}
		$output .= "\n\n";
		$output .= "| Label | URL | Enabled |\n";
		$output .= "|-------|-----|---------|\n";

		foreach ( $apps as $app ) {
			$label   = isset( $app['label'] ) ? esc_html( $app['label'] ) : '–';
			$url     = isset( $app['url'] ) ? esc_url( $app['url'] ) : '–';
			$enabled = ! empty( $app['enabled'] ) ? '✅' : '❌';
			$output .= "| {$label} | {$url} | {$enabled} |\n";
		}

		return $output;
	}

	/**
	 * Find an app config by label (case-insensitive).
	 *
	 * @param array  $apps  Apps array.
	 * @param string $label App label to search.
	 * @return array|null
	 */
	private function find_app_by_label( $apps, $label ) {
		$label_lower = strtolower( $label );
		foreach ( $apps as $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}
			$app_label = strtolower( isset( $app['label'] ) ? $app['label'] : '' );
			if ( $app_label === $label_lower ) {
				return $app;
			}
		}
		return null;
	}
}
