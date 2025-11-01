<?php
/**
 * Tool registry singleton.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Maintains a list of available tool providers.
 */
class WP_MCP_AI_Tool_Registry {
    /**
     * Singleton instance.
     *
     * @var WP_MCP_AI_Tool_Registry
     */
    protected static $instance = null;

    /**
     * Registered tools keyed by slug.
     *
     * @var WP_MCP_AI_Tool_Interface[]
     */
    protected $tools = array();

    /**
     * Whether the registry has been initialised.
     *
     * @var bool
     */
    protected $bootstrapped = false;

    /**
     * Human readable messages describing tools that were skipped.
     *
     * @var string[]
     */
    protected $unavailable_tool_messages = array();

    /**
     * Retrieve the singleton instance.
     *
     * @return WP_MCP_AI_Tool_Registry
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Prevent direct construction.
     */
    protected function __construct() {}

    /**
     * Prevent cloning.
     */
    protected function __clone() {}

    /**
     * Prevent unserialisation.
     */
    public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

    /**
     * Initialise the registry by loading default tools and triggering hooks.
     */
    public function init() {
        if ( $this->bootstrapped ) {
            return;
        }

        $this->bootstrapped = true;

        $this->load_default_tools();

        if ( is_admin() && ! empty( $this->unavailable_tool_messages ) ) {
            add_action( 'admin_notices', array( $this, 'render_unavailable_tool_notices' ) );
        }

        /**
         * Allow third parties to register additional tools.
         *
         * @param WP_MCP_AI_Tool_Registry $registry Registry instance.
         */
        do_action( 'wp_mcp_ai_register_tools', $this );
    }

    /**
     * Render admin notices for tools that were skipped during registration.
     */
    public function render_unavailable_tool_notices() {
        if ( empty( $this->unavailable_tool_messages ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        foreach ( $this->unavailable_tool_messages as $message ) {
            if ( empty( $message ) ) {
                continue;
            }

            printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $message ) );
        }
    }

    /**
     * Register a tool implementation.
     *
     * @param string|WP_MCP_AI_Tool_Interface $tool Tool class name or instance.
     * @return bool Whether the tool was registered.
     */
    public function register_tool( $tool ) {
        if ( is_string( $tool ) ) {
            if ( ! class_exists( $tool ) ) {
                return false;
            }

            $tool = new $tool();
        }

        if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
            return false;
        }

        $slug = sanitize_key( $tool->get_slug() );

        if ( empty( $slug ) ) {
            return false;
        }

        $this->tools[ $slug ] = $tool;

