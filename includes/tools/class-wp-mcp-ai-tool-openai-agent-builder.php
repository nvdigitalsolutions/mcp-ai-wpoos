<?php
/**
 * Tool that provides a launchpad for the OpenAI Agent Builder interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Provides guidance for creating or editing agents in the OpenAI Agent Builder UI.
 */
class WP_MCP_AI_Tool_OpenAI_Agent_Builder implements WP_MCP_AI_Tool_Interface {
    const AGENT_BUILDER_URL = 'https://platform.openai.com/agent-builder';

    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'openai_agent_builder';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'OpenAI Agent Builder', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Returns a direct link and preparation checklist for the OpenAI Agent Builder interface.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'objective'    => array(
                    'type'        => 'string',
                    'description' => __( 'Short summary of what the agent should accomplish.', 'wp-mcp-ai' ),
                ),
                'capabilities' => array(
                    'type'        => 'array',
                    'description' => __( 'Optional list of capabilities or integrations to configure in the builder.', 'wp-mcp-ai' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'notes'        => array(
                    'type'        => 'string',
                    'description' => __( 'Any additional implementation notes to surface alongside the Agent Builder link.', 'wp-mcp-ai' ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

        if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to launch the OpenAI Agent Builder.', 'wp-mcp-ai' ) );
        }

        if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $objective    = isset( $arguments['objective'] ) ? sanitize_text_field( $arguments['objective'] ) : '';
        $notes        = isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
        $capabilities = array();

        if ( isset( $arguments['capabilities'] ) ) {
            if ( ! is_array( $arguments['capabilities'] ) ) {
                return new WP_Error( 'wp_mcp_ai_invalid_capabilities', __( 'Capabilities must be supplied as an array of strings.', 'wp-mcp-ai' ) );
            }

            foreach ( $arguments['capabilities'] as $capability ) {
                if ( is_scalar( $capability ) ) {
                    $sanitized = sanitize_text_field( (string) $capability );

                    if ( '' !== $sanitized ) {
                        $capabilities[] = $sanitized;
                    }
                }
            }
        }

        $instructions = array(
            __( 'Open the OpenAI Agent Builder interface to create or edit an agent.', 'wp-mcp-ai' ),
            __( 'Sign in with an OpenAI account that has access to Agent Builder.', 'wp-mcp-ai' ),
            __( 'Configure the agent with the desired instructions, actions, and data connections.', 'wp-mcp-ai' ),
        );

        if ( '' !== $objective ) {
            $instructions[] = sprintf(
                /* translators: %s: agent objective */
                __( 'Primary objective: %s', 'wp-mcp-ai' ),
                $objective
            );
        }

        if ( ! empty( $capabilities ) ) {
            $instructions[] = sprintf(
                /* translators: %s: comma separated list of capabilities */
                __( 'Recommended capabilities: %s', 'wp-mcp-ai' ),
                implode( ', ', $capabilities )
            );
        }

        if ( '' !== $notes ) {
            $instructions[] = sprintf(
                /* translators: %s: free-form notes */
                __( 'Implementation notes: %s', 'wp-mcp-ai' ),
                $notes
            );
        }

        $payload = array(
            'url'          => esc_url_raw( self::AGENT_BUILDER_URL ),
            'instructions' => $instructions,
            'next_steps'   => array(
                __( 'Review the drafted configuration inside the Agent Builder before publishing.', 'wp-mcp-ai' ),
                __( 'Test the agent in the OpenAI playground and connect it to WordPress once validated.', 'wp-mcp-ai' ),
            ),
        );

        if ( '' !== $objective ) {
            $payload['objective'] = $objective;
        }

        if ( ! empty( $capabilities ) ) {
            $payload['capabilities'] = $capabilities;
        }

        if ( '' !== $notes ) {
            $payload['notes'] = $notes;
        }

        return $payload;
    }
}
