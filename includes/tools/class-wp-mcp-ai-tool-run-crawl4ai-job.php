<?php
/**
 * Tool that submits Crawl4AI crawl jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides an integration with the Crawl4AI REST API.
 */
class WP_MCP_AI_Tool_Run_Crawl4AI_Job implements WP_MCP_AI_Tool_Interface {
    const DEFAULT_WAIT_TIMEOUT   = 120;
    const DEFAULT_POLL_INTERVAL  = 3;

    /**
     * Determine whether the Crawl4AI integration is available.
     *
     * @return bool
     */
    public static function is_available() {
        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $base_url = self::resolve_base_url( $settings );

        if ( '' !== $base_url ) {
            return true;
        }

        /**
         * Filters whether the built-in crawler should be exposed.
         *
         * Returning false here disables the fallback entirely which effectively
         * mirrors the previous behaviour where an external Crawl4AI endpoint
         * was mandatory.
         *
         * @param bool  $enabled  Whether the local crawler is available.
         * @param array $settings Plugin settings array.
         */
        $local_enabled = apply_filters( 'wp_mcp_ai_crawl4ai_local_enabled', true, $settings );

        return (bool) $local_enabled;
    }

    /**
     * Message explaining why the tool is unavailable.
     *
     * @return string
     */
    public static function get_unavailable_reason() {
        return __( 'The Crawl4AI tool is disabled on this site.', 'wp-mcp-ai' );
    }