        return true;
    }

    /**
     * Unregister a tool by slug.
     *
     * @param string $slug Tool slug.
     */
    public function unregister_tool( $slug ) {
        $slug = sanitize_key( $slug );
        unset( $this->tools[ $slug ] );
    }

    /**
     * Retrieve a tool instance.
     *
     * @param string $slug Tool slug.
     * @return WP_MCP_AI_Tool_Interface|null
     */
    public function get_tool( $slug ) {
        $slug = sanitize_key( $slug );

        return isset( $this->tools[ $slug ] ) ? $this->tools[ $slug ] : null;
    }

    /**
     * Retrieve all registered tools.
     *
     * @return WP_MCP_AI_Tool_Interface[]
     */
    public function get_tools() {
        return array_values( $this->tools );
    }

    /**
     * Retrieve the default tool grouping map keyed by tool slug.
     *
     * @return array<string, string>
     */
    public function get_tool_group_map() {
        $default_map = array(
            'submit_document_prompt'     => 'content',
            'search_content'             => 'content',
            'search_attachments'         => 'content',
            'get_recent_posts'           => 'content',
            'get_elementor_templates' => 'content',
            'save_post'                  => 'content',
            'get_rankmath_seo'           => 'content',
            'get_user_info'              => 'operations',
            'get_site_summary'           => 'operations',
            'get_system_logs'            => 'operations',
            'get_update_status'          => 'operations',
            'get_site_health'            => 'operations',
            'generate_simple_jwt_token'  => 'operations',
            'open_openai_usage'          => 'operations',
            'open_openai_logs'           => 'operations',
            'create_cron_job'            => 'operations',
            'get_import_duty'            => 'operations',
            'create_wpcode_snippet'      => 'operations',
            'purge_cloudflare_cache'     => 'operations',
            'check_wp_cli'               => 'operations',
            'run_openai_external_action' => 'automation',
            'run_crawl4ai_job'           => 'automation',
            'create_google_calendar_event' => 'automation',
            'web_search'                 => 'external-data',
            'crawl4ai_price_lookup'      => 'external-data',
            'get_gdacs_events'           => 'external-data',
            'get_open_meteo_forecast'    => 'external-data',
            'get_nhc_active_storms'      => 'external-data',
            'reliefweb_reports'          => 'external-data',
            'google_analytics_report'    => 'external-data',
            'generate_openai_image'      => 'media',
            'generate_gemini_image'     => 'media',
            'generate_openai_speech'     => 'media',
            'transcribe_openai_audio'    => 'media',
            'generate_perfume_lifestyle_image' => 'media',
            'get_jetengine_items'        => 'jetengine',
            'list_jetengine_rest_routes' => 'jetengine',
            'invoke_jetengine_route'     => 'jetengine',
            'get_woo_recent_orders'      => 'commerce',
            'get_woo_products'           => 'commerce',
            'create_woo_product'         => 'commerce',
            'quickbooks_report'          => 'commerce',
            'search_gmail'               => 'communication',
            'send_group_email'           => 'communication',
            'send_mailjet_email'         => 'communication',
            'send_telegram_message'      => 'communication',
            'post_facebook_instagram'        => 'communication',
            'post_tiktok_video'              => 'communication',
            'post_google_business_update'    => 'communication',
            'post_linkedin_update'           => 'communication',
            'get_facebook_instagram_insights' => 'external-data',
            'get_tiktok_insights'            => 'external-data',
            'get_google_business_insights'   => 'external-data',
            'get_linkedin_insights'          => 'external-data',
            'schedule_notify_sms'        => 'communication',
            'send_whatsapp_message'      => 'communication',
        );

        /**
         * Filter the tool grouping map used throughout the admin UI.
         *
         * @param array<string, string> $default_map Associative array of tool slugs to group identifiers.
         */
        return apply_filters( 'wp_mcp_ai_tool_group_map', $default_map );
    }

    /**
     * Retrieve the default labels for tool groups.
     *
     * @return array<string, string>
     */
    public function get_tool_group_labels() {
        $default_labels = array(
            'content'       => __( 'Content ingestion & search', 'wp-mcp-ai' ),
            'media'         => __( 'Media generation & transcription', 'wp-mcp-ai' ),
            'automation'    => __( 'Automations & workflows', 'wp-mcp-ai' ),
            'jetengine'     => __( 'JetEngine REST utilities', 'wp-mcp-ai' ),
            'commerce'      => __( 'Commerce & finance', 'wp-mcp-ai' ),
            'communication' => __( 'Communications & outreach', 'wp-mcp-ai' ),
            'external-data' => __( 'External data sources', 'wp-mcp-ai' ),
            'operations'    => __( 'Site operations & maintenance', 'wp-mcp-ai' ),
            'other'         => __( 'Other tools', 'wp-mcp-ai' ),
        );

        /**
         * Filter the tool group labels used throughout the admin UI.
         *
         * @param array<string, string> $default_labels Associative array of group identifiers to labels.
         */
        return apply_filters( 'wp_mcp_ai_tool_group_labels', $default_labels );
    }

    /**
     * Load the plugin's default tool providers.
     */
    protected function load_default_tools() {
        $default_tools = array(
            'WP_MCP_AI_Tool_Get_Recent_Posts'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php',
            'WP_MCP_AI_Tool_Search_Content'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-content.php',
            'WP_MCP_AI_Tool_Search_Gmail'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-gmail.php',
            'WP_MCP_AI_Tool_Get_User_Info'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info.php',
            'WP_MCP_AI_Tool_Get_Site_Summary'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-summary.php',
            'WP_MCP_AI_Tool_Get_Site_Health'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-health.php',
            'WP_MCP_AI_Tool_Get_Elementor_Templates' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php',
            'WP_MCP_AI_Tool_Get_NHC_Active_Storms' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php',
            'WP_MCP_AI_Tool_Search_Attachments'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-attachments.php',
            'WP_MCP_AI_Tool_Web_Search'           => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php',
            'WP_MCP_AI_Tool_Crawl4AI_Price_Lookup' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php',
            'WP_MCP_AI_Tool_Get_GDACS_Events'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php',
            'WP_MCP_AI_Tool_Get_Open_Meteo_Forecast' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php',
            'WP_MCP_AI_Tool_Get_Woo_Orders'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php',
            'WP_MCP_AI_Tool_Get_Woo_Products'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-products.php',
            'WP_MCP_AI_Tool_Create_Woo_Product'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product.php',
            'WP_MCP_AI_Tool_Get_JetEngine_Items'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php',
            'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php',
            'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php',
            'WP_MCP_AI_Tool_List_JetEngine_Routes' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php',
            'WP_MCP_AI_Tool_Invoke_JetEngine_Route' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php',
            'WP_MCP_AI_Tool_Run_OpenAI_External_Action' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php',
            'WP_MCP_AI_Tool_Generate_OpenAI_Speech' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php',
            'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php',
            'WP_MCP_AI_Tool_Generate_OpenAI_Image' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php',
            'WP_MCP_AI_Tool_Generate_Gemini_Image' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php',
            'WP_MCP_AI_Tool_Generate_Perfume_Lifestyle_Image' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-perfume-lifestyle-image.php',
            'WP_MCP_AI_Tool_Submit_Document_Prompt' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php',
            'WP_MCP_AI_Tool_Create_Google_Calendar_Event' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php',
            'WP_MCP_AI_Tool_Get_RankMath_SEO' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php',
            'WP_MCP_AI_Tool_Save_Post'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-post.php',
            'WP_MCP_AI_Tool_Run_Crawl4AI_Job'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php',
            'WP_MCP_AI_Tool_Open_OpenAI_Logs'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php',
            'WP_MCP_AI_Tool_Open_OpenAI_Usage'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php',
            'WP_MCP_AI_Tool_Get_System_Logs'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-system-logs.php',
            'WP_MCP_AI_Tool_Get_Update_Status'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-update-status.php',
            'WP_MCP_AI_Tool_Create_Cron_Job'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job.php',
            'WP_MCP_AI_Tool_Generate_Simple_JWT_Token' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php',
            'WP_MCP_AI_Tool_Send_Group_Email'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email.php',
            'WP_MCP_AI_Tool_Purge_Cloudflare_Cache' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php',
            'WP_MCP_AI_Tool_Get_Import_Duty'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-import-duty.php',
            'WP_MCP_AI_Tool_Send_Mailjet_Email'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php',
            'WP_MCP_AI_Tool_Send_Telegram_Message' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php',
            'WP_MCP_AI_Tool_Schedule_Notify_SMS'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-notify-sms.php',
            'WP_MCP_AI_Tool_Send_Mailjet_Email'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php',
            'WP_MCP_AI_Tool_Send_Telegram_Message'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-telegram-message.php',
            'WP_MCP_AI_Tool_Send_WhatsApp_Message'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-whatsapp-message.php',
            'WP_MCP_AI_Tool_Get_QuickBooks_Report' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php',
            'WP_MCP_AI_Tool_ReliefWeb_Reports'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php',
            'WP_MCP_AI_Tool_Get_Google_Analytics_Report' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-google-analytics-report.php',
            'WP_MCP_AI_Tool_Check_WP_CLI'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php',
            'WP_MCP_AI_Tool_Create_WPCode_Snippet' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-wpcode-snippet.php',
            'WP_MCP_AI_Tool_Post_Facebook_Instagram' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-facebook-instagram.php',
            'WP_MCP_AI_Tool_Post_Tiktok_Video'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-tiktok-video.php',
            'WP_MCP_AI_Tool_Post_Google_Business_Update' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-google-business-update.php',
            'WP_MCP_AI_Tool_Post_Linkedin_Update'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-post-linkedin-update.php',
            'WP_MCP_AI_Tool_Get_Facebook_Instagram_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-facebook-instagram-insights.php',
            'WP_MCP_AI_Tool_Get_Tiktok_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-tiktok-insights.php',
            'WP_MCP_AI_Tool_Get_Google_Business_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-google-business-insights.php',
            'WP_MCP_AI_Tool_Get_Linkedin_Insights' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-linkedin-insights.php',
        );

        foreach ( $default_tools as $class => $file ) {
            if ( file_exists( $file ) ) {
                require_once $file;
            }

            if ( class_exists( $class ) ) {
                $should_register = true;

                if ( method_exists( $class, 'is_available' ) ) {
                    $should_register = (bool) call_user_func( array( $class, 'is_available' ) );

                    if ( ! $should_register && method_exists( $class, 'get_unavailable_reason' ) ) {
                        $message = (string) call_user_func( array( $class, 'get_unavailable_reason' ) );
                        if ( $message && ! in_array( $message, $this->unavailable_tool_messages, true ) ) {
                            $this->unavailable_tool_messages[] = $message;
                        }
                    }
                }

                if ( $should_register ) {
                    $this->register_tool( new $class() );
                }
            }
        }
    }
}
