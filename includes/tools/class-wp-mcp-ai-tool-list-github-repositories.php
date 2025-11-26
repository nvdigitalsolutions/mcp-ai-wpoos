<?php
/**
 * Tool that lists GitHub repositories for the authenticated user.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-github-client.php';

/**
 * Provides an assistant tool for listing GitHub repositories.
 */
class WP_MCP_AI_Tool_List_Github_Repositories implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_github_repositories';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List GitHub Repositories', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists GitHub repositories for the authenticated user.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Repository type filter: all, owner, public, private, or member.', 'wp-mcp-ai' ),
					'enum'        => array( 'all', 'owner', 'public', 'private', 'member' ),
					'default'     => 'all',
				),
				'sort'      => array(
					'type'        => 'string',
					'description' => __( 'Sort repositories by: created, updated, pushed, or full_name.', 'wp-mcp-ai' ),
					'enum'        => array( 'created', 'updated', 'pushed', 'full_name' ),
					'default'     => 'updated',
				),
				'direction' => array(
					'type'        => 'string',
					'description' => __( 'Sort direction: asc or desc.', 'wp-mcp-ai' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of repositories to return (1-100).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 30,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'default'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_github_repos_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_github_forbidden', __( 'You do not have permission to access GitHub repositories.', 'wp-mcp-ai' ) );
		}

		$client = new WP_MCP_AI_Github_Client();
		$repos  = $client->list_repositories( $arguments );

		if ( is_wp_error( $repos ) ) {
			return $repos;
		}

		$formatted_repos = array_map(
			function ( $repo ) {
				return array(
					'id'            => $repo['id'],
					'name'          => $repo['name'],
					'full_name'     => $repo['full_name'],
					'owner'         => $repo['owner']['login'],
					'private'       => $repo['private'],
					'description'   => $repo['description'] ?? '',
					'url'           => $repo['html_url'],
					'clone_url'     => $repo['clone_url'],
					'ssh_url'       => $repo['ssh_url'],
					'default_branch' => $repo['default_branch'],
					'updated_at'    => $repo['updated_at'],
					'created_at'    => $repo['created_at'],
					'language'      => $repo['language'] ?? null,
				);
			},
			$repos
		);

		return array(
			'repositories' => $formatted_repos,
			'count'        => count( $formatted_repos ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array();
	}
}
