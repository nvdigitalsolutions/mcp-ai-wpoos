<?php
/**
 * Admin settings for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles registration and rendering of the plugin's settings page.
 */
class WP_MCP_AI_Admin_Settings {
    const OPTION_NAME = 'wp_mcp_ai_settings';
    const SETTINGS_GROUP = 'wp_mcp_ai_settings_group';
    const PAGE_SLUG = 'wp-mcp-ai-settings';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Returns the option defaults.
     *
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'openai_api_key'   => '',
            'default_assistant' => 0,
            'enable_logging'   => false,
            'default_model'    => 'gpt-4o-mini',
            'request_timeout'  => 30,
        );
    }

    /**
     * Retrieve the merged settings array.
     *
     * @return array
     */
    public static function get_settings() {
        $saved = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, self::get_default_settings() );
    }

    /**
     * Determine whether debug logging is enabled.
     *
     * @return bool
     */
    public static function is_logging_enabled() {
        $settings = self::get_settings();

        return ! empty( $settings['enable_logging'] );
    }

    /**
     * Write a message to the PHP error log when logging is enabled.
     *
     * @param string $message Message to log.
     * @param array  $context Additional context to encode with the message.
     */
    public static function log( $message, $context = array() ) {
        if ( ! self::is_logging_enabled() ) {
            return;
        }

        $prefix = '[WP MCP AI] ';

        if ( ! empty( $context ) ) {
            $message .= ' ' . wp_json_encode( $context );
        }

        error_log( $prefix . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Register the settings page within the WordPress admin.
     */
    public function register_settings_page() {
        add_options_page(
            __( 'WP MCP AI', 'wp-mcp-ai' ),
            __( 'WP MCP AI', 'wp-mcp-ai' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register the settings, sections, and fields exposed in the admin UI.
     */
    public function register_settings() {
        register_setting( self::SETTINGS_GROUP, self::OPTION_NAME, array( $this, 'sanitize_settings' ) );

        add_settings_section(
            'wp_mcp_ai_openai_section',
            __( 'OpenAI Configuration', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'openai_api_key',
            __( 'OpenAI API Key', 'wp-mcp-ai' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_field(
            'default_model',
            __( 'Default Model', 'wp-mcp-ai' ),
            array( $this, 'render_default_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_field(
            'request_timeout',
            __( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
            array( $this, 'render_timeout_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_section(
            'wp_mcp_ai_assistant_section',
            __( 'Assistant Defaults', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'default_assistant',
            __( 'Default Assistant', 'wp-mcp-ai' ),
            array( $this, 'render_default_assistant_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
        );

        add_settings_field(
            'enable_logging',
            __( 'Enable Logging', 'wp-mcp-ai' ),
            array( $this, 'render_logging_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
        );
    }

    /**
     * Sanitize the submitted settings array.
     *
     * @param array $settings Submitted values.
     * @return array
     */
    public function sanitize_settings( $settings ) {
        $clean = self::get_default_settings();

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        if ( isset( $settings['openai_api_key'] ) ) {
            $clean['openai_api_key'] = trim( sanitize_text_field( $settings['openai_api_key'] ) );
        }

        if ( isset( $settings['default_assistant'] ) ) {
            $clean['default_assistant'] = absint( $settings['default_assistant'] );
        }

        $clean['enable_logging'] = ! empty( $settings['enable_logging'] );

        if ( isset( $settings['default_model'] ) ) {
            $clean['default_model'] = sanitize_text_field( $settings['default_model'] );
        }

        if ( isset( $settings['request_timeout'] ) ) {
            $timeout = absint( $settings['request_timeout'] );
            $clean['request_timeout'] = $timeout > 0 ? $timeout : $clean['request_timeout'];
        }

        return $clean;
    }

    /**
     * Render the settings page contents.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP MCP AI Settings', 'wp-mcp-ai' ); ?></h1>
            <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
                <?php
                settings_fields( self::SETTINGS_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the OpenAI API key field.
     */
    public function render_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]" value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Enter the OpenAI secret key with access to the Chat Completions API.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the default assistant dropdown field.
     */
    public function render_default_assistant_field() {
        $settings   = self::get_settings();
        $assistants = $this->get_assistant_posts();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_assistant]" class="regular-text">
            <option value="0" <?php selected( 0, $settings['default_assistant'] ); ?>><?php esc_html_e( 'None', 'wp-mcp-ai' ); ?></option>
            <?php foreach ( $assistants as $assistant ) : ?>
                <option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assistant->ID, $settings['default_assistant'] ); ?>><?php echo esc_html( $assistant->post_title ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'The assistant used by default in REST interactions when one is not provided explicitly.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render logging checkbox.
     */
    public function render_logging_field() {
        $settings = self::get_settings();
        ?>
        <label for="wp-mcp-ai-enable-logging">
            <input id="wp-mcp-ai-enable-logging" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_logging]" value="1" <?php checked( $settings['enable_logging'] ); ?> />
            <?php esc_html_e( 'Write OpenAI request and response details to the debug log.', 'wp-mcp-ai' ); ?>
        </label>
        <?php
    }

    /**
     * Render the default model field.
     */
    public function render_default_model_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_model]" value="<?php echo esc_attr( $settings['default_model'] ); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e( 'The Chat Completions model to use when assistants do not specify one.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the timeout field.
     */
    public function render_timeout_field() {
        $settings = self::get_settings();
        ?>
        <input type="number" min="5" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[request_timeout]" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" class="small-text" />
        <p class="description"><?php esc_html_e( 'How long to wait for OpenAI responses before aborting the request.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Retrieve published assistant posts.
     *
     * @return WP_Post[]
     */
    protected function get_assistant_posts() {
        $args = array(
            'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'all',
        );

        $posts = get_posts( $args );

        if ( ! $posts ) {
            return array();
        }

        return $posts;
    }
}
