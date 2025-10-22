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
    const META_SYSTEM_PROMPT = '_wp_mcp_ai_system_prompt';

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
     * Persist assistant meta fields.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function save_post( $post_id, $post ) {
        if ( ! isset( $_POST['wp_mcp_ai_tools_meta_nonce'], $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_tools_meta_nonce'] ) ), 'wp_mcp_ai_tools_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_defaults_meta_nonce'] ) ), 'wp_mcp_ai_defaults_meta' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
            'tools'          => get_post_meta( $assistant_id, self::META_TOOLS, true ),
            'model'          => get_post_meta( $assistant_id, self::META_MODEL, true ),
            'temperature'    => get_post_meta( $assistant_id, self::META_TEMPERATURE, true ),
            'system_prompt'  => get_post_meta( $assistant_id, self::META_SYSTEM_PROMPT, true ),
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

        return $config;
    }
}
