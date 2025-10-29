<?php
/**
 * Tool that creates or updates WordPress posts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates a new post or updates an existing one.
 */
class WP_MCP_AI_Tool_Save_Post implements WP_MCP_AI_Tool_Interface {
    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'save_post';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Create or Update Post', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Creates a new post or updates an existing one with the supplied content.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Existing post ID to update. Leave empty to create a new post.', 'wp-mcp-ai' ),
                    'minimum'     => 1,
                ),
                'post_type' => array(
                    'type'        => 'string',
                    'description' => __( 'The post type to create or update.', 'wp-mcp-ai' ),
                    'default'     => 'post',
                ),
                'title'     => array(
                    'type'        => 'string',
                    'description' => __( 'Title of the post.', 'wp-mcp-ai' ),
                ),
                'content'   => array(
                    'type'        => 'string',
                    'description' => __( 'Main content for the post.', 'wp-mcp-ai' ),
                ),
                'status'    => array(
                    'type'        => 'string',
                    'description' => __( 'The status to assign to the post, e.g. draft or publish.', 'wp-mcp-ai' ),
                    'default'     => 'draft',
                ),
                'excerpt'   => array(
                    'type'        => 'string',
                    'description' => __( 'Optional excerpt for the post.', 'wp-mcp-ai' ),
                ),
                'slug'      => array(
                    'type'        => 'string',
                    'description' => __( 'Optional slug to use for the post permalink.', 'wp-mcp-ai' ),
                ),
            ),
            'required'             => array( 'content' ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

        if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage posts.', 'wp-mcp-ai' ) );
        }

        if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
        $post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : '';

        $post = null;
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'wp_mcp_ai_invalid_post', __( 'The specified post could not be found.', 'wp-mcp-ai' ) );
            }

            if ( '' === $post_type ) {
                $post_type = $post->post_type;
            } elseif ( $post->post_type !== $post_type ) {
                return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not match the existing post.', 'wp-mcp-ai' ) );
            }

            if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
                return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit this post.', 'wp-mcp-ai' ) );
            }
        } else {
            if ( '' === $post_type ) {
                $post_type = 'post';
            }

            $post_type_object = get_post_type_object( $post_type );
            if ( ! $post_type_object ) {
                return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
            }

            $create_cap = isset( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : $post_type_object->cap->edit_posts;

            if ( ! user_can( $user_id, $create_cap ) ) {
                return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create posts of this type.', 'wp-mcp-ai' ) );
            }
        }

        $post_type_object = isset( $post_type_object ) ? $post_type_object : get_post_type_object( $post_type );
        if ( ! $post_type_object ) {
            return new WP_Error( 'wp_mcp_ai_invalid_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
        }

        $content = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
        if ( '' === $content ) {
            return new WP_Error( 'wp_mcp_ai_missing_content', __( 'Post content is required.', 'wp-mcp-ai' ) );
        }

        $post_data = array(
            'post_type'    => $post_type,
            'post_content' => $content,
        );

        if ( $post_id > 0 ) {
            $post_data['ID'] = $post_id;
        } else {
            $post_data['post_author'] = $user_id;
        }

        if ( isset( $arguments['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $arguments['title'] );
        } elseif ( ! $post_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_title', __( 'A title is required when creating a new post.', 'wp-mcp-ai' ) );
        }

        if ( isset( $arguments['excerpt'] ) ) {
            $post_data['post_excerpt'] = wp_kses_post( $arguments['excerpt'] );
        }

        if ( isset( $arguments['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( $arguments['slug'] );
        }

        $status = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
        if ( '' !== $status ) {
            $valid_statuses = get_post_stati();
            if ( in_array( $status, $valid_statuses, true ) ) {
                $post_data['post_status'] = $status;
            }
        } elseif ( $post_id > 0 && $post ) {
            $post_data['post_status'] = $post->post_status;
        } elseif ( ! $post_id ) {
            $post_data['post_status'] = 'draft';
        }

        if ( isset( $post_data['ID'] ) ) {
            $result = wp_update_post( wp_slash( $post_data ), true );
        } else {
            $result = wp_insert_post( wp_slash( $post_data ), true );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $updated_post = get_post( $result );
        if ( ! $updated_post ) {
            return new WP_Error( 'wp_mcp_ai_unknown_error', __( 'The post was saved but could not be retrieved.', 'wp-mcp-ai' ) );
        }

        $response = array(
            'ID'        => $updated_post->ID,
            'title'     => get_the_title( $updated_post ),
            'status'    => get_post_status( $updated_post ),
            'post_type' => $updated_post->post_type,
            'permalink' => get_permalink( $updated_post ),
        );

        $edit_link = get_edit_post_link( $updated_post->ID, '' );
        if ( $edit_link ) {
            $response['edit_link'] = $edit_link;
        }

        return $response;
    }
}
