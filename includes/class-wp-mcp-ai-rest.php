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
    const MEMORY_MAX_FILE_BYTES     = 5242880; // 5MB default memory file size limit.
    const MEMORY_MAX_DOCUMENT_BYTES = 262144; // ~256KB, enough headroom for markup around 4K characters of text.
    const MEMORY_MAX_TOTAL_BYTES    = 1048576; // ~1MB aggregate streaming budget across attachments.
    const CHAT_MAX_REQUEST_TOKENS   = 480000;  // Fallback ceiling when no model-specific limit is available.
    const CHAT_APPROX_CHARS_PER_TOKEN = 4;     // Rough heuristic used when trimming oversized chats.

    /**
     * Tool slug used for document + prompt submissions.
     *
     * Requests that include attachments are temporarily granted access to this
     * tool so the OpenAI client can forward the files without requiring admins
     * to manually toggle the capability for every assistant.
     */
    const DOCUMENT_PROMPT_TOOL_SLUG = 'submit_document_prompt';

    /**
     * Tool registry instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected $registry;

    /**
     * Language model router.
     *
     * @var WP_MCP_AI_Language_Model_Router
     */
    protected $client;

    /**
     * Tracks authentication details for the current request.
     *
     * @var array
     */
    protected $auth_context = array();

    /**
     * Constructor.
     *
     * @param WP_MCP_AI_Tool_Registry         $registry Tool registry instance.
     * @param WP_MCP_AI_Language_Model_Router $client   Language model router.
     */
    public function __construct( WP_MCP_AI_Tool_Registry $registry, WP_MCP_AI_Language_Model_Router $client ) {
        $this->registry = $registry;
        $this->client   = $client;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_filter( 'rest_request_after_callbacks', array( $this, 'format_actionable_error' ), 10, 3 );
        add_filter( 'rest_post_dispatch', array( $this, 'augment_error_actions' ), 10, 3 );
    }

    /**
     * Ensure permission errors expose actionable guidance for MCP routes.
     *
     * @param mixed           $response Result from the endpoint callbacks.
     * @param array           $handler  Route handler configuration.
     * @param WP_REST_Request $request  Current REST request.
     * @return mixed
     */
    public function format_actionable_error( $response, $handler, $request ) {
        if ( ! is_wp_error( $response ) ) {
            return $response;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        $route = $request->get_route();
        if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
            return $response;
        }

        $data = $response->get_error_data();
        if ( ! is_array( $data ) || empty( $data['actions'] ) ) {
            return $response;
        }

        $status = isset( $data['status'] ) ? (int) $data['status'] : 500;

        $payload = array(
            'code'    => $response->get_error_code(),
            'message' => $response->get_error_message(),
            'actions' => $data['actions'],
            'data'    => $data,
        );

        return new WP_REST_Response( $payload, $status );
    }

    /**
     * Ensure actionable guidance is surfaced at the top-level of REST error responses.
     *
     * @param WP_REST_Response $response Response object.
     * @param WP_REST_Server   $server   REST server instance.
     * @param WP_REST_Request  $request  Original request object.
     * @return WP_REST_Response
     */
    public function augment_error_actions( $response, $server, $request ) {
        if ( ! $response instanceof WP_REST_Response ) {
            return $response;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        $route = $request->get_route();
        if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) ) {
            return $response;
        }

        if ( isset( $data['actions'] ) ) {
            return $response;
        }

        if ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['actions'] ) ) {
            $data['actions'] = $data['data']['actions'];
            $response->set_data( $data );
        }

        return $response;
    }

    /**
     * Reset the stored authentication context for the current request.
     */
    protected function reset_auth_context() {
        $this->auth_context = array(
            'user_id'             => absint( get_current_user_id() ),
            'token_authenticated' => false,
            'token_type'          => null,
            'token_context'       => array(),
            'assistant_id'        => 0,
        );
    }

    /**
     * Persist information about token-based authentication.
     *
     * @param string $type    Authentication method identifier.
     * @param array  $context Additional context information.
     */
    protected function mark_token_authenticated( $type, $context = array() ) {
        if ( empty( $this->auth_context ) ) {
            $this->reset_auth_context();
        }

        $this->auth_context['token_authenticated'] = true;
        $this->auth_context['token_type']          = sanitize_key( $type );
        $this->auth_context['token_context']       = is_array( $context ) ? $context : array();

        if ( isset( $context['user_id'] ) ) {
            $user_id                              = absint( $context['user_id'] );
            $this->auth_context['user_id']        = $user_id;
            $this->maybe_set_current_user( $user_id );
        }

        $assistant_id = 0;
        if ( isset( $context['assistant_id'] ) ) {
            $assistant_id = absint( $context['assistant_id'] );
        } elseif ( isset( $context['credential']['assistant_id'] ) ) {
            $assistant_id = absint( $context['credential']['assistant_id'] );
        }

        if ( $assistant_id ) {
            $this->auth_context['assistant_id'] = $assistant_id;
        }
    }

    /**
     * Store the resolved WordPress user ID for the request.
     *
     * @param int $user_id WordPress user identifier.
     */
    protected function set_authenticated_user_id( $user_id ) {
        if ( empty( $this->auth_context ) ) {
            $this->reset_auth_context();
        }

        $user_id = absint( $user_id );
        $this->auth_context['user_id'] = $user_id;
        $this->maybe_set_current_user( $user_id );
    }

    /**
     * Sync the global current user with the authenticated context when available.
     *
     * @param int $user_id WordPress user identifier.
     */
    protected function maybe_set_current_user( $user_id ) {
        if ( $user_id > 0 ) {
            wp_set_current_user( $user_id );
        }
    }

    /**
     * Retrieve the authentication context for the current request.
     *
     * @return array
     */
    protected function get_auth_context() {
        if ( empty( $this->auth_context ) ) {
            $this->reset_auth_context();
        }

        return $this->auth_context;
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/assistants',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_assistants_index' ),
                    'args'                => array(
                        'search' => array(
                            'type'     => 'string',
                            'required' => false,
                        ),
                        'include' => array(
                            'type'     => 'array',
                            'required' => false,
                            'items'    => array(
                                'type' => 'integer',
                            ),
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_assistants_index' ),
                ),
            ),
            true
        );

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
                        'attachments' => array(
                            'type'     => 'array',
                            'required' => false,
                        ),
                        'options' => array(
                            'type'     => 'object',
                            'required' => false,
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_chat_request' ),
                ),
            ),
            true
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/chat-transcripts',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
                    'callback'            => array( $this, 'handle_chat_transcripts' ),
                    'args'                => array(
                        'user_id' => array(
                            'type'     => 'integer',
                            'required' => false,
                        ),
                        'session_key' => array(
                            'type'     => 'string',
                            'required' => false,
                        ),
                        'per_page' => array(
                            'type'     => 'integer',
                            'required' => false,
                            'minimum'  => 1,
                            'maximum'  => 100,
                        ),
                        'page' => array(
                            'type'     => 'integer',
                            'required' => false,
                            'minimum'  => 1,
                        ),
                    ),
                ),
            ),
            true
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
            ),
            true
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/files/(?P<file_id>[^/]+)/download',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'download_file_permissions_check' ),
                    'callback'            => array( $this, 'handle_file_download' ),
                    'args'                => array(
                        'assistant_id' => array(
                            'type'     => 'integer',
                            'required' => false,
                        ),
                        'file_id' => array(
                            'type'     => 'string',
                            'required' => true,
                        ),
                        'download_name' => array(
                            'type'     => 'string',
                            'required' => false,
                        ),
                        'disposition' => array(
                            'type'     => 'string',
                            'required' => false,
                            'enum'     => array( 'attachment', 'inline' ),
                        ),
                    ),
                ),
            ),
            true
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/sse',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'callback'            => array( $this, 'handle_sse_handshake' ),
                ),
            ),
            true
        );
    }

    public function chat_transcripts_permissions_check( WP_REST_Request $request ) {
        $user_id      = absint( $request->get_param( 'user_id' ) );
        $current_user = get_current_user_id();

        if ( ! $user_id && $current_user ) {
            $user_id = $current_user;
            $request->set_param( 'user_id', $user_id );
        }

        if ( $user_id && $current_user && $user_id === $current_user ) {
            if ( ! is_user_logged_in() ) {
                return new WP_Error(
                    'wp_mcp_ai_forbidden',
                    __( 'You do not have permission to view chat transcripts.', 'wp-mcp-ai' ),
                    array( 'status' => 403 )
                );
            }

            return true;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        return new WP_Error(
            'wp_mcp_ai_forbidden',
            __( 'You do not have permission to view chat transcripts.', 'wp-mcp-ai' ),
            array( 'status' => 403 )
        );
    }

    /**
     * Handle chat transcript lookup requests.
     *
     * @param WP_REST_Request $request Request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_chat_transcripts( WP_REST_Request $request ) {
        $user_id = absint( $request->get_param( 'user_id' ) );

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            return new WP_Error(
                'wp_mcp_ai_transcripts_missing_user',
                __( 'A valid user is required to query chat transcripts.', 'wp-mcp-ai' ),
                array( 'status' => 400 )
            );
        }

        $session_key = $this->normalise_transcript_session_key( $request->get_param( 'session_key' ) );

        if ( '' !== $session_key ) {
            $session = $this->get_transcript_session( $user_id, $session_key );

            if ( is_wp_error( $session ) ) {
                if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
                    return rest_ensure_response(
                        array(
                            'session' => null,
                            'message' => $session->get_error_message(),
                        )
                    );
                }

                return $session;
            }

            return rest_ensure_response( array( 'session' => $session ) );
        }

        $per_page = (int) $request->get_param( 'per_page' );

        if ( $per_page <= 0 ) {
            $per_page = 20;
        }

        $per_page = min( 100, max( 1, $per_page ) );

        $page = (int) $request->get_param( 'page' );

        if ( $page <= 0 ) {
            $page = 1;
        }

        $sessions = $this->get_transcript_sessions( $user_id, $per_page, $page );

        if ( is_wp_error( $sessions ) ) {
            if ( 'wp_mcp_ai_transcripts_unavailable' === $sessions->get_error_code() ) {
                return rest_ensure_response(
                    array(
                        'sessions' => array(),
                        'total'    => 0,
                        'per_page' => $per_page,
                        'page'     => $page,
                        'message'  => $sessions->get_error_message(),
                    )
                );
            }

            return $sessions;
        }

        return rest_ensure_response(
            array(
                'sessions' => isset( $sessions['items'] ) ? $sessions['items'] : array(),
                'total'    => isset( $sessions['total'] ) ? (int) $sessions['total'] : 0,
                'per_page' => $per_page,
                'page'     => $page,
            )
        );
    }

    /**
     * Provide a directory of assistants the authenticated client can access.
     *
     * Credential-scoped requests are limited to the issuing assistant while
     * traditional authentication modes return every published assistant the
     * caller can read.
     *
     * @param WP_REST_Request $request REST request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_assistants_index( WP_REST_Request $request ) {
        $settings          = WP_MCP_AI_Admin_Settings::get_settings();
        $default_assistant = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
        $auth_context      = $this->get_auth_context();

        $scoped_assistant = $this->apply_token_assistant_scope( 0 );
        if ( is_wp_error( $scoped_assistant ) ) {
            return $scoped_assistant;
        }

        $posts = array();

        if ( $scoped_assistant ) {
            $assistant_post = $this->validate_assistant_access( $scoped_assistant );

            if ( is_wp_error( $assistant_post ) ) {
                return $assistant_post;
            }

            $posts = array( $assistant_post );
        } else {
            $query_args = array(
                'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status'    => array( 'publish' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            );

            $search = $request->get_param( 'search' );
            if ( is_string( $search ) && '' !== $search ) {
                $query_args['s'] = sanitize_text_field( $search );
            }

            $include = $request->get_param( 'include' );
            if ( ! empty( $include ) ) {
                $include_ids = array();

                if ( is_string( $include ) ) {
                    $include = explode( ',', $include );
                }

                foreach ( (array) $include as $candidate ) {
                    $candidate = absint( $candidate );

                    if ( $candidate ) {
                        $include_ids[] = $candidate;
                    }
                }

                if ( ! empty( $include_ids ) ) {
                    $query_args['post__in'] = $include_ids;
                    $query_args['orderby']  = 'post__in';
                }
            }

            /**
             * Allow developers to adjust the assistant directory query.
             *
             * @since 1.0.0
             *
             * @param array           $query_args   WP_Query arguments.
             * @param WP_REST_Request $request      Current REST request.
             * @param array           $auth_context Authentication context for the caller.
             */
            $query_args = apply_filters( 'wp_mcp_ai_rest_assistant_query_args', $query_args, $request, $auth_context );

            $query = new WP_Query( $query_args );
            $posts = $query->posts;

            if ( ! is_array( $posts ) ) {
                $posts = array();
            }

            $filtered = array();
            foreach ( $posts as $post ) {
                if ( ! $post instanceof WP_Post ) {
                    $post = get_post( $post );
                }

                if ( ! $post instanceof WP_Post ) {
                    continue;
                }

                $accessible = $this->validate_assistant_access( $post->ID );

                if ( $accessible instanceof WP_Post ) {
                    $filtered[] = $accessible;
                }
            }

            $posts = $filtered;
        }

        $assistants = array();

        foreach ( $posts as $assistant_post ) {
            if ( ! $assistant_post instanceof WP_Post ) {
                continue;
            }

            $summary      = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
            $assistants[] = $summary;
        }

        $assistants = array_values( $assistants );

        $directory_default = $scoped_assistant ? $scoped_assistant : $default_assistant;
        if ( ! $directory_default && ! empty( $assistants ) ) {
            $first_assistant = reset( $assistants );
            if ( is_array( $first_assistant ) && isset( $first_assistant['id'] ) ) {
                $directory_default = absint( $first_assistant['id'] );
            }
        }

        $response_data = array(
            'assistants'        => $assistants,
            'default_assistant' => $directory_default,
            'rest'              => array(
                'namespace'     => self::REST_NAMESPACE,
                'base'          => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
                'chat'          => esc_url_raw( rest_url( self::REST_NAMESPACE . '/chat' ) ),
                'tools'         => esc_url_raw( rest_url( self::REST_NAMESPACE . '/tools' ) ),
                'file_download' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/files' ) ),
                'sse'           => esc_url_raw( rest_url( self::REST_NAMESPACE . '/sse' ) ),
            ),
        );

        $capabilities = $this->build_assistant_directory_capabilities( $response_data );
        if ( ! empty( $capabilities ) ) {
            $response_data['capabilities'] = $capabilities;
        }

        $response_data['implementation'] = array(
            'name'    => 'WP MCP AI',
            'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
        );

        if ( ! empty( $auth_context['token_authenticated'] ) ) {
            $token_scope = array(
                'type' => $auth_context['token_type'],
            );

            if ( 'local_token' === $auth_context['token_type'] && $scoped_assistant ) {
                $token_scope['assistant_id'] = $scoped_assistant;
            }

            $response_data['token_scope'] = $token_scope;
        }

        /**
         * Filter the assistant directory response payload before it is returned.
         *
         * @since 1.0.0
         *
         * @param array           $response_data Response payload.
         * @param WP_REST_Request $request       Current REST request.
         * @param array           $auth_context  Authentication context for the caller.
         */
        $response_data = apply_filters( 'wp_mcp_ai_rest_assistant_index', $response_data, $request, $auth_context );

        if ( $this->request_wants_event_stream( $request ) ) {
            return $this->stream_event_stream_payload( $response_data, 'directory' );
        }

        return new WP_REST_Response( $response_data, 200 );
    }

    /**
     * Provide an explicit SSE endpoint for MCP clients that expect `/sse` handshakes.
     *
     * @param WP_REST_Request $request REST request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_sse_handshake( WP_REST_Request $request ) {
        $request->set_param( 'stream', true );

        return $this->handle_assistants_index( $request );
    }

    /**
     * Convert an assistant post into a safe directory summary.
     *
     * @param WP_Post         $assistant_post   Assistant post object.
     * @param int             $default_assistant Default assistant identifier.
     * @param array           $settings          Plugin settings array.
     * @param WP_REST_Request $request           Current REST request.
     * @return array
     */
    protected function summarize_assistant_for_directory( WP_Post $assistant_post, $default_assistant, array $settings, WP_REST_Request $request ) {
        $assistant_id = absint( $assistant_post->ID );
        $config       = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

        $provider = isset( $config['provider'] ) ? sanitize_key( $config['provider'] ) : '';
        if ( '' === $provider ) {
            $provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
        }

        $model = isset( $config['model'] ) ? (string) $config['model'] : '';
        if ( '' === $model ) {
            if ( 'gemini' === $provider ) {
                $model = isset( $settings['default_gemini_model'] ) ? (string) $settings['default_gemini_model'] : '';
            } else {
                $model = isset( $settings['default_model'] ) ? (string) $settings['default_model'] : '';
            }
        }

        $temperature = isset( $config['temperature'] ) ? $config['temperature'] : null;
        if ( null !== $temperature ) {
            $temperature = floatval( $temperature );
        }

        $tools = array();
        if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
            foreach ( $config['tools'] as $tool_slug ) {
                $tool_slug = sanitize_key( $tool_slug );
                if ( '' !== $tool_slug ) {
                    $tools[] = $tool_slug;
                }
            }

            $tools = array_values( array_unique( $tools ) );
        }

        $memory_files = 0;
        if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
            $memory_files = count( array_filter( array_map( 'absint', $config['memory_files'] ) ) );
        }

        $summary = array(
            'id'                 => $assistant_id,
            'title'              => get_the_title( $assistant_post ),
            'slug'               => $assistant_post->post_name,
            'status'             => $assistant_post->post_status,
            'is_default'         => ( $assistant_id === absint( $default_assistant ) ),
            'provider'           => $provider,
            'model'              => $model,
            'temperature'        => ( null === $temperature ? null : $temperature ),
            'tools'              => $tools,
            'tool_count'         => count( $tools ),
            'memory_file_count'  => $memory_files,
            'has_vector_store'   => ( isset( $config['vector_store_id'] ) && '' !== $config['vector_store_id'] ),
            'has_external_action'=> ( ! empty( $config['external_action_identifier'] ) ),
            'description'        => $this->get_assistant_directory_description( $assistant_post ),
            'updated_at'         => get_post_modified_time( 'c', true, $assistant_post ),
            'permalink'          => get_permalink( $assistant_post ),
        );

        /**
         * Filter the assistant summary returned by the directory endpoint.
         *
         * @since 1.0.0
         *
         * @param array           $summary        Assistant summary array.
         * @param WP_Post         $assistant_post Assistant post object.
         * @param array           $config         Assistant configuration array.
         * @param array           $settings       Plugin settings array.
         * @param WP_REST_Request $request        Current REST request.
         */
        return apply_filters( 'wp_mcp_ai_rest_assistant_summary', $summary, $assistant_post, $config, $settings, $request );
    }

    /**
     * Build the capability metadata exposed alongside the assistant directory.
     *
     * @param array $response_data Current response payload.
     * @return array
     */
    protected function build_assistant_directory_capabilities( array $response_data ) {
        $capabilities = array();

        $capabilities['tools'] = array(
            'listChanged' => false,
        );

        $rest_links = array();
        if ( isset( $response_data['rest'] ) && is_array( $response_data['rest'] ) ) {
            $rest_links = $response_data['rest'];
        }

        $has_sse_route          = isset( $rest_links['sse'] ) && '' !== $rest_links['sse'];
        $has_file_download_route = isset( $rest_links['file_download'] ) && '' !== $rest_links['file_download'];

        if ( $has_sse_route || $has_file_download_route ) {
            $capabilities['resources'] = array(
                'subscribe'   => $has_sse_route,
                'listChanged' => false,
            );
        }

        /**
         * Filter the capability metadata returned with the assistant directory response.
         *
         * @since 1.0.0
         *
         * @param array $capabilities  Capability metadata.
         * @param array $response_data Current response payload.
         */
        $capabilities = apply_filters( 'wp_mcp_ai_rest_assistant_capabilities', $capabilities, $response_data );

        return is_array( $capabilities ) ? $capabilities : array();
    }

    /**
     * Generate a trimmed description for an assistant directory entry.
     *
     * @param WP_Post $assistant_post Assistant post object.
     * @return string
     */
    protected function get_assistant_directory_description( WP_Post $assistant_post ) {
        $excerpt = get_post_field( 'post_excerpt', $assistant_post->ID );

        if ( '' === $excerpt ) {
            $content = get_post_field( 'post_content', $assistant_post->ID );
            $excerpt = wp_trim_words( wp_strip_all_tags( (string) $content ), 30, '&hellip;' );
        }

        $excerpt = wp_strip_all_tags( (string) $excerpt );

        return $excerpt;
    }

    /**
     * Permission callback for file download requests, ensuring query string nonces are honoured.
     *
     * @param WP_REST_Request $request REST request.
     * @return true|WP_Error
     */
    public function download_file_permissions_check( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );

        if ( empty( $nonce ) ) {
            $nonce_param = $request->get_param( '_wpnonce' );

            if ( is_string( $nonce_param ) && '' !== $nonce_param ) {
                $request->set_header( 'X-WP-Nonce', $nonce_param );
            }
        }

        return $this->permissions_check( $request );
    }

    /**
     * Check permissions for REST requests, validating the nonce and capability.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public function permissions_check( WP_REST_Request $request ) {
        $this->reset_auth_context();

        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        $capability   = wp_mcp_ai_get_required_chat_capability( $assistant_id, 'rest' );

        $guest_token = $this->extract_guest_token( $request );

        if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
            $guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id );

            if ( $guest_assistant ) {
                if ( ! $assistant_id ) {
                    $assistant_id = $guest_assistant;
                    $request->set_param( 'assistant_id', $assistant_id );
                }

                $capability = 'public';
            }
        }

        if ( is_string( $capability ) ) {
            $capability = sanitize_key( $capability );
        }

        $requires_authenticated_user = ! empty( $capability ) && 'public' !== $capability;

        $bearer = $request->get_header( 'Authorization' );
        if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
            $token     = trim( $matches[1] );
            $local     = $this->validate_local_token( $token, $request );

            if ( true === $local ) {
                return true;
            } elseif ( $local instanceof WP_Error ) {
                return $local;
            }

            $validated = $this->validate_bearer_token( $token, $request );

            if ( is_wp_error( $validated ) ) {
                return $validated;
            }

            return true;
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $requires_authenticated_user ) {
            if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                $this->set_authenticated_user_id( get_current_user_id() );
            }

            return true;
        }

        if ( empty( $nonce ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_credentials',
                __( 'Authentication is required. Provide an Auth0 bearer token or a WordPress REST nonce.', 'wp-mcp-ai' ),
                array(
                    'status'  => 401,
                    'actions' => array(
                        'supply_bearer_token' => __( 'Include an Auth0-issued access token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
                        'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
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

        if ( $capability && ! current_user_can( $capability ) ) {
            return $this->insufficient_permissions_error( $capability );
        }

        $this->set_authenticated_user_id( get_current_user_id() );

        return true;
    }

    /**
     * Build a consistent error response when the authenticated user lacks access.
     *
     * @return WP_Error
     */
    protected function insufficient_permissions_error( $capability = 'edit_posts' ) {
        if ( is_string( $capability ) ) {
            $capability = sanitize_key( $capability );
        }

        return new WP_Error(
            'wp_mcp_ai_insufficient_permissions',
            sprintf(
                __( 'The authenticated user cannot access the MCP AI API. Grant the account the "%s" capability or switch to another user.', 'wp-mcp-ai' ),
                $capability
            ),
            array(
                'status'  => 403,
                'actions' => array(
                    'grant_capability' => sprintf(
                        __( 'Assign a role that includes the "%s" capability.', 'wp-mcp-ai' ),
                        $capability
                    ),
                ),
            )
        );
    }

    /**
     * Attempt to validate a plugin-issued credential token.
     *
     * @param string          $token   Raw token string.
     * @param WP_REST_Request $request Current REST request.
     * @return true|WP_Error|null True when valid, WP_Error when rejected, null when the token should be treated as a JWT.
     */
    protected function validate_local_token( $token, WP_REST_Request $request ) {
        if ( ! WP_MCP_AI_Credentials::is_token_format( $token ) ) {
            return null;
        }

        $assistant_hint = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        $validated       = WP_MCP_AI_Credentials::validate_token( $token, $assistant_hint );

        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $this->mark_token_authenticated(
            'local_token',
            array(
                'credential'   => $validated,
                'assistant_id' => isset( $validated['assistant_id'] ) ? absint( $validated['assistant_id'] ) : 0,
            )
        );

        /**
         * Fires when a request authenticates using a stored credential token.
         *
         * @param array            $credential Credential metadata including assistant_id and credential_id.
         * @param WP_REST_Request  $request    Current REST request.
         */
        do_action( 'wp_mcp_ai_authenticated_with_credential', $validated, $request );

        return true;
    }

    /**
     * Validate an Auth0 bearer token.
     *
     * @param string          $token   Raw bearer token string.
     * @param WP_REST_Request $request Current REST request.
     * @return true|WP_Error
     */
    protected function validate_bearer_token( $token, WP_REST_Request $request ) {
        /**
         * Allow short-circuiting bearer token validation.
         *
         * Returning a boolean true grants access, false denies access, and a WP_Error bubbles to the client.
         *
         * @param null|bool|WP_Error $pre     Pre-determined validation result.
         * @param string             $token   Raw bearer token.
         * @param WP_REST_Request    $request Current request object.
         */
        $pre = apply_filters( 'wp_mcp_ai_pre_validate_bearer_token', null, $token, $request );
        if ( null !== $pre ) {
            if ( true === $pre ) {
                /**
                 * Allow mapping a pre-validated bearer token to a WordPress user.
                 *
                 * @param int|null        $user_id Previously mapped user identifier.
                 * @param array|null      $payload Decoded token payload when available, or null for pre-validation shortcuts.
                 * @param WP_REST_Request $request Current REST request.
                 */
                $mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, null, $request );
                if ( $mapped_user instanceof WP_Error ) {
                    return $mapped_user;
                }

                $context = array( 'prevalidated' => true );
                if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
                    $context['user_id'] = absint( $mapped_user );
                    $this->set_authenticated_user_id( $context['user_id'] );
                }

                $this->mark_token_authenticated( 'bearer', $context );

                return true;
            } elseif ( is_array( $pre ) ) {
                $pre_payload = $pre;
                $pre_context = array();

                if ( isset( $pre['payload'] ) && is_array( $pre['payload'] ) ) {
                    $pre_payload = $pre['payload'];
                }

                if ( isset( $pre['context'] ) && is_array( $pre['context'] ) ) {
                    $pre_context = $pre['context'];
                }

                /**
                 * Filter the decoded bearer token payload after it has been validated.
                 *
                 * Returning a WP_Error will reject the request with that error.
                 *
                 * @param array            $payload Decoded JWT payload.
                 * @param WP_REST_Request  $request Current REST request.
                 */
                $filtered_payload = apply_filters( 'wp_mcp_ai_bearer_token_payload', $pre_payload, $request );
                if ( $filtered_payload instanceof WP_Error ) {
                    return $filtered_payload;
                }

                $initial_user = null;
                if ( isset( $pre_context['user_id'] ) && is_numeric( $pre_context['user_id'] ) && (int) $pre_context['user_id'] > 0 ) {
                    $initial_user = absint( $pre_context['user_id'] );
                }

                /**
                 * Allow mapping a validated bearer token payload to a WordPress user for logging/auditing.
                 *
                 * Returning a WP_Error will surface the error to the client.
                 *
                 * @param int|null        $user_id Previously mapped user identifier.
                 * @param array           $payload Decoded JWT payload.
                 * @param WP_REST_Request $request Current REST request instance.
                 */
                $mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', $initial_user, $filtered_payload, $request );
                if ( $mapped_user instanceof WP_Error ) {
                    return $mapped_user;
                }

                $context            = $pre_context;
                $context['payload'] = $filtered_payload;

                if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
                    $context['user_id'] = absint( $mapped_user );
                    $this->set_authenticated_user_id( $context['user_id'] );
                } elseif ( $initial_user ) {
                    $this->set_authenticated_user_id( $initial_user );
                }

                $this->mark_token_authenticated( 'bearer', $context );

                return true;
            }

            return ( $pre instanceof WP_Error ) ? $pre : new WP_Error(
                'wp_mcp_ai_invalid_bearer_token',
                __( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
                array(
                    'status'  => 401,
                    'actions' => array(
                        'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        if ( empty( $token ) ) {
            return new WP_Error(
                'wp_mcp_ai_invalid_bearer_token',
                __( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
                array(
                    'status'  => 401,
                    'actions' => array(
                        'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $segments = explode( '.', $token );
        if ( 3 !== count( $segments ) ) {
            return $this->invalid_bearer_error();
        }

        $header  = json_decode( $this->base64_url_decode( $segments[0] ), true );
        $payload = json_decode( $this->base64_url_decode( $segments[1] ), true );

        if ( ! is_array( $header ) || ! is_array( $payload ) ) {
            return $this->invalid_bearer_error();
        }

        if ( ! function_exists( 'openssl_verify' ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_openssl',
                __( 'PHP OpenSSL support is required to validate Auth0 bearer tokens.', 'wp-mcp-ai' ),
                array(
                    'status'  => 500,
                    'actions' => array(
                        'enable_openssl' => __( 'Enable the PHP OpenSSL extension on the web server.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $domain   = isset( $settings['auth0_domain'] ) ? $settings['auth0_domain'] : '';

        if ( empty( $domain ) ) {
            return new WP_Error(
                'wp_mcp_ai_auth0_not_configured',
                __( 'Auth0 authentication is not configured. Set the Auth0 domain in the WP MCP AI settings screen.', 'wp-mcp-ai' ),
                array(
                    'status'  => 500,
                    'actions' => array(
                        'configure_auth0_domain' => __( 'In WordPress, visit Settings → WP MCP AI and provide the Auth0 domain.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $domain = rtrim( preg_replace( '#^https?://#', '', $domain ), '/' );

        if ( empty( $header['kid'] ) || ( isset( $header['alg'] ) && 'RS256' !== $header['alg'] ) ) {
            return $this->invalid_bearer_error();
        }

        $jwks = $this->get_auth0_jwks( $domain );
        if ( is_wp_error( $jwks ) ) {
            return $jwks;
        }

        $key = null;
        foreach ( $jwks as $jwk ) {
            if ( isset( $jwk['kid'] ) && $header['kid'] === $jwk['kid'] ) {
                $key = $jwk;
                break;
            }
        }

        if ( null === $key ) {
            return $this->invalid_bearer_error();
        }

        $pem = $this->jwk_to_pem( $key );

        if ( is_wp_error( $pem ) ) {
            return $pem;
        }

        $signature  = $this->base64_url_decode( $segments[2] );
        $signed     = $segments[0] . '.' . $segments[1];
        $verified   = openssl_verify( $signed, $signature, $pem, OPENSSL_ALGO_SHA256 );

        if ( 1 !== $verified ) {
            return $this->invalid_bearer_error();
        }

        if ( empty( $payload['exp'] ) || time() >= (int) $payload['exp'] ) {
            return new WP_Error(
                'wp_mcp_ai_expired_bearer_token',
                __( 'The provided bearer token has expired.', 'wp-mcp-ai' ),
                array(
                    'status'  => 401,
                    'actions' => array(
                        'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $expected_issuer = 'https://' . $domain . '/';
        if ( empty( $payload['iss'] ) || $expected_issuer !== $payload['iss'] ) {
            return $this->invalid_bearer_error();
        }

        $audience = isset( $settings['auth0_audience'] ) ? $settings['auth0_audience'] : '';
        if ( ! empty( $audience ) && ! $this->audience_matches( $payload, $audience ) ) {
            return new WP_Error(
                'wp_mcp_ai_invalid_bearer_audience',
                __( 'The bearer token was not issued for this MCP API.', 'wp-mcp-ai' ),
                array(
                    'status'  => 403,
                    'actions' => array(
                        'request_correct_audience' => __( 'Request an Auth0 access token that includes the configured API audience.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $required_scope = isset( $settings['auth0_required_scope'] ) ? $settings['auth0_required_scope'] : '';
        if ( ! empty( $required_scope ) && ! $this->scope_satisfied( $payload, $required_scope ) ) {
            return new WP_Error(
                'wp_mcp_ai_insufficient_bearer_scope',
                __( 'The bearer token is missing the required scope to call this endpoint.', 'wp-mcp-ai' ),
                array(
                    'status'  => 403,
                    'actions' => array(
                        'request_scope' => sprintf(
                            /* translators: %s: required Auth0 scope name */
                            __( 'Request an Auth0 access token that includes the "%s" scope.', 'wp-mcp-ai' ),
                            $required_scope
                        ),
                    ),
                )
            );
        }

        /**
         * Filter the decoded bearer token payload after it has been validated.
         *
         * Returning a WP_Error will reject the request with that error.
         *
         * @param array            $payload Decoded JWT payload.
         * @param WP_REST_Request  $request Current REST request.
         */
        $filtered_payload = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
        if ( $filtered_payload instanceof WP_Error ) {
            return $filtered_payload;
        }

        /**
         * Allow mapping a validated bearer token payload to a WordPress user for logging/auditing.
         *
         * Returning a WP_Error will surface the error to the client.
         *
         * @param int|null        $user_id Previously mapped user identifier.
         * @param array           $payload Decoded JWT payload.
         * @param WP_REST_Request $request Current REST request instance.
         */
        $mapped_user = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $filtered_payload, $request );
        if ( $mapped_user instanceof WP_Error ) {
            return $mapped_user;
        }

        $context = array(
            'payload' => $filtered_payload,
        );

        if ( is_numeric( $mapped_user ) && (int) $mapped_user > 0 ) {
            $mapped_user = absint( $mapped_user );
            $context['user_id'] = $mapped_user;
            $this->set_authenticated_user_id( $mapped_user );
        }

        $this->mark_token_authenticated( 'bearer', $context );

        return true;
    }

    /**
     * Retrieve the JWKS for the configured Auth0 domain.
     *
     * @param string $domain Auth0 domain (without scheme).
     * @return array|WP_Error
     */
    protected function get_auth0_jwks( $domain ) {
        $transient_key = 'wp_mcp_ai_auth0_jwks_' . md5( $domain );
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( 'https://' . $domain . '/.well-known/jwks.json', array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'wp_mcp_ai_auth0_jwks_fetch_failed',
                __( 'Unable to contact Auth0 to validate the bearer token.', 'wp-mcp-ai' ),
                array(
                    'status'  => 502,
                    'actions' => array(
                        'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error(
                'wp_mcp_ai_auth0_jwks_fetch_failed',
                __( 'Auth0 rejected the JWKS request while validating the bearer token.', 'wp-mcp-ai' ),
                array(
                    'status'  => 502,
                    'actions' => array(
                        'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
            return new WP_Error(
                'wp_mcp_ai_auth0_jwks_fetch_failed',
                __( 'Auth0 did not return a valid JWKS response.', 'wp-mcp-ai' ),
                array(
                    'status'  => 502,
                    'actions' => array(
                        'retry_request' => __( 'Retry the request once connectivity with Auth0 is restored.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        set_transient( $transient_key, $body['keys'], HOUR_IN_SECONDS );

        return $body['keys'];
    }

    /**
     * Convert an RSA JWK to a PEM encoded public key.
     *
     * @param array $jwk JWK data.
     * @return string|WP_Error
     */
    protected function jwk_to_pem( $jwk ) {
        if ( empty( $jwk['kty'] ) || 'RSA' !== $jwk['kty'] || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
            return $this->invalid_bearer_error();
        }

        $modulus  = $this->base64_url_decode( $jwk['n'] );
        $exponent = $this->base64_url_decode( $jwk['e'] );

        if ( false === $modulus || false === $exponent ) {
            return $this->invalid_bearer_error();
        }

        $modulus  = ltrim( $modulus, "\x00" );
        $exponent = ltrim( $exponent, "\x00" );

        $components = array(
            $this->encode_asn1_integer( $modulus ),
            $this->encode_asn1_integer( $exponent ),
        );

        $sequence = $this->encode_asn1_sequence( implode( '', $components ) );
        $bitstring = "\x03" . $this->encode_asn1_length( strlen( "\x00" . $sequence ) ) . "\x00" . $sequence;
        $rsa_oid  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $public_key = $this->encode_asn1_sequence( $rsa_oid . $bitstring );

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Determine whether the audience claim matches the configured audience.
     *
     * @param array $payload Token payload.
     * @param string $expected Expected audience string.
     * @return bool
     */
    protected function audience_matches( $payload, $expected ) {
        if ( empty( $payload['aud'] ) ) {
            return false;
        }

        if ( is_string( $payload['aud'] ) ) {
            return $payload['aud'] === $expected;
        }

        if ( is_array( $payload['aud'] ) ) {
            return in_array( $expected, $payload['aud'], true );
        }

        return false;
    }

    /**
     * Determine whether the scope claim satisfies the requirement.
     *
     * @param array  $payload Token payload.
     * @param string $required Scope string required.
     * @return bool
     */
    protected function scope_satisfied( $payload, $required ) {
        if ( empty( $required ) ) {
            return true;
        }

        if ( ! empty( $payload['scope'] ) && is_string( $payload['scope'] ) ) {
            $scopes = preg_split( '/\s+/', $payload['scope'] );
            if ( in_array( $required, $scopes, true ) ) {
                return true;
            }
        }

        if ( ! empty( $payload['permissions'] ) && is_array( $payload['permissions'] ) && in_array( $required, $payload['permissions'], true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Decode a base64url string.
     *
     * @param string $input Encoded string.
     * @return string|false
     */
    protected function base64_url_decode( $input ) {
        $remainder = strlen( $input ) % 4;
        if ( 2 === $remainder ) {
            $input .= '==';
        } elseif ( 3 === $remainder ) {
            $input .= '=';
        } elseif ( 1 === $remainder ) {
            return false;
        }

        $input = strtr( $input, '-_', '+/' );

        return base64_decode( $input );
    }

    /**
     * Encode an ASN.1 integer.
     *
     * @param string $value Integer bytes.
     * @return string
     */
    protected function encode_asn1_integer( $value ) {
        if ( '' === $value ) {
            $value = "\x00";
        }

        if ( ord( $value[0] ) > 0x7f ) {
            $value = "\x00" . $value;
        }

        return "\x02" . $this->encode_asn1_length( strlen( $value ) ) . $value;
    }

    /**
     * Encode an ASN.1 sequence.
     *
     * @param string $value Sequence content.
     * @return string
     */
    protected function encode_asn1_sequence( $value ) {
        return "\x30" . $this->encode_asn1_length( strlen( $value ) ) . $value;
    }

    /**
     * Encode an ASN.1 length field.
     *
     * @param int $length Length value.
     * @return string
     */
    protected function encode_asn1_length( $length ) {
        if ( $length <= 0x7f ) {
            return chr( $length );
        }

        $temp = ltrim( pack( 'N', $length ), "\x00" );

        return chr( 0x80 | strlen( $temp ) ) . $temp;
    }

    /**
     * Return a standard invalid bearer token error.
     *
     * @return WP_Error
     */
    protected function invalid_bearer_error() {
        return new WP_Error(
            'wp_mcp_ai_invalid_bearer_token',
            __( 'The supplied bearer token is invalid.', 'wp-mcp-ai' ),
            array(
                'status'  => 401,
                'actions' => array(
                    'obtain_new_token' => __( 'Request a fresh Auth0 access token and retry the call.', 'wp-mcp-ai' ),
                ),
            )
        );
    }

    /**
     * Populate request parameters when JSON arrives in the body of a GET request.
     *
     * LM Studio 0.3.x removes the `method` attribute from `mcp.json` entries during
     * config edits, causing its MCP transport to fall back to GET. WordPress ignores
     * body payloads on GET requests, so we hydrate the REST request manually to
     * preserve backwards compatibility.
     *
     * @param WP_REST_Request $request REST request.
     * @return void
     */
    protected function hydrate_request_body_params( WP_REST_Request $request ) {
        if ( 'GET' !== $request->get_method() ) {
            return;
        }

        if ( $request->get_param( 'messages' ) ) {
            return;
        }

        $raw_body = $request->get_body();

        if ( '' === $raw_body ) {
            return;
        }

        $decoded = json_decode( $raw_body, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return;
        }

        $copyable_keys = array(
            'assistant_id',
            'messages',
            'attachments',
            'options',
            'session_key',
            'probe',
        );

        foreach ( $copyable_keys as $key ) {
            if ( array_key_exists( $key, $decoded ) ) {
                $request->set_param( $key, $decoded[ $key ] );
            }
        }

        if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
            $options = $request->get_param( 'options' );

            if ( ! is_array( $options ) ) {
                $options = array();
            }

            $request->set_param( 'options', array_merge( $options, $decoded['options'] ) );
        }
    }

    /**
     * Handle chat completion requests, normalising attachments and auto-enabling
     * the document prompt tool whenever uploads are detected.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_chat_request( WP_REST_Request $request ) {
        $this->hydrate_request_body_params( $request );

        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        $scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
        if ( is_wp_error( $scoped_id ) ) {
            return $scoped_id;
        }

        $assistant_id = $scoped_id;

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

        $options = $this->sanitize_options( $request->get_param( 'options' ), $assistant_config );

        $limit_context = $this->build_chat_limit_context( $assistant_id, $options );
        $enforced      = $this->enforce_chat_request_limits( $messages, $attachments, $limit_context );

        if ( is_wp_error( $enforced ) ) {
            return $enforced;
        }

        $messages    = $enforced['messages'];
        $attachments = $enforced['attachments'];

        $transcript_context = array(
            'save_transcript' => $this->should_save_transcript( $request ),
            'session_key'     => $this->sanitize_session_key_param( $request->get_param( 'session_key' ) ),
        );

        if ( ! empty( $attachments ) ) {
            $assistant_config = $this->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
        }

        $tools = $this->build_tools_payload( $assistant_config );
        if ( is_wp_error( $tools ) ) {
            return $tools;
        }

        $options['tools'] = $tools;

        if ( ! empty( $options['memory_files'] ) ) {
            $memory_documents = $this->prepare_memory_documents( $options['memory_files'] );

            if ( is_wp_error( $memory_documents ) ) {
                return $memory_documents;
            }

            if ( ! empty( $memory_documents ) ) {
                $options['memory_documents'] = $memory_documents;
                $options['memory_files']     = wp_list_pluck( $memory_documents, 'id' );
            } else {
                $options['memory_files'] = array();
            }
        }

        if ( ! empty( $attachments ) ) {
            $options['attachments'] = $attachments;
        }

        $probe_mode = ! empty( $options['probe'] );
        if ( $probe_mode ) {
            unset( $options['probe'] );

            $response_data = array(
                'assistant_id' => $assistant_id,
                'probe'        => array(
                    'status'     => 'ok',
                    'checked_at' => gmdate( 'c' ),
                ),
                'message'      => __( 'Chat probe acknowledged.', 'wp-mcp-ai' ),
            );

            return rest_ensure_response( $response_data );
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

        $transcript_context['request_started_at'] = microtime( true );
        $response                                = $this->client->create_chat_completion( $messages, $options );
        $transcript_context['response_completed_at'] = microtime( true );

        if ( ! is_wp_error( $response ) ) {
            $response = $this->maybe_convert_failed_chat_response( $response );
        }

        if ( is_wp_error( $response ) ) {
            $context = array(
                'assistant_id' => $assistant_id,
                'user_id'      => $user_id,
                'error_code'   => $response->get_error_code(),
                'error'        => $response->get_error_message(),
            );

            $error_data = $response->get_error_data();
            if ( is_array( $error_data ) && isset( $error_data['provider_error_code'] ) ) {
                $context['provider_error_code'] = $error_data['provider_error_code'];
            }

            $context = array_merge( $context, $this->extract_chat_error_log_context( $response ) );

            $log_message = $this->build_chat_error_log_message( $response );

            WP_MCP_AI_Logger::log_error( $log_message, $context );
            return $response;
        }

        WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

        if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
            WP_MCP_AI_Chat_Transcript_Recorder::record(
                $assistant_id,
                $messages,
                $options,
                $response,
                $request,
                $user_id,
                $transcript_context
            );
        }

        WP_MCP_AI_Usage_Tracker::record_chat_usage(
            $user_id,
            $assistant_id,
            $options,
            $response
        );

        /**
         * Fires after a chat response has been received from the language model.
         *
         * @param int              $assistant_id Assistant identifier.
         * @param array            $response     Raw response array.
         * @param WP_REST_Request  $request      REST request instance.
         */
        do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

        $payload = array(
            'assistant_id' => $assistant_id,
            'data'         => $response,
        );

        if ( $this->request_wants_event_stream( $request ) ) {
            return $this->stream_event_stream_payload( $payload, 'message' );
        }

        return rest_ensure_response( $payload );
    }

    /**
     * Determine whether the current request prefers an event stream response.
     *
     * @param WP_REST_Request $request REST request instance.
     * @return bool
     */
    protected function request_wants_event_stream( WP_REST_Request $request ) {
        $stream_param    = $request->get_param( 'stream' );
        $explicit_stream = null;

        if ( null !== $stream_param ) {
            if ( is_array( $stream_param ) || is_object( $stream_param ) ) {
                $stream_data = (array) $stream_param;

                if ( array_key_exists( 'enabled', $stream_data ) ) {
                    $normalized_enabled = rest_sanitize_boolean( $stream_data['enabled'] );

                    if ( null !== $normalized_enabled ) {
                        $explicit_stream = $normalized_enabled;
                    }
                }

                if ( null === $explicit_stream && ! empty( $stream_data ) ) {
                    $explicit_stream = true;
                }
            } else {
                $normalized_stream = rest_sanitize_boolean( $stream_param );

                if ( null !== $normalized_stream ) {
                    $explicit_stream = $normalized_stream;
                }
            }

        }

        if ( true === $explicit_stream ) {
            return true;
        }

        if ( false === $explicit_stream ) {
            return false;
        }

        $accept_header = $request->get_header( 'accept' );

        if ( is_string( $accept_header ) && '' !== $accept_header ) {
            $normalized_accept = strtolower( $accept_header );

            if ( preg_match( '#(^|,|\s)text/event-stream(?:(?=\s*[;,])|$)#i', $normalized_accept ) ) {
                return true;
            }
        }

        if ( false === $explicit_stream ) {
            return false;
        }

        return false;
    }

    /**
     * Stream the provided payload as an event stream response.
     *
     * @param array  $payload Response payload to emit.
     * @param string $event   Event name used for the SSE frame.
     * @return WP_REST_Response
     */
    protected function stream_event_stream_payload( array $payload, $event = 'message' ) {
        $encoded_payload = wp_json_encode( $payload );

        if ( false === $encoded_payload ) {
            return rest_ensure_response( $payload );
        }

        $event_name = (string) $event;
        if ( '' === $event_name ) {
            $event_name = 'message';
        }

        $frames = $this->build_event_stream_chunk( $event_name, $encoded_payload );
        $frames .= $this->build_event_stream_chunk( '', '[DONE]' );

        $headers = array(
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache, no-store, must-revalidate, no-transform',
            'Pragma'                      => 'no-cache',
            'Connection'                  => 'keep-alive',
            'Vary'                        => 'Accept, Authorization',
            'Access-Control-Allow-Origin' => '*',
            'X-Accel-Buffering'           => 'no',
            'X-Content-Type-Options'      => 'nosniff',
        );

        if ( isset( $_SERVER['SERVER_PROTOCOL'] ) && 0 === strpos( $_SERVER['SERVER_PROTOCOL'], 'HTTP/2' ) ) {
            unset( $headers['Connection'] );
        }

        $callback = null;
        $callback = static function ( $served, $response, $request, $server ) use ( $headers, $frames, &$callback ) {
            if ( $served ) {
                return $served;
            }

            foreach ( $headers as $name => $value ) {
                if ( '' === $name || null === $value ) {
                    continue;
                }

                $server->send_header( $name, $value );
            }

            echo $frames; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            if ( function_exists( 'ob_get_level' ) && function_exists( 'ob_end_flush' ) ) {
                while ( ob_get_level() > 0 ) {
                    if ( false === ob_end_flush() ) {
                        break;
                    }
                }
            } elseif ( function_exists( 'ob_flush' ) ) {
                ob_flush();
            }

            if ( function_exists( 'flush' ) ) {
                flush();
            }

            remove_filter( 'rest_pre_serve_request', $callback, 10 );

            return true;
        };

        add_filter( 'rest_pre_serve_request', $callback, 10, 4 );

        $response = new WP_REST_Response( null, 200 );
        $response->set_headers( $headers );

        return $response;
    }

    /**
     * Build a Server-Sent Events chunk for the provided data.
     *
     * @param string $event  Event name.
     * @param string $data   Event data payload.
     * @return string
     */
    protected function build_event_stream_chunk( $event, $data ) {
        $chunk = '';

        $event = (string) $event;
        if ( '' !== $event ) {
            $chunk .= 'event: ' . $event . "\n";
        }

        $data_lines = explode( "\n", (string) $data );

        foreach ( $data_lines as $line ) {
            $chunk .= 'data: ' . $line . "\n";
        }

        $chunk .= "\n";

        return $chunk;
    }

    /**
     * Convert failed chat responses into WP_Error instances so they surface in the UI.
     *
     * @param mixed $response Raw response from the language model router.
     * @return mixed
     */
    protected function maybe_convert_failed_chat_response( $response ) {
        if ( ! is_array( $response ) ) {
            return $response;
        }

        $status = $this->extract_chat_response_status( $response );

        if ( ! in_array( $status, array( 'failed', 'cancelled', 'expired' ), true ) ) {
            return $response;
        }

        $message = $this->extract_failed_chat_error_message( $response );

        $data = array(
            'status'          => 502,
            'response_status' => $status,
            'response'        => $response,
            'message'         => $message,
        );

        if ( isset( $response['last_error'] ) && is_array( $response['last_error'] ) ) {
            $data['last_error'] = $response['last_error'];

            if ( isset( $response['last_error']['code'] ) && is_string( $response['last_error']['code'] ) ) {
                $data['provider_error_code'] = sanitize_key( $response['last_error']['code'] );
            }
        }

        if ( isset( $response['id'] ) && is_string( $response['id'] ) ) {
            $data['response_id'] = sanitize_text_field( $response['id'] );
        }

        return new WP_Error( 'wp_mcp_ai_chat_failed', $message, $data );
    }

    /**
     * Extract the status from a chat response payload.
     *
     * @param array $response Chat response payload.
     * @return string
     */
    protected function extract_chat_response_status( array $response ) {
        if ( isset( $response['status'] ) && is_string( $response['status'] ) ) {
            return sanitize_key( $response['status'] );
        }

        if ( isset( $response['response'] ) && is_array( $response['response'] ) ) {
            return $this->extract_chat_response_status( $response['response'] );
        }

        return '';
    }

    /**
     * Extract a human readable error message from a failed chat response.
     *
     * @param array $response Chat response payload.
     * @return string
     */
    protected function extract_failed_chat_error_message( array $response ) {
        $candidates = array();

        if ( isset( $response['last_error'] ) && is_array( $response['last_error'] ) ) {
            if ( isset( $response['last_error']['message'] ) && is_string( $response['last_error']['message'] ) ) {
                $candidates[] = $response['last_error']['message'];
            }
        }

        if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
            if ( isset( $response['error']['message'] ) && is_string( $response['error']['message'] ) ) {
                $candidates[] = $response['error']['message'];
            }
        }

        if ( isset( $response['incomplete_details'] ) && is_array( $response['incomplete_details'] ) ) {
            if ( isset( $response['incomplete_details']['message'] ) && is_string( $response['incomplete_details']['message'] ) ) {
                $candidates[] = $response['incomplete_details']['message'];
            } elseif ( isset( $response['incomplete_details']['reason'] ) && is_string( $response['incomplete_details']['reason'] ) ) {
                $candidates[] = $response['incomplete_details']['reason'];
            }
        }

        if ( empty( $candidates ) && isset( $response['response'] ) && is_array( $response['response'] ) ) {
            $nested_message = $this->extract_failed_chat_error_message( $response['response'] );
            if ( $nested_message ) {
                $candidates[] = $nested_message;
            }
        }

        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) ) {
                continue;
            }

            $sanitized = trim( wp_strip_all_tags( $candidate ) );
            if ( '' !== $sanitized ) {
                return $sanitized;
            }
        }

        return __( 'The assistant response failed to generate.', 'wp-mcp-ai' );
    }

    /**
     * Build a descriptive log message for chat failures.
     *
     * @param WP_Error $error Chat failure error object.
     * @return string
     */
    protected function build_chat_error_log_message( $error ) {
        if ( ! $error instanceof WP_Error ) {
            return 'Chat request failed.';
        }

        $data = $error->get_error_data();
        if ( ! is_array( $data ) ) {
            return 'Chat request failed.';
        }

        $status = isset( $data['status'] ) ? (int) $data['status'] : 0;

        if ( 429 !== $status ) {
            return 'Chat request failed.';
        }

        $details     = $this->parse_openai_rate_limit_details( $data );
        $description = 'OpenAI rate limits';

        if ( ! empty( $details['type'] ) ) {
            $label = str_replace( '_', ' ', $details['type'] );
            $label = trim( preg_replace( '/\s+/', ' ', $label ) );

            if ( false !== strpos( $label, 'token' ) ) {
                $description = 'token limits';
            } elseif ( false !== strpos( $label, 'request' ) ) {
                $description = 'request limits';
            } elseif ( '' !== $label ) {
                $description = $label . ' limits';
            }
        }

        if ( '' === $description ) {
            $description = 'OpenAI rate limits';
        }

        if ( ! empty( $details['unit'] ) ) {
            $description .= ' (' . $details['unit'] . ')';
        }

        return sprintf(
            'Chat request failed due to %s being exceeded; OpenAI rate-limit response %d.',
            $description,
            $status
        );
    }

    /**
     * Extract additional context for chat failure log entries.
     *
     * @param WP_Error $error Chat failure error object.
     * @return array
     */
    protected function extract_chat_error_log_context( $error ) {
        if ( ! $error instanceof WP_Error ) {
            return array();
        }

        $data = $error->get_error_data();
        if ( ! is_array( $data ) ) {
            return array();
        }

        $context = array();

        if ( isset( $data['status'] ) && '' !== $data['status'] ) {
            $context['http_status'] = (int) $data['status'];
        }

        if ( isset( $data['response_status'] ) && '' !== $data['response_status'] ) {
            $context['response_status'] = sanitize_key( $data['response_status'] );
        }

        if ( isset( $data['response_id'] ) && '' !== $data['response_id'] ) {
            $context['response_id'] = sanitize_text_field( $data['response_id'] );
        }

        $status = isset( $context['http_status'] ) ? (int) $context['http_status'] : ( isset( $data['status'] ) ? (int) $data['status'] : 0 );

        if ( 429 === $status ) {
            $details = $this->parse_openai_rate_limit_details( $data );

            if ( ! empty( $details['unit'] ) ) {
                $context['rate_limit_unit'] = $details['unit'];
            }

            if ( ! empty( $details['type'] ) ) {
                $context['rate_limit_type'] = $details['type'];
            }

            if ( ! empty( $details['scope'] ) ) {
                $context['rate_limit_scope'] = $details['scope'];
            }

            if ( null !== $details['limit'] ) {
                $context['rate_limit_limit'] = $details['limit'];
            }

            if ( null !== $details['remaining'] ) {
                $context['rate_limit_remaining'] = $details['remaining'];
            }

            if ( null !== $details['reset_seconds'] ) {
                $context['rate_limit_reset_seconds'] = $details['reset_seconds'];
            }
        }

        return $context;
    }

    /**
     * Parse rate limit details from an OpenAI error payload.
     *
     * @param array $error_data Error data array attached to the WP_Error instance.
     * @return array
     */
    protected function parse_openai_rate_limit_details( array $error_data ) {
        $details = array(
            'unit'          => '',
            'type'          => '',
            'scope'         => '',
            'limit'         => null,
            'remaining'     => null,
            'reset_seconds' => null,
        );

        $error_payload = array();

        if ( isset( $error_data['body'] ) && is_array( $error_data['body'] ) && isset( $error_data['body']['error'] ) && is_array( $error_data['body']['error'] ) ) {
            $error_payload = $error_data['body']['error'];
        } elseif ( isset( $error_data['error'] ) && is_array( $error_data['error'] ) ) {
            $error_payload = $error_data['error'];
        }

        $detail_sections = array();

        if ( ! empty( $error_payload ) ) {
            $detail_sections[] = $error_payload;

            if ( isset( $error_payload['detail'] ) && is_array( $error_payload['detail'] ) ) {
                $detail_sections[] = $error_payload['detail'];
            }
        }

        foreach ( $detail_sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }

            if ( '' === $details['unit'] ) {
                if ( isset( $section['rate_limit_unit'] ) && is_string( $section['rate_limit_unit'] ) ) {
                    $candidate = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $section['rate_limit_unit'] ) );
                    if ( '' !== $candidate ) {
                        $details['unit'] = $candidate;
                    }
                } elseif ( isset( $section['unit'] ) && is_string( $section['unit'] ) ) {
                    $candidate = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $section['unit'] ) );
                    if ( '' !== $candidate ) {
                        $details['unit'] = $candidate;
                    }
                }
            }

            if ( '' === $details['type'] ) {
                $candidates = array();

                if ( isset( $section['limit_type'] ) ) {
                    $candidates[] = $section['limit_type'];
                }

                if ( isset( $section['type'] ) ) {
                    $candidates[] = $section['type'];
                }

                foreach ( $candidates as $candidate ) {
                    if ( ! is_string( $candidate ) || '' === $candidate ) {
                        continue;
                    }

                    $normalised = strtolower( preg_replace( '/[^a-z0-9_]/', '', $candidate ) );

                    if ( '' !== $normalised ) {
                        $details['type'] = $normalised;
                        break;
                    }
                }
            }

            if ( '' === $details['scope'] && isset( $section['scope'] ) && is_string( $section['scope'] ) ) {
                $scope = sanitize_key( $section['scope'] );
                if ( '' !== $scope ) {
                    $details['scope'] = $scope;
                }
            }

            if ( null === $details['limit'] && isset( $section['limit'] ) ) {
                $limit = $this->normalise_rate_limit_number( $section['limit'] );
                if ( null !== $limit ) {
                    $details['limit'] = $limit;
                }
            }

            if ( null === $details['remaining'] && isset( $section['remaining'] ) ) {
                $remaining = $this->normalise_rate_limit_number( $section['remaining'] );
                if ( null !== $remaining ) {
                    $details['remaining'] = $remaining;
                }
            }

            if ( null === $details['reset_seconds'] && isset( $section['reset_seconds'] ) ) {
                $reset = $this->normalise_rate_limit_number( $section['reset_seconds'] );
                if ( null !== $reset ) {
                    $details['reset_seconds'] = $reset;
                }
            }

            if ( null === $details['reset_seconds'] && isset( $section['retry_after'] ) ) {
                $reset = $this->normalise_rate_limit_number( $section['retry_after'] );
                if ( null !== $reset ) {
                    $details['reset_seconds'] = $reset;
                }
            }
        }

        return $details;
    }

    /**
     * Normalise numeric rate limit values.
     *
     * @param mixed $value Rate limit field value.
     * @return int|float|null
     */
    protected function normalise_rate_limit_number( $value ) {
        if ( is_int( $value ) || is_float( $value ) ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return 0 + $value;
        }

        if ( is_string( $value ) ) {
            $trimmed = trim( $value );
            if ( is_numeric( $trimmed ) ) {
                return 0 + $trimmed;
            }
        }

        return null;
    }

    /**
     * Handle requests to execute a specific tool, temporarily granting access to
     * the document prompt helper when the payload includes attachments.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_tool_request( WP_REST_Request $request ) {
        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        $scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
        if ( is_wp_error( $scoped_id ) ) {
            return $scoped_id;
        }

        $assistant_id = $scoped_id;

        if ( ! $assistant_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $assistant_post = $this->validate_assistant_access( $assistant_id );
        if ( is_wp_error( $assistant_post ) ) {
            return $assistant_post;
        }

        $assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
        $raw_tool         = $request->get_param( 'tool' );
        $arguments        = $request->get_param( 'arguments' );
        $allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

        $tool_candidates = $this->generate_tool_slug_candidates( $raw_tool );

        if ( $this->candidates_include_slug( $tool_candidates, self::DOCUMENT_PROMPT_TOOL_SLUG ) && ! in_array( self::DOCUMENT_PROMPT_TOOL_SLUG, $allowed_tools, true ) ) {
            if ( $this->tool_arguments_include_document_payload( $arguments ) ) {
                $assistant_config = $this->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
                $allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
            }
        }

        $tool_slug = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

        if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
            return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
        }

        $tool = $this->registry->get_tool( $tool_slug );
        if ( ! $tool ) {
            return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
        }

        $auth_context = $this->get_auth_context();
        $user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : 0;

        $context = array(
            'user_id'           => $user_id,
            'assistant_id'      => $assistant_id,
            'request'           => $request,
            'assistant_config'  => $assistant_config,
        );

        if ( ! empty( $auth_context['token_authenticated'] ) ) {
            $context['token_authenticated'] = true;
            $context['token_type']          = $auth_context['token_type'];

            if ( ! empty( $auth_context['token_context'] ) ) {
                $context['token_context'] = $auth_context['token_context'];
            }
        }

        if ( empty( $context['user_id'] ) && empty( $auth_context['token_authenticated'] ) ) {
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

        if ( 'run_openai_external_action' === $tool_slug ) {
            if ( empty( $prepared_arguments['action_type'] ) && ! empty( $assistant_config['external_action_type'] ) ) {
                $prepared_arguments['action_type'] = $assistant_config['external_action_type'];
            }

            if ( empty( $prepared_arguments['identifier'] ) && ! empty( $assistant_config['external_action_identifier'] ) ) {
                $prepared_arguments['identifier'] = $assistant_config['external_action_identifier'];
            }
        }

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
     * Build a list of potential tool slugs based on the supplied identifier.
     *
     * @param mixed $tool_name Raw tool identifier from the REST request.
     * @return array
     */
    protected function generate_tool_slug_candidates( $tool_name ) {
        if ( ! is_string( $tool_name ) ) {
            $tool_name = '';
        }

        $tool_name = trim( $tool_name );

        if ( '' === $tool_name ) {
            return array();
        }

        $candidates = array();

        $primary = sanitize_key( $tool_name );
        if ( '' !== $primary ) {
            $candidates[] = $primary;
        }

        $variants = array(
            str_replace( array( '-', ' ' ), '_', $tool_name ),
        );

        $camel_split = preg_replace( '/(?<=\p{Ll})(\p{Lu})/u', '_$1', $tool_name );

        if ( is_string( $camel_split ) && '' !== $camel_split ) {
            $lower_camel = strtolower( $camel_split );
            $variants[]  = $lower_camel;
            $variants[]  = str_replace( array( '-', ' ' ), '_', $lower_camel );
        }

        foreach ( $variants as $variant ) {
            if ( ! is_string( $variant ) ) {
                continue;
            }

            $variant = trim( $variant );

            if ( '' === $variant ) {
                continue;
            }

            $sanitized = sanitize_key( $variant );
            if ( '' !== $sanitized ) {
                $candidates[] = $sanitized;
            }
        }

        $candidates = array_values( array_unique( $candidates ) );

        return $candidates;
    }

    /**
     * Determine whether the supplied candidates refer to a specific tool slug.
     *
     * @param array  $candidates Candidate tool slugs.
     * @param string $slug       Target slug to match.
     * @return bool
     */
    protected function candidates_include_slug( array $candidates, $slug ) {
        $slug = sanitize_key( $slug );

        if ( '' === $slug ) {
            return false;
        }

        if ( in_array( $slug, $candidates, true ) ) {
            return true;
        }

        $normalised_slug = preg_replace( '/[_-]/', '', $slug );

        if ( '' === $normalised_slug ) {
            return false;
        }

        foreach ( $candidates as $candidate ) {
            if ( $normalised_slug === preg_replace( '/[_-]/', '', $candidate ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the requested tool slug by comparing candidates against the assistant's allow-list.
     *
     * @param array $candidates     Candidate tool slugs derived from the REST payload.
     * @param array $allowed_tools  Assistant tool allow-list.
     * @return string
     */
    protected function resolve_tool_slug_from_candidates( array $candidates, array $allowed_tools ) {
        if ( empty( $candidates ) ) {
            return '';
        }

        $allowed_lookup = array();
        foreach ( $allowed_tools as $slug ) {
            $sanitized = sanitize_key( $slug );

            if ( '' === $sanitized ) {
                continue;
            }

            $allowed_lookup[ $sanitized ] = $sanitized;
        }

        foreach ( $candidates as $candidate ) {
            if ( isset( $allowed_lookup[ $candidate ] ) ) {
                return $allowed_lookup[ $candidate ];
            }
        }

        if ( ! empty( $allowed_lookup ) ) {
            $normalised_candidates = array();
            foreach ( $candidates as $candidate ) {
                $normalised_candidates[] = preg_replace( '/[_-]/', '', $candidate );
            }

            $normalised_candidates = array_values( array_filter( array_unique( $normalised_candidates ) ) );

            if ( ! empty( $normalised_candidates ) ) {
                foreach ( $allowed_lookup as $slug ) {
                    $normalised_slug = preg_replace( '/[_-]/', '', $slug );

                    if ( in_array( $normalised_slug, $normalised_candidates, true ) ) {
                        return $slug;
                    }
                }
            }
        }

        return $candidates[0];
    }

    /**
     * Proxy OpenAI file downloads through WordPress so attachments can be saved locally.
     *
     * @param WP_REST_Request $request REST request instance.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_file_download( WP_REST_Request $request ) {
        $assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
        $scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

        if ( is_wp_error( $scoped_id ) ) {
            return $scoped_id;
        }

        if ( $scoped_id ) {
            $assistant_id = $scoped_id;
        }

        $file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

        if ( '' === $file_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $local_attachment = $this->resolve_local_attachment_for_openai_file( $file_id );

        if ( is_wp_error( $local_attachment ) ) {
            return $local_attachment;
        }

        if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
            require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
        }

        $client = new WP_MCP_AI_OpenAI_Client();
        $result = $client->download_file( $file_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $body = isset( $result['body'] ) ? (string) $result['body'] : '';

        if ( '' === $body ) {
            return new WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'wp-mcp-ai' ) );
        }

        $content_type = isset( $result['content_type'] ) && '' !== $result['content_type'] ? $result['content_type'] : 'application/octet-stream';

        if ( 'application/octet-stream' === $content_type && ! empty( $local_attachment['metadata']['mime_type'] ) ) {
            if ( function_exists( 'sanitize_mime_type' ) ) {
                $content_type = sanitize_mime_type( $local_attachment['metadata']['mime_type'] );
            } else {
                $content_type = sanitize_text_field( $local_attachment['metadata']['mime_type'] );
            }
        }

        $requested_name = $request->get_param( 'download_name' );
        $download_name  = '';

        if ( is_string( $requested_name ) && '' !== $requested_name ) {
            $download_name = sanitize_file_name( $requested_name );
        }

        $filename = '';

        if ( isset( $result['filename'] ) && '' !== $result['filename'] ) {
            $filename = sanitize_file_name( $result['filename'] );
        } elseif ( ! empty( $local_attachment['metadata']['filename'] ) ) {
            $filename = sanitize_file_name( $local_attachment['metadata']['filename'] );
        }

        if ( '' === $filename && '' !== $download_name ) {
            $filename = $download_name;
        }

        if ( '' === $filename ) {
            $fallback_name = sanitize_file_name( 'openai-file-' . $file_id );
            $filename      = '' !== $fallback_name ? $fallback_name : 'openai-file';
        }

        $disposition = $request->get_param( 'disposition' );
        $disposition = is_string( $disposition ) ? strtolower( $disposition ) : '';

        if ( ! in_array( $disposition, array( 'inline', 'attachment' ), true ) ) {
            $disposition = 'attachment';
        }

        $content_length = strlen( $body );

        $headers = array(
            'Content-Type'            => $content_type,
            'Content-Length'          => (string) $content_length,
            'Content-Disposition'     => sprintf( '%s; filename="%s"', $disposition, $filename ),
            'Cache-Control'           => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'                  => 'no-cache',
            'X-Content-Type-Options'  => 'nosniff',
            'X-Robots-Tag'            => 'noindex',
        );

        $headers = apply_filters( 'wp_mcp_ai_file_download_headers', $headers, $file_id, $result, $request );

        add_filter(
            'rest_pre_serve_request',
            function ( $served, $response, $request_obj, $server ) use ( $headers, $body ) {
                if ( $served ) {
                    return $served;
                }

                foreach ( $headers as $key => $value ) {
                    if ( '' === $key || null === $value ) {
                        continue;
                    }

                    $server->send_header( $key, $value );
                }

                echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                return true;
            },
            10,
            4
        );

        return new WP_REST_Response( null, 200 );
    }

    /**
     * Locate the local attachment associated with an OpenAI file identifier and ensure it is accessible.
     *
     * @param string $file_id OpenAI file identifier.
     * @return array|WP_Error Array containing the attachment ID and metadata, or WP_Error on failure.
     */
    protected function resolve_local_attachment_for_openai_file( $file_id ) {
        if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
            require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
        }

        $file_id = sanitize_text_field( (string) $file_id );

        if ( '' === $file_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        global $wpdb;

        $meta_key = WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY;
        $like     = '%' . $wpdb->esc_like( $file_id ) . '%';

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                $meta_key,
                $like
            )
        );

        if ( empty( $post_ids ) ) {
            return new WP_Error(
                'wp_mcp_ai_file_download_not_found',
                __( 'The requested file could not be located or is no longer available.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $post_ids        = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
        $unauthorised_id = 0;

        foreach ( $post_ids as $attachment_id ) {
            if ( ! $attachment_id ) {
                continue;
            }

            $attachment = get_post( $attachment_id );
            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                continue;
            }

            $raw_meta = get_post_meta( $attachment_id, $meta_key, true );

            if ( is_string( $raw_meta ) && '' !== $raw_meta ) {
                $maybe_unserialized = maybe_unserialize( $raw_meta );

                if ( is_array( $maybe_unserialized ) ) {
                    $metadata = $maybe_unserialized;
                } else {
                    $metadata = array( 'file_id' => (string) $raw_meta );
                }
            } elseif ( is_array( $raw_meta ) ) {
                $metadata = $raw_meta;
            } else {
                $metadata = array();
            }

            $meta_file_id = '';
            if ( isset( $metadata['file_id'] ) ) {
                $meta_file_id = sanitize_text_field( (string) $metadata['file_id'] );
            }

            if ( $file_id !== $meta_file_id ) {
                continue;
            }

            if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
                $unauthorised_id = $attachment_id;
                continue;
            }

            if ( ! is_array( $metadata ) ) {
                $metadata = array();
            }

            $metadata['file_id'] = $meta_file_id;

            return array(
                'attachment_id' => $attachment_id,
                'metadata'      => $metadata,
            );
        }

        if ( $unauthorised_id ) {
            return new WP_Error(
                'wp_mcp_ai_file_download_forbidden',
                __( 'You do not have permission to download this file.', 'wp-mcp-ai' ),
                array(
                    'status'        => 403,
                    'attachment_id' => $unauthorised_id,
                )
            );
        }

        return new WP_Error(
            'wp_mcp_ai_file_download_not_found',
            __( 'The requested file could not be located or is no longer available.', 'wp-mcp-ai' ),
            array( 'status' => 404 )
        );
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
     * Ensure the active assistant aligns with the authenticated token scope.
     *
     * @param int $assistant_id Assistant identifier resolved from the request.
     * @return int|WP_Error Scoped assistant identifier or error when the token cannot access the requested assistant.
     */
    protected function apply_token_assistant_scope( $assistant_id ) {
        $assistant_id = absint( $assistant_id );
        $auth_context = $this->get_auth_context();

        if ( empty( $auth_context['token_authenticated'] ) || 'local_token' !== $auth_context['token_type'] ) {
            return $assistant_id;
        }

        $token_assistant = 0;

        if ( isset( $auth_context['assistant_id'] ) ) {
            $token_assistant = absint( $auth_context['assistant_id'] );
        }

        if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
            $token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
        }

        if ( ! $token_assistant ) {
            return $assistant_id;
        }

        if ( ! $assistant_id ) {
            return $token_assistant;
        }

        if ( $assistant_id !== $token_assistant ) {
            return new WP_Error(
                'wp_mcp_ai_assistant_scope_mismatch',
                __( 'The provided credential cannot access the requested assistant.', 'wp-mcp-ai' ),
                array(
                    'status'  => 403,
                    'actions' => array(
                        'use_scoped_assistant' => __( 'Retry the request without overriding the assistant or request a credential for the desired assistant.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        return $token_assistant;
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

        $token_bypasses_visibility = false;

        $auth_context = $this->get_auth_context();
        if ( ! empty( $auth_context['token_authenticated'] ) && 'local_token' === $auth_context['token_type'] ) {
            $token_assistant = isset( $auth_context['assistant_id'] ) ? absint( $auth_context['assistant_id'] ) : 0;

            if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
                $token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
            }

            if ( $token_assistant && $token_assistant === $assistant_id ) {
                $token_bypasses_visibility = true;
            }
        }

        if ( 'publish' !== $assistant_post->post_status && ! current_user_can( 'read_post', $assistant_id ) && ! $token_bypasses_visibility ) {
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
     * Allowed roles can be customized via the `wp_mcp_ai_allowed_message_roles` filter.
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

        $default_roles = array( 'user', 'assistant', 'system', 'tool' );
        $allowed_roles = apply_filters( 'wp_mcp_ai_allowed_message_roles', $default_roles );

        if ( ! is_array( $allowed_roles ) ) {
            $allowed_roles = $default_roles;
        }

        $allowed_roles = array_values(
            array_filter(
                array_unique(
                    array_map( 'sanitize_key', $allowed_roles )
                )
            )
        );

        if ( empty( $allowed_roles ) ) {
            $allowed_roles = $default_roles;
        }

        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $raw_role = isset( $message['role'] ) ? $message['role'] : '';
            $role     = sanitize_key( $raw_role );
            if ( empty( $role ) ) {
                continue;
            }

            if ( ! in_array( $role, $allowed_roles, true ) ) {
                $display_role = is_scalar( $raw_role ) ? (string) $raw_role : $role;
                $display_role = sanitize_text_field( $display_role );

                return new WP_Error(
                    'wp_mcp_ai_invalid_message_role',
                    sprintf(
                        /* translators: 1: Provided role, 2: list of supported roles. */
                        __( 'The message role "%1$s" is not supported. Supported roles: %2$s.', 'wp-mcp-ai' ),
                        $display_role,
                        implode( ', ', $allowed_roles )
                    ),
                    array( 'status' => 400 )
                );
            }

            $content = isset( $message['content'] ) ? $message['content'] : '';
            $segments = $this->sanitize_message_content( $content, $attachments_helper );

            if ( is_wp_error( $segments ) ) {
                return $segments;
            }

            $metadata = $this->sanitize_message_metadata( $message );

            if ( empty( $segments ) && empty( $metadata ) ) {
                continue;
            }

            $sanitized[] = array_merge(
                array(
                    'role'    => $role,
                    'content' => $segments,
                ),
                $metadata
            );
        }

        $filtered_messages = $this->filter_tool_messages_without_matching_calls( array_values( $sanitized ) );

        return array(
            'messages'    => $filtered_messages,
            'attachments' => $attachments_helper->get_attachments(),
        );
    }

    /**
     * Build the context array used when enforcing chat request limits.
     *
     * @param int   $assistant_id Assistant identifier.
     * @param array $options      Prepared chat options.
     * @return array
     */
    protected function build_chat_limit_context( $assistant_id, array $options ) {
        return array(
            'assistant_id' => absint( $assistant_id ),
            'provider'     => isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '',
            'model'        => isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : '',
        );
    }

    /**
     * Ensure tool messages are paired with the immediately preceding tool call.
     *
     * Messages with the `tool` role must reference the ID of a tool call emitted by
     * the previous assistant message. When that metadata is missing or does not
     * match the pending tool calls the OpenAI API rejects the request. This helper
     * discards any orphaned tool messages before the payload is dispatched.
     *
     * @param array $messages Sanitized chat messages.
     * @return array
     */
    protected function filter_tool_messages_without_matching_calls( array $messages ) {
        if ( empty( $messages ) ) {
            return $messages;
        }

        $filtered      = array();
        $pending_calls   = array();
        $saw_assistant   = false;

        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

            if ( 'assistant' === $role ) {
                $pending_calls = array();
                $saw_assistant = true;

                if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
                    foreach ( $message['tool_calls'] as $tool_call ) {
                        if ( ! is_array( $tool_call ) ) {
                            continue;
                        }

                        $call_id = isset( $tool_call['id'] ) ? (string) $tool_call['id'] : '';

                        if ( '' !== $call_id ) {
                            $pending_calls[ $call_id ] = true;
                        }
                    }
                }

                $filtered[] = $message;
                continue;
            }

            if ( 'tool' === $role ) {
                $tool_call_id = isset( $message['tool_call_id'] ) ? (string) $message['tool_call_id'] : '';

                if ( '' === $tool_call_id ) {
                    WP_MCP_AI_Logger::log_event(
                        'dropped_tool_message',
                        'Dropping tool message without matching tool call.',
                        array(
                            'tool_call_id' => $tool_call_id,
                            'reason'       => 'missing_tool_call_id',
                        )
                    );

                    continue;
                }

                if ( empty( $pending_calls ) ) {
                    if ( ! $saw_assistant ) {
                        $filtered[] = $message;
                    } else {
                        WP_MCP_AI_Logger::log_event(
                            'dropped_tool_message',
                            'Dropping tool message without matching tool call.',
                            array(
                                'tool_call_id' => $tool_call_id,
                                'reason'       => 'no_pending_tool_calls',
                            )
                        );
                    }
                    continue;
                }

                if ( ! isset( $pending_calls[ $tool_call_id ] ) ) {
                    WP_MCP_AI_Logger::log_event(
                        'dropped_tool_message',
                        'Dropping tool message without matching tool call.',
                        array(
                            'tool_call_id' => $tool_call_id,
                            'reason'       => 'tool_call_not_found',
                        )
                    );

                    continue;
                }

                unset( $pending_calls[ $tool_call_id ] );
                $filtered[] = $message;
                continue;
            }

            $pending_calls = array();
            $filtered[]    = $message;
        }

        return $filtered;
    }

    /**
     * Ensure chat requests stay within approximate token limits before dispatching to the model.
     *
     * @param array $messages    Sanitized chat messages.
     * @param array $attachments Attachment payloads associated with the request.
     * @param array $context     Contextual information about the request (assistant, provider, model).
     * @return array|WP_Error
     */
    protected function enforce_chat_request_limits( array $messages, array $attachments, array $context = array() ) {
        $messages    = array_values( $messages );
        $attachments = array_values( $attachments );
        $context     = is_array( $context ) ? $context : array();

        $context['assistant_id'] = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
        $context['provider']     = isset( $context['provider'] ) ? sanitize_key( $context['provider'] ) : '';
        $context['model']        = isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : '';

        $limit_tokens = $this->determine_chat_request_token_limit( $context );
        $limit_tokens = (int) apply_filters( 'wp_mcp_ai_chat_request_token_limit', $limit_tokens, $messages, $attachments, $context );

        if ( $limit_tokens <= 0 ) {
            return array(
                'messages'    => $messages,
                'attachments' => $attachments,
            );
        }

        $chars_per_token = (int) apply_filters( 'wp_mcp_ai_chat_request_chars_per_token', self::CHAT_APPROX_CHARS_PER_TOKEN, $messages, $attachments );

        if ( $chars_per_token <= 0 ) {
            $chars_per_token = self::CHAT_APPROX_CHARS_PER_TOKEN;
        }

        $max_chars = (int) $limit_tokens * $chars_per_token;

        if ( $max_chars <= 0 ) {
            return array(
                'messages'    => $messages,
                'attachments' => $attachments,
            );
        }

        $message_lengths = array();
        $total_chars     = 0;

        foreach ( $messages as $index => $message ) {
            $length                     = $this->calculate_message_character_length( $message );
            $message_lengths[ $index ]   = $length;
            $total_chars                += $length;
        }

        if ( $total_chars <= $max_chars ) {
            return array(
                'messages'    => $messages,
                'attachments' => $attachments,
            );
        }

        $original_total_chars   = $total_chars;
        $original_message_count = count( $messages );
        $trimmed                = false;

        $removal_order = array();
        $system_indexes = array();

        foreach ( array_keys( $messages ) as $index ) {
            $role = isset( $messages[ $index ]['role'] ) ? sanitize_key( $messages[ $index ]['role'] ) : '';

            if ( 'system' === $role ) {
                $system_indexes[] = $index;
            } else {
                $removal_order[] = $index;
            }
        }

        $removal_order = array_merge( $removal_order, $system_indexes );

        foreach ( $removal_order as $index ) {
            if ( $total_chars <= $max_chars ) {
                break;
            }

            if ( ! isset( $messages[ $index ] ) ) {
                continue;
            }

            $length = isset( $message_lengths[ $index ] ) ? (int) $message_lengths[ $index ] : 0;
            $remaining_without_message = $total_chars - $length;

            if ( $remaining_without_message >= $max_chars || $length <= 0 ) {
                unset( $messages[ $index ], $message_lengths[ $index ] );
                $total_chars = max( 0, $remaining_without_message );
                $trimmed     = true;
                continue;
            }

            $available_for_message = max( 0, $max_chars - $remaining_without_message );
            $updated_message       = $this->truncate_message_to_length( $messages[ $index ], $available_for_message );

            if ( empty( $updated_message ) ) {
                unset( $messages[ $index ], $message_lengths[ $index ] );
                $total_chars = max( 0, $remaining_without_message );
                $trimmed     = true;
                continue;
            }

            $new_length = $this->calculate_message_character_length( $updated_message );
            $messages[ $index ]        = $updated_message;
            $message_lengths[ $index ] = $new_length;
            $total_chars               = $remaining_without_message + $new_length;
            $trimmed                   = true;
        }

        if ( $total_chars > $max_chars ) {
            foreach ( array_keys( $messages ) as $index ) {
                if ( $total_chars <= $max_chars ) {
                    break;
                }

                if ( ! isset( $messages[ $index ] ) ) {
                    continue;
                }

                $length = isset( $message_lengths[ $index ] ) ? (int) $message_lengths[ $index ] : 0;
                unset( $messages[ $index ], $message_lengths[ $index ] );
                $total_chars = max( 0, $total_chars - $length );
                $trimmed     = true;
            }
        }

        $messages = array_values( $messages );

        if ( empty( $messages ) ) {
            return new WP_Error(
                'wp_mcp_ai_request_too_large',
                __( 'The chat request exceeds the maximum allowed size.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'reduce_request_size' => __( 'Reduce the length of the conversation before retrying.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $trimmed_total_chars = 0;

        foreach ( $messages as $message ) {
            $trimmed_total_chars += $this->calculate_message_character_length( $message );
        }

        $filtered_attachments = $this->filter_attachments_for_messages( $attachments, $messages );

        if ( $trimmed ) {
            WP_MCP_AI_Logger::log_event(
                'chat_request_trimmed',
                'Chat request trimmed to satisfy token limits.',
                array(
                    'original_total_chars'   => $original_total_chars,
                    'trimmed_total_chars'    => $trimmed_total_chars,
                    'max_chars'              => $max_chars,
                    'original_message_count' => $original_message_count,
                    'trimmed_message_count'  => count( $messages ),
                )
            );
        }

        return array(
            'messages'    => $messages,
            'attachments' => $filtered_attachments,
        );
    }

    /**
     * Determine the maximum token budget for a chat request.
     *
     * @param array $context Contextual information about the request.
     * @return int
     */
    protected function determine_chat_request_token_limit( array $context ) {
        $limit = self::CHAT_MAX_REQUEST_TOKENS;

        $provider_limits = array();

        if ( 'openai' === $context['provider'] ) {
            $provider_limits = array(
                'gpt-5-nano' => 150000,
            );
        }

        /**
         * Filter the provider-specific token ceilings used when trimming chat requests.
         *
         * @param array $provider_limits Associative array of model identifiers mapped to token limits.
         * @param array $context         Contextual information about the request (assistant, provider, model).
         */
        $provider_limits = apply_filters( 'wp_mcp_ai_provider_chat_token_limits', $provider_limits, $context );

        if ( ! is_array( $provider_limits ) ) {
            $provider_limits = array();
        }

        $matched_limit = null;

        if ( ! empty( $context['model'] ) ) {
            $normalized_model = strtolower( $context['model'] );

            foreach ( $provider_limits as $candidate_model => $candidate_limit ) {
                $candidate_model = is_string( $candidate_model ) ? strtolower( $candidate_model ) : '';
                $candidate_limit = (int) $candidate_limit;

                if ( '' === $candidate_model || $candidate_limit <= 0 ) {
                    continue;
                }

                if ( $normalized_model === $candidate_model ) {
                    $matched_limit = $candidate_limit;
                    break;
                }
            }
        }

        if ( null === $matched_limit ) {
            foreach ( array( 'default', '*' ) as $fallback_key ) {
                if ( isset( $provider_limits[ $fallback_key ] ) ) {
                    $fallback_limit = (int) $provider_limits[ $fallback_key ];
                    if ( $fallback_limit > 0 ) {
                        $matched_limit = $fallback_limit;
                        break;
                    }
                }
            }
        }

        if ( null !== $matched_limit && $matched_limit > 0 ) {
            $limit = min( $limit, $matched_limit );
        }

        return (int) $limit;
    }

    /**
     * Estimate the number of characters contributed by a chat message.
     *
     * @param array $message Chat message payload.
     * @return int
     */
    protected function calculate_message_character_length( array $message ) {
        if ( empty( $message['content'] ) ) {
            return 0;
        }

        $content = $message['content'];

        if ( is_string( $content ) ) {
            return $this->mb_strlen( $content );
        }

        if ( ! is_array( $content ) ) {
            return 0;
        }

        $length = 0;

        foreach ( $content as $segment ) {
            if ( is_string( $segment ) ) {
                $length += $this->mb_strlen( $segment );
                continue;
            }

            if ( ! is_array( $segment ) ) {
                continue;
            }

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

            switch ( $type ) {
                case 'text':
                case 'input_text':
                    if ( isset( $segment['text'] ) ) {
                        $length += $this->mb_strlen( (string) $segment['text'] );
                    }
                    break;
                case 'input_image':
                    if ( isset( $segment['caption'] ) ) {
                        $length += $this->mb_strlen( (string) $segment['caption'] );
                    }

                    if ( isset( $segment['detail'] ) ) {
                        $length += $this->mb_strlen( (string) $segment['detail'] );
                    }
                    break;
                case 'input_file':
                    if ( isset( $segment['display_name'] ) ) {
                        $length += $this->mb_strlen( (string) $segment['display_name'] );
                    }
                    break;
            }
        }

        return $length;
    }

    /**
     * Truncate a message's text segments so they fit within the supplied character budget.
     *
     * @param array $message   Chat message payload.
     * @param int   $max_chars Maximum characters to retain.
     * @return array
     */
    protected function truncate_message_to_length( array $message, $max_chars ) {
        $max_chars = (int) $max_chars;

        if ( $max_chars <= 0 ) {
            return array();
        }

        if ( ! isset( $message['content'] ) ) {
            return array();
        }

        $current_length = $this->calculate_message_character_length( $message );

        if ( $current_length <= $max_chars ) {
            return $message;
        }

        $content = $message['content'];

        if ( ! is_array( $content ) ) {
            $content = array();
        }

        $note        = '[' . __( 'Truncated', 'wp-mcp-ai' ) . '] ';
        $note_length = $this->mb_strlen( $note );

        if ( $note_length >= $max_chars ) {
            $note        = '';
            $note_length = 0;
        }

        $available  = max( 0, $max_chars - $note_length );
        $kept       = array();
        $remaining  = $available;
        $truncated  = false;

        for ( $index = count( $content ) - 1; $index >= 0; $index-- ) {
            $segment = $content[ $index ];

            if ( ! is_array( $segment ) ) {
                continue;
            }

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

            if ( in_array( $type, array( 'text', 'input_text' ), true ) ) {
                $text   = isset( $segment['text'] ) ? (string) $segment['text'] : '';
                $length = $this->mb_strlen( $text );

                if ( $length <= 0 ) {
                    array_unshift( $kept, $segment );
                    continue;
                }

                if ( $remaining <= 0 ) {
                    $truncated = true;
                    continue;
                }

                if ( $length <= $remaining ) {
                    $remaining -= $length;
                    array_unshift( $kept, $segment );
                } else {
                    $offset             = max( 0, $length - $remaining );
                    $trimmed_text       = $this->mb_substr( $text, $offset, $remaining );
                    $trimmed_text       = ltrim( $trimmed_text );
                    $modified_segment   = $segment;
                    $modified_segment['text'] = $trimmed_text;

                    array_unshift( $kept, $modified_segment );
                    $remaining  = 0;
                    $truncated  = true;
                }

                continue;
            }

            array_unshift( $kept, $segment );
        }

        if ( empty( $kept ) ) {
            return array();
        }

        if ( $note_length > 0 && $truncated ) {
            $note_added = false;

            foreach ( $kept as &$segment ) {
                if ( ! is_array( $segment ) ) {
                    continue;
                }

                $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

                if ( in_array( $type, array( 'text', 'input_text' ), true ) ) {
                    $segment['text'] = $note . ltrim( (string) $segment['text'] );
                    $note_added      = true;
                    break;
                }
            }

            unset( $segment );

            if ( ! $note_added ) {
                array_unshift(
                    $kept,
                    array(
                        'type' => 'text',
                        'text' => $note,
                    )
                );
            }
        }

        $message['content'] = array_values( $kept );

        return $message;
    }

    /**
     * Remove attachments that are no longer referenced by the trimmed message payload.
     *
     * @param array $attachments Attachment payloads from the request.
     * @param array $messages    Trimmed chat messages.
     * @return array
     */
    protected function filter_attachments_for_messages( array $attachments, array $messages ) {
        if ( empty( $attachments ) ) {
            return array();
        }

        $referenced_ids = $this->collect_message_attachment_ids( $messages );

        if ( empty( $referenced_ids ) ) {
            return array();
        }

        $referenced_lookup = array_flip( $referenced_ids );
        $filtered          = array();

        foreach ( $attachments as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $file_id = '';

            if ( isset( $attachment['file_id'] ) && '' !== $attachment['file_id'] ) {
                $file_id = (string) $attachment['file_id'];
            } elseif ( isset( $attachment['id'] ) && '' !== $attachment['id'] ) {
                $file_id = (string) $attachment['id'];
            }

            if ( '' === $file_id ) {
                continue;
            }

            if ( isset( $referenced_lookup[ $file_id ] ) ) {
                $filtered[] = $attachment;
            }
        }

        return array_values( $filtered );
    }

    /**
     * Collect attachment identifiers referenced in a set of messages.
     *
     * @param array $messages Chat messages.
     * @return array
     */
    protected function collect_message_attachment_ids( array $messages ) {
        $file_ids = array();

        foreach ( $messages as $message ) {
            if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
                continue;
            }

            foreach ( $message['content'] as $segment ) {
                if ( ! is_array( $segment ) ) {
                    continue;
                }

                $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

                if ( 'input_image' === $type ) {
                    $file_id = '';

                    if ( isset( $segment['file_id'] ) ) {
                        $file_id = (string) $segment['file_id'];
                    } elseif ( isset( $segment['image_file'] ) && is_array( $segment['image_file'] ) ) {
                        $file_id = isset( $segment['image_file']['file_id'] ) ? (string) $segment['image_file']['file_id'] : '';
                    } elseif ( isset( $segment['image']['file_id'] ) ) {
                        $file_id = (string) $segment['image']['file_id'];
                    }

                    if ( '' !== $file_id ) {
                        $file_ids[] = $file_id;
                    }

                    continue;
                }

                if ( 'input_file' === $type ) {
                    $file_id = isset( $segment['file_id'] ) ? (string) $segment['file_id'] : '';

                    if ( '' !== $file_id ) {
                        $file_ids[] = $file_id;
                    }
                }
            }
        }

        return array_values( array_unique( $file_ids ) );
    }

    /**
     * Ensure the supplied assistant configuration allows a specific tool.
     *
     * @param array  $assistant_config Assistant configuration array.
     * @param string $tool_slug        Tool identifier to allow.
     * @return array
     */
    protected function ensure_tool_in_config( array $assistant_config, $tool_slug ) {
        if ( ! isset( $assistant_config['tools'] ) || ! is_array( $assistant_config['tools'] ) ) {
            $assistant_config['tools'] = array();
        }

        $tool_slug = sanitize_key( $tool_slug );

        if ( '' === $tool_slug ) {
            return $assistant_config;
        }

        if ( ! in_array( $tool_slug, $assistant_config['tools'], true ) ) {
            $assistant_config['tools'][] = $tool_slug;
        }

        $assistant_config['tools'] = array_values(
            array_filter(
                array_unique(
                    array_map( 'sanitize_key', $assistant_config['tools'] )
                )
            )
        );

        return $assistant_config;
    }

    /**
     * Determine whether a tool request payload references document attachments.
     *
     * Recognises the attachment_id, attachment_ids, file_id, file_ids, and
     * attachments keys so the REST layer mirrors the tool schema.
     *
     * @param mixed $arguments Tool invocation arguments.
     * @return bool
     */
    protected function tool_arguments_include_document_payload( $arguments ) {
        if ( empty( $arguments ) || ! is_array( $arguments ) ) {
            return false;
        }

        if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) ) {
            return true;
        }

        if ( ! empty( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ) {
            foreach ( $arguments['attachment_ids'] as $value ) {
                if ( ! empty( $value ) ) {
                    return true;
                }
            }
        }

        if ( ! empty( $arguments['file_ids'] ) && is_array( $arguments['file_ids'] ) ) {
            foreach ( $arguments['file_ids'] as $value ) {
                if ( ! empty( $value ) ) {
                    return true;
                }
            }
        }

        if ( ! empty( $arguments['attachments'] ) && is_array( $arguments['attachments'] ) ) {
            foreach ( $arguments['attachments'] as $entry ) {
                if ( $entry instanceof \Traversable ) {
                    $entry = iterator_to_array( $entry );
                }

                if ( is_object( $entry ) ) {
                    $entry = (array) $entry;
                }

                if ( ! is_array( $entry ) ) {
                    continue;
                }

                if ( ! empty( $entry['attachment_id'] ) || ! empty( $entry['file_id'] ) || ! empty( $entry['id'] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize additional metadata attached to a message.
     *
     * @param array $message Raw message data.
     * @return array
     */
    protected function sanitize_message_metadata( array $message ) {
        $metadata = array();

        if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
            $tool_calls = array();

            foreach ( $message['tool_calls'] as $tool_call ) {
                if ( ! is_array( $tool_call ) ) {
                    continue;
                }

                $sanitized_call = array();

                if ( isset( $tool_call['id'] ) ) {
                    $sanitized_call['id'] = sanitize_text_field( $tool_call['id'] );
                }

                if ( isset( $tool_call['type'] ) ) {
                    $sanitized_call['type'] = sanitize_text_field( $tool_call['type'] );
                }

                if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
                    $function = array();

                    if ( isset( $tool_call['function']['name'] ) ) {
                        $function['name'] = sanitize_text_field( $tool_call['function']['name'] );
                    }

                    if ( isset( $tool_call['function']['arguments'] ) ) {
                        $function['arguments'] = wp_check_invalid_utf8( (string) $tool_call['function']['arguments'], true );
                    }

                    if ( ! empty( $function ) ) {
                        $sanitized_call['function'] = $function;
                    }
                }

                if ( isset( $tool_call['index'] ) ) {
                    $sanitized_call['index'] = absint( $tool_call['index'] );
                }

                if ( ! empty( $sanitized_call ) ) {
                    $tool_calls[] = $sanitized_call;
                }
            }

            if ( ! empty( $tool_calls ) ) {
                $metadata['tool_calls'] = $tool_calls;
            }
        }

        if ( isset( $message['tool_call_id'] ) ) {
            $metadata['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
        }

        if ( isset( $message['name'] ) ) {
            $metadata['name'] = sanitize_text_field( $message['name'] );
        }

        return $metadata;
    }

    /**
     * Sanitize the content of a single message and normalise into segments.
     *
     * @param mixed                           $content             Raw content provided by the client.
     * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper instance.
     * @return array|WP_Error
     */
    protected function sanitize_message_content( $content, WP_MCP_AI_Message_Attachments $attachments_helper ) {
        if ( $content instanceof \Traversable ) {
            $content = iterator_to_array( $content );
        }

        if ( is_object( $content ) ) {
            $content = (array) $content;
        }

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

        if ( ! wp_is_numeric_array( $content ) ) {
            $content = array( $content );
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

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

            switch ( $type ) {
                case 'text':
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

        $provider = '';
        if ( isset( $options['provider'] ) ) {
            $provider = sanitize_key( $options['provider'] );
        }

        if ( empty( $provider ) && ! empty( $assistant_config['provider'] ) ) {
            $provider = sanitize_key( $assistant_config['provider'] );
        }

        if ( empty( $provider ) ) {
            $settings = WP_MCP_AI_Admin_Settings::get_settings();
            $provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
        }

        $allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );
        if ( ! is_array( $allowed_providers ) ) {
            $allowed_providers = array( 'openai', 'gemini' );
        }

        if ( ! in_array( $provider, $allowed_providers, true ) ) {
            $provider = 'openai';
        }

        $options['provider'] = $provider;

        if ( isset( $options['model'] ) ) {
            $options['model'] = sanitize_text_field( $options['model'] );
        }

        if ( empty( $options['model'] ) && ! empty( $assistant_config['model'] ) ) {
            $options['model'] = sanitize_text_field( $assistant_config['model'] );
        }

        $assistant_temperature = ( isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] )
            ? floatval( $assistant_config['temperature'] )
            : null;

        $has_request_temperature = array_key_exists( 'temperature', $options );
        $raw_temperature         = $has_request_temperature ? $options['temperature'] : null;

        if ( $has_request_temperature && '' !== $raw_temperature && null !== $raw_temperature ) {
            $temperature = floatval( $raw_temperature );

            if ( ( $temperature < 0 || $temperature > 2 ) && null !== $assistant_temperature ) {
                $temperature = $assistant_temperature;
            }
        } elseif ( ! $has_request_temperature && null !== $assistant_temperature ) {
            $temperature = $assistant_temperature;
        } else {
            $temperature = null;
        }

        if ( null !== $temperature ) {
            $options['temperature'] = (float) max( 0, min( 2, $temperature ) );
        } elseif ( $has_request_temperature ) {
            unset( $options['temperature'] );
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
     * Determine whether transcripts should be saved for the current request.
     *
     * @param WP_REST_Request $request REST request instance.
     * @return bool
     */
    protected function should_save_transcript( WP_REST_Request $request ) {
        $value = $request->get_param( 'save_transcript' );

        if ( null === $value ) {
            return true;
        }

        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            return wp_validate_boolean( $value );
        }

        if ( is_numeric( $value ) ) {
            return (bool) (int) $value;
        }

        return ! empty( $value );
    }

    /**
     * Sanitise the session key parameter supplied with chat requests.
     *
     * @param mixed $value Raw session key value.
     * @return string
     */
    protected function sanitize_session_key_param( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        $value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );

        if ( ! is_string( $value ) || '' === $value ) {
            return '';
        }

        $max_length = class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ? WP_MCP_AI_Chat_Transcript_Recorder::MAX_SESSION_KEY_LENGTH : 96;

        return substr( $value, 0, $max_length );
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
     * @return array|WP_Error
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

        $documents             = array();
        $total_chars           = 0;
        $total_bytes           = 0;
        $forbidden_file_ids    = array();
        $encountered_permitted = false;

        $max_total_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_total_bytes', self::MEMORY_MAX_TOTAL_BYTES, $file_ids );
        if ( $max_total_bytes <= 0 ) {
            $max_total_bytes = 0;
        }

        $max_document_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_document_bytes', self::MEMORY_MAX_DOCUMENT_BYTES, $file_ids );
        if ( $max_document_bytes <= 0 ) {
            $max_document_bytes = 0;
        }

        foreach ( $file_ids as $file_id ) {
            $file_id = absint( $file_id );
            if ( ! $file_id ) {
                continue;
            }

            $attachment = get_post( $file_id );
            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                continue;
            }

            if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $file_id ) ) {
                $forbidden_file_ids[] = $file_id;
                continue;
            }

            $encountered_permitted = true;

            $file_path = get_attached_file( $file_id );
            if ( ! $file_path ) {
                continue;
            }

            $file_size = @filesize( $file_path );

            if ( false === $file_size ) {
                return new WP_Error(
                    'wp_mcp_ai_memory_file_size_unknown',
                    __( 'Could not determine the size of a memory file.', 'wp-mcp-ai' ),
                    array(
                        'status'  => 400,
                        'file_id' => $file_id,
                    )
                );
            }

            $max_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_file_bytes', self::MEMORY_MAX_FILE_BYTES, $file_id );

            if ( $file_size > $max_bytes ) {
                /* translators: 1: maximum allowed size in bytes, 2: detected file size in bytes. */
                $message = sprintf(
                    __( 'Memory files must be smaller than %1$s bytes. The requested file is %2$s bytes.', 'wp-mcp-ai' ),
                    number_format_i18n( $max_bytes ),
                    number_format_i18n( $file_size )
                );

                return new WP_Error(
                    'wp_mcp_ai_memory_file_too_large',
                    $message,
                    array(
                        'status'    => 400,
                        'file_id'   => $file_id,
                        'max_bytes' => $max_bytes,
                        'file_size' => (int) $file_size,
                    )
                );
            }

            $mime_type = get_post_mime_type( $file_id );
            $remaining_chars = self::MEMORY_MAX_TOTAL_CHARS - $total_chars;
            if ( $remaining_chars <= 0 ) {
                break;
            }

            $remaining_bytes = $max_total_bytes > 0 ? $max_total_bytes - $total_bytes : PHP_INT_MAX;
            if ( $remaining_bytes <= 0 ) {
                break;
            }

            $document_char_budget = min( self::MEMORY_MAX_DOCUMENT_CHARS, $remaining_chars );
            if ( $document_char_budget <= 0 ) {
                break;
            }

            $document_byte_budget = $max_document_bytes > 0 ? min( $max_document_bytes, $remaining_bytes ) : $remaining_bytes;
            if ( $document_byte_budget <= 0 ) {
                break;
            }

            $bytes_consumed = 0;
            $raw_text       = $this->extract_memory_text( $file_path, $mime_type, $document_char_budget, $document_byte_budget, $bytes_consumed );

            if ( is_wp_error( $raw_text ) ) {
                return $raw_text;
            }

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
            $total_bytes += max( 0, (int) $bytes_consumed );

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

        if ( empty( $documents ) && ! $encountered_permitted && ! empty( $forbidden_file_ids ) ) {
            return new WP_Error(
                'wp_mcp_ai_memory_files_forbidden',
                __( 'You do not have permission to use the requested memory files.', 'wp-mcp-ai' ),
                array(
                    'status'        => 403,
                    'forbidden_ids' => array_values( array_unique( $forbidden_file_ids ) ),
                )
            );
        }

        return $documents;
    }

    /**
     * Extract text content from an attachment.
     *
     * @param string $file_path File system path.
     * @param string $mime_type MIME type.
     * @return string|WP_Error
     */
    protected function extract_memory_text( $file_path, $mime_type, $char_budget = 0, $byte_budget = 0, &$bytes_consumed = 0 ) {
        $char_budget = (int) $char_budget;
        $byte_budget = (int) $byte_budget;
        $bytes_consumed = 0;

        if ( 'application/pdf' === $mime_type ) {
            if ( function_exists( 'wp_read_pdf' ) ) {
                $pdf_content = wp_read_pdf( $file_path );

                if ( is_array( $pdf_content ) && isset( $pdf_content['text'] ) ) {
                    $text = (string) $pdf_content['text'];
                }

                if ( ! isset( $text ) && is_string( $pdf_content ) ) {
                    $text = $pdf_content;
                }

                if ( isset( $text ) ) {
                    if ( $byte_budget > 0 && strlen( $text ) > $byte_budget ) {
                        if ( function_exists( 'mb_strcut' ) ) {
                            $text = mb_strcut( $text, 0, $byte_budget, 'UTF-8' );
                        } else {
                            $text = substr( $text, 0, $byte_budget );
                        }
                    }

                    if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
                        $text = $this->mb_substr( $text, 0, $char_budget );
                    }

                    $bytes_consumed = strlen( $text );

                    return $text;
                }
            }

            return '';
        }

        $docx_mimes = array(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'application/vnd.ms-word.document.macroEnabled.12',
            'application/vnd.ms-word.template.macroEnabled.12',
        );

        if ( in_array( $mime_type, $docx_mimes, true ) ) {
            return $this->extract_docx_text( $file_path, $char_budget, $byte_budget, $bytes_consumed );
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

        $contents = $this->read_file_contents( $file_path, $byte_budget, $bytes_consumed );

        if ( is_wp_error( $contents ) ) {
            return $contents;
        }

        $text = (string) $contents;

        if ( $byte_budget > 0 && strlen( $text ) > $byte_budget ) {
            if ( function_exists( 'mb_strcut' ) ) {
                $text = mb_strcut( $text, 0, $byte_budget, 'UTF-8' );
            } else {
                $text = substr( $text, 0, $byte_budget );
            }
        }

        if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
            $text = $this->mb_substr( $text, 0, $char_budget );
        }

        return $text;
    }

    /**
     * Extract text from a DOCX-based file.
     *
     * @param string $file_path File system path.
     * @param int    $char_budget Maximum characters to extract.
     * @param int    $byte_budget Maximum bytes to read from the underlying XML stream.
     * @param int   &$bytes_consumed Bytes consumed while reading the document.
     * @return string
     */
    protected function extract_docx_text( $file_path, $char_budget = 0, $byte_budget = 0, &$bytes_consumed = 0 ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return '';
        }

        $char_budget   = (int) $char_budget;
        $byte_budget   = (int) $byte_budget;
        $bytes_consumed = 0;

        $stream_path = 'zip://' . $file_path . '#word/document.xml';

        $reader = new XMLReader();
        if ( ! $reader->open( $stream_path, null, LIBXML_NONET ) ) {
            return '';
        }

        $paragraph_open = false;
        $text           = '';

        while ( $reader->read() ) {
            if ( $byte_budget > 0 && $bytes_consumed >= $byte_budget ) {
                break;
            }

            switch ( $reader->nodeType ) {
                case XMLReader::ELEMENT:
                    switch ( $reader->name ) {
                        case 'w:br':
                        case 'w:cr':
                            $text          .= "\n";
                            $bytes_consumed += strlen( "\n" );
                            break;
                        case 'w:tab':
                            $text          .= "\t";
                            $bytes_consumed += strlen( "\t" );
                            break;
                        case 'w:p':
                            $paragraph_open = true;
                            break;
                    }
                    break;
                case XMLReader::END_ELEMENT:
                    if ( 'w:p' === $reader->name && $paragraph_open ) {
                        $paragraph_open = false;
                        $text          .= "\n";
                        $bytes_consumed += strlen( "\n" );
                    }
                    break;
                case XMLReader::TEXT:
                case XMLReader::CDATA:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                case XMLReader::WHITESPACE:
                    $value = $reader->value;
                    if ( '' === $value ) {
                        break;
                    }

                    $value_bytes = strlen( $value );
                    if ( $byte_budget > 0 && $bytes_consumed + $value_bytes > $byte_budget ) {
                        $allowed     = max( 0, $byte_budget - $bytes_consumed );
                        $value       = substr( $value, 0, $allowed );
                        $value_bytes = strlen( $value );
                    }

                    $text          .= $value;
                    $bytes_consumed += $value_bytes;
                    break;
            }

            if ( $char_budget > 0 && $this->mb_strlen( $text ) >= $char_budget ) {
                break;
            }
        }

        $reader->close();

        if ( '' === $text ) {
            return '';
        }

        $text = trim( $text );

        if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
            $text = $this->mb_substr( $text, 0, $char_budget );
        }

        $bytes_consumed = max( $bytes_consumed, strlen( $text ) );

        return $text;
    }

    /**
     * Read a file from disk using the WordPress filesystem when available.
     *
     * @param string $file_path File path.
     * @param int    $byte_budget Maximum bytes to read.
     * @param int   &$bytes_consumed Bytes consumed while reading the file.
     * @return string
     */
    protected function read_file_contents( $file_path, $byte_budget = 0, &$bytes_consumed = 0 ) {
        global $wp_filesystem;

        $byte_budget    = (int) $byte_budget;
        $bytes_consumed = 0;

        if ( $byte_budget <= 0 ) {
            $byte_budget = PHP_INT_MAX;
        }

        $chunk_size = (int) apply_filters( 'wp_mcp_ai_memory_read_chunk_bytes', 1024 * 1024, $file_path );
        if ( $chunk_size <= 0 ) {
            $chunk_size = 1024 * 1024;
        }

        if ( is_readable( $file_path ) ) {
            try {
                $file = new SplFileObject( $file_path, 'rb' );
            } catch ( RuntimeException $exception ) {
                return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'wp-mcp-ai' ) );
            }

            $contents      = '';
            $bytes_allowed = $byte_budget;

            while ( ! $file->eof() && $bytes_allowed > 0 ) {
                $read_length = min( $chunk_size, $bytes_allowed );
                $chunk       = $file->fread( $read_length );

                if ( false === $chunk ) {
                    return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'wp-mcp-ai' ) );
                }

                $length = strlen( $chunk );

                if ( 0 === $length ) {
                    break;
                }

                $contents      .= $chunk;
                $bytes_consumed += $length;
                $bytes_allowed  -= $length;
            }

            return $contents;
        }

        if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->exists( $file_path ) ) {
            $contents = $wp_filesystem->get_contents( $file_path );
            if ( is_string( $contents ) ) {
                if ( $byte_budget < PHP_INT_MAX ) {
                    $contents = substr( $contents, 0, $byte_budget );
                }

                $bytes_consumed = strlen( $contents );

                return $contents;
            }

            return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'wp-mcp-ai' ) );
        }

        return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'wp-mcp-ai' ) );
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
     * Extract a guest token from the incoming request headers or parameters.
     *
     * @param WP_REST_Request $request Request instance.
     * @return string Guest token if supplied, otherwise empty string.
     */
    protected function extract_guest_token( WP_REST_Request $request ) {
        $token = $request->get_header( 'X-WP-MCP-AI-Guest' );

        if ( ! $token ) {
            $token = $request->get_param( 'guest_token' );
        }

        if ( is_string( $token ) ) {
            return trim( $token );
        }

        return '';
    }

    /**
     * Retrieve chat transcript session summaries for a user.
     *
     * @param int $user_id  User identifier.
     * @param int $per_page Number of sessions to return.
     * @param int $page     Results page.
     * @return array|WP_Error
     */
    protected function get_transcript_sessions( $user_id, $per_page, $page ) {
        global $wpdb;

        if ( ! $this->transcript_table_exists() ) {
            return new WP_Error(
                'wp_mcp_ai_transcripts_unavailable',
                __( 'Chat transcripts are not available. Ensure JetEngine Custom Content Types is active and that the /wp-json/jet-cct/ai_chat_transcripts endpoint loads successfully.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $table    = $this->get_transcript_table_name();
        $user_id  = absint( $user_id );
        $per_page = max( 1, (int) $per_page );
        $page     = max( 1, (int) $page );
        $offset   = ( $page - 1 ) * $per_page;

        $query = $wpdb->prepare(
            "SELECT session_key,
                    MIN(request_started_at) AS started_at,
                    MAX(response_completed_at) AS completed_at,
                    MIN(cct_created) AS first_created,
                    MAX(cct_created) AS last_created,
                    MAX(assistant_id) AS assistant_id,
                    MAX(assistant_model) AS assistant_model,
                    COUNT(*) AS turn_count
             FROM {$table}
             WHERE user_id = %d
             GROUP BY session_key
             ORDER BY COALESCE(MAX(CASE WHEN response_completed_at <> '' THEN response_completed_at END), MAX(cct_created), MAX(request_started_at)) DESC, session_key ASC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results( $query, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        $total_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE user_id = %d",
            $user_id
        );

        $total = (int) $wpdb->get_var( $total_query );

        $sessions = array();

        foreach ( $rows as $row ) {
            $sessions[] = $this->format_transcript_session_summary( $row, $user_id );
        }

        return array(
            'items' => $sessions,
            'total' => $total,
        );
    }

    /**
     * Retrieve the full transcript for a specific session.
     *
     * @param int    $user_id     User identifier.
     * @param string $session_key Session key string.
     * @return array|WP_Error
     */
    protected function get_transcript_session( $user_id, $session_key ) {
        global $wpdb;

        $session_key = $this->normalise_transcript_session_key( $session_key );

        if ( '' === $session_key ) {
            return new WP_Error(
                'wp_mcp_ai_transcript_missing',
                __( 'The requested chat transcript could not be found.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        if ( ! $this->transcript_table_exists() ) {
            return new WP_Error(
                'wp_mcp_ai_transcripts_unavailable',
                __( 'Chat transcripts are not available. Ensure JetEngine Custom Content Types is active and that the /wp-json/jet-cct/ai_chat_transcripts endpoint loads successfully.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $table   = $this->get_transcript_table_name();
        $user_id = absint( $user_id );

        $query = $wpdb->prepare(
            "SELECT request_payload,
                    response_payload,
                    metadata,
                    request_started_at,
                    response_completed_at,
                    cct_created,
                    assistant_id,
                    assistant_model,
                    latency_ms
             FROM {$table}
             WHERE session_key = %s AND user_id = %d
             ORDER BY cct_created ASC, id ASC",
            $session_key,
            $user_id
        );

        $rows = $wpdb->get_results( $query, ARRAY_A );

        if ( empty( $rows ) ) {
            return new WP_Error(
                'wp_mcp_ai_transcript_missing',
                __( 'The requested chat transcript could not be found.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $assistant_id    = 0;
        $assistant_model = '';
        $messages         = array();
        $turn_count       = 0;
        $started_at       = '';
        $updated_at       = '';

        foreach ( $rows as $row ) {
            if ( ! $assistant_id && ! empty( $row['assistant_id'] ) ) {
                $assistant_id = (int) $row['assistant_id'];
            }

            if ( '' === $assistant_model && ! empty( $row['assistant_model'] ) ) {
                $assistant_model = sanitize_text_field( $row['assistant_model'] );
            }

            if ( '' === $started_at ) {
                $started_at = $this->format_transcript_timestamp( $row['request_started_at'], $row['cct_created'] );
            }

            $updated_at = $this->format_transcript_timestamp( $row['response_completed_at'], $row['cct_created'] );

            $request_messages  = $this->extract_request_messages( $row );
            $response_messages = $this->extract_response_messages( $row );

            $this->append_new_messages( $messages, $request_messages, $row['request_started_at'], $row['cct_created'] );
            $this->append_new_messages( $messages, $response_messages, $row['response_completed_at'], $row['cct_created'] );

            if ( ! empty( $response_messages ) ) {
                $turn_count += count( $response_messages );
            }
        }

        if ( $turn_count <= 0 ) {
            $turn_count = count( $messages );
        }

        $assistant_title = '';

        if ( $assistant_id ) {
            $assistant_title = get_the_title( $assistant_id );

            if ( ! is_string( $assistant_title ) ) {
                $assistant_title = '';
            } else {
                $assistant_title = wp_strip_all_tags( $assistant_title );
            }
        }

        return array(
            'session_key'     => $session_key,
            'assistant_id'    => $assistant_id,
            'assistant_title' => $assistant_title,
            'assistant_model' => $assistant_model,
            'started_at'      => $started_at,
            'updated_at'      => $updated_at,
            'turn_count'      => $turn_count,
            'messages'        => $messages,
        );
    }

    /**
     * Determine the name of the transcript database table.
     *
     * @return string
     */
    protected function get_transcript_table_name() {
        global $wpdb;

        if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
            return '';
        }

        $slug = WP_MCP_AI_JetEngine_CCT::get_slug();

        if ( '' === $slug ) {
            return '';
        }

        return $wpdb->prefix . 'jet_cct_' . $slug;
    }

    /**
     * Confirm whether the transcript table exists in the database.
     *
     * @return bool
     */
    protected function transcript_table_exists() {
        global $wpdb;

        $table = $this->get_transcript_table_name();

        if ( '' === $table ) {
            return false;
        }

        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        return $result === $table;
    }

    /**
     * Normalise a raw session key into a safe identifier.
     *
     * @param mixed $value Raw session key value.
     * @return string
     */
    protected function normalise_transcript_session_key( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value );

        if ( ! is_string( $value ) || '' === $value ) {
            return '';
        }

        $max = 96;

        if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
            $max = (int) WP_MCP_AI_Chat_Transcript_Recorder::MAX_SESSION_KEY_LENGTH;
        }

        return substr( $value, 0, $max );
    }

    /**
     * Format a timestamp string for API responses.
     *
     * @param string $primary  Primary timestamp string.
     * @param string $fallback Fallback timestamp string.
     * @return string
     */
    protected function format_transcript_timestamp( $primary, $fallback = '' ) {
        $value = is_string( $primary ) && '' !== $primary ? $primary : '';

        if ( '' === $value && is_string( $fallback ) && '' !== $fallback ) {
            $value = $fallback;
        }

        if ( '' === $value ) {
            return '';
        }

        $timestamp = strtotime( $value );

        if ( false === $timestamp ) {
            return '';
        }

        return gmdate( 'c', $timestamp );
    }

    /**
     * Prepare a single session summary entry for REST responses.
     *
     * @param array $row     Database row.
     * @param int   $user_id User identifier.
     * @return array
     */
    protected function format_transcript_session_summary( array $row, $user_id ) {
        $session_key = isset( $row['session_key'] ) ? $this->normalise_transcript_session_key( $row['session_key'] ) : '';
        $assistant_id = isset( $row['assistant_id'] ) ? (int) $row['assistant_id'] : 0;
        $assistant_model = isset( $row['assistant_model'] ) ? sanitize_text_field( $row['assistant_model'] ) : '';
        $assistant_title = '';

        if ( $assistant_id ) {
            $assistant_title = get_the_title( $assistant_id );

            if ( ! is_string( $assistant_title ) ) {
                $assistant_title = '';
            } else {
                $assistant_title = wp_strip_all_tags( $assistant_title );
            }
        }

        $preview = '';

        if ( '' !== $session_key ) {
            $preview = $this->get_session_preview_text( $session_key, $user_id );
        }

        return array(
            'session_key'     => $session_key,
            'assistant_id'    => $assistant_id,
            'assistant_title' => $assistant_title,
            'assistant_model' => $assistant_model,
            'started_at'      => $this->format_transcript_timestamp( isset( $row['started_at'] ) ? $row['started_at'] : '', isset( $row['first_created'] ) ? $row['first_created'] : '' ),
            'completed_at'    => $this->format_transcript_timestamp( isset( $row['completed_at'] ) ? $row['completed_at'] : '', isset( $row['last_created'] ) ? $row['last_created'] : '' ),
            'updated_at'      => $this->format_transcript_timestamp( isset( $row['last_created'] ) ? $row['last_created'] : '', isset( $row['completed_at'] ) ? $row['completed_at'] : '' ),
            'turn_count'      => isset( $row['turn_count'] ) ? (int) $row['turn_count'] : 0,
            'preview'         => $preview,
        );
    }

    /**
     * Extract a preview snippet from the earliest turn in the session.
     *
     * @param string $session_key Session key string.
     * @param int    $user_id     User identifier.
     * @return string
     */
    protected function get_session_preview_text( $session_key, $user_id ) {
        global $wpdb;

        if ( '' === $session_key ) {
            return '';
        }

        $table = $this->get_transcript_table_name();

        $query = $wpdb->prepare(
            "SELECT request_payload
             FROM {$table}
             WHERE session_key = %s AND user_id = %d
             ORDER BY cct_created ASC
             LIMIT 1",
            $session_key,
            absint( $user_id )
        );

        $row = $wpdb->get_row( $query, ARRAY_A );

        if ( empty( $row['request_payload'] ) ) {
            return '';
        }

        $payload = json_decode( $row['request_payload'], true );

        if ( ! is_array( $payload ) || empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) ) {
            return '';
        }

        foreach ( $payload['messages'] as $message ) {
            if ( isset( $message['role'] ) && 'user' === $message['role'] ) {
                $text = $this->prepare_message_text( $message );

                if ( '' !== $text ) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Normalise arbitrary text extracted from transcript payloads.
     *
     * @param string $text Raw text.
     * @return string
     */
    protected function clean_transcript_text( $text ) {
        if ( ! is_string( $text ) ) {
            return '';
        }

        $text = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $text );
        $text = wp_specialchars_decode( $text, ENT_QUOTES );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\r\n|\r/', "\n", $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );

        if ( ! is_string( $text ) ) {
            return '';
        }

        return trim( $text );
    }

    /**
     * Convert structured message content into readable text.
     *
     * @param mixed $content Raw content value.
     * @return string
     */
    protected function normalise_message_content( $content ) {
        if ( is_string( $content ) ) {
            return $this->clean_transcript_text( $content );
        }

        if ( is_array( $content ) ) {
            $parts = array();

            foreach ( $content as $part ) {
                if ( ! is_array( $part ) ) {
                    continue;
                }

                if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
                    $parts[] = $part['text'];
                } elseif ( isset( $part['content'] ) && is_string( $part['content'] ) ) {
                    $parts[] = $part['content'];
                } elseif ( isset( $part['value'] ) && is_string( $part['value'] ) ) {
                    $parts[] = $part['value'];
                }
            }

            if ( ! empty( $parts ) ) {
                return $this->clean_transcript_text( implode( "\n\n", $parts ) );
            }
        }

        if ( is_scalar( $content ) ) {
            return $this->clean_transcript_text( (string) $content );
        }

        return '';
    }

    /**
     * Build a readable string for a message payload.
     *
     * @param array $message Message payload array.
     * @return string
     */
    protected function prepare_message_text( $message ) {
        if ( ! is_array( $message ) ) {
            return '';
        }

        if ( isset( $message['content'] ) ) {
            $text = $this->normalise_message_content( $message['content'] );

            if ( '' !== $text ) {
                return $text;
            }
        }

        if ( isset( $message['text'] ) ) {
            $text = $this->normalise_message_content( $message['text'] );

            if ( '' !== $text ) {
                return $text;
            }
        }

        if ( isset( $message['value'] ) ) {
            $text = $this->normalise_message_content( $message['value'] );

            if ( '' !== $text ) {
                return $text;
            }
        }

        return '';
    }

    /**
     * Extract request messages from a transcript row.
     *
     * @param array $row Database row.
     * @return array
     */
    protected function extract_request_messages( array $row ) {
        if ( empty( $row['request_payload'] ) ) {
            return array();
        }

        $payload = json_decode( $row['request_payload'], true );

        if ( ! is_array( $payload ) || empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) ) {
            return array();
        }

        $messages = array();

        foreach ( $payload['messages'] as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

            if ( '' === $role ) {
                continue;
            }

            $content = $this->prepare_message_text( $message );

            if ( '' === $content && 'tool' !== $role && 'system' !== $role ) {
                continue;
            }

            $messages[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }

        return $messages;
    }

    /**
     * Extract assistant response messages from a transcript row.
     *
     * @param array $row Database row.
     * @return array
     */
    protected function extract_response_messages( array $row ) {
        if ( empty( $row['response_payload'] ) ) {
            return array();
        }

        $payload = json_decode( $row['response_payload'], true );

        if ( ! is_array( $payload ) ) {
            return array();
        }

        $messages = array();

        if ( isset( $payload['choices'] ) && is_array( $payload['choices'] ) ) {
            foreach ( $payload['choices'] as $choice ) {
                if ( empty( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
                    continue;
                }

                $role    = isset( $choice['message']['role'] ) ? sanitize_key( $choice['message']['role'] ) : 'assistant';
                $content = $this->prepare_message_text( $choice['message'] );

                if ( '' !== $content || 'tool' === $role ) {
                    $messages[] = array(
                        'role'    => $role,
                        'content' => $content,
                    );
                }

                if ( ! empty( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
                    foreach ( $choice['message']['tool_calls'] as $tool_call ) {
                        $tool_message = $this->format_tool_call_message( $tool_call );

                        if ( '' !== $tool_message ) {
                            $messages[] = array(
                                'role'    => 'tool',
                                'content' => $tool_message,
                            );
                        }
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Generate a readable message describing a tool call.
     *
     * @param array $tool_call Tool call payload.
     * @return string
     */
    protected function format_tool_call_message( $tool_call ) {
        if ( empty( $tool_call ) || ! is_array( $tool_call ) ) {
            return '';
        }

        $name = '';

        if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
            if ( isset( $tool_call['function']['name'] ) ) {
                $name = sanitize_text_field( $tool_call['function']['name'] );
            }
        } elseif ( isset( $tool_call['name'] ) ) {
            $name = sanitize_text_field( $tool_call['name'] );
        }

        $arguments = '';

        if ( isset( $tool_call['function']['arguments'] ) && is_string( $tool_call['function']['arguments'] ) ) {
            $arguments = $this->clean_transcript_text( $tool_call['function']['arguments'] );
        } elseif ( isset( $tool_call['arguments'] ) && is_string( $tool_call['arguments'] ) ) {
            $arguments = $this->clean_transcript_text( $tool_call['arguments'] );
        }

        $parts = array();

        if ( '' !== $name ) {
            $parts[] = sprintf( __( 'Tool call: %s', 'wp-mcp-ai' ), $name );
        }

        if ( '' !== $arguments ) {
            $parts[] = $arguments;
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return $this->clean_transcript_text( implode( "\n", $parts ) );
    }

    /**
     * Append new messages to the conversation, avoiding duplicates.
     *
     * @param array  $conversation      Current conversation array (passed by reference).
     * @param array  $new_messages      New messages to append.
     * @param string $primary_timestamp Primary timestamp.
     * @param string $fallback_timestamp Fallback timestamp.
     */
    protected function append_new_messages( array &$conversation, array $new_messages, $primary_timestamp, $fallback_timestamp ) {
        if ( empty( $new_messages ) ) {
            return;
        }

        $timestamp      = $this->format_transcript_timestamp( $primary_timestamp, $fallback_timestamp );
        $existing_count = count( $conversation );
        $new_count      = count( $new_messages );
        $position       = 0;

        while ( $position < $existing_count && $position < $new_count ) {
            if ( ! $this->messages_match( $conversation[ $position ], $new_messages[ $position ] ) ) {
                break;
            }

            $position++;
        }

        for ( $index = $position; $index < $new_count; $index++ ) {
            $message = $new_messages[ $index ];
            $message['timestamp'] = $timestamp;
            $conversation[]       = $message;
        }
    }

    /**
     * Compare two message structures.
     *
     * @param array $existing Existing message.
     * @param array $candidate Candidate message.
     * @return bool
     */
    protected function messages_match( $existing, $candidate ) {
        if ( ! is_array( $existing ) || ! is_array( $candidate ) ) {
            return false;
        }

        $existing_role   = isset( $existing['role'] ) ? (string) $existing['role'] : '';
        $candidate_role  = isset( $candidate['role'] ) ? (string) $candidate['role'] : '';
        $existing_text   = isset( $existing['content'] ) ? (string) $existing['content'] : '';
        $candidate_text  = isset( $candidate['content'] ) ? (string) $candidate['content'] : '';

        return $existing_role === $candidate_role && $existing_text === $candidate_text;
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
