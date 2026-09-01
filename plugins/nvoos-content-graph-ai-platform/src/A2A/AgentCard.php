<?php
/**
 * A2A Agent Card builder.
 *
 * Builds an A2A-compliant Agent Card for each WordPress assistant,
 * mapping assistant metadata to the A2A protocol specification.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-agent-card.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds A2A-compliant Agent Cards from assistant data.
 */
class AgentCard {

	/**
	 * A2A protocol version supported.
	 *
	 * @var string
	 */
	const PROTOCOL_VERSION = '1.0';

	/**
	 * Default input modes supported.
	 *
	 * @var array
	 */
	const DEFAULT_INPUT_MODES = array( 'text/plain', 'application/json' );

	/**
	 * Default output modes supported.
	 *
	 * @var array
	 */
	const DEFAULT_OUTPUT_MODES = array( 'text/plain', 'application/json' );

	/**
	 * Build an Agent Card for the site's primary assistant.
	 *
	 * When no specific assistant is requested, returns a card
	 * representing the site's default assistant.
	 *
	 * @return array A2A Agent Card document.
	 */
	public static function build_site_card() {
		$settings     = get_option( 'wp_mcp_ai_settings', array() );
		$assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

		// If no default assistant, return a generic site card.
		if ( ! $assistant_id ) {
			return self::build_generic_site_card();
		}

		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return self::build_generic_site_card();
		}

