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
    const MEMORY_MAX_DOCUMENT_CHARS = 4000;
    const MEMORY_CHUNK_CHARS        = 1200;
    const MEMORY_MAX_TOTAL_CHARS    = 12000;

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
        if ( $this->is_application_password_request() ) {
            if ( ! current_user_can( 'edit_posts' ) ) {
                return $this->insufficient_permissions_error();
            }

            return true;
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_credentials',
                __( 'Authentication is required. Provide a REST nonce or a WordPress application password.', 'wp-mcp-ai' ),
                array(
                    'status'  => 401,
                    'actions' => array(
                        'supply_application_password' => __( 'Generate an application password under Users → Profile and send it in the Authorization header.', 'wp-mcp-ai' ),
                        'include_rest_nonce'          => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'rest_invalid_nonce',
                __( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
                array(
                    'status'  => rest_authorization_required_code(),
                    'actions' => array(
                        'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return $this->insufficient_permissions_error();
        }

        return true;
    }

    /**
     * Build a consistent error response when the authenticated user lacks access.
     *
     * @return WP_Error
     */
    protected function insufficient_permissions_error() {
        return new WP_Error(
            'wp_mcp_ai_insufficient_permissions',
            __( 'The authenticated user cannot access the MCP AI API. Grant the account the "edit_posts" capability or switch to another user.', 'wp-mcp-ai' ),
            array(
                'status'  => 403,
                'actions' => array(
                    'grant_capability' => __( 'Assign a role such as Author or Editor that includes the "edit_posts" capability.', 'wp-mcp-ai' ),
                ),
            )
        );
    }

    /**
     * Determine whether the current request was authenticated with an application password.
     *
     * @return bool
     */
    protected function is_application_password_request() {
        if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
            return false;
        }

        $uuid = rest_get_authenticated_app_password();
        if ( empty( $uuid ) ) {
            return false;
        }

        $current_user = wp_get_current_user();

        return $current_user instanceof WP_User && $current_user->exists();
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

        $assistant_post = $this->validate_assistant_access( $assistant_id );
        if ( is_wp_error( $assistant_post ) ) {
            return $assistant_post;
        }

        $sanitized_messages = $this->sanitize_messages( $request->get_param( 'messages' ) );
        if ( is_wp_error( $sanitized_messages ) ) {
            return $sanitized_messages;
        }

        $messages    = $sanitized_messages['messages'];
        $attachments = $sanitized_messages['attachments'];

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

        if ( ! empty( $options['memory_files'] ) ) {
            $memory_documents = $this->prepare_memory_documents( $options['memory_files'] );
            if ( ! empty( $memory_documents ) ) {
                $options['memory_documents'] = $memory_documents;
            }
        }

        if ( ! empty( $attachments ) ) {
            $options['attachments'] = $attachments;
        }

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

        $assistant_post = $this->validate_assistant_access( $assistant_id );
        if ( is_wp_error( $assistant_post ) ) {
            return $assistant_post;
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
     * Ensure the current user can access the requested assistant post.
     *
     * @param int $assistant_id Assistant post ID.
     * @return WP_Post|WP_Error
     */
    protected function validate_assistant_access( $assistant_id ) {
        $assistant_id   = absint( $assistant_id );
        $assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

        if ( ! $assistant_post || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant_post->post_type ) {
            return new WP_Error(
                'wp_mcp_ai_assistant_forbidden',
                __( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
                array( 'status' => 403 )
            );
        }

        if ( 'publish' !== $assistant_post->post_status && ! current_user_can( 'read_post', $assistant_id ) ) {
            return new WP_Error(
                'wp_mcp_ai_assistant_forbidden',
                __( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
                array( 'status' => 403 )
            );
        }

        return $assistant_post;
    }

    /**
     * Sanitize the messages payload.
     *
     * @param mixed $messages Raw messages.
     * @return array|WP_Error
     */
    protected function sanitize_messages( $messages ) {
        if ( ! is_array( $messages ) ) {
            return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $attachments_helper = new WP_MCP_AI_Message_Attachments();
        $sanitized          = array();

        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
            if ( empty( $role ) ) {
                continue;
            }

            $content = isset( $message['content'] ) ? $message['content'] : '';
            $segments = $this->sanitize_message_content( $content, $attachments_helper );

            if ( is_wp_error( $segments ) ) {
                return $segments;
            }

            if ( empty( $segments ) ) {
                continue;
            }

            $sanitized[] = array(
                'role'    => $role,
                'content' => $segments,
            );
        }

        return array(
            'messages'    => $sanitized,
            'attachments' => $attachments_helper->get_attachments(),
        );
    }

    /**
     * Sanitize the content of a single message and normalise into segments.
     *
     * @param mixed                           $content             Raw content provided by the client.
     * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper instance.
     * @return array|WP_Error
     */
    protected function sanitize_message_content( $content, WP_MCP_AI_Message_Attachments $attachments_helper ) {
        if ( is_string( $content ) || is_numeric( $content ) ) {
            $segment = $attachments_helper->prepare_input_text_segment( $content );

            return '' === $segment['text'] ? array() : array( $segment );
        }

        if ( empty( $content ) ) {
            return array();
        }

        if ( ! is_array( $content ) ) {
            return new WP_Error( 'wp_mcp_ai_invalid_message_content', __( 'Message content must be a string or an array of segments.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $segments = array();

        foreach ( $content as $segment ) {
            if ( is_string( $segment ) || is_numeric( $segment ) ) {
                $prepared = $attachments_helper->prepare_input_text_segment( $segment );

                if ( '' !== $prepared['text'] ) {
                    $segments[] = $prepared;
                }

                continue;
            }

            if ( ! is_array( $segment ) ) {
                continue;
            }

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'input_text';

            switch ( $type ) {
                case 'input_text':
                    if ( isset( $segment['text'] ) ) {
                        $prepared = $attachments_helper->prepare_input_text_segment( $segment['text'] );
                    } elseif ( isset( $segment['content'] ) ) {
                        $prepared = $attachments_helper->prepare_input_text_segment( $segment['content'] );
                    } else {
                        $prepared = $attachments_helper->prepare_input_text_segment( '' );
                    }

                    if ( '' !== $prepared['text'] ) {
                        $segments[] = $prepared;
                    }
                    break;

                case 'input_image':
                    $prepared = $attachments_helper->prepare_input_image_segment( $segment );
                    if ( is_wp_error( $prepared ) ) {
                        return $prepared;
                    }
                    $segments[] = $prepared;
                    break;

                case 'input_file':
                    $prepared = $attachments_helper->prepare_input_file_segment( $segment );
                    if ( is_wp_error( $prepared ) ) {
                        return $prepared;
                    }
                    $segments[] = $prepared;
                    break;

                default:
                    return new WP_Error( 'wp_mcp_ai_invalid_message_segment', __( 'One or more message segments use an unsupported type.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
            }
        }

        return $segments;
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

        if ( isset( $options['memory_files'] ) ) {
            $options['memory_files'] = $this->sanitize_memory_files( $options['memory_files'] );
        } elseif ( ! empty( $assistant_config['memory_files'] ) ) {
            $options['memory_files'] = $this->sanitize_memory_files( $assistant_config['memory_files'] );
        } else {
            $options['memory_files'] = array();
        }

        if ( isset( $options['vector_store_id'] ) ) {
            $options['vector_store_id'] = sanitize_text_field( $options['vector_store_id'] );
        } elseif ( isset( $assistant_config['vector_store_id'] ) && '' !== $assistant_config['vector_store_id'] ) {
            $options['vector_store_id'] = sanitize_text_field( $assistant_config['vector_store_id'] );
        } else {
            $options['vector_store_id'] = '';
        }

        if ( isset( $options['response_format'] ) && ! is_array( $options['response_format'] ) ) {
            unset( $options['response_format'] );
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

    /**
     * Sanitize memory file identifiers.
     *
     * @param mixed $files Raw file identifiers.
     * @return array
     */
    protected function sanitize_memory_files( $files ) {
        if ( ! is_array( $files ) ) {
            $files = array( $files );
        }

        $sanitized = array();
        foreach ( $files as $file_id ) {
            $file_id = absint( $file_id );
            if ( $file_id ) {
                $sanitized[] = $file_id;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Prepare memory documents for inclusion with a chat request.
     *
     * @param array $file_ids Attachment identifiers.
     * @return array
     */
    protected function prepare_memory_documents( array $file_ids ) {
        if ( empty( $file_ids ) ) {
            return array();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
            WP_Filesystem();
        }

        $documents   = array();
        $total_chars = 0;

        foreach ( $file_ids as $file_id ) {
            $file_id = absint( $file_id );
            if ( ! $file_id ) {
                continue;
            }

            $attachment = get_post( $file_id );
            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                continue;
            }

            $file_path = get_attached_file( $file_id );
            if ( ! $file_path ) {
                continue;
            }

            $mime_type = get_post_mime_type( $file_id );
            $raw_text  = $this->extract_memory_text( $file_path, $mime_type );

            if ( '' === $raw_text ) {
                continue;
            }

            $normalized = $this->normalize_memory_text( $raw_text, $mime_type );
            if ( '' === $normalized ) {
                continue;
            }

            $chunk_data = $this->chunk_memory_text( $normalized, $total_chars );

            if ( empty( $chunk_data['chunks'] ) ) {
                continue;
            }

            $total_chars = $chunk_data['total_chars'];

            $documents[] = array(
                'id'        => $file_id,
                'title'     => get_the_title( $attachment ),
                'mime_type' => $mime_type,
                'chunks'    => $chunk_data['chunks'],
                'truncated' => $chunk_data['truncated'],
            );

            if ( $total_chars >= self::MEMORY_MAX_TOTAL_CHARS ) {
                break;
            }
        }

        return $documents;
    }

    /**
     * Extract text content from an attachment.
     *
     * @param string $file_path File system path.
     * @param string $mime_type MIME type.
     * @return string
     */
    protected function extract_memory_text( $file_path, $mime_type ) {
        if ( 'application/pdf' === $mime_type ) {
            if ( function_exists( 'wp_read_pdf' ) ) {
                $pdf_content = wp_read_pdf( $file_path );

                if ( is_array( $pdf_content ) && isset( $pdf_content['text'] ) ) {
                    return (string) $pdf_content['text'];
                }

                if ( is_string( $pdf_content ) ) {
                    return $pdf_content;
                }
            }

            return '';
        }

        $textual_mimes = array(
            'text/',
            'application/json',
            'application/javascript',
            'application/xml',
            'application/rss+xml',
            'application/xhtml+xml',
        );

        $is_textual = 0 === strpos( $mime_type, 'text/' ) || in_array( $mime_type, $textual_mimes, true );

        if ( ! $is_textual ) {
            return '';
        }

        return (string) $this->read_file_contents( $file_path );
    }

    /**
     * Read a file from disk using the WordPress filesystem when available.
     *
     * @param string $file_path File path.
     * @return string
     */
    protected function read_file_contents( $file_path ) {
        global $wp_filesystem;

        if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->exists( $file_path ) ) {
            $contents = $wp_filesystem->get_contents( $file_path );
            return is_string( $contents ) ? $contents : '';
        }

        if ( is_readable( $file_path ) ) {
            return (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }

        return '';
    }

    /**
     * Normalise extracted text for downstream processing.
     *
     * @param string $text      Raw text.
     * @param string $mime_type MIME type of the file.
     * @return string
     */
    protected function normalize_memory_text( $text, $mime_type ) {
        $text = (string) $text;

        if ( 'text/html' === $mime_type ) {
            $text = wp_strip_all_tags( $text );
        }

        $text = preg_replace( "/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/", ' ', $text );
        $text = preg_replace( "/\r\n|\r/", "\n", $text );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    /**
     * Chunk and truncate text to the configured limits.
     *
     * @param string $text          Normalized text.
     * @param int    $current_total Characters already accounted for in this request.
     * @return array
     */
    protected function chunk_memory_text( $text, &$current_total ) {
        $available_total = max( 0, self::MEMORY_MAX_TOTAL_CHARS - $current_total );

        if ( $available_total <= 0 ) {
            return array(
                'chunks'      => array(),
                'total_chars' => $current_total,
                'truncated'   => true,
            );
        }

        $length = $this->mb_strlen( $text );
        $limit  = min( $available_total, min( $length, self::MEMORY_MAX_DOCUMENT_CHARS ) );

        $chunks = array();

        for ( $offset = 0; $offset < $limit; $offset += self::MEMORY_CHUNK_CHARS ) {
            $remaining = $limit - $offset;
            $take      = min( self::MEMORY_CHUNK_CHARS, $remaining );
            $chunk     = trim( $this->mb_substr( $text, $offset, $take ) );

            if ( '' !== $chunk ) {
                $chunks[] = $chunk;
            }
        }

        $truncated = $limit < $length;

        if ( $truncated && ! empty( $chunks ) ) {
            $chunks[ count( $chunks ) - 1 ] .= "\n\n[" . __( 'Truncated', 'wp-mcp-ai' ) . ']';
        }

        $current_total += $limit;

        return array(
            'chunks'      => array_values( $chunks ),
            'total_chars' => $current_total,
            'truncated'   => $truncated,
        );
    }

    /**
     * Multibyte-safe string length helper.
     *
     * @param string $string String to measure.
     * @return int
     */
    protected function mb_strlen( $string ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string );
    }

    /**
     * Multibyte-safe substring helper.
     *
     * @param string $string Input string.
     * @param int    $start  Start position.
     * @param int    $length Length of substring.
     * @return string
     */
    protected function mb_substr( $string, $start, $length ) {
        return function_exists( 'mb_substr' ) ? mb_substr( $string, $start, $length ) : substr( $string, $start, $length );
    }
}
