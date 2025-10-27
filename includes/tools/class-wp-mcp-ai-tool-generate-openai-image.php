<?php
/**
 * Tool that generates images using OpenAI's Images API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for generating images via OpenAI and storing them as attachments.
 */
class WP_MCP_AI_Tool_Generate_OpenAI_Image implements WP_MCP_AI_Tool_Interface {
    const DEFAULT_MODEL   = 'gpt-image-1';
    const DEFAULT_SIZE    = '1024x1024';
    const DEFAULT_QUALITY = 'standard';
    const DEFAULT_FORMAT  = 'png';

    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'generate_openai_image';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Generate OpenAI Image', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Creates an image with OpenAI and stores it in the Media Library.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'prompt'     => array(
                    'type'        => 'string',
                    'description' => __( 'The text prompt describing the desired image.', 'wp-mcp-ai' ),
                ),
                'model'      => array(
                    'type'        => 'string',
                    'description' => __( 'OpenAI image model to use.', 'wp-mcp-ai' ),
                    'default'     => self::DEFAULT_MODEL,
                ),
                'size'       => array(
                    'type'        => 'string',
                    'description' => __( 'Size of the generated image.', 'wp-mcp-ai' ),
                    'enum'        => array_values( $this->get_allowed_sizes() ),
                    'default'     => self::DEFAULT_SIZE,
                ),
                'quality'    => array(
                    'type'        => 'string',
                    'description' => __( 'Image quality setting.', 'wp-mcp-ai' ),
                    'enum'        => array_values( $this->get_allowed_qualities() ),
                    'default'     => self::DEFAULT_QUALITY,
                ),
                'style'      => array(
                    'type'        => 'string',
                    'description' => __( 'Optional style hint (for example vivid or natural).', 'wp-mcp-ai' ),
                    'enum'        => array_values( $this->get_allowed_styles() ),
                ),
                'background' => array(
                    'type'        => 'string',
                    'description' => __( 'Optional background preference such as transparent or white.', 'wp-mcp-ai' ),
                    'enum'        => array_values( $this->get_allowed_backgrounds() ),
                ),
                'format'     => array(
                    'type'        => 'string',
                    'description' => __( 'Image format for the generated file.', 'wp-mcp-ai' ),
                    'enum'        => array_keys( $this->get_allowed_formats() ),
                    'default'     => self::DEFAULT_FORMAT,
                ),
                'file_name'  => array(
                    'type'        => 'string',
                    'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
                ),
                'timeout'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Override the OpenAI request timeout in seconds.', 'wp-mcp-ai' ),
                    'minimum'     => 5,
                    'maximum'     => 300,
                ),
            ),
            'required'             => array( 'prompt' ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
        $has_token = ! empty( $context['token_authenticated'] );

        if ( ! $user_id && ! $has_token ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
        }

        if ( $user_id ) {
            if ( ! user_can( $user_id, 'read' ) ) {
                return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate images.', 'wp-mcp-ai' ) );
            }

            if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
                return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
            }
        }

        $prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
        $prompt = trim( $prompt );

        if ( '' === $prompt ) {
            return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied for the image request.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $format = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : self::DEFAULT_FORMAT;
        $format = $this->normalise_image_format( $format );

        if ( '' === $format ) {
            return new WP_Error( 'wp_mcp_ai_invalid_image_format', __( 'The requested image format is not supported.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $size = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : self::DEFAULT_SIZE;
        $size = $this->normalise_image_size( $size );

        if ( '' === $size ) {
            $size = self::DEFAULT_SIZE;
        }

        $quality = isset( $arguments['quality'] ) ? sanitize_key( $arguments['quality'] ) : self::DEFAULT_QUALITY;
        $quality = $this->normalise_image_quality( $quality );

        if ( '' === $quality ) {
            $quality = self::DEFAULT_QUALITY;
        }

        $style = isset( $arguments['style'] ) ? sanitize_key( $arguments['style'] ) : '';
        $style = $this->normalise_style( $style );

        $background = isset( $arguments['background'] ) ? sanitize_key( $arguments['background'] ) : '';
        $background = $this->normalise_background( $background );

        $model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL;
        if ( '' === $model ) {
            $model = self::DEFAULT_MODEL;
        }

        $options = array(
            'model'      => $model,
            'size'       => $size,
            'quality'    => $quality,
            'format'     => $format,
        );

        if ( '' !== $style ) {
            $options['style'] = $style;
        }

        if ( '' !== $background ) {
            $options['background'] = $background;
        }

        if ( isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ) {
            $timeout = absint( $arguments['timeout'] );
            if ( $timeout >= 5 ) {
                $options['timeout'] = $timeout;
            }
        }

        $client = new WP_MCP_AI_OpenAI_Client();
        $image  = $client->generate_image( $prompt, $options );

        if ( is_wp_error( $image ) ) {
            return $image;
        }

        if ( empty( $image['image'] ) ) {
            return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
        }

        $file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
        $storage   = $this->store_image_attachment( $image, $file_name, $prompt, $user_id );

        if ( is_wp_error( $storage ) ) {
            return $storage;
        }

        $result = array(
            'attachment_id'  => $storage['attachment_id'],
            'url'            => $storage['url'],
            'file_path'      => $storage['file'],
            'file_name'      => $storage['file_name'],
            'mime_type'      => $storage['mime_type'],
            'bytes'          => $storage['bytes'],
            'format'         => $format,
            'size'           => $size,
            'quality'        => $quality,
            'model'          => $image['model'],
            'style'          => $style,
            'background'     => $background,
            'revised_prompt' => isset( $image['revised_prompt'] ) ? $image['revised_prompt'] : '',
            'created'        => isset( $image['created'] ) ? $image['created'] : 0,
        );

        /**
         * Allow third parties to filter the image generation result before it is returned.
         *
         * @param array $result    Result array to be returned.
         * @param array $arguments Arguments supplied to the tool.
         * @param array $context   Execution context supplied to the tool.
         */
        $result = apply_filters( 'wp_mcp_ai_generate_openai_image_result', $result, $arguments, $context );

        return $result;
    }

    /**
     * Retrieve the allowed image sizes.
     *
     * @return array
     */
    protected function get_allowed_sizes() {
        return array(
            '256x256',
            '512x512',
            '1024x1024',
            '1024x1792',
            '1792x1024',
        );
    }

    /**
     * Retrieve the allowed image qualities.
     *
     * @return array
     */
    protected function get_allowed_qualities() {
        return array(
            'standard',
            'high',
        );
    }

    /**
     * Retrieve the allowed style hints.
     *
     * @return array
     */
    protected function get_allowed_styles() {
        return array(
            '',
            'natural',
            'vivid',
        );
    }

    /**
     * Retrieve the allowed background options.
     *
     * @return array
     */
    protected function get_allowed_backgrounds() {
        return array(
            '',
            'transparent',
            'white',
        );
    }

    /**
     * Retrieve the allowed formats and metadata.
     *
     * @return array
     */
    protected function get_allowed_formats() {
        return array(
            'png'  => array(
                'extension' => 'png',
                'mime_type' => 'image/png',
            ),
            'jpeg' => array(
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
            ),
            'webp' => array(
                'extension' => 'webp',
                'mime_type' => 'image/webp',
            ),
        );
    }

    /**
     * Normalise the requested size value.
     *
     * @param string $size Raw size input.
     * @return string
     */
    protected function normalise_image_size( $size ) {
        $size   = strtolower( (string) $size );
        $sizes  = $this->get_allowed_sizes();

        return in_array( $size, $sizes, true ) ? $size : '';
    }

    /**
     * Normalise the requested image format.
     *
     * @param string $format Raw format input.
     * @return string
     */
    protected function normalise_image_format( $format ) {
        $format = sanitize_key( $format );
        $formats = $this->get_allowed_formats();

        return isset( $formats[ $format ] ) ? $format : '';
    }

    /**
     * Normalise the requested image quality.
     *
     * @param string $quality Raw quality input.
     * @return string
     */
    protected function normalise_image_quality( $quality ) {
        $quality = sanitize_key( $quality );
        $qualities = $this->get_allowed_qualities();

        return in_array( $quality, $qualities, true ) ? $quality : '';
    }

    /**
     * Normalise the requested style hint.
     *
     * @param string $style Raw style input.
     * @return string
     */
    protected function normalise_style( $style ) {
        $style = sanitize_key( $style );
        $styles = $this->get_allowed_styles();

        return in_array( $style, $styles, true ) ? $style : '';
    }

    /**
     * Normalise the requested background option.
     *
     * @param string $background Raw background input.
     * @return string
     */
    protected function normalise_background( $background ) {
        $background = sanitize_key( $background );
        $backgrounds = $this->get_allowed_backgrounds();

        return in_array( $background, $backgrounds, true ) ? $background : '';
    }

    /**
     * Store the generated image as a WordPress attachment.
     *
     * @param array  $image     Response payload from the OpenAI client.
     * @param string $file_name Optional preferred file name.
     * @param string $prompt    Original text prompt.
     * @param int    $user_id   Acting user ID.
     * @return array|WP_Error
     */
    protected function store_image_attachment( array $image, $file_name, $prompt, $user_id ) {
        $data    = isset( $image['image'] ) ? $image['image'] : '';
        $format  = isset( $image['format'] ) ? $this->normalise_image_format( $image['format'] ) : self::DEFAULT_FORMAT;
        $formats = $this->get_allowed_formats();

        if ( '' === $data || '' === $format || ! isset( $formats[ $format ] ) ) {
            return new WP_Error( 'wp_mcp_ai_image_storage_error', __( 'Unable to determine the image format for storage.', 'wp-mcp-ai' ) );
        }

        $file_stem = $this->normalise_file_stem( $file_name );
        $file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $formats[ $format ]['extension'] );

        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_upload_bits( $file_name, null, $data );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to save the generated image file.', 'wp-mcp-ai' ), array( 'error' => $upload['error'] ) );
        }

        $file_path = isset( $upload['file'] ) ? $upload['file'] : '';

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'wp_mcp_ai_image_upload_failed', __( 'Failed to write the generated image file to disk.', 'wp-mcp-ai' ) );
        }

        $mime_type = $this->determine_mime_type( $file_path, $formats[ $format ]['mime_type'], $image );
        $title     = $this->generate_attachment_title( $prompt );

        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        if ( $user_id ) {
            $attachment['post_author'] = $user_id;
        }

        $attachment_id = wp_insert_attachment( $attachment, $file_path );

        if ( is_wp_error( $attachment_id ) ) {
            $this->delete_file_safely( $file_path );

            return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated image as an attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
        }

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

        if ( is_array( $metadata ) && ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        } else {
            $metadata = array();
        }

        $bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

        return array(
            'attachment_id' => (int) $attachment_id,
            'file'          => $file_path,
            'file_name'     => wp_basename( $file_path ),
            'url'           => isset( $upload['url'] ) ? $upload['url'] : '',
            'mime_type'     => $mime_type,
            'bytes'         => $bytes ? (int) $bytes : 0,
            'title'         => $title,
        );
    }

    /**
     * Normalise a file stem used for generated attachments.
     *
     * @param string $file_name Raw file name input.
     * @return string
     */
    protected function normalise_file_stem( $file_name ) {
        $file_name = sanitize_file_name( (string) $file_name );

        if ( '' === $file_name ) {
            return 'openai-image';
        }

        $info = pathinfo( $file_name );
        $stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
        $stem = sanitize_title( $stem );

        if ( '' === $stem ) {
            return 'openai-image';
        }

        return $stem;
    }

    /**
     * Determine the MIME type for the saved image file.
     *
     * @param string $file_path      Absolute file path.
     * @param string $preferred_type Preferred MIME type for the format.
     * @param array  $image          Response payload from OpenAI.
     * @return string
     */
    protected function determine_mime_type( $file_path, $preferred_type, array $image ) {
        $file_info = wp_check_filetype( wp_basename( $file_path ), null );

        if ( ! empty( $file_info['type'] ) ) {
            return $file_info['type'];
        }

        if ( ! empty( $image['mime_type'] ) ) {
            $content_type = sanitize_text_field( $image['mime_type'] );
            if ( '' !== $content_type ) {
                return $content_type;
            }
        }

        if ( ! empty( $preferred_type ) ) {
            return $preferred_type;
        }

        return 'image/png';
    }

    /**
     * Generate a human readable attachment title using the source prompt.
     *
     * @param string $prompt Original prompt text.
     * @return string
     */
    protected function generate_attachment_title( $prompt ) {
        $prompt = (string) $prompt;
        $prompt = preg_replace( '/\s+/', ' ', $prompt );
        $prompt = trim( $prompt );

        if ( '' === $prompt ) {
            return __( 'OpenAI Image', 'wp-mcp-ai' );
        }

        $excerpt = wp_trim_words( $prompt, 12, '…' );

        /* translators: %s: Short excerpt of the prompt used to generate an image. */
        return sprintf( __( 'OpenAI Image: %s', 'wp-mcp-ai' ), $excerpt );
    }

    /**
     * Delete a generated file from disk safely when an error occurs.
     *
     * @param string $file_path Absolute file path.
     */
    protected function delete_file_safely( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return;
        }

        if ( ! function_exists( 'wp_delete_file' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        wp_delete_file( $file_path );
    }
}