		return self::build_card_for_assistant( $assistant_id );
	}

	/**
	 * Build an Agent Card for a specific assistant.
	 *
	 * @param int $assistant_id The assistant post ID.
	 * @return array|\WP_Error A2A Agent Card document or error.
	 */
	public static function build_card_for_assistant( $assistant_id ) {
		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new \WP_Error(
				'a2a_invalid_assistant',
				__( 'Invalid assistant ID.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		$name        = $post->post_title;
		$description = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( $post->post_content, 50 );

		// Build skills from tools and roles.
		$skills = self::build_skills( $assistant_id );

		// Build security schemes from configured auth methods.
		$security_schemes = self::build_security_schemes();

		// Build the A2A endpoint URL.
		$a2a_url = rest_url( 'mcp-ai/v1/a2a' );

		$card = array(
			'name'                => $name,
			'description'         => ! empty( $description ) ? $description : __( 'An AI assistant.', 'nvoos-content-graph-ai-platform' ),
			'url'                 => $a2a_url,
			'protocolVersion'     => self::PROTOCOL_VERSION,
			'version'             => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			'capabilities'        => self::build_capabilities(),
			'skills'              => $skills,
			'defaultInputModes'   => self::DEFAULT_INPUT_MODES,
			'defaultOutputModes'  => self::DEFAULT_OUTPUT_MODES,
			'securitySchemes'     => $security_schemes,
			'security'            => self::build_security_requirements( $security_schemes ),
			'provider'            => self::build_provider_info(),
			'supportedInterfaces' => self::build_supported_interfaces(),
		);

		/**
		 * Filter the A2A Agent Card for a specific assistant.
		 *
		 * @param array $card         The Agent Card data.
		 * @param int   $assistant_id The assistant post ID.
		 */
		return apply_filters( 'wp_mcp_ai_a2a_agent_card', $card, $assistant_id );
	}

	/**
	 * Build a generic site Agent Card when no specific assistant is configured.
	 *
	 * @return array A2A Agent Card document.
	 */
	protected static function build_generic_site_card() {
		$a2a_url = rest_url( 'mcp-ai/v1/a2a' );

		$security_schemes = self::build_security_schemes();

		$card = array(
			'name'                => get_bloginfo( 'name' ) . ' AI Agent',
			'description'         => sprintf(
				/* translators: %s: site name */
				__( 'AI agent for %s.', 'nvoos-content-graph-ai-platform' ),
				get_bloginfo( 'name' )
			),
			'url'                 => $a2a_url,
			'protocolVersion'     => self::PROTOCOL_VERSION,
			'version'             => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			'capabilities'        => self::build_capabilities(),
			'skills'              => array(),
			'defaultInputModes'   => self::DEFAULT_INPUT_MODES,
			'defaultOutputModes'  => self::DEFAULT_OUTPUT_MODES,
			'securitySchemes'     => $security_schemes,
			'security'            => self::build_security_requirements( $security_schemes ),
			'provider'            => self::build_provider_info(),
			'supportedInterfaces' => self::build_supported_interfaces(),
		);

		/**
		 * Filter the A2A Agent Card for the site.
		 *
		 * @param array $card The Agent Card data.
		 */
		return apply_filters( 'wp_mcp_ai_a2a_agent_card', $card, 0 );
	}

	/**
	 * Build capabilities declaration.
	 *
	 * @return array A2A capabilities object.
	 */
	protected static function build_capabilities() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$capabilities = array(
			'streaming'         => true,
			'pushNotifications' => ! empty( $settings['a2a_enable_push_notifications'] ),
		);

		// Declare extensions support if configured.
		$extensions = self::build_extensions();
		if ( ! empty( $extensions ) ) {
			$capabilities['extensions'] = $extensions;
		}

		return $capabilities;
	}

	/**
	 * Build supported A2A extensions.
	 *
	 * @return array Array of AgentExtension objects.
	 */
	protected static function build_extensions() {
		/**
		 * Filter the A2A extensions declared by this agent.
		 *
		 * @param array $extensions Array of extension declarations.
		 */
		return apply_filters( 'wp_mcp_ai_a2a_register_extensions', array() );
	}

	/**
	 * Build skills from assistant tools and roles.
	 *
	 * @param int $assistant_id The assistant post ID.
	 * @return array Array of A2A skill objects.
	 */
	protected static function build_skills( $assistant_id ) {
		$skills = array();

		// Map assigned tools to skills.
		$tool_slugs = get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true );
		if ( is_array( $tool_slugs ) && ! empty( $tool_slugs ) ) {
			$registry = self::resolve_tool_registry();

			foreach ( $tool_slugs as $slug ) {
				if ( ! $registry ) {
					break;
				}

				$tool = $registry->get_tool( $slug );
				if ( ! $tool || ! is_object( $tool ) ) {
					continue;
				}

				$skills[] = array(
					'id'          => $slug,
					'name'        => method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug,
					'description' => method_exists( $tool, 'get_description' ) ? $tool->get_description() : '',
					'tags'        => array( 'tool' ),
					'inputModes'  => self::DEFAULT_INPUT_MODES,
					'outputModes' => self::DEFAULT_OUTPUT_MODES,
				);
			}
		}

		// Map assistant-level skills metadata.
		$meta_skills = get_post_meta( $assistant_id, '_wp_mcp_ai_skills', true );
		if ( is_array( $meta_skills ) ) {
			foreach ( $meta_skills as $skill_data ) {
				if ( is_string( $skill_data ) ) {
					$skills[] = array(
						'id'          => sanitize_key( $skill_data ),
						'name'        => $skill_data,
						'description' => '',
						'tags'        => array( 'skill' ),
					);
				} elseif ( is_array( $skill_data ) && ! empty( $skill_data['name'] ) ) {
					$skills[] = array(
						'id'          => isset( $skill_data['id'] ) ? sanitize_key( $skill_data['id'] ) : sanitize_key( $skill_data['name'] ),
						'name'        => sanitize_text_field( $skill_data['name'] ),
						'description' => isset( $skill_data['description'] ) ? sanitize_text_field( $skill_data['description'] ) : '',
						'tags'        => isset( $skill_data['tags'] ) ? array_map( 'sanitize_text_field', (array) $skill_data['tags'] ) : array( 'skill' ),
					);
				}
			}
		}

		// Map agent role to a top-level skill.
		$role_type = get_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', true );
		if ( $role_type && function_exists( 'wp_mcp_ai_get_agent_role' ) ) {
			$role = wp_mcp_ai_get_agent_role( $role_type );
			if ( $role ) {
				$skills[] = array(
					'id'          => 'role_' . $role->get_role_type(),
					'name'        => $role->get_role_name(),
					'description' => $role->get_role_description(),
					'tags'        => array( 'role', $role->get_role_type() ),
				);
			}
		}

		return $skills;
	}

	/**
	 * Resolve the tool registry to use for skill mapping.
	 *
	 * Prefers the base plugin's registry when present (monolith mode) and
	 * falls back to the Content Graph core registry (standalone mode).
	 *
	 * @since 2.0.0
	 *
	 * @return object|null Registry instance or null when unavailable.
	 */
	protected static function resolve_tool_registry() {
		// Gate the base registry behind the boot discriminator: the monorepo
		// root autoloader can classmap base classes to disk even when the
		// base plugin is inactive, and those files reference WP_MCP_AI_PATH.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return \WP_MCP_AI_Tool_Registry::get_instance();
		}

		if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
			return nvoos_content_graph_get_tool_registry();
		}

		return null;
	}

	/**
	 * Build security schemes from configured auth methods.
	 *
	 * @return array Map of security scheme ID to SecurityScheme objects.
	 */
	protected static function build_security_schemes() {
		$schemes  = array();
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Bearer token authentication (always available via assistant credentials).
		$schemes['bearer'] = array(
			'type'   => 'http',
			'scheme' => 'bearer',
		);

		// API Key authentication (available if mesh inbound key is configured).
		if ( ! empty( $settings['mesh_inbound_api_key'] ) ) {
			$schemes['apiKey'] = array(
				'type' => 'apiKey',
				'in'   => 'header',
				'name' => 'X-WP-MCP-AI-Mesh-Key',
			);
		}

		// OAuth2 / Auth0 (if configured).
		if ( ! empty( $settings['auth0_domain'] ) ) {
			$auth0_domain      = rtrim( $settings['auth0_domain'], '/' );
			$schemes['oauth2'] = array(
				'type'             => 'openIdConnect',
				'openIdConnectUrl' => 'https://' . $auth0_domain . '/.well-known/openid-configuration',
			);
		}

		return $schemes;
	}

	/**
	 * Build security requirements array.
	 *
	 * @param array $schemes Available security schemes.
	 * @return array Array of security requirement objects.
	 */
	protected static function build_security_requirements( $schemes ) {
		$requirements = array();

		foreach ( array_keys( $schemes ) as $scheme_id ) {
			$requirements[] = array( $scheme_id => array() );
		}

		return $requirements;
	}

	/**
	 * Build provider information.
	 *
	 * @return array AgentProvider object.
	 */
	protected static function build_provider_info() {
		return array(
			'organization' => get_bloginfo( 'name' ),
			'url'          => get_site_url(),
		);
	}

	/**
	 * Build supported interfaces.
	 *
	 * @return array Array of AgentInterface objects.
	 */
	protected static function build_supported_interfaces() {
		return array(
			array(
				'url'             => rest_url( 'mcp-ai/v1/a2a' ),
				'protocolBinding' => 'JSON-RPC',
				'protocolVersion' => self::PROTOCOL_VERSION,
			),
		);
	}

	/**
	 * Get a list of exposed assistant IDs for A2A.
	 *
	 * Returns the assistant IDs that the admin has opted to expose via A2A.
	 * If none are explicitly configured, returns the default assistant.
	 *
	 * @return array Array of assistant post IDs.
	 */
	public static function get_exposed_assistants() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check for explicitly configured exposed assistants.
		if ( ! empty( $settings['a2a_exposed_assistants'] ) && is_array( $settings['a2a_exposed_assistants'] ) ) {
			return array_map( 'absint', $settings['a2a_exposed_assistants'] );
		}

		// Fall back to default assistant.
		$default = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		return $default ? array( $default ) : array();
	}
}
