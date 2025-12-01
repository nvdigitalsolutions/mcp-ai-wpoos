<?php
/**
 * Tool that probes a remote MCP REST namespace.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the WP_MCP_AI_Remote_Tester inside a callable assistant tool.
 */
class WP_MCP_AI_Tool_Probe_Remote_MCP implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'probe_remote_mcp';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Probe Remote MCP REST', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Runs the remote MCP connectivity tester against a live REST namespace to validate authentication and chat access.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'base_url'     => array(
					'type'        => 'string',
					'description' => __( 'Base URL to the remote MCP REST namespace (for example https://example.com/wp-json/mcp-ai/v1).', 'wp-mcp-ai' ),
				),
				'token'        => array(
					'type'        => 'string',
					'description' => __( 'Optional bearer token sent via the Authorization header.', 'wp-mcp-ai' ),
				),
				'guest_token'  => array(
					'type'        => 'string',
					'description' => __( 'Optional guest token forwarded via the X-WP-MCP-AI-Guest header.', 'wp-mcp-ai' ),
				),
				'nonce'        => array(
					'type'        => 'string',
					'description' => __( 'Optional WordPress REST nonce passed through the X-WP-Nonce header.', 'wp-mcp-ai' ),
				),
				'assistant_id' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Assistant ID hint appended to the /assistants probe.', 'wp-mcp-ai' ),
				),
				'timeout'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Request timeout in seconds.', 'wp-mcp-ai' ),
				),
				'verify_ssl'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to verify the remote SSL certificate.', 'wp-mcp-ai' ),
				),
				'user_agent'   => array(
					'type'        => 'string',
					'description' => __( 'Override the default user agent string.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'base_url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'title'       => __( 'Probe a remote MCP deployment', 'wp-mcp-ai' ),
				'description' => __( 'Validate the /assistants and /chat endpoints for a live MCP REST namespace.', 'wp-mcp-ai' ),
				'arguments'   => new stdClass(),
			),
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

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run remote MCP probes.', 'wp-mcp-ai' ) );
		}

		$base_url = isset( $arguments['base_url'] ) ? trim( (string) $arguments['base_url'] ) : '';

		if ( '' === $base_url ) {
			return new WP_Error( 'wp_mcp_ai_remote_missing_url', __( 'Provide the base REST URL for the remote MCP deployment.', 'wp-mcp-ai' ) );
		}

		$probe_args = array();

		if ( isset( $arguments['token'] ) ) {
			$probe_args['token'] = (string) $arguments['token'];
		}

		if ( isset( $arguments['guest_token'] ) ) {
			$probe_args['guest_token'] = (string) $arguments['guest_token'];
		}

		if ( isset( $arguments['nonce'] ) ) {
			$probe_args['nonce'] = (string) $arguments['nonce'];
		}

		if ( isset( $arguments['assistant_id'] ) ) {
			$probe_args['assistant_id'] = absint( $arguments['assistant_id'] );
		}

		if ( isset( $arguments['timeout'] ) ) {
			$probe_args['timeout'] = absint( $arguments['timeout'] );
		}

		if ( array_key_exists( 'verify_ssl', $arguments ) ) {
			$probe_args['verify_ssl'] = (bool) $arguments['verify_ssl'];
		}

		if ( isset( $arguments['user_agent'] ) ) {
			$probe_args['user_agent'] = (string) $arguments['user_agent'];
		}

		$tester = new WP_MCP_AI_Remote_Tester();
		$result = $tester->probe( $base_url, $probe_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['checked_at'] = gmdate( 'c' );
		$result['summary']    = $this->build_summary( $result );

		return $result;
	}

	/**
	 * Build a short textual summary for successful probes.
	 *
	 * @param array $result Probe result payload.
	 * @return string
	 */
	protected function build_summary( array $result ) {
		$status = ! empty( $result['success'] ) ? __( 'success', 'wp-mcp-ai' ) : __( 'failure', 'wp-mcp-ai' );

		if ( empty( $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			/* translators: %s: Overall probe status word. */
			return sprintf( __( 'Probe completed with %s.', 'wp-mcp-ai' ), $status );
		}

		$messages = array();

		foreach ( $result['checks'] as $check ) {
			if ( empty( $check['step'] ) ) {
				continue;
			}

			$step_status = isset( $check['status'] ) ? $check['status'] : $status;
			$messages[]  = sprintf( '%s: %s', $check['step'], $step_status );
		}

		if ( empty( $messages ) ) {
			/* translators: %s: Overall probe status word. */
			return sprintf( __( 'Probe completed with %s.', 'wp-mcp-ai' ), $status );
		}

		$step_summary = implode( '; ', $messages );

		/* translators: 1: Overall probe status word, 2: List of per-step status summaries. */
		return sprintf( __( 'Probe completed with %1$s (%2$s).', 'wp-mcp-ai' ), $status, $step_summary );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