    /**
     * Resolve the configured Crawl4AI base URL.
     *
     * @param array $settings Plugin settings array.
     * @param array $context  Optional execution context passed to the tool.
     * @return string
     */
    protected static function resolve_base_url( array $settings, array $context = array() ) {
        $base_url = '';

        if ( isset( $settings['crawl4ai_base_url'] ) ) {
            $base_url = (string) $settings['crawl4ai_base_url'];
        }

        /**
         * Filters the Crawl4AI base URL used by the tool.
         *
         * This allows environments to provide a base URL dynamically (for example,
         * from environment variables) when the admin setting is left blank.
         *
         * @param string $base_url Base URL configured in the settings.
         * @param array  $settings Entire WP MCP AI settings array.
         * @param array  $context  Execution context provided to the tool.
         */
        $base_url = apply_filters( 'wp_mcp_ai_crawl4ai_base_url', $base_url, $settings, $context );

        if ( ! is_string( $base_url ) ) {
            return '';
        }

        $sanitised = esc_url_raw( trim( $base_url ) );

        if ( ! $sanitised ) {
            return '';
        }

        return untrailingslashit( $sanitised );
    }

    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'run_crawl4ai_job';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Run Crawl4AI Job', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Submits a Crawl4AI crawl request and optionally waits for the results.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'urls'               => array(
                    'type'        => 'array',
                    'description' => __( 'List of URLs that should be crawled.', 'wp-mcp-ai' ),
                    'items'       => array(
                        'type' => 'string',
                        'format' => 'uri',
                    ),
                    'minItems'    => 1,
                ),
                'url'                => array(
                    'type'        => 'string',
                    'description' => __( 'Convenience field for a single URL when `urls` is not provided.', 'wp-mcp-ai' ),
                ),
                'priority'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Optional job priority forwarded to Crawl4AI.', 'wp-mcp-ai' ),
                    'minimum'     => 0,
                    'maximum'     => 100,
                ),
                'options'            => array(
                    'type'        => 'object',
                    'description' => __( 'Additional Crawl4AI options (for example, crawler configuration or hook overrides).', 'wp-mcp-ai' ),
                    'additionalProperties' => true,
                ),
                'wait_for_completion' => array(
                    'type'        => 'boolean',
                    'description' => __( 'When true, the tool polls Crawl4AI until the job finishes.', 'wp-mcp-ai' ),
                    'default'     => false,
                ),
                'poll_interval'      => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of seconds to wait between polling attempts when waiting for completion.', 'wp-mcp-ai' ),
                    'minimum'     => 0,
                    'maximum'     => 30,
                    'default'     => self::DEFAULT_POLL_INTERVAL,
                ),
                'timeout'            => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum number of seconds to wait for the job to finish when polling.', 'wp-mcp-ai' ),
                    'minimum'     => 0,
                    'maximum'     => 600,
                    'default'     => self::DEFAULT_WAIT_TIMEOUT,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'wp_mcp_ai_crawl4ai_unavailable', __( 'Crawl4AI is not available on this site.', 'wp-mcp-ai' ) );
        }

        $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

        if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run Crawl4AI jobs.', 'wp-mcp-ai' ) );
        }

        if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $urls = $this->extract_urls( $arguments );
        if ( is_wp_error( $urls ) ) {
            return $urls;
        }

        $payload = array(
            'urls' => $urls,
        );

        if ( isset( $arguments['priority'] ) ) {
            $priority = absint( $arguments['priority'] );
            $payload['priority'] = max( 0, min( 100, $priority ) );
        }

        if ( isset( $arguments['options'] ) ) {
            if ( ! is_array( $arguments['options'] ) ) {
                return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_options', __( 'Crawl4AI options must be provided as an object.', 'wp-mcp-ai' ) );
            }

            $payload = array_merge( $payload, $this->sanitize_options( $arguments['options'] ) );
        }

        $payload = apply_filters( 'wp_mcp_ai_crawl4ai_payload', $payload, $arguments, $context );

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $base_url = $this->get_base_url( $settings, $context );

        if ( '' !== $base_url ) {
            return $this->execute_remote_crawl( $payload, $arguments, $context, $settings, $base_url );
        }

        return $this->execute_local_crawl( $payload, $arguments, $context, $settings );
    }

    /**
     * Extract and sanitise URLs from the provided arguments.
     *
     * @param array $arguments Tool arguments.
     * @return array|WP_Error
     */
    protected function extract_urls( array $arguments ) {
        $urls = array();

        if ( isset( $arguments['urls'] ) ) {
            if ( ! is_array( $arguments['urls'] ) ) {
                return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_urls', __( 'The Crawl4AI tool expects the `urls` parameter to be an array.', 'wp-mcp-ai' ) );
            }

            foreach ( $arguments['urls'] as $url ) {
                $sanitised = $this->sanitize_url( $url );
                if ( $sanitised ) {
                    $urls[] = $sanitised;
                }
            }
        }

        if ( empty( $urls ) && ! empty( $arguments['url'] ) ) {
            $single = $this->sanitize_url( $arguments['url'] );
            if ( $single ) {
                $urls[] = $single;
            }
        }

        $urls = array_values( array_unique( $urls ) );

        if ( empty( $urls ) ) {
            return new WP_Error( 'wp_mcp_ai_crawl4ai_missing_urls', __( 'At least one URL must be provided to Crawl4AI.', 'wp-mcp-ai' ) );
        }

        return $urls;
    }

    /**
     * Sanitise a URL string.
     *
     * @param mixed $value Potential URL value.
     * @return string
     */
    protected function sanitize_url( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }

        $sanitised = esc_url_raw( $value );

        return $sanitised ? $sanitised : '';
    }

    /**
     * Sanitise arbitrary Crawl4AI options provided by the caller.
     *
     * @param array $options Options array supplied by the assistant.
     * @return array
     */
    protected function sanitize_options( array $options ) {
        $sanitised = array();

        foreach ( $options as $key => $value ) {
            $clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

            if ( '' === $clean_key && ! is_int( $key ) ) {
                continue;
            }

            $sanitised[ $clean_key ] = $this->sanitize_option_value( $value );
        }

        return $sanitised;
    }

    /**
     * Sanitise a single option value.
     *
     * @param mixed $value Value to sanitise.
     * @return mixed
     */
    protected function sanitize_option_value( $value ) {
        if ( is_array( $value ) ) {
            $sanitised = array();

            foreach ( $value as $key => $nested_value ) {
                $clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

                if ( '' === $clean_key && ! is_int( $key ) ) {
                    continue;
                }

                $sanitised[ $clean_key ] = $this->sanitize_option_value( $nested_value );
            }

            return $sanitised;
        }

        if ( is_string( $value ) ) {
            return sanitize_textarea_field( $value );
        }

        if ( is_bool( $value ) ) {
            return (bool) $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return 0 + $value;
        }

        if ( null === $value ) {
            return null;
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Retrieve the configured Crawl4AI base URL.
     *
     * @param array $settings Plugin settings array.
     * @return string
     */
    protected function get_base_url( array $settings, array $context = array() ) {
        return self::resolve_base_url( $settings, $context );
    }

    /**
     * Build the HTTP headers for Crawl4AI requests.
     *
     * @param array $settings Plugin settings array.
     * @param array $context  Execution context.
     * @return array
     */
    protected function build_headers( array $settings, array $context ) {
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        if ( ! empty( $settings['crawl4ai_api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $settings['crawl4ai_api_key'];
        }

        /**
         * Allow plugins to filter the headers sent to Crawl4AI.
         */
        return apply_filters( 'wp_mcp_ai_crawl4ai_headers', $headers, $settings, $context );
    }

    /**
     * Determine the HTTP timeout for Crawl4AI requests.
     *
     * @param array $settings Plugin settings array.
     * @return int
     */
    protected function get_request_timeout( array $settings ) {
        $timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

        return max( 5, $timeout );
    }

    /**
     * Determine whether the tool should wait for job completion.
     *
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @return bool
     */
    protected function should_wait_for_results( array $arguments, array $context ) {
        if ( isset( $arguments['wait_for_completion'] ) ) {
            return (bool) $arguments['wait_for_completion'];
        }

        if ( isset( $context['assistant_config']['crawl4ai_wait_for_completion'] ) ) {
            return (bool) $context['assistant_config']['crawl4ai_wait_for_completion'];
        }

        return false;
    }

    /**
     * Retrieve the polling timeout in seconds.
     *
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @return int
     */
    protected function get_wait_timeout( array $arguments, array $context ) {
        if ( isset( $arguments['timeout'] ) ) {
            return max( 0, min( 600, absint( $arguments['timeout'] ) ) );
        }

        if ( isset( $context['assistant_config']['crawl4ai_timeout'] ) ) {
            return max( 0, min( 600, absint( $context['assistant_config']['crawl4ai_timeout'] ) ) );
        }

        return self::DEFAULT_WAIT_TIMEOUT;
    }

    /**
     * Retrieve the polling interval in seconds.
     *
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @return int
     */
    protected function get_poll_interval( array $arguments, array $context ) {
        if ( isset( $arguments['poll_interval'] ) ) {
            return max( 0, min( 30, absint( $arguments['poll_interval'] ) ) );
        }

        if ( isset( $context['assistant_config']['crawl4ai_poll_interval'] ) ) {
            return max( 0, min( 30, absint( $context['assistant_config']['crawl4ai_poll_interval'] ) ) );
        }

        return self::DEFAULT_POLL_INTERVAL;
    }

    /**
     * Build request arguments for local crawls.
     *
     * @param array $settings Plugin settings array.
     * @param array $context  Execution context.
     * @param array $arguments Tool arguments.
     * @return array
     */
    protected function build_local_request_args( array $settings, array $context, array $arguments ) {
        $language = get_bloginfo( 'language' );
        $headers  = array(
            'Accept'         => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language'=> $language ? str_replace( '_', '-', $language ) : 'en-US,en;q=0.9',
            'User-Agent'     => $this->get_local_user_agent( $settings, $context ),
        );

        $args = array(
            'headers'      => $headers,
            'redirection'  => 5,
            'sslverify'    => true,
            'decompress'   => true,
        );

        /**
         * Filters the HTTP request arguments used by the local crawler.
         */
        return apply_filters( 'wp_mcp_ai_crawl4ai_local_request_args', $args, $settings, $context, $arguments );
    }

    /**
     * Determine the default User-Agent string for local crawl requests.
     *
     * @param array $settings Plugin settings array.
     * @param array $context  Execution context.
     * @return string
     */
    protected function get_local_user_agent( array $settings, array $context ) {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url( '/' );

        $user_agent = sprintf( 'WP-MCP-AI-Crawler/1.0 (+%s)', $site_url );

        if ( $site_name ) {
            $user_agent = sprintf( 'WP-MCP-AI-Crawler/1.0 (%s; +%s)', sanitize_text_field( $site_name ), $site_url );
        }

        /**
         * Filters the User-Agent string sent by the local crawler.
         */
        return apply_filters( 'wp_mcp_ai_crawl4ai_local_user_agent', $user_agent, $settings, $context );
    }

    /**
     * Build a structured result for a locally crawled URL.
     *
     * @param string $url      Requested URL.
     * @param array  $response HTTP response array.
     * @param array  $payload  Prepared payload data.
     * @param array  $settings Plugin settings array.
     * @param array  $context  Execution context.
     * @return array
     */
    protected function build_local_result( $url, $response, array $payload, array $settings, array $context ) {
        $status_code  = wp_remote_retrieve_response_code( $response );
        $body         = wp_remote_retrieve_body( $response );
        $headers      = wp_remote_retrieve_headers( $response );
        $header_array = $this->normalise_headers( $headers );
        $content_type = isset( $header_array['content-type'] ) ? $header_array['content-type'] : '';
        $charset      = isset( $header_array['content-type'] ) ? $this->detect_charset_from_content_type( $header_array['content-type'] ) : '';

        if ( $charset && function_exists( 'mb_convert_encoding' ) ) {
            $body = mb_convert_encoding( $body, 'UTF-8', $charset );
        }

        $result = array(
            'url'            => $url,
            'status_code'    => $status_code,
            'content_type'   => $content_type,
            'content_length' => strlen( (string) $body ),
            'retrieved_at'   => current_time( 'mysql', true ),
            'html'           => '',
            'markdown'       => '',
            'text'           => '',
            'metadata'       => array(
                'headers' => $header_array,
            ),
        );

        if ( $this->should_treat_as_html( $content_type ) ) {
            $result['html']     = $body;
            $result['markdown'] = $this->convert_html_to_markdown( $body );
            $result['text']     = $this->convert_html_to_text( $body );
        } elseif ( $this->should_treat_as_json( $content_type, $body ) ) {
            $decoded = json_decode( $body, true );
            if ( null !== $decoded ) {
                $result['markdown'] = "```json\n" . wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n```";
                $result['text']     = wp_json_encode( $decoded );
            } else {
                $result['text'] = trim( (string) $body );
            }
        } elseif ( $this->should_treat_as_text( $content_type ) ) {
            $result['text']     = trim( (string) $body );
            $result['markdown'] = $result['text'];
        }

        return $result;
    }

    /**
     * Normalise HTTP headers from the WordPress HTTP API into an array of lowercase keys.
     *
     * @param array|Requests_Utility_CaseInsensitiveDictionary $headers HTTP headers.
     * @return array
     */
    protected function normalise_headers( $headers ) {
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        $normalised = array();

        if ( is_array( $headers ) ) {
            foreach ( $headers as $key => $value ) {
                $normalised[ strtolower( (string) $key ) ] = is_array( $value ) ? array_map( 'trim', $value ) : trim( (string) $value );
            }
        }

        return $normalised;
    }

    /**
     * Detect the charset from a content type header string.
     *
     * @param string $content_type Content type header.
     * @return string
     */
    protected function detect_charset_from_content_type( $content_type ) {
        if ( preg_match( '/charset=([^;]+)/i', $content_type, $matches ) ) {
            return trim( $matches[1] );
        }

        return '';
    }

    /**
     * Determine if the response should be parsed as HTML.
     *
     * @param string $content_type Content type header.
     * @return bool
     */
    protected function should_treat_as_html( $content_type ) {
        if ( empty( $content_type ) ) {
            return true;
        }

        return false !== strpos( strtolower( $content_type ), 'text/html' ) || false !== strpos( strtolower( $content_type ), 'application/xhtml+xml' );
    }

    /**
     * Determine if the response should be parsed as JSON.
     *
     * @param string $content_type Content type header.
     * @param string $body         Response body.
     * @return bool
     */
    protected function should_treat_as_json( $content_type, $body ) {
        if ( false !== strpos( strtolower( $content_type ), 'application/json' ) ) {
            return true;
        }

        $trimmed = trim( (string) $body );
        return ( '' !== $trimmed ) && ( '{' === $trimmed[0] || '[' === $trimmed[0] );
    }

    /**
     * Determine if the response should be treated as plain text.
     *
     * @param string $content_type Content type header.
     * @return bool
     */
    protected function should_treat_as_text( $content_type ) {
        return false !== strpos( strtolower( $content_type ), 'text/plain' );
    }

    /**
     * Convert HTML to Markdown.
     *
     * @param string $html HTML content.
     * @return string
     */
    protected function convert_html_to_markdown( $html ) {
        $html = (string) $html;

        if ( '' === trim( $html ) ) {
            return '';
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return $this->convert_html_to_text( $html );
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
        libxml_clear_errors();

        if ( ! $loaded ) {
            return $this->convert_html_to_text( $html );
        }

        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        if ( ! $body ) {
            return $this->convert_html_to_text( $html );
        }

        $markdown = '';

        foreach ( $body->childNodes as $child ) {
            $markdown .= $this->render_dom_node_to_markdown( $child, 0 );
        }

        $markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
        $markdown = preg_replace( "/[ \t]+\n/", "\n", $markdown );

        return trim( $markdown );
    }

    /**
     * Render a DOM node to Markdown.
     *
     * @param DOMNode $node       DOM node.
     * @param int     $list_depth Current list depth.
     * @return string
     */
    protected function render_dom_node_to_markdown( $node, $list_depth = 0 ) {
        if ( $node instanceof DOMText ) {
            $text = preg_replace( '/\s+/u', ' ', $node->wholeText );
            return $text;
        }

        if ( ! $node instanceof DOMElement ) {
            return '';
        }

        $tag      = strtolower( $node->tagName );
        $contents = $this->render_dom_children_to_markdown( $node, $list_depth );

        switch ( $tag ) {
            case 'h1':
                return "\n\n# " . trim( $contents ) . "\n\n";
            case 'h2':
                return "\n\n## " . trim( $contents ) . "\n\n";
            case 'h3':
                return "\n\n### " . trim( $contents ) . "\n\n";
            case 'h4':
                return "\n\n#### " . trim( $contents ) . "\n\n";
            case 'h5':
                return "\n\n##### " . trim( $contents ) . "\n\n";
            case 'h6':
                return "\n\n###### " . trim( $contents ) . "\n\n";
            case 'p':
                return "\n\n" . trim( $contents ) . "\n\n";
            case 'br':
                return "  \n";
            case 'strong':
            case 'b':
                return '**' . trim( $contents ) . '**';
            case 'em':
            case 'i':
                return '_' . trim( $contents ) . '_';
            case 'code':
                if ( strtolower( $node->parentNode->nodeName ) === 'pre' ) {
                    return $contents;
                }
                return '`' . trim( $contents ) . '`';
            case 'pre':
                $text = trim( $contents );
                return "\n\n```\n" . $text . "\n```\n\n";
            case 'a':
                $href = $node->getAttribute( 'href' );
                $href = esc_url_raw( $href );
                $label = trim( $contents );
                if ( '' === $label ) {
                    $label = $href;
                }
                if ( '' === $href ) {
                    return $label;
                }
                return '[' . $label . '](' . $href . ')';
            case 'ul':
            case 'ol':
                $output = "\n";
                foreach ( $node->childNodes as $child ) {
                    $output .= $this->render_dom_node_to_markdown( $child, $list_depth + 1 );
                }
                return $output . "\n";
            case 'li':
                $content = trim( $contents );
                if ( '' === $content ) {
                    return '';
                }
                $indent = str_repeat( '    ', max( 0, $list_depth - 1 ) );
                $ordered = $node->parentNode && 'ol' === strtolower( $node->parentNode->nodeName );
                $marker  = $ordered ? '1.' : '-';
                $content = preg_replace( '/\n+/', "\n" . $indent . '    ', $content );
                return $indent . $marker . ' ' . $content . "\n";
            case 'img':
                $alt = trim( $node->getAttribute( 'alt' ) );
                $src = esc_url_raw( $node->getAttribute( 'src' ) );
                if ( ! $src ) {
                    return '';
                }
                return '![' . $alt . '](' . $src . ')';
            default:
                return $contents;
        }
    }

    /**
     * Render the child nodes of a DOM element to Markdown.
     *
     * @param DOMNode $node       DOM element.
     * @param int     $list_depth Current list depth.
     * @return string
     */
    protected function render_dom_children_to_markdown( $node, $list_depth ) {
        $buffer = '';

        foreach ( $node->childNodes as $child ) {
            $buffer .= $this->render_dom_node_to_markdown( $child, $list_depth );
        }

        return $buffer;
    }

    /**
     * Convert HTML content to plain text.
     *
     * @param string $html HTML markup.
     * @return string
     */
    protected function convert_html_to_text( $html ) {
        $text = wp_strip_all_tags( (string) $html );
        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( $text );
    }

    /**
     * Decode the Crawl4AI HTTP response body.
     *
     * @param array $response Response array from wp_remote_*.
     * @return array|WP_Error
     */
    protected function decode_response( $response ) {
        $body = wp_remote_retrieve_body( $response );

        if ( '' === $body ) {
            return new WP_Error( 'wp_mcp_ai_crawl4ai_empty_response', __( 'Crawl4AI returned an empty response.', 'wp-mcp-ai' ) );
        }

        $decoded = json_decode( $body, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            WP_MCP_AI_Logger::log_error( 'Failed to decode Crawl4AI response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_response', __( 'Crawl4AI returned malformed JSON.', 'wp-mcp-ai' ) );
        }

        return $decoded;
    }

    /**
     * Create a human readable error message from a Crawl4AI response.
     *
     * @param array $decoded Decoded response body.
     * @return string
     */
    protected function build_error_from_response( array $decoded ) {
        if ( isset( $decoded['error'] ) ) {
            if ( is_string( $decoded['error'] ) ) {
                return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error'] );
            }

            if ( is_array( $decoded['error'] ) ) {
                if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
                    return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['message'] );
                }

                if ( isset( $decoded['error']['detail'] ) && is_string( $decoded['error']['detail'] ) ) {
                    return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['detail'] );
                }
            }
        }

        if ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
            return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['detail'] );
        }

        if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
            return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['message'] );
        }

        return __( 'Crawl4AI returned an unexpected response.', 'wp-mcp-ai' );
    }

    /**
     * Normalise a Crawl4AI response into a consistent structure for the assistant.
     *
     * @param array $decoded Decoded response body.
     * @return array
     */
    protected function format_response( array $decoded ) {
        $status = '';

        if ( isset( $decoded['status'] ) && is_string( $decoded['status'] ) ) {
            $status = sanitize_key( $decoded['status'] );
        } elseif ( isset( $decoded['state'] ) && is_string( $decoded['state'] ) ) {
            $status = sanitize_key( $decoded['state'] );
        } elseif ( ! empty( $decoded['results'] ) ) {
            $status = 'completed';
        } elseif ( isset( $decoded['task_id'] ) ) {
            $status = 'pending';
        }

        $task_id = '';
        if ( isset( $decoded['task_id'] ) && is_scalar( $decoded['task_id'] ) ) {
            $task_id = sanitize_text_field( (string) $decoded['task_id'] );
        }

        $results = array();
        if ( isset( $decoded['results'] ) && is_array( $decoded['results'] ) ) {
            $results = $decoded['results'];
        }

        $metadata = array();
        if ( isset( $decoded['metadata'] ) && is_array( $decoded['metadata'] ) ) {
            $metadata = $decoded['metadata'];
        }

        return array(
            'status'   => $status,
            'task_id'  => $task_id,
            'results'  => $results,
            'metadata' => $metadata,
            'raw'      => $decoded,
        );
    }

    /**
     * Poll Crawl4AI for job completion.
     *
     * @param string $task_id       Task identifier returned by Crawl4AI.
     * @param string $base_url      Crawl4AI base URL.
     * @param array  $headers       Request headers.
     * @param int    $timeout       Maximum seconds to wait.
     * @param int    $poll_interval Seconds between polls.
     * @param int    $request_timeout HTTP timeout for individual poll requests.
     * @return array|WP_Error
     */
    protected function poll_for_results( $task_id, $base_url, array $headers, $timeout, $poll_interval, $request_timeout ) {
        $endpoint = trailingslashit( $base_url ) . 'task/' . rawurlencode( $task_id );
        $deadline = time() + max( 0, (int) $timeout );

        do {
            WP_MCP_AI_Logger::log_event(
                'crawl4ai_poll_request',
                'Polling Crawl4AI for task status.',
                array(
                    'task_id' => $task_id,
                )
            );

            $response = wp_remote_get(
                $endpoint,
                array(
                    'headers' => $headers,
                    'timeout' => max( 5, $request_timeout ),
                )
            );

            if ( is_wp_error( $response ) ) {
                WP_MCP_AI_Logger::log_error( 'Crawl4AI polling request failed.', array( 'error' => $response->get_error_message() ) );

                return new WP_Error(
                    'wp_mcp_ai_crawl4ai_poll_error',
                    __( 'The Crawl4AI status check failed.', 'wp-mcp-ai' ),
                    array( 'error' => $response )
                );
            }

            $decoded = $this->decode_response( $response );
            if ( is_wp_error( $decoded ) ) {
                return $decoded;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code < 200 || $status_code >= 300 ) {
                $message = $this->build_error_from_response( $decoded );

                return new WP_Error(
                    'wp_mcp_ai_crawl4ai_poll_http_error',
                    $message,
                    array(
                        'status' => $status_code,
                        'body'   => $decoded,
                    )
                );
            }

            if ( isset( $decoded['status'] ) && is_string( $decoded['status'] ) ) {
                $status = strtolower( $decoded['status'] );
                if ( in_array( $status, array( 'failed', 'error' ), true ) ) {
                    $message = $this->build_error_from_response( $decoded );

                    return new WP_Error( 'wp_mcp_ai_crawl4ai_failed', $message, array( 'body' => $decoded ) );
                }
            }

            if ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) {
                $message = $this->build_error_from_response( $decoded );

                return new WP_Error( 'wp_mcp_ai_crawl4ai_failed', $message, array( 'body' => $decoded ) );
            }

            $formatted          = $this->format_response( $decoded );
            $formatted['task_id'] = $task_id;

            if ( ! empty( $formatted['results'] ) ) {
                return $formatted;
            }

            if ( time() >= $deadline ) {
                break;
            }

            if ( $poll_interval > 0 ) {
                $this->sleep( $poll_interval );
            }
        } while ( time() <= $deadline );

        return new WP_Error( 'wp_mcp_ai_crawl4ai_timeout', __( 'Timed out while waiting for Crawl4AI to finish the job.', 'wp-mcp-ai' ) );
    }

    /**
     * Sleep for a number of seconds.
     *
     * @param int $seconds Seconds to sleep.
     */
    protected function sleep( $seconds ) {
        if ( function_exists( 'wp_sleep' ) ) {
            wp_sleep( $seconds );
        } else {
            sleep( $seconds );
        }
    }

    /**
     * Reduce payload noise before logging.
     *
     * @param array $payload Payload that will be logged.
     * @return array
     */
    protected function get_log_safe_payload( array $payload ) {
        $log_payload = $payload;

        if ( isset( $log_payload['urls'] ) && is_array( $log_payload['urls'] ) ) {
            $log_payload['urls'] = array_slice( $log_payload['urls'], 0, 3 );
            if ( count( $payload['urls'] ) > 3 ) {
                $log_payload['urls'][] = '…';
            }
        }

        return $log_payload;
    }
    /**
     * Execute a crawl through the remote Crawl4AI service.
     *
     * @param array  $payload   Prepared payload array.
     * @param array  $arguments Tool arguments.
     * @param array  $context   Execution context.
     * @param array  $settings  Plugin settings array.
     * @param string $base_url  Crawl4AI base URL.
     * @return array|WP_Error
     */
    protected function execute_remote_crawl( array $payload, array $arguments, array $context, array $settings, $base_url ) {
        $encoded_payload = wp_json_encode( $payload );
        if ( false === $encoded_payload ) {
            return new WP_Error( 'wp_mcp_ai_crawl4ai_encoding_error', __( 'Failed to encode the Crawl4AI request payload.', 'wp-mcp-ai' ) );
        }

        $headers   = $this->build_headers( $settings, $context );
        $timeout   = $this->get_request_timeout( $settings );
        $crawl_url = trailingslashit( $base_url ) . 'crawl';

        $request_args = array(
            'headers' => $headers,
            'timeout' => $timeout,
            'body'    => $encoded_payload,
        );

        WP_MCP_AI_Logger::log_event(
            'crawl4ai_request',
            'Sending Crawl4AI crawl request.',
            array(
                'endpoint' => $crawl_url,
                'payload'  => $this->get_log_safe_payload( $payload ),
            )
        );

        $response = wp_remote_post( $crawl_url, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'Crawl4AI request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_crawl4ai_http_error',
                __( 'The Crawl4AI request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $decoded = $this->decode_response( $response );
        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $message = $this->build_error_from_response( $decoded );

            WP_MCP_AI_Logger::log_error(
                'Crawl4AI returned an error response.',
                array(
                    'status' => $status_code,
                    'body'   => $decoded,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_crawl4ai_api_error',
                $message,
                array(
                    'status' => $status_code,
                    'body'   => $decoded,
                )
            );
        }

        if ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) {
            $message = $this->build_error_from_response( $decoded );

            WP_MCP_AI_Logger::log_error(
                'Crawl4AI reported an error.',
                array(
                    'status' => $status_code,
                    'body'   => $decoded,
                )
            );

            return new WP_Error( 'wp_mcp_ai_crawl4ai_error', $message, array( 'body' => $decoded ) );
        }

        $formatted = $this->format_response( $decoded );

        if ( $this->should_wait_for_results( $arguments, $context ) && ! empty( $formatted['task_id'] ) && empty( $formatted['results'] ) ) {
            $wait_timeout  = $this->get_wait_timeout( $arguments, $context );
            $poll_interval = $this->get_poll_interval( $arguments, $context );

            $formatted = $this->poll_for_results( $formatted['task_id'], $base_url, $headers, $wait_timeout, $poll_interval, $timeout );

            if ( is_wp_error( $formatted ) ) {
                return $formatted;
            }
        }

        WP_MCP_AI_Logger::log_event(
            'crawl4ai_response',
            'Crawl4AI request completed.',
            array(
                'status'  => $formatted['status'],
                'task_id' => $formatted['task_id'],
            )
        );

        return apply_filters( 'wp_mcp_ai_crawl4ai_response', $formatted, $decoded, $arguments, $context );
    }

    /**
     * Execute a crawl using the built-in WordPress HTTP client.
     *
     * @param array $payload   Prepared payload array.
     * @param array $arguments Tool arguments.
     * @param array $context   Execution context.
     * @param array $settings  Plugin settings array.
     * @return array|WP_Error
     */
    protected function execute_local_crawl( array $payload, array $arguments, array $context, array $settings ) {
        $timeout      = $this->get_request_timeout( $settings );
        $results      = array();
        $errors       = array();
        $urls         = isset( $payload['urls'] ) ? (array) $payload['urls'] : array();
        $request_args = $this->build_local_request_args( $settings, $context, $arguments );

        foreach ( $urls as $url ) {
            $response = wp_remote_get(
                $url,
                array_merge(
                    $request_args,
                    array(
                        'timeout' => $timeout,
                    )
                )
            );

            if ( is_wp_error( $response ) ) {
                $errors[ $url ] = $response->get_error_message();
                WP_MCP_AI_Logger::log_error(
                    'Crawl4AI local crawl failed.',
                    array(
                        'url'   => $url,
                        'error' => $response->get_error_message(),
                    )
                );
                continue;
            }

            $result = $this->build_local_result( $url, $response, $payload, $settings, $context );
            $results[] = apply_filters( 'wp_mcp_ai_crawl4ai_local_result', $result, $response, $url, $settings, $context, $arguments );
        }

        if ( empty( $results ) ) {
            return new WP_Error(
                'wp_mcp_ai_crawl4ai_local_failed',
                __( 'Unable to crawl the requested URLs.', 'wp-mcp-ai' ),
                array( 'errors' => $errors )
            );
        }

        $metadata = array(
            'mode'       => 'local',
            'errors'     => $errors,
            'fetched_at' => current_time( 'mysql', true ),
        );

        if ( isset( $request_args['headers']['User-Agent'] ) ) {
            $metadata['user_agent'] = $request_args['headers']['User-Agent'];
        }

        if ( isset( $payload['priority'] ) ) {
            $metadata['priority'] = $payload['priority'];
        }

        $metadata = apply_filters( 'wp_mcp_ai_crawl4ai_local_metadata', $metadata, $payload, $context, $settings );

        $response = array(
            'status'   => 'completed',
            'task_id'  => '',
            'results'  => $results,
            'metadata' => $metadata,
            'raw'      => array(
                'results'  => $results,
                'metadata' => $metadata,
            ),
        );

        WP_MCP_AI_Logger::log_event(
            'crawl4ai_local_response',
            'Local Crawl4AI request completed.',
            array(
                'url_count' => count( $results ),
                'errors'    => $errors,
            )
        );

        return apply_filters( 'wp_mcp_ai_crawl4ai_local_response', $response, $payload, $arguments, $context, $settings );
    }
}
