<?php
/**
 * /model slash command.
 *
 * Lists available models, shows or sets the model for an assistant,
 * and triggers model discovery refresh.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Slash_Command_Model
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Slash_Command_Model {

	/**
	 * Execute the /model command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'insufficient_capability', __( 'You do not have permission to use /model.', 'mcp-ai-wpoos' ) );
		}

		$as_json      = isset( $flags['json'] );
		$set_model    = isset( $flags['set'] ) ? sanitize_text_field( $flags['set'] ) : '';
		$do_discover  = isset( $flags['discover'] );
		$show_current = isset( $flags['current'] );
		$assistant_id = isset( $flags['assistant-id'] ) ? absint( $flags['assistant-id'] ) : 0;

		// Resolve assistant ID from context if not provided.
		if ( ! $assistant_id ) {
			$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		}

		// --discover requires manage_options.
		if ( $do_discover ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'insufficient_capability', __( 'manage_options capability is required to refresh model discovery.', 'mcp-ai-wpoos' ) );
			}
			return $this->run_discovery();
		}

		// --set requires manage_options and an assistant ID.
		if ( $set_model ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'insufficient_capability', __( 'manage_options capability is required to set a model.', 'mcp-ai-wpoos' ) );
			}
			if ( ! $assistant_id ) {
				return new WP_Error( 'missing_assistant', __( 'An assistant ID is required. Use --assistant-id=<n>.', 'mcp-ai-wpoos' ) );
			}
			return $this->set_model( $assistant_id, $set_model );
		}

		// --current: show current model.
		if ( $show_current ) {
			if ( ! $assistant_id ) {
				return new WP_Error( 'missing_assistant', __( 'An assistant ID is required. Use --assistant-id=<n>.', 'mcp-ai-wpoos' ) );
			}
			return $this->show_current_model( $assistant_id, $as_json );
		}

		// Default: list available models.
		return $this->list_models( $as_json );
	}

	/**
	 * Trigger model discovery refresh.
	 *
	 * @return array|WP_Error
	 */
	private function run_discovery() {
		if ( ! class_exists( 'WP_MCP_AI_Model_Discovery_Service' ) ) {
			return new WP_Error( 'service_unavailable', __( 'Model discovery service is not available.', 'mcp-ai-wpoos' ) );
		}

		$service = new WP_MCP_AI_Model_Discovery_Service();
		$result  = $service->run();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'Model discovery has been triggered. The catalog will refresh shortly.', 'mcp-ai-wpoos' ),
			'data'    => is_array( $result ) ? $result : array(),
		);
	}

	/**
	 * Set the model for an assistant.
	 *
	 * @param int    $assistant_id Target assistant post ID.
	 * @param string $model        Model slug.
	 * @return array|WP_Error
	 */
	private function set_model( $assistant_id, $model ) {
		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new WP_Error(
				'invalid_assistant',
				sprintf(
					/* translators: %d: assistant ID */
					__( 'Assistant #%d not found.', 'mcp-ai-wpoos' ),
					$assistant_id
				)
			);
		}

		update_post_meta( $assistant_id, '_wp_mcp_ai_model', sanitize_text_field( $model ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: model name, 2: assistant ID */
				__( 'Model set to `%1$s` for assistant #%2$d.', 'mcp-ai-wpoos' ),
				esc_html( $model ),
				$assistant_id
			),
			'data'    => array(
				'assistant_id' => $assistant_id,
				'model'        => $model,
			),
		);
	}

	/**
	 * Show the current model for an assistant.
	 *
	 * @param int  $assistant_id Target assistant post ID.
	 * @param bool $as_json      Return JSON format.
	 * @return array
	 */
	private function show_current_model( $assistant_id, $as_json ) {
		$model = get_post_meta( $assistant_id, '_wp_mcp_ai_model', true );
		$model = $model ? (string) $model : __( '(default)', 'mcp-ai-wpoos' );

		$data = array(
			'assistant_id' => $assistant_id,
			'model'        => $model,
		);

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				"## Current Model\n\n**Assistant:** #%d\n**Model:** `%s`\n",
				$assistant_id,
				esc_html( $model )
			),
			'data'    => $data,
		);
	}

	/**
	 * List available models.
	 *
	 * @param bool $as_json Return JSON format.
	 * @return array
	 */
	private function list_models( $as_json ) {
		$models = array();

		// Try WP_MCP_AI_Model_Service first.
		if ( class_exists( 'WP_MCP_AI_Model_Service' ) && method_exists( 'WP_MCP_AI_Model_Service', 'get_available_models' ) ) {
			$svc    = new WP_MCP_AI_Model_Service();
			$result = $svc->get_available_models();
			if ( is_array( $result ) ) {
				$models = $result;
			}
		}

		// Fall back to option catalog.
		if ( empty( $models ) ) {
			$catalog = get_option( 'wp_mcp_ai_model_catalog', array() );
			if ( is_array( $catalog ) ) {
				$models = $catalog;
			}
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $models, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $models,
			);
		}

		if ( empty( $models ) ) {
			return array(
				'success' => true,
				'message' => __( '_No models found. Try `/model --discover` to refresh the catalog._', 'mcp-ai-wpoos' ),
				'data'    => array(),
			);
		}

		$out  = sprintf( "## Available Models (%d)\n\n", count( $models ) );
		$out .= "| Slug | Name | Provider |\n";
		$out .= "|------|------|----------|\n";

		foreach ( $models as $slug => $model ) {
			if ( is_array( $model ) ) {
				$name     = isset( $model['name'] ) ? esc_html( (string) $model['name'] ) : esc_html( (string) $slug );
				$provider = isset( $model['provider'] ) ? esc_html( (string) $model['provider'] ) : '—';
			} else {
				$name     = esc_html( (string) $model );
				$provider = '—';
				$slug     = $name;
			}
			$out .= sprintf( "| `%s` | %s | %s |\n", esc_html( (string) $slug ), $name, $provider );
		}

		$out .= "\n_Use `/model --set=<slug> --assistant-id=<n>` to apply a model._\n";

		return array(
			'success' => true,
			'message' => $out,
			'data'    => $models,
		);
	}
}
