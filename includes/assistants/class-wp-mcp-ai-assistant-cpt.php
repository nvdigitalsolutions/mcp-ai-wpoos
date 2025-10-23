<?php
/**
 * Assistant custom post type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the assistant custom post type and associated meta boxes.
 */
class WP_MCP_AI_Assistant_CPT {
    const POST_TYPE = 'mcp_ai_assistant';
    const META_TOOLS = '_wp_mcp_ai_tools';
    const META_MODEL = '_wp_mcp_ai_model';
    const META_TEMPERATURE = '_wp_mcp_ai_temperature';
    const META_SYSTEM_PROMPT   = '_wp_mcp_ai_system_prompt';
    const META_MEMORY_FILES    = '_wp_mcp_ai_memory_files';
    const META_VECTOR_STORE_ID = '_wp_mcp_ai_vector_store_id';
    const META_CREDENTIALS     = WP_MCP_AI_Credentials::META_KEY;

    /**
     * Tool registry instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected $registry;

    /**
     * Constructor.
     *
     * @param WP_MCP_AI_Tool_Registry $registry Tool registry.
     */
    public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
        $this->registry = $registry;

        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_meta' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
        add_action( 'admin_post_wp_mcp_ai_issue_credential', array( $this, 'handle_issue_credential' ) );
        add_action( 'admin_post_wp_mcp_ai_revoke_credential', array( $this, 'handle_revoke_credential' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
        add_action( 'before_delete_post', array( $this, 'cleanup_deleted_assistant_credentials' ) );
    }

    /**
     * Register the assistant custom post type.
     */
    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'AI Assistants', 'wp-mcp-ai' ),
            'singular_name'      => __( 'AI Assistant', 'wp-mcp-ai' ),
            'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
            'add_new_item'       => __( 'Add New Assistant', 'wp-mcp-ai' ),
            'edit_item'          => __( 'Edit Assistant', 'wp-mcp-ai' ),
            'new_item'           => __( 'New Assistant', 'wp-mcp-ai' ),
            'view_item'          => __( 'View Assistant', 'wp-mcp-ai' ),
            'search_items'       => __( 'Search Assistants', 'wp-mcp-ai' ),
            'not_found'          => __( 'No assistants found', 'wp-mcp-ai' ),
            'not_found_in_trash' => __( 'No assistants found in Trash', 'wp-mcp-ai' ),
            'all_items'          => __( 'Assistants', 'wp-mcp-ai' ),
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'rest_base'           => 'mcp-ai-assistants',
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor' ),
            'menu_icon'           => 'dashicons-robot',
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_nav_menus'   => false,
            'map_meta_cap'        => true,
        );

        register_post_type( self::POST_TYPE, $args );
    }

    /**
     * Register assistant post meta for REST access and sanitization.
     */
    public static function register_meta() {
        $auth_callback = array( __CLASS__, 'meta_auth_callback' );

        register_post_meta(
            self::POST_TYPE,
            self::META_TOOLS,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_tools_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_MODEL,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_model_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_TEMPERATURE,
            array(
                'type'              => 'number',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'    => 'number',
                        'minimum' => 0,
                        'maximum' => 2,
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_temperature_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_SYSTEM_PROMPT,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_system_prompt_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_MEMORY_FILES,
            array(
                'type'              => 'array',
                'single'            => true,
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                'sanitize_callback' => array( __CLASS__, 'sanitize_memory_files_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );

        register_post_meta(
            self::POST_TYPE,
            self::META_VECTOR_STORE_ID,
            array(
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize_vector_store_meta' ),
                'auth_callback'     => $auth_callback,
            )
        );
    }

    /**
     * Meta capability check for assistant meta values.
     *
     * @param bool       $allowed Existing permission.
     * @param string     $meta_key Meta key being modified.
     * @param int        $post_id Post ID.
     * @param int        $user_id User ID.
     * @param string|array $cap Capability name(s).
     * @param array      $caps Primitive caps.
     * @return bool
     */
    public static function meta_auth_callback( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
        unset( $allowed, $meta_key, $user_id, $cap, $caps );

        return current_user_can( 'edit_post', $post_id );
    }

    /**
     * Sanitize tools meta value.
     *
     * @param mixed $tools Raw tools value.
     * @return array
     */
    public static function sanitize_tools_meta( $tools ) {
        if ( ! is_array( $tools ) ) {
            return array();
        }

        $registry  = WP_MCP_AI_Tool_Registry::get_instance();
        $available = array();

        foreach ( $registry->get_tools() as $tool ) {
            $available[] = $tool->get_slug();
        }

        $sanitized = array();

        foreach ( $tools as $tool_slug ) {
            $tool_slug = sanitize_key( $tool_slug );
            if ( in_array( $tool_slug, $available, true ) ) {
                $sanitized[] = $tool_slug;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Sanitize model meta value.
     *
     * @param mixed $model Raw model value.
     * @return string
     */
    public static function sanitize_model_meta( $model ) {
        if ( ! is_string( $model ) ) {
            return '';
        }

        return sanitize_text_field( $model );
    }

    /**
     * Sanitize temperature meta value.
     *
     * @param mixed $temperature Raw temperature value.
     * @return float|null
     */
    public static function sanitize_temperature_meta( $temperature ) {
        if ( is_string( $temperature ) ) {
            $temperature = trim( $temperature );
        }

        if ( '' === $temperature || null === $temperature ) {
            return null;
        }

        if ( is_numeric( $temperature ) ) {
            $temperature = floatval( $temperature );
            if ( $temperature < 0 || $temperature > 2 ) {
                return null;
            }

            return $temperature;
        }

        return null;
    }

    /**
     * Sanitize system prompt meta value.
     *
     * @param mixed $prompt Raw prompt value.
     * @return string
     */
    public static function sanitize_system_prompt_meta( $prompt ) {
        if ( ! is_string( $prompt ) ) {
            return '';
        }

        return wp_kses_post( $prompt );
    }

    /**
     * Sanitize memory files meta value.
     *
     * @param mixed $memory_files Raw memory file IDs.
     * @return array
     */
    public static function sanitize_memory_files_meta( $memory_files ) {
        if ( ! is_array( $memory_files ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $memory_files as $file_id ) {
            $file_id = absint( $file_id );
            if ( $file_id && 'attachment' === get_post_type( $file_id ) ) {
                $sanitized[] = $file_id;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Sanitize vector store ID meta value.
     *
     * @param mixed $vector_store_id Raw vector store ID.
     * @return string
     */
    public static function sanitize_vector_store_meta( $vector_store_id ) {
        if ( ! is_string( $vector_store_id ) ) {
            return '';
        }

        return sanitize_text_field( $vector_store_id );
    }

    /**
     * Register meta boxes for the assistant CPT.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'wp-mcp-ai-tools',
            __( 'Available Tools', 'wp-mcp-ai' ),
            array( $this, 'render_tools_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-defaults',
            __( 'Model Defaults', 'wp-mcp-ai' ),
            array( $this, 'render_defaults_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-base-knowledge',
            __( 'Base Knowledge', 'wp-mcp-ai' ),
            array( $this, 'render_base_knowledge_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wp-mcp-ai-credentials',
            __( 'API Credentials', 'wp-mcp-ai' ),
            array( $this, 'render_credentials_meta_box' ),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the credentials meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_credentials_meta_box( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to manage credentials.', 'wp-mcp-ai' ) . '</p>';
            return;
        }

        $credentials = WP_MCP_AI_Credentials::get_credentials( $post->ID );

        echo '<p>' . esc_html__( 'Issue tokens for remote integrations. Store the generated token securely; it will not be shown again.', 'wp-mcp-ai' ) . '</p>';

        if ( empty( $credentials ) ) {
            echo '<p>' . esc_html__( 'No credentials have been issued for this assistant.', 'wp-mcp-ai' ) . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Credential ID', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Created', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ( $credentials as $credential ) {
                $created_at = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
                $status     = __( 'Active', 'wp-mcp-ai' );
                $actions    = '&#8212;';

                if ( ! empty( $credential['revoked_at'] ) ) {
                    $status = sprintf(
                        /* translators: %s: revocation timestamp */
                        __( 'Revoked %s', 'wp-mcp-ai' ),
                        get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
                    );
                } else {
                    $revoke_url = admin_url( 'admin-post.php' );
                    ob_start();
                    ?>
                    <form method="post" action="<?php echo esc_url( $revoke_url ); ?>">
                        <?php wp_nonce_field( 'wp_mcp_ai_revoke_credential_' . $post->ID . '_' . $credential['id'], 'wp_mcp_ai_revoke_nonce' ); ?>
                        <input type="hidden" name="action" value="wp_mcp_ai_revoke_credential" />
                        <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
                        <input type="hidden" name="credential_id" value="<?php echo esc_attr( $credential['id'] ); ?>" />
                        <?php
                        submit_button(
                            __( 'Revoke', 'wp-mcp-ai' ),
                            'link delete',
                            'submit',
                            false,
                            array(
                                'onclick' => 'return confirm("' . esc_js( __( 'Revoke this credential? This action cannot be undone.', 'wp-mcp-ai' ) ) . '");',
                            )
                        );
                        ?>
                    </form>
                    <?php
                    $actions = ob_get_clean();
                }

                echo '<tr>';
                echo '<td><code>' . esc_html( $credential['id'] ) . '</code></td>';
                echo '<td>' . esc_html( $created_at ) . '</td>';
                echo '<td>' . esc_html( $status ) . '</td>';
                echo '<td>' . $actions . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'wp_mcp_ai_issue_credential_' . $post->ID, 'wp_mcp_ai_issue_nonce' ); ?>
            <input type="hidden" name="action" value="wp_mcp_ai_issue_credential" />
            <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
            <?php submit_button( __( 'Generate Credential', 'wp-mcp-ai' ), 'secondary', 'submit', false ); ?>
        </form>
        <?php
    }

    /**
     * Render the tools meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_tools_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_tools_meta', 'wp_mcp_ai_tools_meta_nonce' );

        $selected_tools = get_post_meta( $post->ID, self::META_TOOLS, true );
        if ( ! is_array( $selected_tools ) ) {
            $selected_tools = array();
        }

        $tools = $this->registry->get_tools();

        if ( empty( $tools ) ) {
            echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
            return;
        }

        echo '<p>' . esc_html__( 'Select the tools this assistant is permitted to invoke.', 'wp-mcp-ai' ) . '</p>';

        echo '<ul class="wp-mcp-ai-tools">';
        foreach ( $tools as $tool ) {
            $slug = $tool->get_slug();
            printf(
                '<li><label><input type="checkbox" name="wp_mcp_ai_tools[]" value="%1$s" %2$s /> <strong>%3$s</strong><br/><span class="description">%4$s</span></label></li>',
                esc_attr( $slug ),
                checked( in_array( $slug, $selected_tools, true ), true, false ),
                esc_html( $tool->get_name() ),
                esc_html( $tool->get_description() )
            );
        }
        echo '</ul>';
    }

    /**
     * Render the defaults meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_defaults_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_defaults_meta', 'wp_mcp_ai_defaults_meta_nonce' );

        $model        = get_post_meta( $post->ID, self::META_MODEL, true );
        $temperature  = get_post_meta( $post->ID, self::META_TEMPERATURE, true );
        $system_prompt = get_post_meta( $post->ID, self::META_SYSTEM_PROMPT, true );

        if ( '' === $temperature ) {
            $temperature = '';
        }

        ?>
        <p>
            <label for="wp-mcp-ai-model"><strong><?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?></strong></label>
            <input type="text" id="wp-mcp-ai-model" name="wp_mcp_ai_model" value="<?php echo esc_attr( $model ); ?>" class="widefat" />
        </p>
        <p>
            <label for="wp-mcp-ai-temperature"><strong><?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?></strong></label>
            <input type="number" step="0.1" min="0" max="2" id="wp-mcp-ai-temperature" name="wp_mcp_ai_temperature" value="<?php echo esc_attr( $temperature ); ?>" class="widefat" />
        </p>
        <p>
            <label for="wp-mcp-ai-system-prompt"><strong><?php esc_html_e( 'System Prompt', 'wp-mcp-ai' ); ?></strong></label>
            <textarea id="wp-mcp-ai-system-prompt" name="wp_mcp_ai_system_prompt" class="widefat" rows="5"><?php echo esc_textarea( $system_prompt ); ?></textarea>
        </p>
        <?php
    }

    /**
     * Render the base knowledge meta box content.
     *
     * @param WP_Post $post Post object.
     */
    public function render_base_knowledge_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            wp_die( esc_html__( 'You do not have permission to edit this assistant.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        wp_nonce_field( 'wp_mcp_ai_base_knowledge_meta', 'wp_mcp_ai_base_knowledge_meta_nonce' );

        wp_enqueue_media();
        wp_enqueue_script( 'jquery' );

        $memory_files    = get_post_meta( $post->ID, self::META_MEMORY_FILES, true );
        $vector_store_id = get_post_meta( $post->ID, self::META_VECTOR_STORE_ID, true );

        if ( ! is_array( $memory_files ) ) {
            $memory_files = array();
        }

        if ( ! is_string( $vector_store_id ) ) {
            $vector_store_id = '';
        }

        ?>
        <p><?php esc_html_e( 'Select Media Library items that should be preloaded as reference material for this assistant.', 'wp-mcp-ai' ); ?></p>
        <ul id="wp-mcp-ai-memory-files-list" class="wp-mcp-ai-memory-files">
            <?php foreach ( $memory_files as $file_id ) :
                $file_id     = absint( $file_id );
                $attachment  = get_post( $file_id );
                if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                    continue;
                }
                $title = get_the_title( $attachment );
                ?>
                <li data-id="<?php echo esc_attr( $file_id ); ?>">
                    <span class="wp-mcp-ai-memory-file-title"><?php echo esc_html( $title ? $title : sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id ) ); ?></span>
                    <button type="button" class="button-link wp-mcp-ai-remove-memory"><?php esc_html_e( 'Remove', 'wp-mcp-ai' ); ?></button>
                    <input type="hidden" name="wp_mcp_ai_memory_files[]" value="<?php echo esc_attr( $file_id ); ?>" />
                </li>
            <?php endforeach; ?>
        </ul>
        <p>
            <button type="button" class="button" id="wp-mcp-ai-memory-select">
                <?php esc_html_e( 'Add Knowledge Files', 'wp-mcp-ai' ); ?>
            </button>
        </p>
        <p>
            <label for="wp-mcp-ai-vector-store-id"><strong><?php esc_html_e( 'Vector Store ID', 'wp-mcp-ai' ); ?></strong></label>
            <input type="text" id="wp-mcp-ai-vector-store-id" name="wp_mcp_ai_vector_store_id" value="<?php echo esc_attr( $vector_store_id ); ?>" class="widefat" />
            <span class="description"><?php esc_html_e( 'Optional identifier for an external vector store that should be associated with this assistant.', 'wp-mcp-ai' ); ?></span>
        </p>
        <script type="text/javascript">
        jQuery( function( $ ) {
            var frame;
            var list = $( '#wp-mcp-ai-memory-files-list' );

            function addAttachment( attachment ) {
                var id = attachment.id || attachment.ID;
                if ( ! id ) {
                    return;
                }

                if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
                    return;
                }

                var title = attachment.title || attachment.filename || attachment.name || '<?php echo esc_js( __( 'Attachment', 'wp-mcp-ai' ) ); ?>';
                var label = title + ' (ID: ' + id + ')';

                var item = $( '<li />', { 'data-id': id } );
                item.append( $( '<span />', { 'class': 'wp-mcp-ai-memory-file-title', 'text': label } ) );
                item.append( $( '<button />', { 'type': 'button', 'class': 'button-link wp-mcp-ai-remove-memory', 'text': '<?php echo esc_js( __( 'Remove', 'wp-mcp-ai' ) ); ?>' } ) );
                item.append( $( '<input />', { 'type': 'hidden', 'name': 'wp_mcp_ai_memory_files[]', 'value': id } ) );

                list.append( item );
            }

            $( '#wp-mcp-ai-memory-select' ).on( 'click', function( event ) {
                event.preventDefault();

                if ( frame ) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: '<?php echo esc_js( __( 'Select knowledge files', 'wp-mcp-ai' ) ); ?>',
                    button: {
                        text: '<?php echo esc_js( __( 'Use files', 'wp-mcp-ai' ) ); ?>'
                    },
                    multiple: true
                });

                frame.on( 'select', function() {
                    var selection = frame.state().get( 'selection' );
                    if ( ! selection ) {
                        return;
                    }

                    selection.each( function( attachment ) {
                        addAttachment( attachment.toJSON() );
                    } );
                });

                frame.open();
            } );

            list.on( 'click', '.wp-mcp-ai-remove-memory', function( event ) {
                event.preventDefault();
                $( this ).closest( 'li' ).remove();
            } );
        } );
        </script>
        <?php
    }

    /**
     * Handle credential issuance requests from the admin UI.
     */
    public function handle_issue_credential() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
        }

        check_admin_referer( 'wp_mcp_ai_issue_credential_' . $post_id, 'wp_mcp_ai_issue_nonce' );

        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
        }

        $user_id = get_current_user_id();
        $issued  = WP_MCP_AI_Credentials::issue_credential( $post_id, $user_id );

        if ( is_wp_error( $issued ) ) {
            $error_code = sanitize_key( $issued->get_error_code() );
            $this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
        }

        set_transient(
            $this->get_token_transient_key( $user_id ),
            array(
                'assistant_id' => $post_id,
                'token'        => $issued['token'],
            ),
            10 * MINUTE_IN_SECONDS
        );

        $this->redirect_with_notice( $post_id, 'credential_created' );
    }

    /**
     * Handle credential revocation requests from the admin UI.
     */
    public function handle_revoke_credential() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage assistant credentials.', 'wp-mcp-ai' ), '', array( 'response' => 403 ) );
        }

        $post_id       = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $credential_id = isset( $_POST['credential_id'] ) ? sanitize_key( wp_unslash( $_POST['credential_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! $post_id || '' === $credential_id ) {
            wp_die( esc_html__( 'Invalid credential request.', 'wp-mcp-ai' ), '', array( 'response' => 400 ) );
        }

        check_admin_referer( 'wp_mcp_ai_revoke_credential_' . $post_id . '_' . $credential_id, 'wp_mcp_ai_revoke_nonce' );

        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            wp_die( esc_html__( 'Invalid assistant.', 'wp-mcp-ai' ), '', array( 'response' => 404 ) );
        }

        $result = WP_MCP_AI_Credentials::revoke_credential( $post_id, $credential_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            $error_code = sanitize_key( $result->get_error_code() );
            $this->redirect_with_notice( $post_id, 'credential_error', array( 'error' => $error_code ) );
        }

        $this->redirect_with_notice( $post_id, 'credential_revoked' );
    }

    /**
     * Display notices related to credential management.
     */
    public function render_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();

        if ( ! $screen || 'post' !== $screen->base || self::POST_TYPE !== $screen->post_type ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $post_id ) {
            return;
        }

        $user_id      = get_current_user_id();
        $transient_key = $this->get_token_transient_key( $user_id );
        $token_notice  = get_transient( $transient_key );

        if ( is_array( $token_notice ) && isset( $token_notice['assistant_id'], $token_notice['token'] ) && absint( $token_notice['assistant_id'] ) === $post_id ) {
            delete_transient( $transient_key );

            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: credential token */
                    esc_html__( 'New credential issued. Copy this token now: %s', 'wp-mcp-ai' ),
                    '<code>' . esc_html( $token_notice['token'] ) . '</code>'
                )
            );
        }

        $notice = isset( $_GET['wp_mcp_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_mcp_ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( '' === $notice ) {
            return;
        }

        $error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $message    = $this->get_notice_message( $notice, $error_code );

        if ( '' === $message ) {
            return;
        }

        $class = in_array( $notice, array( 'credential_created', 'credential_revoked' ), true ) ? 'notice-success' : 'notice-error';

        printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    /**
     * Build the transient key used for temporary token storage.
     *
     * @param int $user_id User ID.
     * @return string
     */
    protected function get_token_transient_key( $user_id ) {
        return 'wp_mcp_ai_new_token_' . absint( $user_id );
    }

    /**
     * Redirect back to the assistant edit screen with a notice.
     *
     * @param int    $post_id Assistant post ID.
     * @param string $notice  Notice identifier.
     * @param array  $extra   Additional query arguments.
     */
    protected function redirect_with_notice( $post_id, $notice, $extra = array() ) {
        $args = array_merge(
            array(
                'post'             => absint( $post_id ),
                'action'           => 'edit',
                'wp_mcp_ai_notice' => sanitize_key( $notice ),
            ),
            array_change_key_case( $extra, CASE_LOWER )
        );

        wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
        exit;
    }

    /**
     * Map notice identifiers to user-facing messages.
     *
     * @param string $notice     Notice identifier.
     * @param string $error_code Optional error code.
     * @return string
     */
    protected function get_notice_message( $notice, $error_code = '' ) {
        switch ( $notice ) {
            case 'credential_created':
                return __( 'Credential issued successfully.', 'wp-mcp-ai' );
            case 'credential_revoked':
                return __( 'Credential revoked successfully.', 'wp-mcp-ai' );
            case 'credential_error':
                switch ( $error_code ) {
                    case 'wp_mcp_ai_unknown_credential':
                        return __( 'The requested credential could not be found.', 'wp-mcp-ai' );
                    case 'wp_mcp_ai_credential_already_revoked':
                        return __( 'The credential has already been revoked.', 'wp-mcp-ai' );
                    case 'wp_mcp_ai_invalid_assistant':
                        return __( 'Unable to manage credentials for this assistant.', 'wp-mcp-ai' );
                    default:
                        return __( 'An error occurred while managing the credential.', 'wp-mcp-ai' );
                }
        }

        return '';
    }

    /**
     * Remove credential index entries when an assistant is deleted.
     *
     * @param int $post_id Post ID being deleted.
     */
    public function cleanup_deleted_assistant_credentials( $post_id ) {
        if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
            return;
        }

        WP_MCP_AI_Credentials::purge_assistant_credentials( $post_id );
    }

    /**
     * Persist assistant meta fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_post( $post_id, $post ) {
        $tools_nonce_verified         = false;
        $defaults_nonce_verified      = false;
        $base_knowledge_nonce_verified = false;

        if ( isset( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $tools_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $defaults_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( isset( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $base_knowledge_nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ), 'wp_mcp_ai_base_knowledge_meta' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ( ! $tools_nonce_verified && ! $defaults_nonce_verified && ! $base_knowledge_nonce_verified ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( $tools_nonce_verified ) {
            $tool_slugs = array();
            if ( isset( $_POST['wp_mcp_ai_tools'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $tool_slugs = self::sanitize_tools_meta( wp_unslash( $_POST['wp_mcp_ai_tools'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            update_post_meta( $post_id, self::META_TOOLS, $tool_slugs );
        }

        if ( $defaults_nonce_verified ) {
            $model = isset( $_POST['wp_mcp_ai_model'] ) ? self::sanitize_model_meta( wp_unslash( $_POST['wp_mcp_ai_model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_MODEL, $model );

            $temperature = null;
            if ( isset( $_POST['wp_mcp_ai_temperature'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $temperature = self::sanitize_temperature_meta( wp_unslash( $_POST['wp_mcp_ai_temperature'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            if ( null === $temperature ) {
                delete_post_meta( $post_id, self::META_TEMPERATURE );
            } else {
                update_post_meta( $post_id, self::META_TEMPERATURE, $temperature );
            }

            $system_prompt = isset( $_POST['wp_mcp_ai_system_prompt'] ) ? self::sanitize_system_prompt_meta( wp_unslash( $_POST['wp_mcp_ai_system_prompt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $system_prompt );
        }

        if ( $base_knowledge_nonce_verified ) {
            $memory_files = array();
            if ( isset( $_POST['wp_mcp_ai_memory_files'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $memory_files = self::sanitize_memory_files_meta( wp_unslash( $_POST['wp_mcp_ai_memory_files'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            update_post_meta( $post_id, self::META_MEMORY_FILES, $memory_files );

            $vector_store_id = isset( $_POST['wp_mcp_ai_vector_store_id'] ) ? self::sanitize_vector_store_meta( wp_unslash( $_POST['wp_mcp_ai_vector_store_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, self::META_VECTOR_STORE_ID, $vector_store_id );
        }
    }

    /**
     * Retrieve the configuration for a specific assistant.
     *
     * @param int $assistant_id Assistant post ID.
     * @return array
     */
    public static function get_assistant_configuration( $assistant_id ) {
        $assistant_id = absint( $assistant_id );
        if ( ! $assistant_id ) {
            return array();
        }

        $config = array(
            'tools'           => get_post_meta( $assistant_id, self::META_TOOLS, true ),
            'model'           => get_post_meta( $assistant_id, self::META_MODEL, true ),
            'temperature'     => get_post_meta( $assistant_id, self::META_TEMPERATURE, true ),
            'system_prompt'   => get_post_meta( $assistant_id, self::META_SYSTEM_PROMPT, true ),
            'memory_files'    => get_post_meta( $assistant_id, self::META_MEMORY_FILES, true ),
            'vector_store_id' => get_post_meta( $assistant_id, self::META_VECTOR_STORE_ID, true ),
        );

        if ( ! is_array( $config['tools'] ) ) {
            $config['tools'] = array();
        }

        if ( '' === $config['model'] ) {
            $config['model'] = '';
        }

        if ( '' === $config['temperature'] ) {
            $config['temperature'] = null;
        } else {
            $config['temperature'] = floatval( $config['temperature'] );
        }

        if ( '' === $config['system_prompt'] ) {
            $config['system_prompt'] = '';
        }

        if ( ! is_array( $config['memory_files'] ) ) {
            $config['memory_files'] = array();
        }

        $config['memory_files'] = array_values( array_filter( array_map( 'absint', $config['memory_files'] ) ) );

        if ( ! is_string( $config['vector_store_id'] ) ) {
            $config['vector_store_id'] = '';
        } else {
            $config['vector_store_id'] = sanitize_text_field( $config['vector_store_id'] );
        }

        return $config;
    }
}
