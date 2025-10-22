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
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_post' ), 10, 2 );
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
     * Persist assistant meta fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_post( $post_id, $post ) {
        if ( ! isset( $_POST['wp_mcp_ai_tools_meta_nonce'], $_POST['wp_mcp_ai_defaults_meta_nonce'], $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_base_knowledge_meta_nonce'] ) ), 'wp_mcp_ai_base_knowledge_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $tool_slugs = array();
        if ( isset( $_POST['wp_mcp_ai_tools'] ) && is_array( $_POST['wp_mcp_ai_tools'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $available = array();
            foreach ( $this->registry->get_tools() as $tool ) {
                $available[] = $tool->get_slug();
            }

            foreach ( $_POST['wp_mcp_ai_tools'] as $slug ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $slug = sanitize_key( wp_unslash( $slug ) );
                if ( in_array( $slug, $available, true ) ) {
                    $tool_slugs[] = $slug;
                }
            }
        }

        update_post_meta( $post_id, self::META_TOOLS, $tool_slugs );

        $model = isset( $_POST['wp_mcp_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta( $post_id, self::META_MODEL, $model );

        $temperature_raw = isset( $_POST['wp_mcp_ai_temperature'] ) ? wp_unslash( $_POST['wp_mcp_ai_temperature'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $temperature     = is_numeric( $temperature_raw ) ? floatval( $temperature_raw ) : '';
        if ( '' !== $temperature && ( $temperature < 0 || $temperature > 2 ) ) {
            $temperature = '';
        }
        update_post_meta( $post_id, self::META_TEMPERATURE, $temperature );

        $system_prompt = isset( $_POST['wp_mcp_ai_system_prompt'] ) ? wp_kses_post( wp_unslash( $_POST['wp_mcp_ai_system_prompt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta( $post_id, self::META_SYSTEM_PROMPT, $system_prompt );

        $memory_files = array();
        if ( isset( $_POST['wp_mcp_ai_memory_files'] ) && is_array( $_POST['wp_mcp_ai_memory_files'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            foreach ( $_POST['wp_mcp_ai_memory_files'] as $file_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $file_id = absint( $file_id );
                if ( $file_id && 'attachment' === get_post_type( $file_id ) ) {
                    $memory_files[] = $file_id;
                }
            }
        }

        $memory_files = array_values( array_unique( $memory_files ) );
        update_post_meta( $post_id, self::META_MEMORY_FILES, $memory_files );

        $vector_store_id = isset( $_POST['wp_mcp_ai_vector_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_vector_store_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta( $post_id, self::META_VECTOR_STORE_ID, $vector_store_id );
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
