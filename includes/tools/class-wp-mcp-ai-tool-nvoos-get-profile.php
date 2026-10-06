<?php
/**
 * Profile tool for the ChatGPT plugin bridge.
 *
 * Implements the OpenAI plugin profile-tool contract: a read-only tool
 * that returns the profile represented by the current request's
 * authenticated credentials, with an opaque `id` that is unique within
 * this site and stable across token refresh, reconnection, and display
 * metadata changes. Never returns an email or placeholder as the identity.
 *
 * Advertised in tools/list with `_meta["openai/profile"]: true` and an
 * `outputSchema` (see WP_MCP_AI_OAuth_Resource_Server::get_profile_output_schema())
 * so ChatGPT can label connected accounts and enable multi-account flows.
 *
 * Identity resolution order:
 *   1. The WordPress user bound to the request (OAuth-linked or logged-in).
 *   2. The assistant-scoped credential (cred_xxx.SECRET) — one profile per
 *      assistant connection.
 *   3. A site-scoped profile for any other authenticated connection.
 *
 * @package WP_MCP_AI
 * @since 1.1.94
 * @see    docs/project/proposals/053-chatgpt-plugin-addon-implementation-plan.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the stable profile identity for the connected ChatGPT plugin.
 */
class WP_MCP_AI_Tool_Nvoos_Get_Profile implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nvoos_get_profile';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get NV oOS Profile', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return the profile represented by this request\'s authenticated credentials. The opaque id is unique within this app and remains unchanged across token refresh, reconnection, and display-metadata changes.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Identifying which connected account a ChatGPT or Codex plugin session is acting as, or labelling connections in multi-account flows.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Fetching arbitrary user details; use get_user_info for user records.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_user_info' ),
			'notes'           => __( 'Read-only. The id is opaque — it never encodes names, emails, or roles.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Build a stable, opaque profile id for a given identity seed.
	 *
	 * HMAC over the seed with the site's auth salt: stable for the same
	 * profile, distinct across profiles and sites, and irreversible.
	 *
	 * @param string $seed Identity seed (e.g. "user:42").
	 * @return string Opaque profile id.
	 */
	private function build_profile_id( $seed ) {
		return 'prf_' . substr( hash_hmac( 'sha256', $seed, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (ignored — the profile comes from credentials).
	 * @param array $context   Execution context including user_id / assistant_id.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// 1. WordPress user bound to the request.
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				return $this->profile_envelope(
					$this->build_profile_id( 'user:' . $user->ID ),
					$user->display_name,
					$user->user_email,
					$user->display_name
				);
			}
		}

		// 2. Assistant-scoped credential — one profile per assistant connection.
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		if ( $assistant_id ) {
			$title = get_the_title( $assistant_id );
			$label = $title ? $title : sprintf(
				/* translators: %d: assistant ID */
				__( 'Assistant %d', 'mcp-ai-wpoos' ),
				$assistant_id
			);

			return $this->profile_envelope(
				$this->build_profile_id( 'assistant:' . $assistant_id ),
				$label,
				null,
				$label
			);
		}

		// 3. Site-scoped profile for other authenticated connections.
		$site_name = get_bloginfo( 'name' );
		return $this->profile_envelope(
			$this->build_profile_id( 'site:' . get_current_blog_id() ),
			$site_name,
			null,
			$site_name
		);
	}

	/**
	 * Build the canonical profile envelope (array + JSON text message).
	 *
	 * @param string      $id       Opaque stable profile id.
	 * @param string      $name     Display name.
	 * @param string|null $email    Display email, or null to omit.
	 * @param string      $nickname Useful connection label.
	 * @return array<string,mixed>
	 */
	private function profile_envelope( $id, $name, $email, $nickname ) {
		$profile = array(
			'id'       => $id,
			'name'     => $name,
			'nickname' => $nickname,
		);

		if ( null !== $email && '' !== $email ) {
			$profile['email'] = $email;
		}

		return array_merge(
			$profile,
			array(
				'message' => wp_json_encode( $profile ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'wordpress-core',
			'pattern_compatibility' => array( 'layered_defense' ),
			'profession_tags'       => array( 'systems_administrator' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only', // Only reads the identity bound to the request credentials.
			'local-only', // No external API calls.
		);
	}
}
