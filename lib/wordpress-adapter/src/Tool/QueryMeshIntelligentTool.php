<?php
/**
 * WordPress-specific intelligent mesh query tool with AI-powered routing.
 *
 * Lives in the WordPress adapter because it directly depends on the
 * WP_MCP_AI_Mesh_Router, settings API, and multisite checks.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Allows AI assistants to query the mesh network with intelligent peer selection.
 *
 * Uses AI-powered routing for optimal peer site selection based on
 * current load, response times, and task complexity.
 */
class QueryMeshIntelligentTool extends AbstractTool {

	public function getSlug(): string {
		return 'query_mesh_intelligent';
	}

	public function getName(): string {
		return 'Query Mesh (Intelligent Routing)';
	}

	public function getDescription(): string {
		return 'Send a prompt to the mesh network with AI-powered peer selection and automatic failover. The system intelligently routes your request to the optimal peer site based on current load, response times, and task complexity.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt' => array(
					'type'        => 'string',
					'description' => 'The message or question to send to the mesh network.',
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] )
			? \absint( $context['user_id'] )
			: \get_current_user_id();

		if ( ! $user_id || ! \user_can( $user_id, 'manage_options' ) ) {
			return $this->errors->accessDenied(
				'You do not have permission to query the mesh network.'
			);
		}

		if ( \is_multisite() && ! \is_user_member_of_blog( $user_id, \get_current_blog_id() ) ) {
			return $this->errors->accessDenied(
				'You do not have access to this site.'
			);
		}

		$settings = \WP_MCP_AI_Admin_Settings::get_settings();

		if ( empty( $settings['enable_mesh'] ) ) {
			return $this->errors->create(
				'wp_mcp_ai_mesh_disabled',
				'Mesh networking is not enabled. Please enable it in Settings → NV oOS → Mesh Network.',
			);
		}

		$prompt = isset( $arguments['prompt'] )
			? \trim( (string) $arguments['prompt'] )
			: '';

		if ( '' === $prompt ) {
			return $this->errors->validationFailed(
				'Please provide a prompt to send to the mesh network.',
				array( 'prompt' => array( 'This field is required.' ) ),
			);
		}

		$assistant_id = isset( $context['assistant_id'] )
			? \absint( $context['assistant_id'] )
			: 0;

		if ( ! $assistant_id ) {
			return $this->errors->validationFailed(
				'Assistant ID is required for intelligent mesh routing.',
				array( 'assistant_id' => array( 'This field is required.' ) ),
			);
		}

		$hub_config       = \WP_MCP_AI_Mesh_Router::get_hub_config( $assistant_id );
		$routing_strategy = $hub_config['routing_strategy'] ?? 'ai_optimized';

		$result = \WP_MCP_AI_Mesh_Router::query_with_retry( $assistant_id, $prompt, $context );

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		$response_content = $result['choices'][0]['message']['content']
			?? $result['content']
			?? '';

		return $this->success(
			'Mesh query completed.',
			array(
				'response' => $response_content,
				'metadata' => array(
					'routing_method' => $routing_strategy,
					'query_success'  => true,
				),
			)
		);
	}
}
