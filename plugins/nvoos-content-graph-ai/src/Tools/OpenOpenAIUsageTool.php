<?php
/**
 * Open OpenAI Usage tool (D8 Cluster 2c-5 port of the base plugin's
 * WP_MCP_AI_Tool_Open_OpenAI_Usage — byte-identical slug, schema,
 * error codes, and envelope).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Provides a link so administrators can review OpenAI usage analytics.
 */
class OpenOpenAIUsageTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'open_openai_usage';
	}

	public function getName(): string {
		return __( 'Open OpenAI Usage', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Returns the URL for the OpenAI platform usage dashboard so administrators can review billing and quota details.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // Returns an external URL for user navigation but makes no server-side HTTP calls.
			'requires-capability',  // Requires user capabilities.
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the OpenAI usage link.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		$summary_text = __( 'OpenAI Usage Dashboard', 'nvoos-content-graph-ai' );

		return array(
			'message'     => $summary_text,
			'summary'     => $summary_text,
			'label'       => __( 'OpenAI Usage Dashboard', 'nvoos-content-graph-ai' ),
			'url'         => 'https://platform.openai.com/usage',
			'description' => __( 'Visit the OpenAI platform usage dashboard to review billing, quotas, and consumption analytics.', 'nvoos-content-graph-ai' ),
		);
	}
}
