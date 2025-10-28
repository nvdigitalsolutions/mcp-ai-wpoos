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
            'openai_api_key'       => '',
            'gemini_api_key'       => '',
            'default_assistant'    => 0,
            'enable_logging'       => false,
            'default_model'        => 'gpt-4o-mini',
            'default_gemini_model' => 'gemini-1.5-flash',
            'default_provider'     => 'openai',
            'request_timeout'      => 30,
            'auth0_domain'         => '',
            'auth0_audience'       => '',
            'auth0_required_scope' => '',
            'delete_on_uninstall'  => false,
            'crawl4ai_base_url'    => '',
            'crawl4ai_api_key'     => '',
            'group_email_capability'      => 'publish_posts',
            'group_email_max_recipients'  => 100,
            'openai_image_model'          => 'gpt-image-1',
            'openai_image_size'           => '1024x1024',
            'openai_image_quality'        => 'standard',
            'openai_image_background'     => '',
            'openai_speech_model'         => 'gpt-4o-mini-tts',
            'openai_speech_voice'         => 'alloy',
            'openai_speech_format'        => 'mp3',
            'allowed_image_mimes'  => array(),
            'allowed_file_mimes'   => array(),
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
        WP_MCP_AI_Logger::log_event( 'debug', (string) $message, $context );
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
            __( 'Default OpenAI Model', 'wp-mcp-ai' ),
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
            'wp_mcp_ai_gemini_section',
            __( 'Gemini Configuration', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'gemini_api_key',
            __( 'Gemini API Key', 'wp-mcp-ai' ),
            array( $this, 'render_gemini_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_gemini_section'
        );

        add_settings_field(
            'default_gemini_model',
            __( 'Default Gemini Model', 'wp-mcp-ai' ),
            array( $this, 'render_default_gemini_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_gemini_section'
        );

        add_settings_section(
            'wp_mcp_ai_authentication_section',
            __( 'Authentication', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'auth0_domain',
            __( 'Auth0 Domain', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_domain_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_field(
            'auth0_audience',
            __( 'Auth0 API Audience', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_audience_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_field(
            'auth0_required_scope',
            __( 'Required Access Scope', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_scope_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_section(
            'wp_mcp_ai_assistant_section',
            __( 'Assistant Defaults', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'default_provider',
            __( 'Default Provider', 'wp-mcp-ai' ),
            array( $this, 'render_default_provider_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
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

        add_settings_section(
            'wp_mcp_ai_attachments_section',
            __( 'Attachments', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'allowed_image_mimes',
            __( 'Allowed Image MIME Types', 'wp-mcp-ai' ),
            array( $this, 'render_allowed_image_mimes_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_attachments_section'
        );

        add_settings_field(
            'allowed_file_mimes',
            __( 'Allowed File MIME Types', 'wp-mcp-ai' ),
            array( $this, 'render_allowed_file_mimes_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_attachments_section'
        );

        add_settings_section(
            'wp_mcp_ai_tools_section',
            __( 'Tools', 'wp-mcp-ai' ),
            array( $this, 'render_tools_section_description' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'openai_image_model',
            __( 'OpenAI Image Model', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_size',
            __( 'Default Image Size', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_size_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_quality',
            __( 'Default Image Quality', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_quality_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_background',
            __( 'Background Preference', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_background_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_model',
            __( 'OpenAI Speech Model', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_voice',
            __( 'Default Speech Voice', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_voice_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_format',
            __( 'Default Speech Format', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_format_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'crawl4ai_base_url',
            __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
            array( $this, 'render_crawl4ai_base_url_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'crawl4ai_api_key',
            __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
            array( $this, 'render_crawl4ai_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'group_email_capability',
            __( 'Group Email Capability', 'wp-mcp-ai' ),
            array( $this, 'render_group_email_capability_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'group_email_max_recipients',
            __( 'Group Email Recipient Limit', 'wp-mcp-ai' ),
            array( $this, 'render_group_email_max_recipients_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_section(
            'wp_mcp_ai_maintenance_section',
            __( 'Maintenance', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'delete_on_uninstall',
            __( 'Remove Data on Uninstall', 'wp-mcp-ai' ),
            array( $this, 'render_delete_on_uninstall_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_maintenance_section'
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

        if ( isset( $settings['gemini_api_key'] ) ) {
            $clean['gemini_api_key'] = trim( sanitize_text_field( $settings['gemini_api_key'] ) );
        }

        if ( isset( $settings['default_assistant'] ) ) {
            $clean['default_assistant'] = absint( $settings['default_assistant'] );
        }

        $clean['enable_logging'] = ! empty( $settings['enable_logging'] );

        if ( isset( $settings['default_model'] ) ) {
            $clean['default_model'] = sanitize_text_field( $settings['default_model'] );
        }

        if ( isset( $settings['default_gemini_model'] ) ) {
            $clean['default_gemini_model'] = sanitize_text_field( $settings['default_gemini_model'] );
        }

        if ( isset( $settings['default_provider'] ) ) {
            $provider = sanitize_key( $settings['default_provider'] );
            $allowed  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

            if ( ! is_array( $allowed ) ) {
                $allowed = array( 'openai', 'gemini' );
            }

            if ( in_array( $provider, $allowed, true ) ) {
                $clean['default_provider'] = $provider;
            }
        }

        if ( isset( $settings['request_timeout'] ) ) {
            $timeout = absint( $settings['request_timeout'] );

            if ( $timeout > 0 ) {
                $clean['request_timeout'] = max( 5, $timeout );
            }
        }

        if ( isset( $settings['auth0_domain'] ) ) {
            $clean['auth0_domain'] = trim( sanitize_text_field( $settings['auth0_domain'] ) );
        }

        if ( isset( $settings['auth0_audience'] ) ) {
            $clean['auth0_audience'] = trim( sanitize_text_field( $settings['auth0_audience'] ) );
        }

        if ( isset( $settings['auth0_required_scope'] ) ) {
            $clean['auth0_required_scope'] = trim( sanitize_text_field( $settings['auth0_required_scope'] ) );
        }

        $clean['delete_on_uninstall'] = ! empty( $settings['delete_on_uninstall'] );

        if ( isset( $settings['crawl4ai_base_url'] ) ) {
            $base_url = trim( $settings['crawl4ai_base_url'] );

            $clean['crawl4ai_base_url'] = $base_url ? esc_url_raw( $base_url ) : '';
        }

        if ( isset( $settings['crawl4ai_api_key'] ) ) {
            $clean['crawl4ai_api_key'] = trim( sanitize_text_field( $settings['crawl4ai_api_key'] ) );
        }

        if ( isset( $settings['group_email_capability'] ) ) {
            $clean['group_email_capability'] = sanitize_key( $settings['group_email_capability'] );
        }

        if ( isset( $settings['group_email_max_recipients'] ) ) {
            $clean['group_email_max_recipients'] = absint( $settings['group_email_max_recipients'] );
        }

        if ( isset( $settings['openai_image_model'] ) ) {
            $model  = sanitize_text_field( $settings['openai_image_model'] );
            $models = $this->get_openai_image_model_choices();

            if ( isset( $models[ $model ] ) ) {
                $clean['openai_image_model'] = $model;
            }
        }

        if ( isset( $settings['openai_image_size'] ) ) {
            $size   = sanitize_text_field( $settings['openai_image_size'] );
            $sizes  = array_keys( $this->get_openai_image_size_choices() );

            if ( in_array( $size, $sizes, true ) ) {
                $clean['openai_image_size'] = $size;
            }
        }

        if ( isset( $settings['openai_image_quality'] ) ) {
            $quality   = sanitize_key( $settings['openai_image_quality'] );
            $qualities = array_keys( $this->get_openai_image_quality_choices() );

            if ( in_array( $quality, $qualities, true ) ) {
                $clean['openai_image_quality'] = $quality;
            }
        }

        if ( isset( $settings['openai_image_background'] ) ) {
            $background   = sanitize_key( $settings['openai_image_background'] );
            $backgrounds  = array_keys( $this->get_openai_image_background_choices() );

            if ( in_array( $background, $backgrounds, true ) ) {
                $clean['openai_image_background'] = $background;
            }
        }

        if ( isset( $settings['openai_speech_model'] ) ) {
            $clean['openai_speech_model'] = sanitize_text_field( $settings['openai_speech_model'] );
        }

        if ( isset( $settings['openai_speech_voice'] ) ) {
            $clean['openai_speech_voice'] = sanitize_key( $settings['openai_speech_voice'] );
        }

        if ( isset( $settings['openai_speech_format'] ) ) {
            $format   = sanitize_key( $settings['openai_speech_format'] );
            $formats  = array_keys( $this->get_openai_speech_format_choices() );

            if ( in_array( $format, $formats, true ) ) {
                $clean['openai_speech_format'] = $format;
            }
        }

        if ( isset( $settings['allowed_image_mimes'] ) ) {
            $clean['allowed_image_mimes'] = $this->parse_mime_list( $settings['allowed_image_mimes'] );
        }

        if ( isset( $settings['allowed_file_mimes'] ) ) {
            $clean['allowed_file_mimes'] = $this->parse_mime_list( $settings['allowed_file_mimes'] );
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
     * Render the Auth0 domain field.
     */
    public function render_auth0_domain_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_domain]" value="<?php echo esc_attr( $settings['auth0_domain'] ); ?>" class="regular-text" placeholder="example.us.auth0.com" />
        <p class="description"><?php esc_html_e( 'The Auth0 tenant domain that issues access tokens for remote MCP assistants.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Auth0 audience field.
     */
    public function render_auth0_audience_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_audience]" value="<?php echo esc_attr( $settings['auth0_audience'] ); ?>" class="regular-text" placeholder="https://api.example.com/" />
        <p class="description"><?php esc_html_e( 'Optional. When provided, bearer tokens must include this audience (or API Identifier) claim.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Auth0 scope field.
     */
    public function render_auth0_scope_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_required_scope]" value="<?php echo esc_attr( $settings['auth0_required_scope'] ); ?>" class="regular-text" placeholder="mcp:invoke" />
        <p class="description"><?php esc_html_e( 'Optional space-delimited scope that must be present on remote bearer tokens.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the delete on uninstall checkbox.
     */
    public function render_delete_on_uninstall_field() {
        $settings = self::get_settings();
        ?>
        <label for="wp-mcp-ai-delete-on-uninstall">
            <input id="wp-mcp-ai-delete-on-uninstall" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?> />
            <?php esc_html_e( 'When uninstalling the plugin, remove assistants, settings, and other stored data.', 'wp-mcp-ai' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Leave unchecked to preserve plugin data for future installations.', 'wp-mcp-ai' ); ?></p>
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
     * Render the Gemini API key field.
     */
    public function render_gemini_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]" value="<?php echo esc_attr( $settings['gemini_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Enter the Gemini API key with access to the Generative Language API.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Crawl4AI base URL field.
     */
    public function render_crawl4ai_base_url_field() {
        $settings = self::get_settings();
        ?>
        <input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_base_url]" value="<?php echo esc_attr( $settings['crawl4ai_base_url'] ); ?>" class="regular-text" placeholder="https://example.com/" />
        <p class="description"><?php esc_html_e( 'Base URL for the Crawl4AI API (for example, https://localhost:11235/).', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the tools section description.
     */
    public function render_tools_section_description() {
        ?>
        <p><?php esc_html_e( 'Configure the optional MCP tools exposed to assistants.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image model field.
     */
    public function render_openai_image_model_field() {
        $settings = self::get_settings();
        $models   = $this->get_openai_image_model_choices();
        $current  = isset( $settings['openai_image_model'] ) ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_model]" class="regular-text">
            <?php foreach ( $models as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Default OpenAI model used by the Generate OpenAI Image tool.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image size field.
     */
    public function render_openai_image_size_field() {
        $settings = self::get_settings();
        $sizes    = $this->get_openai_image_size_choices();
        $current  = isset( $settings['openai_image_size'] ) ? sanitize_text_field( $settings['openai_image_size'] ) : '1024x1024';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_size]" class="regular-text">
            <?php foreach ( $sizes as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Image dimensions requested from OpenAI when size is not supplied explicitly.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image quality field.
     */
    public function render_openai_image_quality_field() {
        $settings  = self::get_settings();
        $qualities = $this->get_openai_image_quality_choices();
        $current   = isset( $settings['openai_image_quality'] ) ? sanitize_key( $settings['openai_image_quality'] ) : 'standard';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_quality]" class="regular-text">
            <?php foreach ( $qualities as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Quality hint passed to OpenAI when generating new images.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image background field.
     */
    public function render_openai_image_background_field() {
        $settings    = self::get_settings();
        $backgrounds = $this->get_openai_image_background_choices();
        $current     = isset( $settings['openai_image_background'] ) ? sanitize_key( $settings['openai_image_background'] ) : '';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_background]" class="regular-text">
            <?php foreach ( $backgrounds as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Preferred background mode when OpenAI supports the option.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI speech model field.
     */
    public function render_openai_speech_model_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['openai_speech_model'] ) ? sanitize_text_field( $settings['openai_speech_model'] ) : 'gpt-4o-mini-tts';
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_model]"
            value="<?php echo esc_attr( $current ); ?>"
            class="regular-text"
            placeholder="gpt-4o-mini-tts"
        />
        <p class="description"><?php esc_html_e( 'Default text-to-speech model used by the Generate OpenAI Speech tool.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI speech voice field.
     */
    public function render_openai_speech_voice_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['openai_speech_voice'] ) ? sanitize_key( $settings['openai_speech_voice'] ) : 'alloy';
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_voice]"
            value="<?php echo esc_attr( $current ); ?>"
            class="regular-text"
            placeholder="alloy"
        />
        <p class="description"><?php esc_html_e( 'Default OpenAI voice requested for speech responses (for example, alloy, verse, or shimmer).', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI speech format field.
     */
    public function render_openai_speech_format_field() {
        $settings = self::get_settings();
        $formats  = $this->get_openai_speech_format_choices();
        $current  = isset( $settings['openai_speech_format'] ) ? sanitize_key( $settings['openai_speech_format'] ) : 'mp3';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_format]" class="regular-text">
            <?php foreach ( $formats as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Preferred audio container when assistants omit the format.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Crawl4AI API key field.
     */
    public function render_crawl4ai_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_api_key]" value="<?php echo esc_attr( $settings['crawl4ai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Optional bearer token that will be sent with Crawl4AI requests.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the group email capability field.
     */
    public function render_group_email_capability_field() {
        $settings    = self::get_settings();
        $capability  = isset( $settings['group_email_capability'] ) ? sanitize_key( $settings['group_email_capability'] ) : '';
        $choices     = $this->get_group_email_capability_choices();

        if ( '' !== $capability && ! in_array( $capability, $choices, true ) ) {
            $choices[] = $capability;
        }
        ?>
        <select
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[group_email_capability]"
            class="regular-text"
        >
            <option value="" <?php selected( '', $capability ); ?>>
                <?php esc_html_e( 'Any logged-in user (no capability required)', 'wp-mcp-ai' ); ?>
            </option>
            <?php foreach ( $choices as $choice ) : ?>
                <?php $label = $this->get_group_email_capability_label( $choice ); ?>
                <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $capability, $choice ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Select the capability required to use the Send Group Email tool. Choose "Any logged-in user" to allow any logged-in user that passes attachment checks.', 'wp-mcp-ai' ); ?>
        </p>
        <?php
    }

    /**
     * Retrieve the available capability choices for the group email tool.
     *
     * @return string[] List of capability slugs.
     */
    protected function get_group_email_capability_choices() {
        $choices = array();

        if ( function_exists( 'wp_roles' ) ) {
            $wp_roles = wp_roles();

            if ( $wp_roles && is_a( $wp_roles, 'WP_Roles' ) ) {
                foreach ( $wp_roles->roles as $role ) {
                    if ( empty( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
                        continue;
                    }

                    foreach ( $role['capabilities'] as $capability => $granted ) {
                        if ( empty( $granted ) ) {
                            continue;
                        }

                        $capability = sanitize_key( $capability );

                        if ( '' !== $capability ) {
                            $choices[ $capability ] = $capability;
                        }
                    }
                }
            }
        }

        if ( ! isset( $choices['publish_posts'] ) ) {
            $choices['publish_posts'] = 'publish_posts';
        }

        $choices = array_values( $choices );
        sort( $choices, SORT_NATURAL | SORT_FLAG_CASE );

        /**
         * Filter the capability options shown in the group email settings field.
         *
         * @param string[] $choices Capability slugs available for selection.
         */
        $choices = apply_filters( 'wp_mcp_ai_group_email_capability_choices', $choices );

        if ( ! is_array( $choices ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $choices as $choice ) {
            $choice = sanitize_key( $choice );

            if ( '' === $choice ) {
                continue;
            }

            $sanitized[ $choice ] = $choice;
        }

        return array_values( $sanitized );
    }

    /**
     * Convert a capability slug into a human-friendly label.
     *
     * @param string $capability Capability slug.
     * @return string
     */
    protected function get_group_email_capability_label( $capability ) {
        $readable = trim( preg_replace( '/[\-_]+/', ' ', (string) $capability ) );
        $readable = preg_replace( '/\s+/', ' ', $readable );

        if ( '' === $readable ) {
            return $capability;
        }

        $readable = ucwords( $readable );

        if ( strtolower( $readable ) === strtolower( $capability ) ) {
            return $readable;
        }

        return sprintf( '%1$s (%2$s)', $readable, $capability );
    }

    /**
     * Render the group email max recipients field.
     */
    public function render_group_email_max_recipients_field() {
        $settings        = self::get_settings();
        $max_recipients  = isset( $settings['group_email_max_recipients'] ) ? (int) $settings['group_email_max_recipients'] : 0;
        ?>
        <input
            type="number"
            min="0"
            step="1"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[group_email_max_recipients]"
            value="<?php echo esc_attr( $max_recipients ); ?>"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Maximum number of recipients allowed per Send Group Email request. Set to 0 to disable the limit.', 'wp-mcp-ai' ); ?>
        </p>
        <?php
    }

    /**
     * Render the default Gemini model field.
     */
    public function render_default_gemini_model_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_gemini_model]" value="<?php echo esc_attr( $settings['default_gemini_model'] ); ?>" class="regular-text" />
        <?php
    }

    /**
     * Render the default provider dropdown field.
     */
    public function render_default_provider_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
        $choices  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

        if ( ! is_array( $choices ) ) {
            $choices = array( 'openai', 'gemini' );
        }
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_provider]" id="wp-mcp-ai-default-provider" class="regular-text">
            <?php
            foreach ( $choices as $choice ) {
                $choice = sanitize_key( $choice );
                if ( '' === $choice ) {
                    continue;
                }

                $label = 'openai' === $choice ? __( 'OpenAI', 'wp-mcp-ai' ) : __( 'Gemini', 'wp-mcp-ai' );
                ?>
                <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current, $choice ); ?>><?php echo esc_html( $label ); ?></option>
                <?php
            }
            ?>
        </select>
        <p class="description"><?php esc_html_e( 'Select which provider new assistants should use when no override is set.', 'wp-mcp-ai' ); ?></p>
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
        <?php if ( ! empty( $settings['enable_logging'] ) ) :
            $entries = WP_MCP_AI_Logger::get_recent_error_messages();
            ?>
            <p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'wp-mcp-ai' ); ?></p>
            <?php if ( empty( $entries ) ) : ?>
                <p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'wp-mcp-ai' ); ?></p>
            <?php else : ?>
                <ul class="wp-mcp-ai-log-preview">
                    <?php foreach ( $entries as $entry ) :
                        $timestamp = '';

                        if ( ! empty( $entry['timestamp'] ) ) {
                            $timestamp = get_date_from_gmt(
                                $entry['timestamp'],
                                get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
                            );
                        }

                        $type_label    = strtoupper( $entry['type'] );
                        $message_label = $entry['message'];
                        $context_label = '';

                        if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
                            $options = 0;

                            if ( defined( 'JSON_PRETTY_PRINT' ) ) {
                                $options |= JSON_PRETTY_PRINT;
                            }

                            if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
                                $options |= JSON_UNESCAPED_SLASHES;
                            }

                            $context_json = wp_json_encode( $entry['context'], $options );

                            if ( false !== $context_json ) {
                                $context_label = $context_json;
                            }
                        }
                        ?>
                        <li>
                            <?php if ( ! empty( $timestamp ) ) : ?>
                                <span class="wp-mcp-ai-log-preview__time"><?php echo esc_html( $timestamp ); ?></span>
                                &mdash;
                            <?php endif; ?>
                            <span class="wp-mcp-ai-log-preview__type"><?php echo esc_html( $type_label ); ?></span>:
                            <span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
                            <?php if ( '' !== $context_label ) : ?>
                                <details class="wp-mcp-ai-log-preview__context">
                                    <summary><?php esc_html_e( 'Context details', 'wp-mcp-ai' ); ?></summary>
                                    <pre><?php echo esc_html( $context_label ); ?></pre>
                                </details>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
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

    /**
     * Render the allowed image MIME types field.
     */
    public function render_allowed_image_mimes_field() {
        $settings = self::get_settings();
        $value    = $this->format_mime_list_for_display( $settings['allowed_image_mimes'] );
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowed_image_mimes]"
            rows="5"
            cols="40"
            class="large-text code"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php
            printf(
                '%s %s',
                esc_html__(
                    'Optional. Enter one MIME type per line to replace the default allowed image types.',
                    'wp-mcp-ai'
                ),
                esc_html__(
                    'Leave blank to use the plugin defaults.',
                    'wp-mcp-ai'
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the allowed file MIME types field.
     */
    public function render_allowed_file_mimes_field() {
        $settings = self::get_settings();
        $value    = $this->format_mime_list_for_display( $settings['allowed_file_mimes'] );
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowed_file_mimes]"
            rows="6"
            cols="40"
            class="large-text code"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php
            printf(
                '%s %s',
                esc_html__(
                    'Optional. Enter one MIME type per line to replace the default allowed file types.',
                    'wp-mcp-ai'
                ),
                esc_html__(
                    'Leave blank to use the plugin defaults.',
                    'wp-mcp-ai'
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Retrieve the list of available OpenAI image models.
     *
     * @return array
     */
    protected function get_openai_image_model_choices() {
        $models = array(
            'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
        );

        $models = apply_filters( 'wp_mcp_ai_openai_image_models', $models );

        if ( ! is_array( $models ) || empty( $models ) ) {
            $models = array(
                'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
            );
        }

        return $models;
    }

    /**
     * Retrieve the list of available OpenAI image sizes.
     *
     * @return array
     */
    protected function get_openai_image_size_choices() {
        $sizes = array(
            '1024x1024' => __( '1024 × 1024 (square)', 'wp-mcp-ai' ),
            '1024x1536' => __( '1024 × 1536 (portrait)', 'wp-mcp-ai' ),
            '1536x1024' => __( '1536 × 1024 (landscape)', 'wp-mcp-ai' ),
            'auto'      => __( 'Auto (let OpenAI decide)', 'wp-mcp-ai' ),
        );

        $sizes = apply_filters( 'wp_mcp_ai_openai_image_sizes', $sizes );

        if ( ! is_array( $sizes ) || empty( $sizes ) ) {
            $sizes = array(
                '1024x1024' => __( '1024 × 1024 (square)', 'wp-mcp-ai' ),
                '1024x1536' => __( '1024 × 1536 (portrait)', 'wp-mcp-ai' ),
                '1536x1024' => __( '1536 × 1024 (landscape)', 'wp-mcp-ai' ),
                'auto'      => __( 'Auto (let OpenAI decide)', 'wp-mcp-ai' ),
            );
        }

        return $sizes;
    }

    /**
     * Retrieve the list of available OpenAI image quality options.
     *
     * @return array
     */
    protected function get_openai_image_quality_choices() {
        $qualities = array(
            'standard' => __( 'Standard', 'wp-mcp-ai' ),
            'high'     => __( 'High', 'wp-mcp-ai' ),
        );

        $qualities = apply_filters( 'wp_mcp_ai_openai_image_qualities', $qualities );

        if ( ! is_array( $qualities ) || empty( $qualities ) ) {
            $qualities = array(
                'standard' => __( 'Standard', 'wp-mcp-ai' ),
                'high'     => __( 'High', 'wp-mcp-ai' ),
            );
        }

        return $qualities;
    }

    /**
     * Retrieve the list of available OpenAI image background preferences.
     *
     * @return array
     */
    protected function get_openai_image_background_choices() {
        $backgrounds = array(
            ''            => __( 'No preference (OpenAI default)', 'wp-mcp-ai' ),
            'transparent' => __( 'Transparent', 'wp-mcp-ai' ),
            'opaque'      => __( 'Opaque', 'wp-mcp-ai' ),
            'auto'        => __( 'Auto', 'wp-mcp-ai' ),
        );

        $backgrounds = apply_filters( 'wp_mcp_ai_openai_image_backgrounds', $backgrounds );

        if ( ! is_array( $backgrounds ) || empty( $backgrounds ) ) {
            $backgrounds = array(
                ''            => __( 'No preference (OpenAI default)', 'wp-mcp-ai' ),
                'transparent' => __( 'Transparent', 'wp-mcp-ai' ),
                'opaque'      => __( 'Opaque', 'wp-mcp-ai' ),
                'auto'        => __( 'Auto', 'wp-mcp-ai' ),
            );
        }

        return $backgrounds;
    }

    /**
     * Retrieve the allowed OpenAI speech formats.
     *
     * @return array
     */
    protected function get_openai_speech_format_choices() {
        $formats = array(
            'mp3'  => __( 'MP3', 'wp-mcp-ai' ),
            'aac'  => __( 'AAC', 'wp-mcp-ai' ),
            'flac' => __( 'FLAC', 'wp-mcp-ai' ),
            'ogg'  => __( 'OGG', 'wp-mcp-ai' ),
            'opus' => __( 'Opus', 'wp-mcp-ai' ),
            'wav'  => __( 'WAV', 'wp-mcp-ai' ),
        );

        /**
         * Filter the audio format options available in the admin settings.
         *
         * @param array $formats Associative array of format slugs to labels.
         */
        $formats = apply_filters( 'wp_mcp_ai_openai_speech_formats', $formats );

        if ( ! is_array( $formats ) || empty( $formats ) ) {
            return array(
                'mp3' => __( 'MP3', 'wp-mcp-ai' ),
            );
        }

        $sanitized = array();

        foreach ( $formats as $key => $label ) {
            $key = sanitize_key( $key );

            if ( '' === $key ) {
                continue;
            }

            $sanitized[ $key ] = $label;
        }

        if ( empty( $sanitized ) ) {
            return array(
                'mp3' => __( 'MP3', 'wp-mcp-ai' ),
            );
        }

        return $sanitized;
    }

    /**
     * Parse an arbitrary value into a list of MIME types.
     *
     * @param mixed $value Raw submitted value.
     * @return array
     */
    protected function parse_mime_list( $value ) {
        $items = array();

        if ( is_string( $value ) ) {
            $items = preg_split( '/[\r\n]+/', $value );
        } elseif ( is_array( $value ) ) {
            $items = $value;
        }

        if ( ! is_array( $items ) ) {
            $items = array();
        }

        $sanitized = array();

        foreach ( $items as $item ) {
            $item = trim( (string) $item );

            if ( '' === $item ) {
                continue;
            }

            $item = sanitize_text_field( $item );

            if ( '' !== $item ) {
                $sanitized[] = $item;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Convert an array of MIME types to display text.
     *
     * @param mixed $value Stored value.
     * @return string
     */
    protected function format_mime_list_for_display( $value ) {
        if ( ! is_array( $value ) ) {
            return '';
        }

        return implode( "\n", array_map( 'trim', array_filter( $value ) ) );
    }
}
