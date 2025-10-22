<?php
/**
 * REST API controller for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the plugin's REST API endpoints.
 */
class WP_MCP_AI_REST {
    const REST_NAMESPACE = 'mcp-ai/v1';

    /**
     * Tool registry instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected $registry;

    /**
     * OpenAI client.
     *
     * @var WP_MCP_AI_OpenAI_Client
     */
    protected $client;

    /**
     * Constructor.
     *
     * @param WP_MCP_AI_Tool_Registry  $registry Tool registry instance.
     * @param WP_MCP_AI_OpenAI_Client $client   OpenAI client.
     */
    public function __construct( WP_MCP_AI_Tool_Registry $registry, WP_MCP_AI_OpenAI_Client $client ) {
        $this->registry = $registry;
        $this->client   = $client;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/chat',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_chat_request' ),
                    'args'                => array(
                        'assistant_id' => array(
                            'type'     => 'integer',
                            'required' => false,
                        ),
                        'messages' => array(
                            'type'     => 'array',
                            'required' => true,
                        ),
                        'options' => array(
                            'type'     => 'object',
                            'required' => false,
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tools',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_tool_request' ),
                    'args'                => array(
                        'assistant_id' => array(
                            'type'     => 'integer',
                            'required' => false,
                        ),
                        'tool' => array(
                            'type'     => 'string',
                            'required' => true,
                        ),
                        'arguments' => array(
                            'type'     => 'object',
                            'required' => false,
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Check permissions for REST requests, validating the nonce and capability.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public function permissions_check( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'rest_invalid_nonce', __( 'Could not verify the request nonce.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You do not have permission to access the MCP AI API.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
        }

        return true;
    }

    /**
     * Handle chat completion requests.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_chat_request( WP_REST_Request $request ) {
        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        if ( ! $assistant_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $messages = $this->sanitize_messages( $request->get_param( 'messages' ) );
        if ( empty( $messages ) ) {
            return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
        $options          = $this->sanitize_options( $request->get_param( 'options' ), $assistant_config );
        $tools            = $this->build_tools_payload( $assistant_config );
        if ( is_wp_error( $tools ) ) {
            return $tools;
        }

        $options['tools'] = $tools;

        $user_id = get_current_user_id();

        /**
         * Fires before a chat request is sent to the language model.
         *
         * @param int              $assistant_id Assistant identifier.
         * @param array            $messages     Chat messages.
         * @param array            $options      Prepared options.
         * @param WP_REST_Request  $request      REST request instance.
         */
        do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request );

        $options = apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request );

        $response = $this->client->create_chat_completion( $messages, $options );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'Chat request failed.', array(
                'assistant_id' => $assistant_id,
                'user_id'      => $user_id,
                'error_code'   => $response->get_error_code(),
                'error'        => $response->get_error_message(),
            ) );
            return $response;
        }

        WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

        /**
         * Fires after a chat response has been received from the language model.
         *
         * @param int              $assistant_id Assistant identifier.
         * @param array            $response     Raw response array.
         * @param WP_REST_Request  $request      REST request instance.
         */
        do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

        return rest_ensure_response( array(
            'assistant_id' => $assistant_id,
            'data'         => $response,
        ) );
    }

    /**
     * Handle requests to execute a specific tool.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_tool_request( WP_REST_Request $request ) {
        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        if ( ! $assistant_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
        $tool_slug        = sanitize_key( $request->get_param( 'tool' ) );
        $arguments        = $request->get_param( 'arguments' );
        $allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

        if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
            return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
        }

        $tool = $this->registry->get_tool( $tool_slug );
        if ( ! $tool ) {
            return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
        }

        $context = array(
            'user_id'      => get_current_user_id(),
            'assistant_id' => $assistant_id,
            'request'      => $request,
        );

        if ( empty( $context['user_id'] ) ) {
            return new WP_Error( 'wp_mcp_ai_anonymous_user', __( 'You must be logged in to execute tools.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
        }

        /**
         * Fires immediately before executing a registered tool.
         *
         * @param string           $tool_slug Tool identifier.
         * @param array            $arguments Arguments passed in the request.
         * @param array            $context   Execution context including user_id and assistant_id.
         */
        $prepared_arguments = is_array( $arguments ) ? $arguments : array();

        do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );

        $result = $tool->execute( $prepared_arguments, $context );

        if ( is_wp_error( $result ) ) {
            WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );
            return $result;
        }

        $result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $prepared_arguments, $context );

        WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );

        /**
         * Fires after a registered tool has completed execution.
         *
         * @param string           $tool_slug Tool identifier.
         * @param array            $arguments Arguments passed in the request.
         * @param array            $context   Execution context including user_id and assistant_id.
         * @param mixed            $result    Tool result after filters have been applied.
         */
        do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $prepared_arguments, $context, $result );

        return rest_ensure_response( array(
            'assistant_id' => $assistant_id,
            'tool'         => $tool_slug,
            'result'       => $result,
        ) );
    }

    /**
     * Retrieve the assistant ID to use for a request.
     *
     * @param mixed $assistant_id Assistant ID from the request.
     * @return int
     */
    protected function resolve_assistant_id( $assistant_id ) {
        $assistant_id = absint( $assistant_id );
        if ( $assistant_id ) {
            return $assistant_id;
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

        return $default;
    }

    /**
     * Sanitize the messages payload.
     *
     * @param mixed $messages Raw messages.
     * @return array
     */
    protected function sanitize_messages( $messages ) {
        if ( ! is_array( $messages ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
            $content = isset( $message['content'] ) ? wp_kses_post( $message['content'] ) : '';

            if ( empty( $role ) || '' === $content ) {
                continue;
            }

            $sanitized[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }

        return $sanitized;
    }

    /**
     * Sanitize request options and merge with assistant defaults.
     *
     * @param mixed $options          Raw options from the request.
     * @param array $assistant_config Assistant configuration array.
     * @return array
     */
    protected function sanitize_options( $options, array $assistant_config ) {
        $options = is_array( $options ) ? $options : array();

        if ( isset( $options['model'] ) ) {
            $options['model'] = sanitize_text_field( $options['model'] );
        }

        if ( empty( $options['model'] ) && ! empty( $assistant_config['model'] ) ) {
            $options['model'] = sanitize_text_field( $assistant_config['model'] );
        }

        if ( ! isset( $options['temperature'] ) && null !== $assistant_config['temperature'] ) {
            $options['temperature'] = $assistant_config['temperature'];
        } elseif ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
            $options['temperature'] = floatval( $options['temperature'] );
        }

        if ( isset( $options['system_prompt'] ) ) {
            $options['system_prompt'] = wp_kses_post( $options['system_prompt'] );
        }

        if ( empty( $options['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] ) ) {
            $options['system_prompt'] = wp_kses_post( $assistant_config['system_prompt'] );
        }

        return $options;
    }

    /**
     * Build the tool payload to send to OpenAI.
     *
     * @param array $assistant_config Assistant configuration array.
     * @return array|WP_Error
     */
    protected function build_tools_payload( array $assistant_config ) {
        $allowed_tool_slugs = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

        if ( empty( $allowed_tool_slugs ) ) {
            return array();
        }

        $tools_payload = array();
        foreach ( $allowed_tool_slugs as $slug ) {
            $tool = $this->registry->get_tool( $slug );
            if ( ! $tool ) {
                WP_MCP_AI_Admin_Settings::log( 'Assistant references missing tool.', array( 'tool' => $slug ) );
                continue;
            }

            $tools_payload[] = array(
                'type'     => 'function',
                'function' => array(
                    'name'        => $tool->get_slug(),
                    'description' => $tool->get_description(),
                    'parameters'  => $tool->get_parameters_schema(),
                ),
            );
        }

        return $tools_payload;
    }
}
