<?php
/**
 * Tool that lists files from Google Drive using a service account.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Lists Google Drive files.
 */
class WP_MCP_AI_Tool_List_Google_Drive_Files implements WP_MCP_AI_Tool_Interface {
    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const DRIVE_FILES_ENDPOINT = 'https://www.googleapis.com/drive/v3/files';

    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'list_google_drive_files';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'List Google Drive Files', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Retrieves files from Google Drive using a configured service account.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'query'                        => array(
                    'type'        => 'string',
                    'description' => __( 'Optional Drive query string used to filter results.', 'wp-mcp-ai' ),
                ),
                'folder_id'                   => array(
                    'type'        => 'string',
                    'description' => __( 'Restrict results to files within the supplied parent folder.', 'wp-mcp-ai' ),
                ),
                'page_size'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum number of files to return (1-100).', 'wp-mcp-ai' ),
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 10,
                ),
                'page_token'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Opaque page token from a previous response.', 'wp-mcp-ai' ),
                ),
                'include_trashed'             => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether to include trashed files.', 'wp-mcp-ai' ),
                    'default'     => false,
                ),
                'order_by'                    => array(
                    'type'        => 'string',
                    'description' => __( 'Optional order by expression supported by Google Drive.', 'wp-mcp-ai' ),
                ),
                'fields'                      => array(
                    'description' => __( 'Fields to request from the Drive API. Accepts a comma separated string or array.', 'wp-mcp-ai' ),
                    'oneOf'       => array(
                        array(
                            'type' => 'string',
                        ),
                        array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'string',
                            ),
                        ),
                    ),
                ),
                'supports_all_drives'         => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether to include files from shared drives.', 'wp-mcp-ai' ),
                    'default'     => false,
                ),
                'include_items_from_all_drives' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Include files from shared drives and shortcuts.', 'wp-mcp-ai' ),
                    'default'     => false,
                ),
                'drive_id'                    => array(
                    'type'        => 'string',
                    'description' => __( 'When set, limits the search to the specified shared drive.', 'wp-mcp-ai' ),
                ),
                'spaces'                      => array(
                    'type'        => 'string',
                    'description' => __( 'Optional spaces parameter (e.g. drive, appDataFolder).', 'wp-mcp-ai' ),
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
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list Google Drive files.', 'wp-mcp-ai' ) );
        }

        if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $credentials = apply_filters( 'wp_mcp_ai_google_drive_credentials', array(), $context, $arguments );

        if ( empty( $credentials ) || ! is_array( $credentials ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_credentials',
                __( 'Google Drive credentials have not been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_google_drive' => __( 'Provide Google service account credentials via the wp_mcp_ai_google_drive_credentials filter.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $client_email = isset( $credentials['client_email'] ) ? sanitize_email( $credentials['client_email'] ) : '';
        $private_key  = isset( $credentials['private_key'] ) ? $this->normalize_private_key( $credentials['private_key'] ) : '';
        $delegated_user = isset( $credentials['delegated_user'] ) ? sanitize_email( $credentials['delegated_user'] ) : '';
        $scopes = $this->resolve_scopes( $credentials );

        if ( empty( $client_email ) || empty( $private_key ) ) {
            return new WP_Error( 'wp_mcp_ai_invalid_credentials', __( 'The Google Drive credentials are incomplete.', 'wp-mcp-ai' ) );
        }

        if ( empty( $scopes ) ) {
            $scopes = array( 'https://www.googleapis.com/auth/drive.metadata.readonly' );
        }

        $access_token = $this->request_access_token( $client_email, $private_key, $scopes, $delegated_user, $arguments, $context );

        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $page_size   = isset( $arguments['page_size'] ) ? absint( $arguments['page_size'] ) : 10;
        $page_size   = $page_size > 0 ? min( $page_size, 100 ) : 10;
        $order_by    = isset( $arguments['order_by'] ) ? $this->sanitize_order_by( $arguments['order_by'] ) : '';
        $page_token  = isset( $arguments['page_token'] ) ? sanitize_text_field( $arguments['page_token'] ) : '';
        $include_trashed = isset( $arguments['include_trashed'] ) ? (bool) $arguments['include_trashed'] : false;
        $supports_all_drives = isset( $arguments['supports_all_drives'] ) ? (bool) $arguments['supports_all_drives'] : false;
        $include_items_all_drives = isset( $arguments['include_items_from_all_drives'] ) ? (bool) $arguments['include_items_from_all_drives'] : false;
        $drive_id = isset( $arguments['drive_id'] ) ? sanitize_text_field( $arguments['drive_id'] ) : '';
        $spaces   = isset( $arguments['spaces'] ) ? $this->sanitize_spaces( $arguments['spaces'] ) : '';

        $query = $this->build_query( $arguments, $include_trashed );
        $fields = $this->resolve_fields( $arguments );

        $request_args = array(
            'pageSize' => $page_size,
            'fields'   => $fields,
        );

        if ( $query ) {
            $request_args['q'] = $query;
        }

        if ( $order_by ) {
            $request_args['orderBy'] = $order_by;
        }

        if ( $page_token ) {
            $request_args['pageToken'] = $page_token;
        }

        if ( $supports_all_drives ) {
            $request_args['supportsAllDrives'] = 'true';
        }

        if ( $include_items_all_drives ) {
            $request_args['includeItemsFromAllDrives'] = 'true';
        }

        if ( $drive_id ) {
            $request_args['driveId'] = $drive_id;
            $request_args['corpora'] = 'drive';
        }

        if ( $spaces ) {
            $request_args['spaces'] = $spaces;
        }

        $timeout = apply_filters( 'wp_mcp_ai_google_drive_timeout', 20, $arguments, $context );

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
        );

        $endpoint = add_query_arg( $request_args, self::DRIVE_FILES_ENDPOINT );

        WP_MCP_AI_Logger::log_event(
            'google_drive_list_files_request',
            'Requesting Google Drive file listing.',
            array(
                'query'        => $query,
                'page_size'    => $page_size,
                'order_by'     => $order_by,
                'drive_id'     => $drive_id,
                'has_page_token' => ! empty( $page_token ),
            )
        );

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => $headers,
                'timeout' => $timeout,
            )
        );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'Google Drive request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_google_drive_http_error',
                __( 'Unable to query Google Drive. Try again later.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );

        if ( 200 !== $status ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

            WP_MCP_AI_Logger::log_error(
                'Google Drive responded with an error.',
                array(
                    'status'  => $status,
                    'message' => $message,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_google_drive_error',
                $message ? $message : __( 'The Google Drive API returned an unexpected response.', 'wp-mcp-ai' ),
                array(
                    'status' => $status,
                    'body'   => $data,
                )
            );
        }

        if ( null === $data || ! is_array( $data ) ) {
            WP_MCP_AI_Logger::log_error( 'Google Drive returned an unreadable response.' );

            return new WP_Error( 'wp_mcp_ai_google_drive_invalid_response', __( 'Received an invalid response from Google Drive.', 'wp-mcp-ai' ) );
        }

        $files = isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();
        $next_page_token = isset( $data['nextPageToken'] ) ? sanitize_text_field( $data['nextPageToken'] ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

        return array(
            'files'          => $files,
            'next_page_token' => $next_page_token,
        );
    }

    /**
     * Generate an access token for the configured service account.
     *
     * @param string $client_email   Service account email address.
     * @param string $private_key    Private key in PEM format.
     * @param array  $scopes         Array of scopes.
     * @param string $delegated_user Optional delegated user email.
     * @param array  $arguments      Tool arguments.
     * @param array  $context        Invocation context.
     * @return string|WP_Error
     */
    protected function request_access_token( $client_email, $private_key, array $scopes, $delegated_user, array $arguments, array $context ) {
        $jwt = $this->build_jwt( $client_email, $private_key, $scopes, $delegated_user );

        if ( is_wp_error( $jwt ) ) {
            return $jwt;
        }

        $timeout = apply_filters( 'wp_mcp_ai_google_drive_token_timeout', 20, $arguments, $context );

        $response = wp_remote_post(
            self::TOKEN_ENDPOINT,
            array(
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body'    => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ),
                'timeout' => $timeout,
            )
        );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'Failed to retrieve Google Drive token.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error( 'wp_mcp_ai_google_drive_token_error', __( 'Unable to authenticate with Google Drive.', 'wp-mcp-ai' ), array( 'error' => $response ) );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );

        if ( 200 !== $status || null === $data ) {
            $message = is_array( $data ) && isset( $data['error_description'] ) ? $data['error_description'] : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

            WP_MCP_AI_Logger::log_error(
                'Unexpected response while requesting Google Drive token.',
                array(
                    'status'  => $status,
                    'message' => $message,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_google_drive_token_error',
                $message ? $message : __( 'Google Drive authentication failed.', 'wp-mcp-ai' ),
                array(
                    'status' => $status,
                    'body'   => $data,
                )
            );
        }

        if ( empty( $data['access_token'] ) ) {
            return new WP_Error( 'wp_mcp_ai_google_drive_token_error', __( 'Google Drive did not return an access token.', 'wp-mcp-ai' ), array( 'body' => $data ) );
        }

        return sanitize_text_field( $data['access_token'] );
    }

    /**
     * Build a signed JWT for the OAuth assertion flow.
     *
     * @param string $client_email   Service account email address.
     * @param string $private_key    Private key in PEM format.
     * @param array  $scopes         Requested scopes.
     * @param string $delegated_user Optional delegated user email.
     * @return string|WP_Error
     */
    protected function build_jwt( $client_email, $private_key, array $scopes, $delegated_user ) {
        $now = time();
        $claims = array(
            'iss'   => $client_email,
            'scope' => implode( ' ', $scopes ),
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        );

        if ( $delegated_user ) {
            $claims['sub'] = $delegated_user;
        }

        $header  = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        );

        $segments = array(
            $this->base64_url_encode( wp_json_encode( $header ) ),
            $this->base64_url_encode( wp_json_encode( $claims ) ),
        );

        $signing_input = implode( '.', $segments );

        $private_key_resource = openssl_pkey_get_private( $private_key );

        if ( false === $private_key_resource ) {
            return new WP_Error( 'wp_mcp_ai_google_drive_invalid_key', __( 'Invalid Google Drive private key.', 'wp-mcp-ai' ) );
        }

        $signature = '';
        $result    = openssl_sign( $signing_input, $signature, $private_key_resource, 'sha256WithRSAEncryption' );

        if ( is_resource( $private_key_resource ) && function_exists( 'openssl_pkey_free' ) ) {
            openssl_pkey_free( $private_key_resource );
        }

        if ( false === $result ) {
            return new WP_Error( 'wp_mcp_ai_google_drive_sign_error', __( 'Failed to sign the Google Drive authentication request.', 'wp-mcp-ai' ) );
        }

        $segments[] = $this->base64_url_encode( $signature );

        return implode( '.', $segments );
    }

    /**
     * Normalize private key formatting.
     *
     * @param string $key Raw key.
     * @return string
     */
    protected function normalize_private_key( $key ) {
        if ( ! is_string( $key ) ) {
            return '';
        }

        $key = trim( $key );

        $key = str_replace( '\\n', "\n", $key );

        return $key;
    }

    /**
     * Resolve the scopes array from credentials.
     *
     * @param array $credentials Credential array.
     * @return array
     */
    protected function resolve_scopes( array $credentials ) {
        if ( empty( $credentials['scopes'] ) ) {
            return array();
        }

        $scopes = $credentials['scopes'];

        if ( is_string( $scopes ) ) {
            $scopes = preg_split( '/\s+/', trim( $scopes ) );
        }

        if ( ! is_array( $scopes ) ) {
            return array();
        }

        $sanitised = array();
        foreach ( $scopes as $scope ) {
            $scope = esc_url_raw( trim( $scope ) );
            if ( $scope ) {
                $sanitised[] = $scope;
            }
        }

        return array_values( array_unique( $sanitised ) );
    }

    /**
     * Resolve the fields parameter.
     *
     * @param array $arguments Tool arguments.
     * @return string
     */
    protected function resolve_fields( array $arguments ) {
        $default = 'files(id,name,mimeType,modifiedTime,createdTime,size,webViewLink,webContentLink,iconLink,parents,owners(displayName,emailAddress)),nextPageToken';

        if ( empty( $arguments['fields'] ) ) {
            return $default;
        }

        $fields = $arguments['fields'];

        if ( is_array( $fields ) ) {
            $fields = implode( ',', array_map( array( $this, 'sanitize_field_segment' ), $fields ) );
        } else {
            $fields = $this->sanitize_field_segment( $fields );
        }

        $fields = trim( $fields, " \t\n\r\0\x0B," );

        if ( '' === $fields ) {
            return $default;
        }

        return $fields;
    }

    /**
     * Sanitize a single field segment.
     *
     * @param string $segment Raw segment.
     * @return string
     */
    protected function sanitize_field_segment( $segment ) {
        $segment = is_string( $segment ) ? $segment : '';
        $segment = preg_replace( '/[^a-zA-Z0-9_\.,()\/]/', '', $segment );

        return $segment;
    }

    /**
     * Build the Drive query string.
     *
     * @param array $arguments        Tool arguments.
     * @param bool  $include_trashed  Whether to include trashed files.
     * @return string
     */
    protected function build_query( array $arguments, $include_trashed ) {
        $conditions = array();

        if ( ! empty( $arguments['folder_id'] ) ) {
            $folder_id = sanitize_text_field( $arguments['folder_id'] );
            if ( $folder_id ) {
                $conditions[] = sprintf( '\'%s\' in parents', $this->escape_drive_value( $folder_id ) );
            }
        }

        if ( ! $include_trashed ) {
            $conditions[] = 'trashed = false';
        }

        if ( ! empty( $arguments['query'] ) ) {
            $query = $this->sanitize_drive_query( $arguments['query'] );
            if ( $query ) {
                $conditions[] = '(' . $query . ')';
            }
        }

        if ( empty( $conditions ) ) {
            return '';
        }

        return implode( ' and ', $conditions );
    }

    /**
     * Escape Drive query values.
     *
     * @param string $value Raw value.
     * @return string
     */
    protected function escape_drive_value( $value ) {
        $value = str_replace( "'", "\\'", $value );

        return $value;
    }

    /**
     * Sanitize a Drive query string provided by the user.
     *
     * @param string $query Raw query.
     * @return string
     */
    protected function sanitize_drive_query( $query ) {
        $query = is_string( $query ) ? $query : '';
        $query = trim( $query );
        $query = str_replace( array( "\r", "\n" ), ' ', $query );

        return $query;
    }

    /**
     * Sanitize the orderBy parameter.
     *
     * @param string $order Raw order.
     * @return string
     */
    protected function sanitize_order_by( $order ) {
        $order = is_string( $order ) ? $order : '';
        $order = trim( $order );
        $order = preg_replace( '/[^a-zA-Z0-9_\s,]/', '', $order );

        return $order;
    }

    /**
     * Sanitize the spaces argument.
     *
     * @param string $spaces Raw spaces value.
     * @return string
     */
    protected function sanitize_spaces( $spaces ) {
        $spaces = is_string( $spaces ) ? $spaces : '';
        $spaces = trim( $spaces );
        $spaces = preg_replace( '/[^a-zA-Z0-9_,]/', '', $spaces );

        return $spaces;
    }

    /**
     * Base64 URL encode helper.
     *
     * @param string $value Raw value.
     * @return string
     */
    protected function base64_url_encode( $value ) {
        $encoded = base64_encode( $value );
        $encoded = str_replace( array( '+', '/' ), array( '-', '_' ), $encoded );

        return rtrim( $encoded, '=' );
    }
}
