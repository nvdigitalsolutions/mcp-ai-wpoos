<?php
/**
 * Tool that performs a web search using DuckDuckGo's Instant Answer API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Performs lightweight web searches and returns the top results.
 */
class WP_MCP_AI_Tool_Web_Search implements WP_MCP_AI_Tool_Interface {
    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'web_search';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Web Search', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Searches the public web via DuckDuckGo and returns the top results.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'query'       => array(
                    'type'        => 'string',
                    'description' => __( 'The search query to look up.', 'wp-mcp-ai' ),
                ),
                'max_results' => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum number of results to return (1-10).', 'wp-mcp-ai' ),
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'default'     => 5,
                ),
            ),
            'required'             => array( 'query' ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

        if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to perform web searches.', 'wp-mcp-ai' ) );
        }

        if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

        if ( '' === $query ) {
            return new WP_Error( 'wp_mcp_ai_missing_query', __( 'A search query is required.', 'wp-mcp-ai' ) );
        }

        $max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 5;
        $max_results = $max_results > 0 ? min( $max_results, 10 ) : 5;

        $request_url = add_query_arg(
            array(
                'q'             => $query,
                'format'        => 'json',
                'no_html'       => 1,
                'skip_disambig' => 1,
            ),
            'https://api.duckduckgo.com/'
        );

        $response = wp_remote_get(
            $request_url,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'wp_mcp_ai_search_failed',
                __( 'The web search request failed.', 'wp-mcp-ai' ),
                $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            return new WP_Error(
                'wp_mcp_ai_search_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'The web search service returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
                    (int) $status_code
                )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( null === $data || ! is_array( $data ) ) {
            return new WP_Error( 'wp_mcp_ai_search_bad_json', __( 'The web search response could not be decoded.', 'wp-mcp-ai' ) );
        }

        $results = array();

        if ( ! empty( $data['AbstractText'] ) && ! empty( $data['AbstractURL'] ) ) {
            $results[] = array(
                'title'       => isset( $data['Heading'] ) ? sanitize_text_field( $data['Heading'] ) : sanitize_text_field( wp_trim_words( $data['AbstractText'], 12 ) ),
                'url'         => esc_url_raw( $data['AbstractURL'] ),
                'snippet'     => sanitize_text_field( $data['AbstractText'] ),
                'source'      => 'duckduckgo',
                'type'        => 'abstract',
            );
        }

        if ( ! empty( $data['RelatedTopics'] ) && is_array( $data['RelatedTopics'] ) ) {
            foreach ( $data['RelatedTopics'] as $topic ) {
                if ( $this->maybe_add_topic_result( $topic, $results, $max_results ) ) {
                    break;
                }
            }
        }

        $results = array_slice( $results, 0, $max_results );

        if ( empty( $results ) ) {
            return array(
                'query'   => $query,
                'results' => array(),
                'note'    => __( 'No web search results were found for this query.', 'wp-mcp-ai' ),
            );
        }

        return array(
            'query'   => $query,
            'results' => $results,
        );
    }

    /**
     * Maybe add a topic result to the results array.
     *
     * @param array $topic       Topic data from DuckDuckGo.
     * @param array $results     Current list of results (passed by reference).
     * @param int   $max_results Maximum number of results allowed.
     *
     * @return bool Whether the caller should stop processing further topics.
     */
    protected function maybe_add_topic_result( $topic, array &$results, $max_results ) {
        if ( empty( $topic ) || ! is_array( $topic ) ) {
            return false;
        }

        if ( count( $results ) >= $max_results ) {
            return true;
        }

        if ( isset( $topic['Topics'] ) && is_array( $topic['Topics'] ) ) {
            foreach ( $topic['Topics'] as $nested_topic ) {
                if ( $this->maybe_add_topic_result( $nested_topic, $results, $max_results ) ) {
                    return true;
                }
            }

            return false;
        }

        if ( empty( $topic['FirstURL'] ) || empty( $topic['Text'] ) ) {
            return false;
        }

        $results[] = array(
            'title'   => isset( $topic['Text'] ) ? sanitize_text_field( $topic['Text'] ) : '',
            'url'     => esc_url_raw( $topic['FirstURL'] ),
            'snippet' => isset( $topic['Result'] ) ? wp_strip_all_tags( $topic['Result'] ) : '',
            'source'  => 'duckduckgo',
            'type'    => 'result',
        );

        return count( $results ) >= $max_results;
    }
}
