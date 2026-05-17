<?php
/**
 * A2A Protocol Settings Section.
 *
 * Admin settings for the Agent-to-Agent (A2A) protocol integration,
 * including server/client configuration, exposed assistants, and security.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_A2A' ) ) {
	/**
	 * A2A protocol settings section.
	 */
	class WP_MCP_AI_Section_A2A extends WP_MCP_AI_Settings_Section {

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'a2a';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'A2A Protocol', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'a2a';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure the Agent-to-Agent (A2A) protocol to enable inter-agent communication. A2A allows your assistants to be discovered by and collaborate with external AI agents. Complements MCP (agent-to-tool) with agent-to-agent capabilities.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://a2a-protocol.org/latest/specification/';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// ========================================
				// A2A SERVER SETTINGS
				// ========================================
				'_heading_a2a_server'           => array(
					'type'  => 'heading',
					'label' => __( 'A2A Server', 'mcp-ai-wpoos' ),
				),
				'enable_a2a_server'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable A2A Server', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable A2A protocol endpoints and agent discovery', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, your site will serve an Agent Card at <code>/.well-known/agent.json</code> and accept A2A JSON-RPC requests at <code>/wp-json/mcp-ai/v1/a2a</code>. External agents can discover and communicate with your assistants.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'a2a_exposed_assistants'        => array(
					'type'        => 'text',
					'label'       => __( 'Exposed Assistants', 'mcp-ai-wpoos' ),
					'description' => __( 'Comma-separated list of assistant post IDs to expose via A2A. Leave empty to expose the default assistant only. Each exposed assistant will have its own Agent Card.', 'mcp-ai-wpoos' ),
					'placeholder' => __( 'e.g., 42, 87, 156', 'mcp-ai-wpoos' ),
				),

				// ========================================
				// PUSH NOTIFICATIONS
				// ========================================
				'_heading_a2a_push'             => array(
					'type'  => 'heading',
					'label' => __( 'Push Notifications', 'mcp-ai-wpoos' ),
				),
				'a2a_enable_push_notifications' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Push Notifications', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Allow clients to register webhooks for task updates', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, A2A clients can register webhook URLs to receive push notifications when tasks change state. The agent will deliver task updates via HTTP POST to registered endpoints with retry logic.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ========================================
				// A2A CLIENT SETTINGS
				// ========================================
				'_heading_a2a_client'           => array(
					'type'  => 'heading',
					'label' => __( 'A2A Client (Outbound)', 'mcp-ai-wpoos' ),
				),
				'enable_a2a_client'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable A2A Client', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Allow assistants to delegate tasks to remote A2A agents', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, the "Delegate to A2A Agent" tool becomes available, allowing your assistants to discover and communicate with external A2A-compliant agents. This enables cross-organizational agent collaboration.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'a2a_default_auth_type'         => array(
					'type'        => 'select',
					'label'       => __( 'Default Authentication Type', 'mcp-ai-wpoos' ),
					'description' => __( 'Default authentication method when connecting to remote A2A agents.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'none'   => __( 'None', 'mcp-ai-wpoos' ),
						'bearer' => __( 'Bearer Token', 'mcp-ai-wpoos' ),
						'apiKey' => __( 'API Key', 'mcp-ai-wpoos' ),
					),
					'default'     => 'none',
				),
				'a2a_default_auth_token'        => array(
					'type'        => 'password',
					'label'       => __( 'Default Auth Token / API Key', 'mcp-ai-wpoos' ),
					'description' => __( 'Default authentication credential for outbound A2A requests. Can be overridden per-request by the tool.', 'mcp-ai-wpoos' ),
				),
			);
		}

		/**
		 * Render the settings section content.
		 *
		 * The base wrapper opens `<table class="form-table">` for us, and our
		 * job here is to emit the `<tr>` rows for each field. We also support
		 * `heading`-typed fields by closing/reopening the wrapper table so
		 * group headings render as standalone `<h3>` blocks between field
		 * groups (the same trick the Orchestration section uses for its
		 * `html` separator fields).
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				if ( 'heading' === $type ) {
					$label = isset( $field['label'] ) ? $field['label'] : '';

					// Close the wrapper-opened table, emit the heading, then
					// reopen a fresh form-table so following fields render as
					// proper <tr> rows under their heading.
					echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					echo '<h3 class="wp-mcp-ai-section-subheading">' . esc_html( $label ) . '</h3>';
					echo '<table class="form-table" role="presentation">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					continue;
				}

				$this->render_field( $key, $field );
			}
		}
	}
}
