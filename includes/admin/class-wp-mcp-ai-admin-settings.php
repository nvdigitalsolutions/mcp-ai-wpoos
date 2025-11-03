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
	const DEFAULT_MEMORY_MAX_FILE_BYTES  = 5242880; // 5 MB.
	const OPTION_NAME                    = 'wp_mcp_ai_settings';
	const SETTINGS_GROUP                 = 'wp_mcp_ai_settings_group';
	const PAGE_SLUG                      = 'wp-mcp-ai-settings';
	const SIMPLE_JWT_LOGIN_PLUGIN        = 'simple-jwt-login/simple-jwt-login.php';
	const GMAIL_OAUTH_SCOPE              = 'https://www.googleapis.com/auth/gmail.readonly';
	const GMAIL_OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GMAIL_OAUTH_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
	const GMAIL_PROFILE_ENDPOINT         = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_wp_mcp_ai_gmail_oauth_start', array( $this, 'handle_gmail_oauth_start' ) );
		add_action( 'admin_post_wp_mcp_ai_gmail_oauth_callback', array( $this, 'handle_gmail_oauth_callback' ) );
		add_filter( 'wp_mcp_ai_memory_max_file_bytes', array( $this, 'filter_memory_max_file_bytes' ), 10, 2 );
		add_action( 'admin_post_wp_mcp_ai_prune_log', array( $this, 'handle_prune_log_request' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_simple_jwt_login_notice' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_ollama_connection', array( $this, 'handle_test_ollama_connection' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_ollama_models', array( $this, 'handle_fetch_ollama_models' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_lm_studio_connection', array( $this, 'handle_test_lm_studio_connection' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_lm_studio_models', array( $this, 'handle_fetch_lm_studio_models' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_cloudways_data', array( $this, 'handle_fetch_cloudways_data' ) );
		if ( ! has_filter( 'allowed_redirect_hosts', array( $this, 'allow_gmail_oauth_redirect_host' ) ) ) {
			add_filter( 'allowed_redirect_hosts', array( $this, 'allow_gmail_oauth_redirect_host' ), 10, 2 );
		}
	}

	/**
	 * Returns the option defaults.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'openai_api_key'                    => '',
			'gemini_api_key'                    => '',
			'ollama_endpoint_url'               => '',
			'ollama_model'                      => '',
			'lm_studio_endpoint_url'            => '',
			'lm_studio_model'                   => '',
			'default_assistant'                 => 0,
			'enable_logging'                    => false,
			'default_model'                     => 'gpt-4o-mini',
			'default_gemini_model'              => 'gemini-1.5-flash',
			'default_provider'                  => 'openai',
			'web_search_provider'               => 'duckduckgo',
			'brave_search_api_key'              => '',
			'ita_tariff_api_key'                => '',
			'request_timeout'                   => 30,
			'memory_max_file_bytes'             => self::DEFAULT_MEMORY_MAX_FILE_BYTES,
			'auth0_domain'                      => '',
			'auth0_audience'                    => '',
			'auth0_required_scope'              => '',
			'enable_auth0_github_bridge'        => false,
			'auth0_management_client_id'        => '',
			'auth0_management_client_secret'    => '',
			'enable_simple_jwt_login'           => false,
			'delete_on_uninstall'               => false,
			'crawl4ai_base_url'                 => '',
			'crawl4ai_api_key'                  => '',
			'cloudflare_api_token'              => '',
			'cloudflare_zone_id'                => '',
			'enable_varnish_purge'              => false,
			'cloudways_email'                   => '',
			'cloudways_api_key'                 => '',
			'cloudways_server_id'               => '',
			'cloudways_app_id'                  => '',
			'mailjet_api_key'                   => '',
			'mailjet_api_secret'                => '',
			'mailjet_from_email'                => '',
			'mailjet_from_name'                 => '',
			'quickbooks_company_id'             => '',
			'quickbooks_api_key'                => '',
			'google_analytics_property_id'      => '',
			'google_analytics_credentials_json' => '',
			'gmail_client_id'                   => '',
			'gmail_client_secret'               => '',
			'gmail_refresh_token'               => '',
			'gmail_user_email'                  => '',
			'group_email_capability'            => 'publish_posts',
			'group_email_max_recipients'        => 100,
			'openai_image_model'                => 'gpt-image-1',
			'openai_image_size'                 => '1024x1024',
			'openai_image_quality'              => 'standard',
			'openai_image_response_format'      => 'b64_json',
			'openai_speech_model'               => 'gpt-4o-mini-tts',
			'openai_speech_voice'               => 'alloy',
			'openai_speech_format'              => 'mp3',
			'chat_colors'                       => self::get_default_chat_colors(),
			'allowed_image_mimes'               => array(),
			'allowed_file_mimes'                => array(),
		);
	}

	/**
	 * Retrieve metadata describing external service connectors.
	 *
	 * @return array[]
	 */
	private static function get_connector_definitions() {
		return array(
			'auth0'            => array(
				'label'            => __( 'Auth0', 'wp-mcp-ai' ),
				'required_options' => array( 'auth0_domain', 'auth0_audience' ),
				'fields'           => array(
					'auth0_domain'         => __( 'Domain', 'wp-mcp-ai' ),
					'auth0_audience'       => __( 'API Audience', 'wp-mcp-ai' ),
					'auth0_required_scope' => __( 'Required Scope', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Secures the MCP REST namespace for remote clients that authenticate with bearer tokens.', 'wp-mcp-ai' ),
				'usage'            => __( 'Fill this in when provisioning external connectors or integrations that need REST access.', 'wp-mcp-ai' ),
			),
			'openai'           => array(
				'label'            => __( 'OpenAI', 'wp-mcp-ai' ),
				'required_options' => array( 'openai_api_key' ),
				'fields'           => array(
					'openai_api_key' => __( 'API Key', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Powers chat completions, document tools, speech synthesis, and image generation workflows.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enter this connector before routing assistants through OpenAI or enabling OpenAI-powered tools.', 'wp-mcp-ai' ),
			),
			'gemini'           => array(
				'label'            => __( 'Gemini', 'wp-mcp-ai' ),
				'required_options' => array( 'gemini_api_key' ),
				'fields'           => array(
					'gemini_api_key' => __( 'API Key', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Provides access to Google Gemini models when routing assistant conversations.', 'wp-mcp-ai' ),
				'usage'            => __( 'Add credentials once you plan to use Gemini as a provider or fallback.', 'wp-mcp-ai' ),
			),
			'ollama'           => array(
				'label'            => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'required_options' => array( 'ollama_endpoint_url', 'ollama_model' ),
				'fields'           => array(
					'ollama_endpoint_url' => __( 'Endpoint URL', 'wp-mcp-ai' ),
					'ollama_model'        => __( 'Model', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Connects to a local Ollama instance for privacy-focused, cost-free AI processing.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enter the endpoint URL (e.g., http://localhost:11434) and select a model from your Ollama instance.', 'wp-mcp-ai' ),
			),
			'lm_studio'        => array(
				'label'            => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
				'required_options' => array( 'lm_studio_endpoint_url', 'lm_studio_model' ),
				'fields'           => array(
					'lm_studio_endpoint_url' => __( 'Endpoint URL', 'wp-mcp-ai' ),
					'lm_studio_model'        => __( 'Model', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Connects to a local LM Studio server with OpenAI-compatible API for privacy-focused, cost-free AI processing.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enter the endpoint URL (e.g., http://localhost:1234) and select a model from your LM Studio server.', 'wp-mcp-ai' ),
			),
			'brave'            => array(
				'label'            => __( 'Brave Search', 'wp-mcp-ai' ),
				'required_options' => array( 'brave_search_api_key' ),
				'fields'           => array(
					'brave_search_api_key' => __( 'API Key', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Enables enhanced web search results when assistants run the search tool.', 'wp-mcp-ai' ),
				'usage'            => __( 'Provide the key after switching the web search provider to Brave.', 'wp-mcp-ai' ),
				'active_when'      => array(
					'web_search_provider' => array( 'brave' ),
				),
				'inactive_message' => __( 'Set the web search provider to Brave to activate this connector.', 'wp-mcp-ai' ),
			),
			'ita_tariff_rates' => array(
				'label'            => __( 'ITA Tariff Rates', 'wp-mcp-ai' ),
				'required_options' => array( 'ita_tariff_api_key' ),
				'fields'           => array(
					'ita_tariff_api_key' => __( 'API Key', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Enables automated tariff lookups through Trade.gov for supported destinations.', 'wp-mcp-ai' ),
				'usage'            => __( 'Request an API key from Trade.gov and store it before using the import duty lookup tool.', 'wp-mcp-ai' ),
				'docs_url'         => 'https://developer.trade.gov/ita-tariff-rates-api',
			),
			'crawl4ai'         => array(
				'label'            => __( 'Crawl4AI', 'wp-mcp-ai' ),
				'required_options' => array( 'crawl4ai_base_url' ),
				'fields'           => array(
					'crawl4ai_base_url' => __( 'Base URL', 'wp-mcp-ai' ),
					'crawl4ai_api_key'  => __( 'API Key', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Lets assistants launch Crawl4AI harvesting jobs for large-scale content gathering.', 'wp-mcp-ai' ),
				'usage'            => __( 'Configure this before dispatching Crawl4AI jobs from an assistant workflow.', 'wp-mcp-ai' ),
				'ready_message'    => __( 'Remote Crawl4AI endpoint configured.', 'wp-mcp-ai' ),
				'empty_status'     => array(
					'status'  => 'info',
					'label'   => __( 'Running locally', 'wp-mcp-ai' ),
					'message' => __( 'No remote endpoint configured. Crawl4AI jobs will run locally until a base URL is provided.', 'wp-mcp-ai' ),
				),
			),
			'cloudflare'       => array(
				'label'            => __( 'Cloudflare', 'wp-mcp-ai' ),
				'required_options' => array( 'cloudflare_zone_id', 'cloudflare_api_token' ),
				'fields'           => array(
					'cloudflare_zone_id'   => __( 'Zone ID', 'wp-mcp-ai' ),
					'cloudflare_api_token' => __( 'API Token', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Used by operations automations that purge cache or interact with Cloudflare APIs.', 'wp-mcp-ai' ),
				'usage'            => __( 'Add these credentials ahead of enabling Cloudflare-related tools.', 'wp-mcp-ai' ),
			),
			'varnish'          => array(
				'label'            => __( 'Varnish', 'wp-mcp-ai' ),
				'required_options' => array( 'enable_varnish_purge' ),
				'fields'           => array(
					'enable_varnish_purge' => __( 'Enable Varnish Purge', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Enables purging of local Varnish cache. Sends PURGE requests to 127.0.0.1, which is the standard practice for Varnish on hosting platforms like Cloudways.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enable this option if your server uses Varnish caching and you need to purge it when content updates.', 'wp-mcp-ai' ),
				'empty_status'     => array(
					'status'  => 'info',
					'label'   => __( 'Not enabled', 'wp-mcp-ai' ),
					'message' => __( 'Varnish purge is not currently enabled. Enable it if your hosting environment uses Varnish caching.', 'wp-mcp-ai' ),
				),
			),
			'cloudways'        => array(
				'label'            => __( 'Cloudways', 'wp-mcp-ai' ),
				'required_options' => array( 'cloudways_email', 'cloudways_api_key' ),
				'fields'           => array(
					'cloudways_email'     => __( 'Account Email', 'wp-mcp-ai' ),
					'cloudways_api_key'   => __( 'API Key', 'wp-mcp-ai' ),
					'cloudways_server_id' => __( 'Server ID', 'wp-mcp-ai' ),
					'cloudways_app_id'    => __( 'Application ID', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Connects to Cloudways hosting platform for server and application management.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enter your Cloudways account email and API key. Use the "Fetch Cloudways Data" button to automatically retrieve your server and application IDs.', 'wp-mcp-ai' ),
				'docs_url'         => 'https://developers.cloudways.com/docs/',
			),
			'mailjet'          => array(
				'label'            => __( 'Mailjet', 'wp-mcp-ai' ),
				'required_options' => array( 'mailjet_api_key', 'mailjet_api_secret' ),
				'fields'           => array(
					'mailjet_api_key'    => __( 'API Key', 'wp-mcp-ai' ),
					'mailjet_api_secret' => __( 'API Secret', 'wp-mcp-ai' ),
					'mailjet_from_email' => __( 'From Email', 'wp-mcp-ai' ),
					'mailjet_from_name'  => __( 'From Name', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Allows assistants to send transactional or group email through Mailjet.', 'wp-mcp-ai' ),
				'usage'            => __( 'Populate these fields before assigning assistants to send Mailjet emails.', 'wp-mcp-ai' ),
			),
			'quickbooks'       => array(
				'label'            => __( 'QuickBooks Online', 'wp-mcp-ai' ),
				'required_options' => array( 'quickbooks_company_id', 'quickbooks_api_key' ),
				'fields'           => array(
					'quickbooks_company_id' => __( 'Company ID', 'wp-mcp-ai' ),
					'quickbooks_api_key'    => __( 'API Key / Token', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Feeds financial reporting tools that summarise QuickBooks Online data.', 'wp-mcp-ai' ),
				'usage'            => __( 'Enter credentials when activating the QuickBooks report tool for assistants.', 'wp-mcp-ai' ),
			),
			'google_analytics' => array(
				'label'            => __( 'Google Analytics', 'wp-mcp-ai' ),
				'required_options' => array( 'google_analytics_property_id', 'google_analytics_credentials_json' ),
				'fields'           => array(
					'google_analytics_property_id'      => __( 'Property ID', 'wp-mcp-ai' ),
					'google_analytics_credentials_json' => __( 'Service account JSON', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Supplies authenticated access to the Google Analytics Data API for GA4 properties.', 'wp-mcp-ai' ),
				'usage'            => __( 'Paste a service account credential and default property before enabling Analytics reporting tools.', 'wp-mcp-ai' ),
			),
			'gmail'            => array(
				'label'            => __( 'Gmail', 'wp-mcp-ai' ),
				'required_options' => array( 'gmail_client_id', 'gmail_client_secret', 'gmail_refresh_token', 'gmail_user_email' ),
				'fields'           => array(
					'gmail_client_id'     => __( 'Client ID', 'wp-mcp-ai' ),
					'gmail_client_secret' => __( 'Client Secret', 'wp-mcp-ai' ),
					'gmail_refresh_token' => __( 'Refresh Token', 'wp-mcp-ai' ),
					'gmail_user_email'    => __( 'Authorised Email', 'wp-mcp-ai' ),
				),
				'description'      => __( 'Unlocks Gmail search tools so assistants can review messages within scope.', 'wp-mcp-ai' ),
				'usage'            => __( 'Complete this connector when assistants need to search connected Gmail inboxes.', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Returns metadata about configurable chat colors.
	 *
	 * @return array
	 */
	public static function get_chat_color_definitions() {
		return array(
			'container-border'                    => array(
				'label'       => __( 'Container border', 'wp-mcp-ai' ),
				'group'       => 'container',
				'default'     => '#d5d5d5',
				'format'      => 'hex',
				'description' => __( 'Border surrounding the chat interface.', 'wp-mcp-ai' ),
			),
			'container-background'                => array(
				'label'       => __( 'Container background', 'wp-mcp-ai' ),
				'group'       => 'container',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Main background color for the chat container.', 'wp-mcp-ai' ),
			),
			'container-shadow'                    => array(
				'label'       => __( 'Container shadow', 'wp-mcp-ai' ),
				'group'       => 'container',
				'default'     => 'rgba(15, 23, 42, 0.08)',
				'format'      => 'rgba',
				'description' => __( 'Drop shadow applied to the chat container.', 'wp-mcp-ai' ),
			),
			'shortcut-border'                     => array(
				'label'       => __( 'Shortcut border', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => 'rgba(148, 163, 184, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Border for the transcript toggle and saved reply shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-background'                 => array(
				'label'       => __( 'Shortcut background', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => 'rgba(148, 163, 184, 0.16)',
				'format'      => 'rgba',
				'description' => __( 'Background fill for the transcript toggle and shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-text'                       => array(
				'label'       => __( 'Shortcut text', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => '#111827',
				'format'      => 'hex',
				'description' => __( 'Text and icon color for transcript controls and shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-hover-background'           => array(
				'label'       => __( 'Shortcut hover background', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => 'rgba(59, 130, 246, 0.12)',
				'format'      => 'rgba',
				'description' => __( 'Background color when hovering the transcript toggle or shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-hover-border'               => array(
				'label'       => __( 'Shortcut hover border', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => 'rgba(59, 130, 246, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Border color when hovering transcript controls or shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-hover-text'                 => array(
				'label'       => __( 'Shortcut hover text', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => '#0f172a',
				'format'      => 'hex',
				'description' => __( 'Text color when hovering the transcript toggle or shortcuts.', 'wp-mcp-ai' ),
			),
			'shortcut-focus-ring'                 => array(
				'label'       => __( 'Shortcut focus ring', 'wp-mcp-ai' ),
				'group'       => 'shortcuts',
				'default'     => 'rgba(59, 130, 246, 0.45)',
				'format'      => 'rgba',
				'description' => __( 'Focus outline color for transcript controls and shortcuts.', 'wp-mcp-ai' ),
			),
			'bubble-neutral-background'           => array(
				'label'       => __( 'Default bubble background', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#f5f5f5',
				'format'      => 'hex',
				'description' => __( 'Background color for neutral chat bubbles.', 'wp-mcp-ai' ),
			),
			'bubble-neutral-text'                 => array(
				'label'       => __( 'Default bubble text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#111827',
				'format'      => 'hex',
				'description' => __( 'Primary text color inside neutral chat bubbles.', 'wp-mcp-ai' ),
			),
			'bubble-neutral-border'               => array(
				'label'       => __( 'Default bubble border', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => 'rgba(148, 163, 184, 0.4)',
				'format'      => 'rgba',
				'description' => __( 'Border color used for neutral chat bubbles.', 'wp-mcp-ai' ),
			),
			'bubble-neutral-shadow'               => array(
				'label'       => __( 'Default bubble shadow', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => 'rgba(15, 23, 42, 0.06)',
				'format'      => 'rgba',
				'description' => __( 'Soft shadow beneath neutral chat bubbles.', 'wp-mcp-ai' ),
			),
			'bubble-heading-text'                 => array(
				'label'       => __( 'Bubble heading text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#1e293b',
				'format'      => 'hex',
				'description' => __( 'Heading color for titles inside chat bubbles.', 'wp-mcp-ai' ),
			),
			'code-block-background'               => array(
				'label'       => __( 'Code block background', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#0f172a',
				'format'      => 'hex',
				'description' => __( 'Background for preformatted code blocks.', 'wp-mcp-ai' ),
			),
			'code-block-text'                     => array(
				'label'       => __( 'Code block text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#f8fafc',
				'format'      => 'hex',
				'description' => __( 'Text color for preformatted code blocks.', 'wp-mcp-ai' ),
			),
			'code-block-border'                   => array(
				'label'       => __( 'Code block border', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => 'rgba(59, 130, 246, 0.25)',
				'format'      => 'rgba',
				'description' => __( 'Outline applied to code blocks.', 'wp-mcp-ai' ),
			),
			'blockquote-border'                   => array(
				'label'       => __( 'Blockquote border', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => 'rgba(59, 130, 246, 0.4)',
				'format'      => 'rgba',
				'description' => __( 'Accent border for blockquotes within bubbles.', 'wp-mcp-ai' ),
			),
			'blockquote-background'               => array(
				'label'       => __( 'Blockquote background', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#eef2ff',
				'format'      => 'hex',
				'description' => __( 'Background fill for blockquotes inside chat bubbles.', 'wp-mcp-ai' ),
			),
			'blockquote-text'                     => array(
				'label'       => __( 'Blockquote text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#1e293b',
				'format'      => 'hex',
				'description' => __( 'Text color for quoted content.', 'wp-mcp-ai' ),
			),
			'inline-code-background'              => array(
				'label'       => __( 'Inline code background', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => 'rgba(15, 23, 42, 0.08)',
				'format'      => 'rgba',
				'description' => __( 'Background color for inline code snippets.', 'wp-mcp-ai' ),
			),
			'inline-code-text'                    => array(
				'label'       => __( 'Inline code text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#0f172a',
				'format'      => 'hex',
				'description' => __( 'Text color for inline code snippets.', 'wp-mcp-ai' ),
			),
			'bubble-link-text'                    => array(
				'label'       => __( 'Default link text', 'wp-mcp-ai' ),
				'group'       => 'default-message',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Link color inside neutral chat bubbles.', 'wp-mcp-ai' ),
			),
			'speech-button-background'            => array(
				'label'       => __( 'Speech button background', 'wp-mcp-ai' ),
				'group'       => 'speech',
				'default'     => 'rgba(15, 23, 42, 0.82)',
				'format'      => 'rgba',
				'description' => __( 'Default background for the speech playback button.', 'wp-mcp-ai' ),
			),
			'speech-button-text'                  => array(
				'label'       => __( 'Speech button icon', 'wp-mcp-ai' ),
				'group'       => 'speech',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Icon color within the speech playback button.', 'wp-mcp-ai' ),
			),
			'speech-button-hover-background'      => array(
				'label'       => __( 'Speech button hover', 'wp-mcp-ai' ),
				'group'       => 'speech',
				'default'     => 'rgba(15, 23, 42, 0.94)',
				'format'      => 'rgba',
				'description' => __( 'Background color when hovering over the speech button.', 'wp-mcp-ai' ),
			),
			'speech-button-focus-ring'            => array(
				'label'       => __( 'Speech button focus ring', 'wp-mcp-ai' ),
				'group'       => 'speech',
				'default'     => 'rgba(59, 130, 246, 0.45)',
				'format'      => 'rgba',
				'description' => __( 'Outline color when the speech button receives focus.', 'wp-mcp-ai' ),
			),
			'speech-button-error-background'      => array(
				'label'       => __( 'Speech button error', 'wp-mcp-ai' ),
				'group'       => 'speech',
				'default'     => 'rgba(220, 38, 38, 0.88)',
				'format'      => 'rgba',
				'description' => __( 'Background color when a speech error is shown.', 'wp-mcp-ai' ),
			),
			'copy-button-background'              => array(
				'label'       => __( 'Copy button background', 'wp-mcp-ai' ),
				'group'       => 'clipboard',
				'default'     => 'rgba(15, 23, 42, 0.82)',
				'format'      => 'rgba',
				'description' => __( 'Background color for the copy transcript button.', 'wp-mcp-ai' ),
			),
			'copy-button-text'                    => array(
				'label'       => __( 'Copy button icon', 'wp-mcp-ai' ),
				'group'       => 'clipboard',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Icon color for the copy transcript button.', 'wp-mcp-ai' ),
			),
			'copy-button-hover-background'        => array(
				'label'       => __( 'Copy button hover background', 'wp-mcp-ai' ),
				'group'       => 'clipboard',
				'default'     => 'rgba(15, 23, 42, 0.94)',
				'format'      => 'rgba',
				'description' => __( 'Background color when hovering the copy transcript button.', 'wp-mcp-ai' ),
			),
			'copy-button-focus-ring'              => array(
				'label'       => __( 'Copy button focus ring', 'wp-mcp-ai' ),
				'group'       => 'clipboard',
				'default'     => 'rgba(59, 130, 246, 0.45)',
				'format'      => 'rgba',
				'description' => __( 'Focus outline color for the copy transcript button.', 'wp-mcp-ai' ),
			),
			'copy-button-error-background'        => array(
				'label'       => __( 'Copy button error', 'wp-mcp-ai' ),
				'group'       => 'clipboard',
				'default'     => 'rgba(220, 38, 38, 0.88)',
				'format'      => 'rgba',
				'description' => __( 'Background color when the copy transcript button fails.', 'wp-mcp-ai' ),
			),
			'user-bubble-gradient-start'          => array(
				'label'       => __( 'User bubble gradient start', 'wp-mcp-ai' ),
				'group'       => 'user-message',
				'default'     => '#2747f0',
				'format'      => 'hex',
				'description' => __( 'Starting color for the user message gradient.', 'wp-mcp-ai' ),
			),
			'user-bubble-gradient-end'            => array(
				'label'       => __( 'User bubble gradient end', 'wp-mcp-ai' ),
				'group'       => 'user-message',
				'default'     => '#4855f5',
				'format'      => 'hex',
				'description' => __( 'Ending color for the user message gradient.', 'wp-mcp-ai' ),
			),
			'user-bubble-text'                    => array(
				'label'       => __( 'User bubble text', 'wp-mcp-ai' ),
				'group'       => 'user-message',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Text and link color for user messages.', 'wp-mcp-ai' ),
			),
			'user-bubble-shadow'                  => array(
				'label'       => __( 'User bubble shadow', 'wp-mcp-ai' ),
				'group'       => 'user-message',
				'default'     => 'rgba(39, 71, 240, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Shadow cast by user chat bubbles.', 'wp-mcp-ai' ),
			),
			'assistant-bubble-background'         => array(
				'label'       => __( 'Assistant bubble background', 'wp-mcp-ai' ),
				'group'       => 'assistant-message',
				'default'     => '#f8faff',
				'format'      => 'hex',
				'description' => __( 'Background color for assistant responses.', 'wp-mcp-ai' ),
			),
			'assistant-bubble-border'             => array(
				'label'       => __( 'Assistant bubble border', 'wp-mcp-ai' ),
				'group'       => 'assistant-message',
				'default'     => 'rgba(59, 130, 246, 0.25)',
				'format'      => 'rgba',
				'description' => __( 'Border color for assistant responses.', 'wp-mcp-ai' ),
			),
			'assistant-bubble-shadow'             => array(
				'label'       => __( 'Assistant bubble shadow', 'wp-mcp-ai' ),
				'group'       => 'assistant-message',
				'default'     => 'rgba(59, 130, 246, 0.08)',
				'format'      => 'rgba',
				'description' => __( 'Shadow used beneath assistant responses.', 'wp-mcp-ai' ),
			),
			'assistant-strong-text'               => array(
				'label'       => __( 'Assistant strong text', 'wp-mcp-ai' ),
				'group'       => 'assistant-message',
				'default'     => '#1d4ed8',
				'format'      => 'hex',
				'description' => __( 'Accent color for bold text in assistant messages.', 'wp-mcp-ai' ),
			),
			'assistant-em-text'                   => array(
				'label'       => __( 'Assistant emphasized text', 'wp-mcp-ai' ),
				'group'       => 'assistant-message',
				'default'     => '#4338ca',
				'format'      => 'hex',
				'description' => __( 'Accent color for italic text in assistant messages.', 'wp-mcp-ai' ),
			),
			'tool-bubble-background'              => array(
				'label'       => __( 'Tool bubble background', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => '#0f172a',
				'format'      => 'hex',
				'description' => __( 'Background color for tool output bubbles.', 'wp-mcp-ai' ),
			),
			'tool-bubble-text'                    => array(
				'label'       => __( 'Tool bubble text', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => '#e2e8f0',
				'format'      => 'hex',
				'description' => __( 'Text color used in tool output bubbles.', 'wp-mcp-ai' ),
			),
			'tool-bubble-border'                  => array(
				'label'       => __( 'Tool bubble border', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => 'rgba(96, 165, 250, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Border color for tool output bubbles.', 'wp-mcp-ai' ),
			),
			'tool-bubble-inner-shadow'            => array(
				'label'       => __( 'Tool bubble inner shadow', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => 'rgba(30, 64, 175, 0.4)',
				'format'      => 'rgba',
				'description' => __( 'Inset outline applied inside tool bubbles.', 'wp-mcp-ai' ),
			),
			'tool-bubble-link-text'               => array(
				'label'       => __( 'Tool link text', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => '#93c5fd',
				'format'      => 'hex',
				'description' => __( 'Link color for tool output bubbles.', 'wp-mcp-ai' ),
			),
			'tool-code-background'                => array(
				'label'       => __( 'Tool code background', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => 'rgba(148, 163, 184, 0.18)',
				'format'      => 'rgba',
				'description' => __( 'Background for inline code inside tool outputs.', 'wp-mcp-ai' ),
			),
			'tool-code-text'                      => array(
				'label'       => __( 'Tool code text', 'wp-mcp-ai' ),
				'group'       => 'tool-message',
				'default'     => '#f8fafc',
				'format'      => 'hex',
				'description' => __( 'Text color for inline code within tool outputs.', 'wp-mcp-ai' ),
			),
			'system-bubble-background'            => array(
				'label'       => __( 'System bubble background', 'wp-mcp-ai' ),
				'group'       => 'system-message',
				'default'     => '#fef9c3',
				'format'      => 'hex',
				'description' => __( 'Background color for system messages.', 'wp-mcp-ai' ),
			),
			'system-bubble-text'                  => array(
				'label'       => __( 'System bubble text', 'wp-mcp-ai' ),
				'group'       => 'system-message',
				'default'     => '#854d0e',
				'format'      => 'hex',
				'description' => __( 'Text color used in system messages.', 'wp-mcp-ai' ),
			),
			'system-bubble-border'                => array(
				'label'       => __( 'System bubble border', 'wp-mcp-ai' ),
				'group'       => 'system-message',
				'default'     => '#facc15',
				'format'      => 'hex',
				'description' => __( 'Accent border for system messages.', 'wp-mcp-ai' ),
			),
			'status-text'                         => array(
				'label'       => __( 'Status text', 'wp-mcp-ai' ),
				'group'       => 'status',
				'default'     => '#1d4ed8',
				'format'      => 'hex',
				'description' => __( 'Primary color for status messages.', 'wp-mcp-ai' ),
			),
			'status-background'                   => array(
				'label'       => __( 'Status background', 'wp-mcp-ai' ),
				'group'       => 'status',
				'default'     => '#eef2ff',
				'format'      => 'hex',
				'description' => __( 'Background for status notices below the transcript.', 'wp-mcp-ai' ),
			),
			'status-border'                       => array(
				'label'       => __( 'Status border', 'wp-mcp-ai' ),
				'group'       => 'status',
				'default'     => '#3b82f6',
				'format'      => 'hex',
				'description' => __( 'Accent border for status notices.', 'wp-mcp-ai' ),
			),
			'label-text'                          => array(
				'label'       => __( 'Form label text', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#0f172a',
				'format'      => 'hex',
				'description' => __( 'Color used for form field labels.', 'wp-mcp-ai' ),
			),
			'input-border'                        => array(
				'label'       => __( 'Input border', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#cbd5f5',
				'format'      => 'hex',
				'description' => __( 'Border color for the chat input field.', 'wp-mcp-ai' ),
			),
			'input-background'                    => array(
				'label'       => __( 'Input background', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#f9fafb',
				'format'      => 'hex',
				'description' => __( 'Background color for the chat input field.', 'wp-mcp-ai' ),
			),
			'input-focus-border'                  => array(
				'label'       => __( 'Input focus border', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#4361ff',
				'format'      => 'hex',
				'description' => __( 'Border color when the input field is focused.', 'wp-mcp-ai' ),
			),
			'input-focus-shadow'                  => array(
				'label'       => __( 'Input focus glow', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => 'rgba(67, 97, 255, 0.2)',
				'format'      => 'rgba',
				'description' => __( 'Glow applied when the input field is focused.', 'wp-mcp-ai' ),
			),
			'attach-border'                       => array(
				'label'       => __( 'Attachment button border', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#c3c4c7',
				'format'      => 'hex',
				'description' => __( 'Border for the “Attach file” button.', 'wp-mcp-ai' ),
			),
			'attach-text'                         => array(
				'label'       => __( 'Attachment button text', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#1d2327',
				'format'      => 'hex',
				'description' => __( 'Text color for the “Attach file” button and attachment titles.', 'wp-mcp-ai' ),
			),
			'attach-hover-background'             => array(
				'label'       => __( 'Attachment hover background', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#f0f0f0',
				'format'      => 'hex',
				'description' => __( 'Background color when hovering the attachment button.', 'wp-mcp-ai' ),
			),
			'attach-hover-border'                 => array(
				'label'       => __( 'Attachment hover border', 'wp-mcp-ai' ),
				'group'       => 'form',
				'default'     => '#a7aaad',
				'format'      => 'hex',
				'description' => __( 'Border color when hovering the attachment button.', 'wp-mcp-ai' ),
			),
			'submit-gradient-start'               => array(
				'label'       => __( 'Submit gradient start', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#3b5bff',
				'format'      => 'hex',
				'description' => __( 'Starting color for the Send button gradient.', 'wp-mcp-ai' ),
			),
			'submit-gradient-end'                 => array(
				'label'       => __( 'Submit gradient end', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#7c5cff',
				'format'      => 'hex',
				'description' => __( 'Ending color for the Send button gradient.', 'wp-mcp-ai' ),
			),
			'submit-text'                         => array(
				'label'       => __( 'Submit button text', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Text color for the Send button.', 'wp-mcp-ai' ),
			),
			'submit-shadow'                       => array(
				'label'       => __( 'Submit button shadow', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => 'rgba(59, 91, 255, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Shadow below the Send button.', 'wp-mcp-ai' ),
			),
			'submit-hover-gradient-start'         => array(
				'label'       => __( 'Submit hover gradient start', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#324cf8',
				'format'      => 'hex',
				'description' => __( 'Starting gradient color when hovering the Send button.', 'wp-mcp-ai' ),
			),
			'submit-hover-gradient-end'           => array(
				'label'       => __( 'Submit hover gradient end', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#6a4bff',
				'format'      => 'hex',
				'description' => __( 'Ending gradient color when hovering the Send button.', 'wp-mcp-ai' ),
			),
			'submit-hover-shadow'                 => array(
				'label'       => __( 'Submit hover shadow', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => 'rgba(50, 76, 248, 0.4)',
				'format'      => 'rgba',
				'description' => __( 'Shadow applied to the Send button on hover.', 'wp-mcp-ai' ),
			),
			'submit-active-gradient-start'        => array(
				'label'       => __( 'Submit active gradient start', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#2f44f0',
				'format'      => 'hex',
				'description' => __( 'Starting gradient color while the Send button is pressed.', 'wp-mcp-ai' ),
			),
			'submit-active-gradient-end'          => array(
				'label'       => __( 'Submit active gradient end', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#5b3eff',
				'format'      => 'hex',
				'description' => __( 'Ending gradient color while the Send button is pressed.', 'wp-mcp-ai' ),
			),
			'submit-active-shadow'                => array(
				'label'       => __( 'Submit active shadow', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => 'rgba(47, 68, 240, 0.38)',
				'format'      => 'rgba',
				'description' => __( 'Shadow applied to the Send button while it is pressed.', 'wp-mcp-ai' ),
			),
			'submit-disabled-background'          => array(
				'label'       => __( 'Submit disabled background', 'wp-mcp-ai' ),
				'group'       => 'actions',
				'default'     => '#9aa5ff',
				'format'      => 'hex',
				'description' => __( 'Background color when the Send button is disabled.', 'wp-mcp-ai' ),
			),
			'attachments-border'                  => array(
				'label'       => __( 'Attachments border', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#e2e4e7',
				'format'      => 'hex',
				'description' => __( 'Border for the attachments container and items.', 'wp-mcp-ai' ),
			),
			'attachments-background'              => array(
				'label'       => __( 'Attachments background', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#f9fafb',
				'format'      => 'hex',
				'description' => __( 'Background color for the attachments container.', 'wp-mcp-ai' ),
			),
			'attachments-item-background'         => array(
				'label'       => __( 'Attachment item background', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Background color for individual attachment rows.', 'wp-mcp-ai' ),
			),
			'attachments-meta-text'               => array(
				'label'       => __( 'Attachment meta text', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#646970',
				'format'      => 'hex',
				'description' => __( 'Secondary text color for attachment metadata.', 'wp-mcp-ai' ),
			),
			'attachments-remove-text'             => array(
				'label'       => __( 'Remove link text', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#3858e9',
				'format'      => 'hex',
				'description' => __( 'Link color for removing attachments.', 'wp-mcp-ai' ),
			),
			'attachments-remove-hover-background' => array(
				'label'       => __( 'Remove link hover background', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => 'rgba(56, 88, 233, 0.1)',
				'format'      => 'rgba',
				'description' => __( 'Background when hovering the attachment remove link.', 'wp-mcp-ai' ),
			),
			'attachments-remove-hover-text'       => array(
				'label'       => __( 'Remove link hover text', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#2b45b8',
				'format'      => 'hex',
				'description' => __( 'Text color when hovering the attachment remove link.', 'wp-mcp-ai' ),
			),
			'bubble-attachments-text'             => array(
				'label'       => __( 'Bubble attachment text', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Text color for attachments listed inside bubbles.', 'wp-mcp-ai' ),
			),
			'bubble-attachments-link-text'        => array(
				'label'       => __( 'Bubble attachment links', 'wp-mcp-ai' ),
				'group'       => 'attachments',
				'default'     => '#fff',
				'format'      => 'hex',
				'description' => __( 'Link color for attachments displayed within bubbles.', 'wp-mcp-ai' ),
			),
			'notice-border'                       => array(
				'label'       => __( 'Alert border', 'wp-mcp-ai' ),
				'group'       => 'alerts',
				'default'     => 'rgba(214, 54, 56, 0.35)',
				'format'      => 'rgba',
				'description' => __( 'Border for alert notices rendered by the shortcode.', 'wp-mcp-ai' ),
			),
			'notice-background'                   => array(
				'label'       => __( 'Alert background', 'wp-mcp-ai' ),
				'group'       => 'alerts',
				'default'     => '#fef2f2',
				'format'      => 'hex',
				'description' => __( 'Background for alert notices rendered by the shortcode.', 'wp-mcp-ai' ),
			),
			'notice-text'                         => array(
				'label'       => __( 'Alert text', 'wp-mcp-ai' ),
				'group'       => 'alerts',
				'default'     => '#8a1f1f',
				'format'      => 'hex',
				'description' => __( 'Text color for alert notices.', 'wp-mcp-ai' ),
			),
			'notice-shadow'                       => array(
				'label'       => __( 'Alert shadow', 'wp-mcp-ai' ),
				'group'       => 'alerts',
				'default'     => 'rgba(214, 54, 56, 0.12)',
				'format'      => 'rgba',
				'description' => __( 'Shadow applied to alert notices.', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Returns the default chat colors indexed by color key.
	 *
	 * @return array
	 */
	public static function get_default_chat_colors() {
		$defaults = array();

		foreach ( self::get_chat_color_definitions() as $key => $definition ) {
			$defaults[ $key ] = $definition['default'];
		}

		return $defaults;
	}

	/**
	 * Returns the list of available provider choices.
	 *
	 * @return array
	 */
	public static function get_available_providers() {
		return array( 'openai', 'gemini', 'ollama', 'lm_studio' );
	}

	/**
	 * Returns the display labels for color groups.
	 *
	 * @return array
	 */
	public static function get_chat_color_groups() {
		return array(
			'container'         => __( 'Chat container', 'wp-mcp-ai' ),
			'shortcuts'         => __( 'Transcript toggle & shortcuts', 'wp-mcp-ai' ),
			'default-message'   => __( 'Default message bubble', 'wp-mcp-ai' ),
			'speech'            => __( 'Speech controls', 'wp-mcp-ai' ),
			'clipboard'         => __( 'Copy transcript button', 'wp-mcp-ai' ),
			'user-message'      => __( 'User messages', 'wp-mcp-ai' ),
			'assistant-message' => __( 'Assistant messages', 'wp-mcp-ai' ),
			'tool-message'      => __( 'Tool messages', 'wp-mcp-ai' ),
			'system-message'    => __( 'System messages', 'wp-mcp-ai' ),
			'status'            => __( 'Status notice', 'wp-mcp-ai' ),
			'form'              => __( 'Form elements', 'wp-mcp-ai' ),
			'actions'           => __( 'Action buttons', 'wp-mcp-ai' ),
			'attachments'       => __( 'Attachments', 'wp-mcp-ai' ),
			'alerts'            => __( 'Alert notice', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Retrieve the saved chat colors merged with defaults.
	 *
	 * @return array
	 */
	public static function get_chat_colors() {
		$settings = self::get_settings();

		if ( isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ) {
			return array_merge( self::get_default_chat_colors(), $settings['chat_colors'] );
		}

		return self::get_default_chat_colors();
	}

	/**
	 * Build CSS that injects the selected chat colors.
	 *
	 * @return string
	 */
	public static function get_chat_color_css() {
		$colors       = self::get_chat_colors();
		$definitions  = self::get_chat_color_definitions();
		$declarations = array();

		foreach ( $colors as $key => $value ) {
			if ( '' === $value || ! isset( $definitions[ $key ] ) ) {
				continue;
			}

			$declarations[] = sprintf( '    --wp-mcp-ai-color-%s: %s;', sanitize_key( $key ), $value );
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		return ".wp-mcp-ai-chat {\n" . implode( "\n", $declarations ) . "\n}\n";
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

		$settings = wp_parse_args( $saved, self::get_default_settings() );

		if ( ! isset( $settings['chat_colors'] ) || ! is_array( $settings['chat_colors'] ) ) {
			$settings['chat_colors'] = self::get_default_chat_colors();
		} else {
			$settings['chat_colors'] = array_merge( self::get_default_chat_colors(), $settings['chat_colors'] );
		}

		return $settings;
	}

	/**
	 * Determine whether a connector should be considered active for the current settings.
	 *
	 * @param array $definition Connector definition.
	 * @param array $settings   Current plugin settings.
	 * @return bool
	 */
	private function is_connector_active( $definition, $settings ) {
		if ( empty( $definition['active_when'] ) || ! is_array( $definition['active_when'] ) ) {
			return true;
		}

		foreach ( $definition['active_when'] as $option_key => $allowed_values ) {
			$allowed_values = (array) $allowed_values;
			$value          = isset( $settings[ $option_key ] ) ? $settings[ $option_key ] : '';

			if ( ! in_array( $value, $allowed_values, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Format a list of missing credential labels inside a translated message template.
	 *
	 * @param array  $missing_keys Missing option keys.
	 * @param array  $fields       Map of option keys to display labels.
	 * @param string $template     Message template containing a single %s placeholder.
	 * @return string
	 */
	private function format_connector_missing_message( $missing_keys, $fields, $template ) {
		if ( empty( $missing_keys ) ) {
			return '';
		}

		$labels = array();

		foreach ( $missing_keys as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$labels[] = $fields[ $key ];
			} else {
				$labels[] = $key;
			}
		}

		$list = wp_sprintf_l( '%l', $labels );

		return sprintf( $template, $list );
	}

	/**
	 * Build connector status information for the checklist panel.
	 *
	 * @param array $settings Current plugin settings.
	 * @return array
	 */
	private function get_connector_statuses( $settings ) {
		$definitions = self::get_connector_definitions();
		$statuses    = array();

		foreach ( $definitions as $key => $definition ) {
			$is_active = $this->is_connector_active( $definition, $settings );

			$status = array(
				'key'         => $key,
				'label'       => isset( $definition['label'] ) ? $definition['label'] : $key,
				'description' => isset( $definition['description'] ) ? $definition['description'] : '',
				'usage'       => isset( $definition['usage'] ) ? $definition['usage'] : '',
				'docs_url'    => isset( $definition['docs_url'] ) ? $definition['docs_url'] : '',
			);

			if ( ! $is_active ) {
				$status['status']         = 'inactive';
				$status['status_label']   = isset( $definition['inactive_label'] ) ? $definition['inactive_label'] : __( 'Inactive', 'wp-mcp-ai' );
				$status['status_message'] = isset( $definition['inactive_message'] ) ? $definition['inactive_message'] : __( 'This connector is not currently in use.', 'wp-mcp-ai' );
				$statuses[]               = $status;
				continue;
			}

			$required_options = isset( $definition['required_options'] ) ? (array) $definition['required_options'] : array();
			$fields           = isset( $definition['fields'] ) ? $definition['fields'] : array();
			$missing          = array();
			$filled           = array();

			foreach ( $required_options as $option_key ) {
				$value = isset( $settings[ $option_key ] ) ? $settings[ $option_key ] : '';

				if ( '' !== trim( (string) $value ) ) {
					$filled[] = $option_key;
				} else {
					$missing[] = $option_key;
				}
			}

			if ( empty( $required_options ) ) {
				$status['status']         = 'info';
				$status['status_label']   = isset( $definition['info_label'] ) ? $definition['info_label'] : __( 'Info', 'wp-mcp-ai' );
				$status['status_message'] = isset( $definition['info_message'] ) ? $definition['info_message'] : '';
			} elseif ( empty( $missing ) ) {
				$status['status']         = 'ready';
				$status['status_label']   = __( 'Ready', 'wp-mcp-ai' );
				$status['status_message'] = isset( $definition['ready_message'] ) ? $definition['ready_message'] : __( 'All required credentials are stored.', 'wp-mcp-ai' );
			} elseif ( count( $filled ) > 0 ) {
				$status['status']         = 'partial';
				$status['status_label']   = __( 'Incomplete', 'wp-mcp-ai' );
				$status['status_message'] = $this->format_connector_missing_message( $missing, $fields, __( 'Add the missing credential: %s.', 'wp-mcp-ai' ) );
			} elseif ( isset( $definition['empty_status'] ) && is_array( $definition['empty_status'] ) ) {
					$empty_status = wp_parse_args(
						$definition['empty_status'],
						array(
							'status'  => 'info',
							'label'   => __( 'Info', 'wp-mcp-ai' ),
							'message' => '',
						)
					);

					$status['status']         = $empty_status['status'];
					$status['status_label']   = $empty_status['label'];
					$status['status_message'] = $empty_status['message'];
			} else {
				$status['status']         = 'missing';
				$status['status_label']   = __( 'Action required', 'wp-mcp-ai' );
				$status['status_message'] = $this->format_connector_missing_message( $missing, $fields, __( 'No credentials stored yet. Provide: %s.', 'wp-mcp-ai' ) );
			}

			$statuses[] = $status;
		}

		return $statuses;
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
			'wp_mcp_ai_ollama_section',
			__( 'Ollama Configuration (Local AI)', 'wp-mcp-ai' ),
			array( $this, 'render_ollama_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ollama_endpoint_url',
			__( 'Ollama Endpoint URL', 'wp-mcp-ai' ),
			array( $this, 'render_ollama_endpoint_url_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ollama_section'
		);

		add_settings_field(
			'ollama_model',
			__( 'Ollama Model', 'wp-mcp-ai' ),
			array( $this, 'render_ollama_model_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_ollama_section'
		);

		add_settings_section(
			'wp_mcp_ai_lm_studio_section',
			__( 'LM Studio Configuration (Local AI)', 'wp-mcp-ai' ),
			array( $this, 'render_lm_studio_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'lm_studio_endpoint_url',
			__( 'LM Studio Endpoint URL', 'wp-mcp-ai' ),
			array( $this, 'render_lm_studio_endpoint_url_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_lm_studio_section'
		);

		add_settings_field(
			'lm_studio_model',
			__( 'LM Studio Model', 'wp-mcp-ai' ),
			array( $this, 'render_lm_studio_model_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_lm_studio_section'
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

		add_settings_field(
			'enable_auth0_github_bridge',
			__( 'Enable Auth0 GitHub bridge', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_github_bridge_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_field(
			'auth0_management_client_id',
			__( 'Auth0 Management Client ID', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_management_client_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_field(
			'auth0_management_client_secret',
			__( 'Auth0 Management Client Secret', 'wp-mcp-ai' ),
			array( $this, 'render_auth0_management_client_secret_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_authentication_section'
		);

		add_settings_field(
			'enable_simple_jwt_login',
			__( 'Enable Simple JWT Login tokens', 'wp-mcp-ai' ),
			array( $this, 'render_simple_jwt_login_field' ),
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
			__( 'Default API Provider', 'wp-mcp-ai' ),
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

		add_settings_field(
			'memory_max_file_bytes',
			__( 'Maximum Memory File Size', 'wp-mcp-ai' ),
			array( $this, 'render_memory_max_file_bytes_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_attachments_section'
		);

		add_settings_section(
			'wp_mcp_ai_quickbooks_section',
			__( 'QuickBooks Online', 'wp-mcp-ai' ),
			array( $this, 'render_quickbooks_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'wp_mcp_ai_google_analytics_section',
			__( 'Google Analytics', 'wp-mcp-ai' ),
			array( $this, 'render_google_analytics_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'google_analytics_property_id',
			__( 'Default Property ID', 'wp-mcp-ai' ),
			array( $this, 'render_google_analytics_property_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_google_analytics_section'
		);

		add_settings_field(
			'google_analytics_credentials_json',
			__( 'Service Account JSON', 'wp-mcp-ai' ),
			array( $this, 'render_google_analytics_credentials_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_google_analytics_section'
		);

		add_settings_field(
			'quickbooks_company_id',
			__( 'Company ID', 'wp-mcp-ai' ),
			array( $this, 'render_quickbooks_company_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_quickbooks_section'
		);

		add_settings_field(
			'quickbooks_api_key',
			__( 'API Key / Access Token', 'wp-mcp-ai' ),
			array( $this, 'render_quickbooks_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_quickbooks_section'
		);

		add_settings_section(
			'wp_mcp_ai_tools_section',
			__( 'Tools', 'wp-mcp-ai' ),
			array( $this, 'render_tools_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'web_search_provider',
			__( 'Web Search Provider', 'wp-mcp-ai' ),
			array( $this, 'render_web_search_provider_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'brave_search_api_key',
			__( 'Brave Search API Key', 'wp-mcp-ai' ),
			array( $this, 'render_brave_search_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'ita_tariff_api_key',
			__( 'ITA Tariff Rates API Key', 'wp-mcp-ai' ),
			array( $this, 'render_ita_tariff_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
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
			'openai_image_response_format',
			__( 'Image Output Type', 'wp-mcp-ai' ),
			array( $this, 'render_openai_image_response_format_field' ),
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
			'cloudflare_zone_id',
			__( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
			array( $this, 'render_cloudflare_zone_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'cloudflare_api_token',
			__( 'Cloudflare API Token', 'wp-mcp-ai' ),
			array( $this, 'render_cloudflare_api_token_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'enable_varnish_purge',
			__( 'Enable Varnish Purge', 'wp-mcp-ai' ),
			array( $this, 'render_enable_varnish_purge_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'cloudways_email',
			__( 'Cloudways Account Email', 'wp-mcp-ai' ),
			array( $this, 'render_cloudways_email_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'cloudways_api_key',
			__( 'Cloudways API Key', 'wp-mcp-ai' ),
			array( $this, 'render_cloudways_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'cloudways_server_id',
			__( 'Cloudways Server ID', 'wp-mcp-ai' ),
			array( $this, 'render_cloudways_server_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'cloudways_app_id',
			__( 'Cloudways Application ID', 'wp-mcp-ai' ),
			array( $this, 'render_cloudways_app_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'mailjet_api_key',
			__( 'Mailjet API Key', 'wp-mcp-ai' ),
			array( $this, 'render_mailjet_api_key_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'mailjet_api_secret',
			__( 'Mailjet API Secret', 'wp-mcp-ai' ),
			array( $this, 'render_mailjet_api_secret_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'mailjet_from_email',
			__( 'Mailjet From Email', 'wp-mcp-ai' ),
			array( $this, 'render_mailjet_from_email_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_tools_section'
		);

		add_settings_field(
			'mailjet_from_name',
			__( 'Mailjet From Name', 'wp-mcp-ai' ),
			array( $this, 'render_mailjet_from_name_field' ),
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
			'wp_mcp_ai_gmail_section',
			__( 'Gmail Integration', 'wp-mcp-ai' ),
			array( $this, 'render_gmail_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'gmail_client_id',
			__( 'Gmail Client ID', 'wp-mcp-ai' ),
			array( $this, 'render_gmail_client_id_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gmail_section'
		);

		add_settings_field(
			'gmail_client_secret',
			__( 'Gmail Client Secret', 'wp-mcp-ai' ),
			array( $this, 'render_gmail_client_secret_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gmail_section'
		);

		add_settings_field(
			'gmail_refresh_token',
			__( 'Gmail Refresh Token', 'wp-mcp-ai' ),
			array( $this, 'render_gmail_refresh_token_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gmail_section'
		);

		add_settings_field(
			'gmail_user_email',
			__( 'Gmail User Email', 'wp-mcp-ai' ),
			array( $this, 'render_gmail_user_email_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_gmail_section'
		);

		add_settings_section(
			'wp_mcp_ai_chat_colors_section',
			__( 'Chat Appearance', 'wp-mcp-ai' ),
			array( $this, 'render_chat_colors_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'chat_colors',
			__( 'Interface Colors', 'wp-mcp-ai' ),
			array( $this, 'render_chat_colors_field' ),
			self::PAGE_SLUG,
			'wp_mcp_ai_chat_colors_section'
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

		$clean['chat_colors'] = self::get_default_chat_colors();

		if ( isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ) {
			$definitions = self::get_chat_color_definitions();

			foreach ( $clean['chat_colors'] as $color_key => $default_value ) {
				if ( ! isset( $definitions[ $color_key ] ) ) {
					continue;
				}

				if ( isset( $settings['chat_colors'][ $color_key ] ) ) {
					$clean['chat_colors'][ $color_key ] = self::sanitize_color_value(
						$settings['chat_colors'][ $color_key ],
						$definitions[ $color_key ]['format'],
						$default_value
					);
				}
			}
		}

		if ( isset( $settings['openai_api_key'] ) ) {
			$clean['openai_api_key'] = trim( sanitize_text_field( $settings['openai_api_key'] ) );
		}

		if ( isset( $settings['gemini_api_key'] ) ) {
			$clean['gemini_api_key'] = trim( sanitize_text_field( $settings['gemini_api_key'] ) );
		}

		if ( isset( $settings['ollama_endpoint_url'] ) ) {
			$url = trim( $settings['ollama_endpoint_url'] );
			$clean['ollama_endpoint_url'] = $url ? esc_url_raw( $url ) : '';
		}

		if ( isset( $settings['ollama_model'] ) ) {
			$clean['ollama_model'] = trim( sanitize_text_field( $settings['ollama_model'] ) );
		}

		if ( isset( $settings['lm_studio_endpoint_url'] ) ) {
			$url = trim( $settings['lm_studio_endpoint_url'] );
			$clean['lm_studio_endpoint_url'] = $url ? esc_url_raw( $url ) : '';
		}

		if ( isset( $settings['lm_studio_model'] ) ) {
			$clean['lm_studio_model'] = trim( sanitize_text_field( $settings['lm_studio_model'] ) );
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
			$allowed  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini', 'ollama' ) );

			if ( ! is_array( $allowed ) ) {
				$allowed = array( 'openai', 'gemini' );
			}

			if ( in_array( $provider, $allowed, true ) ) {
				$clean['default_provider'] = $provider;
			}
		}

		if ( isset( $settings['web_search_provider'] ) ) {
			$provider = sanitize_key( $settings['web_search_provider'] );
			$allowed  = array( 'duckduckgo', 'brave' );

			if ( in_array( $provider, $allowed, true ) ) {
				$clean['web_search_provider'] = $provider;
			}
		}

		if ( isset( $settings['brave_search_api_key'] ) ) {
			$clean['brave_search_api_key'] = trim( sanitize_text_field( $settings['brave_search_api_key'] ) );
		}

		if ( isset( $settings['ita_tariff_api_key'] ) ) {
			$clean['ita_tariff_api_key'] = trim( sanitize_text_field( $settings['ita_tariff_api_key'] ) );
		}

		if ( isset( $settings['request_timeout'] ) ) {
			$timeout = absint( $settings['request_timeout'] );

			if ( $timeout > 0 ) {
				$clean['request_timeout'] = max( 5, $timeout );
			}
		}

		if ( isset( $settings['memory_max_file_bytes'] ) ) {
			$choices = $this->get_memory_max_file_size_choices();
			$choice  = absint( $settings['memory_max_file_bytes'] );

			if ( isset( $choices[ $choice ] ) ) {
				$clean['memory_max_file_bytes'] = $choice;
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

		$clean['enable_auth0_github_bridge'] = ! empty( $settings['enable_auth0_github_bridge'] );

		if ( isset( $settings['auth0_management_client_id'] ) ) {
			$clean['auth0_management_client_id'] = trim( sanitize_text_field( $settings['auth0_management_client_id'] ) );
		}

		if ( isset( $settings['auth0_management_client_secret'] ) ) {
			$clean['auth0_management_client_secret'] = trim( sanitize_text_field( $settings['auth0_management_client_secret'] ) );
		}

		$clean['enable_simple_jwt_login'] = $this->is_simple_jwt_login_available() && ! empty( $settings['enable_simple_jwt_login'] );

		$clean['delete_on_uninstall'] = ! empty( $settings['delete_on_uninstall'] );

		if ( isset( $settings['crawl4ai_base_url'] ) ) {
			$base_url = trim( $settings['crawl4ai_base_url'] );

			$clean['crawl4ai_base_url'] = $base_url ? esc_url_raw( $base_url ) : '';
		}

		if ( isset( $settings['crawl4ai_api_key'] ) ) {
			$clean['crawl4ai_api_key'] = trim( sanitize_text_field( $settings['crawl4ai_api_key'] ) );
		}

		if ( isset( $settings['cloudflare_api_token'] ) ) {
			$clean['cloudflare_api_token'] = trim( sanitize_text_field( $settings['cloudflare_api_token'] ) );
		}

		if ( isset( $settings['cloudflare_zone_id'] ) ) {
			$clean['cloudflare_zone_id'] = trim( sanitize_text_field( $settings['cloudflare_zone_id'] ) );
		}

		$clean['enable_varnish_purge'] = ! empty( $settings['enable_varnish_purge'] );

		if ( isset( $settings['cloudways_email'] ) ) {
			$clean['cloudways_email'] = sanitize_email( $settings['cloudways_email'] );
		}

		if ( isset( $settings['cloudways_api_key'] ) ) {
			$clean['cloudways_api_key'] = trim( sanitize_text_field( $settings['cloudways_api_key'] ) );
		}

		if ( isset( $settings['cloudways_server_id'] ) ) {
			$clean['cloudways_server_id'] = trim( sanitize_text_field( $settings['cloudways_server_id'] ) );
		}

		if ( isset( $settings['cloudways_app_id'] ) ) {
			$clean['cloudways_app_id'] = trim( sanitize_text_field( $settings['cloudways_app_id'] ) );
		}

		if ( isset( $settings['mailjet_api_key'] ) ) {
			$clean['mailjet_api_key'] = trim( sanitize_text_field( $settings['mailjet_api_key'] ) );
		}

		if ( isset( $settings['mailjet_api_secret'] ) ) {
			$clean['mailjet_api_secret'] = trim( sanitize_text_field( $settings['mailjet_api_secret'] ) );
		}

		if ( isset( $settings['mailjet_from_email'] ) ) {
			$clean['mailjet_from_email'] = sanitize_email( $settings['mailjet_from_email'] );
		}

		if ( isset( $settings['mailjet_from_name'] ) ) {
			$clean['mailjet_from_name'] = sanitize_text_field( $settings['mailjet_from_name'] );
		}

		if ( isset( $settings['google_analytics_property_id'] ) ) {
			$clean['google_analytics_property_id'] = trim( sanitize_text_field( $settings['google_analytics_property_id'] ) );
		}

		if ( isset( $settings['google_analytics_credentials_json'] ) ) {
			$raw_credentials = trim( (string) wp_unslash( $settings['google_analytics_credentials_json'] ) );

			if ( '' === $raw_credentials ) {
				$clean['google_analytics_credentials_json'] = '';
			} else {
				$decoded = json_decode( $raw_credentials, true );

				if ( is_array( $decoded ) ) {
					$clean['google_analytics_credentials_json'] = wp_json_encode( $decoded );
				} else {
					add_settings_error(
						self::OPTION_NAME,
						'google_analytics_credentials_json',
						__( 'The Google Analytics service account JSON could not be parsed. Please paste a valid credential.', 'wp-mcp-ai' ),
						'error'
					);
				}
			}
		}

		if ( isset( $settings['quickbooks_company_id'] ) ) {
			$clean['quickbooks_company_id'] = trim( sanitize_text_field( $settings['quickbooks_company_id'] ) );
		}

		if ( isset( $settings['quickbooks_api_key'] ) ) {
			$clean['quickbooks_api_key'] = trim( sanitize_text_field( $settings['quickbooks_api_key'] ) );
		}

		if ( isset( $settings['gmail_client_id'] ) ) {
			$clean['gmail_client_id'] = trim( sanitize_text_field( $settings['gmail_client_id'] ) );
		}

		if ( isset( $settings['gmail_client_secret'] ) ) {
			$clean['gmail_client_secret'] = trim( sanitize_text_field( $settings['gmail_client_secret'] ) );
		}

		if ( isset( $settings['gmail_refresh_token'] ) ) {
			$clean['gmail_refresh_token'] = trim( sanitize_text_field( $settings['gmail_refresh_token'] ) );
		}

		if ( isset( $settings['gmail_user_email'] ) ) {
			$email = trim( (string) $settings['gmail_user_email'] );

			if ( '' === $email ) {
				$clean['gmail_user_email'] = '';
			} elseif ( 'me' === strtolower( $email ) ) {
				$clean['gmail_user_email'] = 'me';
			} else {
				$sanitized_email           = sanitize_email( $email );
				$clean['gmail_user_email'] = $sanitized_email ? $sanitized_email : '';
			}
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
			$size  = sanitize_text_field( $settings['openai_image_size'] );
			$sizes = array_keys( $this->get_openai_image_size_choices() );

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

		if ( isset( $settings['openai_image_response_format'] ) ) {
			$response_format  = sanitize_key( $settings['openai_image_response_format'] );
			$response_formats = array_keys( $this->get_openai_image_response_format_choices() );

			if ( in_array( $response_format, $response_formats, true ) ) {
				$supports_response_format = true;

				if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					$supports_response_format = WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $clean['openai_image_model'] );
				}

				if ( $supports_response_format ) {
					$clean['openai_image_response_format'] = $response_format;
				} else {
					$clean['openai_image_response_format'] = 'b64_json';
				}
			}
		}

		if ( isset( $settings['openai_speech_model'] ) ) {
			$clean['openai_speech_model'] = sanitize_text_field( $settings['openai_speech_model'] );
		}

		if ( isset( $settings['openai_speech_voice'] ) ) {
			$clean['openai_speech_voice'] = sanitize_key( $settings['openai_speech_voice'] );
		}

		if ( isset( $settings['openai_speech_format'] ) ) {
			$format  = sanitize_key( $settings['openai_speech_format'] );
			$formats = array_keys( $this->get_openai_speech_format_choices() );

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
	 * Sanitize a submitted color value.
	 *
	 * @param string $value   Submitted value.
	 * @param string $format  Expected format (hex or rgba).
	 * @param string $default Default color to fall back to.
	 * @return string
	 */
	private static function sanitize_color_value( $value, $format, $default ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return $default;
		}

		if ( 'rgba' === strtolower( $format ) ) {
			$pattern = '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0|0?\.\d+|1(?:\.0+)?)\s*\)$/i';

			if ( preg_match( $pattern, $value, $matches ) ) {
				$red   = min( 255, max( 0, (int) $matches[1] ) );
				$green = min( 255, max( 0, (int) $matches[2] ) );
				$blue  = min( 255, max( 0, (int) $matches[3] ) );
				$alpha = min( 1, max( 0, (float) $matches[4] ) );

				$alpha_string = rtrim( rtrim( sprintf( '%.3f', $alpha ), '0' ), '.' );

				if ( '' === $alpha_string ) {
					$alpha_string = '0';
				}

				return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, $alpha_string );
			}

			return $default;
		}

		$color = sanitize_hex_color( $value );

		return $color ? $color : $default;
	}

	/**
	 * Enqueue assets used on the settings screen.
	 *
	 * @param string $hook Current admin hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'wp-mcp-ai-admin-settings',
			WP_MCP_AI_URL . 'assets/js/admin-settings.js',
			array( 'wp-color-picker', 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-admin-settings',
			'wpMcpAiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_admin_nonce' ),
			)
		);

		$inline_styles = '.wp-mcp-ai-chat-colors__group{margin-bottom:1.5rem;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;}'
			. '.wp-mcp-ai-chat-colors__group legend{font-weight:600;margin-bottom:0.5rem;}'
			. '.wp-mcp-ai-chat-colors__field{margin-bottom:1rem;}'
			. '.wp-mcp-ai-chat-colors__field label{display:block;font-weight:600;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-connector-checklist{margin:1.5rem 0 2rem;padding:1.25rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;box-shadow:0 1px 2px rgba(15,23,42,0.05);}'
			. '.wp-mcp-ai-connector-checklist__title{margin-top:0;margin-bottom:0.5rem;font-size:1.25rem;}'
			. '.wp-mcp-ai-connector-checklist__intro{margin-top:0;margin-bottom:1rem;color:#475569;}'
			. '.wp-mcp-ai-connector-checklist__list{margin:0;padding:0;list-style:none;}'
			. '.wp-mcp-ai-connector-checklist__item{margin:0;padding:1rem;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;}'
			. '.wp-mcp-ai-connector-checklist__item+.wp-mcp-ai-connector-checklist__item{margin-top:0.75rem;}'
			. '.wp-mcp-ai-connector-checklist__item-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:0.5rem;}'
			. '.wp-mcp-ai-connector-checklist__name{font-weight:600;font-size:1rem;}'
			. '.wp-mcp-ai-connector-checklist__status{font-weight:600;}'
			. '.wp-mcp-ai-connector-checklist__status-message{margin:0 0 0.5rem;color:#0f172a;}'
			. '.wp-mcp-ai-connector-checklist__description,.wp-mcp-ai-connector-checklist__usage{margin:0 0 0.5rem;color:#475569;}'
			. '.wp-mcp-ai-connector-checklist__docs{margin:0;}'
			. '.wp-mcp-ai-connector-checklist__docs a{text-decoration:none;}'
			. '.wp-mcp-ai-connector-checklist__item--ready{background:#f0fdf4;border-color:#bbf7d0;}'
			. '.wp-mcp-ai-connector-checklist__item--ready .wp-mcp-ai-connector-checklist__status{color:#047857;}'
			. '.wp-mcp-ai-connector-checklist__item--partial{background:#fff7ed;border-color:#fdba74;}'
			. '.wp-mcp-ai-connector-checklist__item--partial .wp-mcp-ai-connector-checklist__status{color:#c2410c;}'
			. '.wp-mcp-ai-connector-checklist__item--missing{background:#fef2f2;border-color:#fca5a5;}'
			. '.wp-mcp-ai-connector-checklist__item--missing .wp-mcp-ai-connector-checklist__status{color:#b91c1c;}'
			. '.wp-mcp-ai-connector-checklist__item--inactive{background:#f8fafc;border-color:#cbd5f5;}'
			. '.wp-mcp-ai-connector-checklist__item--inactive .wp-mcp-ai-connector-checklist__status{color:#1e3a8a;}';

		wp_add_inline_style( 'wp-color-picker', $inline_styles );
	}

	/**
	 * Render the settings page contents.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings           = self::get_settings();
		$connector_statuses = $this->get_connector_statuses( $settings );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP MCP AI Settings', 'wp-mcp-ai' ); ?></h1>
			<?php settings_errors( self::OPTION_NAME ); ?>
			<?php if ( ! empty( $connector_statuses ) ) : ?>
				<div class="wp-mcp-ai-connector-checklist" aria-live="polite">
					<h2 class="wp-mcp-ai-connector-checklist__title"><?php esc_html_e( 'Connector Checklist', 'wp-mcp-ai' ); ?></h2>
					<p class="wp-mcp-ai-connector-checklist__intro"><?php esc_html_e( 'Track which integrations still need credentials so you know exactly when to enter a new connector.', 'wp-mcp-ai' ); ?></p>
					<ul class="wp-mcp-ai-connector-checklist__list">
						<?php foreach ( $connector_statuses as $connector ) : ?>
							<li class="wp-mcp-ai-connector-checklist__item wp-mcp-ai-connector-checklist__item--<?php echo esc_attr( $connector['status'] ); ?>">
								<div class="wp-mcp-ai-connector-checklist__item-header">
									<span class="wp-mcp-ai-connector-checklist__name"><?php echo esc_html( $connector['label'] ); ?></span>
									<span class="wp-mcp-ai-connector-checklist__status"><?php echo esc_html( $connector['status_label'] ); ?></span>
								</div>
								<?php if ( ! empty( $connector['status_message'] ) ) : ?>
									<p class="wp-mcp-ai-connector-checklist__status-message"><?php echo esc_html( $connector['status_message'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $connector['description'] ) ) : ?>
									<p class="wp-mcp-ai-connector-checklist__description"><?php echo esc_html( $connector['description'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $connector['usage'] ) ) : ?>
									<p class="wp-mcp-ai-connector-checklist__usage"><?php echo esc_html( $connector['usage'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $connector['docs_url'] ) ) : ?>
									<p class="wp-mcp-ai-connector-checklist__docs">
										<a href="<?php echo esc_url( $connector['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read setup guide', 'wp-mcp-ai' ); ?></a>
									</p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
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
	 * Render the description for the chat colors section.
	 */
	public function render_chat_colors_section_description() {
		echo '<p>' . esc_html__( 'Customize the palette used by the front-end chat interface. Leave a field empty to keep its default color.', 'wp-mcp-ai' ) . '</p>';
		echo '<p>' . esc_html__( 'New controls cover the transcript toggle, saved reply shortcuts, and the copy transcript button so every surface can follow your brand.', 'wp-mcp-ai' ) . '</p>';
	}

	/**
	 * Render the chat color controls.
	 */
	public function render_chat_colors_field() {
		$settings    = self::get_settings();
		$colors      = isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ? $settings['chat_colors'] : self::get_default_chat_colors();
		$definitions = self::get_chat_color_definitions();
		$groups      = self::get_chat_color_groups();

		echo '<div class="wp-mcp-ai-chat-colors">';

		foreach ( $groups as $group_key => $group_label ) {
			$group_colors = array();

			foreach ( $definitions as $color_key => $definition ) {
				if ( isset( $definition['group'] ) && $group_key === $definition['group'] ) {
					$group_colors[ $color_key ] = $definition;
				}
			}

			if ( empty( $group_colors ) ) {
				continue;
			}

			echo '<fieldset class="wp-mcp-ai-chat-colors__group">';
			echo '<legend>' . esc_html( $group_label ) . '</legend>';

			foreach ( $group_colors as $color_key => $definition ) {
				$input_id     = 'wp-mcp-ai-color-' . sanitize_html_class( $color_key );
				$value        = isset( $colors[ $color_key ] ) ? $colors[ $color_key ] : $definition['default'];
				$format       = isset( $definition['format'] ) ? strtolower( $definition['format'] ) : 'hex';
				$descriptions = array();

				if ( ! empty( $definition['description'] ) ) {
					$descriptions[] = $definition['description'];
				}

				if ( 'rgba' === $format ) {
					$descriptions[] = __( 'Enter a value in rgba(R, G, B, A) format.', 'wp-mcp-ai' );
				}

				echo '<div class="wp-mcp-ai-chat-colors__field">';
				echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $definition['label'] ) . '</label>';
				echo '<input type="text" id="' . esc_attr( $input_id ) . '" class="regular-text wp-mcp-ai-color-field" name="' . esc_attr( self::OPTION_NAME ) . '[chat_colors][' . esc_attr( $color_key ) . ']" value="' . esc_attr( $value ) . '" data-format="' . esc_attr( $format ) . '" data-default-color="' . esc_attr( $definition['default'] ) . '" />';

				foreach ( $descriptions as $text ) {
					echo '<p class="description">' . esc_html( $text ) . '</p>';
				}

				echo '</div>';
			}

			echo '</fieldset>';
		}

		echo '</div>';
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
	 * Render the Auth0 GitHub bridge toggle.
	 */
	public function render_auth0_github_bridge_field() {
		$settings = self::get_settings();
		$enabled  = ! empty( $settings['enable_auth0_github_bridge'] );
		$field_id = 'wp-mcp-ai-enable-auth0-github-bridge';

		printf(
			'<label for="%1$s"><input id="%1$s" type="checkbox" name="%2$s[enable_auth0_github_bridge]" value="1" %3$s /> %4$s</label>',
			esc_attr( $field_id ),
			esc_attr( self::OPTION_NAME ),
			checked( $enabled, true, false ),
			esc_html__( 'Resolve Auth0 GitHub identities into WordPress users for REST auditing and assistant scoping.', 'wp-mcp-ai' )
		);

		echo '<p class="description">' . esc_html__( 'Enable after configuring the Auth0 Management API credentials below.', 'wp-mcp-ai' ) . '</p>';
	}

	/**
	 * Render the Auth0 Management API client ID field.
	 */
	public function render_auth0_management_client_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_management_client_id]" value="<?php echo esc_attr( $settings['auth0_management_client_id'] ); ?>" class="regular-text" placeholder="client_abc123" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Client ID for the Auth0 Machine-to-Machine application authorised for the Management API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Auth0 Management API client secret field.
	 */
	public function render_auth0_management_client_secret_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_management_client_secret]" value="<?php echo esc_attr( $settings['auth0_management_client_secret'] ); ?>" class="regular-text" autocomplete="new-password" />
		<p class="description"><?php esc_html_e( 'Secret for the Auth0 Management API application. Required when the GitHub profile lacks email or username claims.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Simple JWT Login integration toggle.
	 */
	public function render_simple_jwt_login_field() {
		$settings  = self::get_settings();
		$enabled   = ! empty( $settings['enable_simple_jwt_login'] );
		$available = $this->is_simple_jwt_login_available();
		$field_id  = 'wp-mcp-ai-enable-simple-jwt-login';

		printf(
			'<label for="%1$s"><input id="%1$s" type="checkbox" name="%2$s[enable_simple_jwt_login]" value="1" %3$s %4$s /> %5$s</label>',
			esc_attr( $field_id ),
			esc_attr( self::OPTION_NAME ),
			checked( $enabled, true, false ),
			disabled( ! $available, true, false ),
			esc_html__( 'Allow bearer tokens validated by Simple JWT Login to access the MCP REST API.', 'wp-mcp-ai' )
		);

		echo '<p class="description">' . esc_html__( 'Enable this after configuring the Simple JWT Login plugin to issue tokens for remote assistants.', 'wp-mcp-ai' ) . '</p>';

		if ( ! $available ) {
			$install_url = 'https://wordpress.org/plugins/simple-jwt-login/#installation';
			$link        = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $install_url ),
				esc_html__( 'Simple JWT Login installation guide', 'wp-mcp-ai' )
			);

			printf(
				'<p class="description">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: Link to the Simple JWT Login documentation. */
						__( 'Install and activate the %s to enable this integration.', 'wp-mcp-ai' ),
						$link
					),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}
	}

	/**
	 * Display a notice when Simple JWT Login is unavailable.
	 */
	public function maybe_render_simple_jwt_login_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->is_simple_jwt_login_available() ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'plugins',
			'plugins-network',
			'plugin-install',
			'plugin-install-network',
		);

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$install_url = 'https://wordpress.org/plugins/simple-jwt-login/#installation';
		$message     = sprintf(
			/* translators: %s: Hyperlink pointing to the Simple JWT Login installation instructions. */
			__( 'The Simple JWT Login plugin is not active. Install and activate it to enable its bearer tokens for the MCP API. Review the %s.', 'wp-mcp-ai' ),
			sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $install_url ),
				esc_html__( 'installation instructions', 'wp-mcp-ai' )
			)
		);

		echo '<div class="notice notice-warning"><p>' . wp_kses(
			$message,
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p></div>';
	}

	/**
	 * Determine whether the Simple JWT Login dependency is active.
	 *
	 * @return bool
	 */
	protected function is_simple_jwt_login_available() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::SIMPLE_JWT_LOGIN_PLUGIN ) ) {
			return true;
		}

		if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::SIMPLE_JWT_LOGIN_PLUGIN ) ) {
			return true;
		}

		return false;
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
	 * Render the Ollama section description.
	 */
	public function render_ollama_section_description() {
		?>
		<p><?php esc_html_e( 'Connect to a local Ollama instance for privacy-focused, cost-free AI processing using your own hardware. Ollama uses its native API format.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Ollama endpoint URL field.
	 */
	public function render_ollama_endpoint_url_field() {
		$settings = self::get_settings();
		?>
		<input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ollama_endpoint_url]" value="<?php echo esc_attr( $settings['ollama_endpoint_url'] ); ?>" class="regular-text" placeholder="http://localhost:11434" />
		<p class="description">
			<?php esc_html_e( 'Enter the URL where your Ollama instance is running (e.g., http://localhost:11434).', 'wp-mcp-ai' ); ?>
			<button type="button" id="wp-mcp-ai-test-ollama-connection" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e( 'Test Connection', 'wp-mcp-ai' ); ?></button>
			<span id="wp-mcp-ai-ollama-test-result" style="margin-left: 10px;"></span>
		</p>
		<?php
	}

	/**
	 * Render the Ollama model field.
	 */
	public function render_ollama_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ollama_model]" id="wp-mcp-ai-ollama-model" value="<?php echo esc_attr( $settings['ollama_model'] ); ?>" class="regular-text" placeholder="llama2" />
		<button type="button" id="wp-mcp-ai-fetch-ollama-models" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e( 'Fetch Models', 'wp-mcp-ai' ); ?></button>
		<p class="description"><?php esc_html_e( 'Enter a model name or click "Fetch Models" to see available models from your Ollama instance.', 'wp-mcp-ai' ); ?></p>
		<div id="wp-mcp-ai-ollama-models-list" style="margin-top: 10px;"></div>
		<?php
	}

	/**
	 * Render the LM Studio section description.
	 */
	public function render_lm_studio_section_description() {
		?>
		<p><?php esc_html_e( 'Connect to a local LM Studio instance for privacy-focused, cost-free AI processing using your own hardware. LM Studio provides an OpenAI-compatible API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the LM Studio endpoint URL field.
	 */
	public function render_lm_studio_endpoint_url_field() {
		$settings = self::get_settings();
		?>
		<input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[lm_studio_endpoint_url]" value="<?php echo esc_attr( $settings['lm_studio_endpoint_url'] ); ?>" class="regular-text" placeholder="http://localhost:1234" />
		<p class="description">
			<?php esc_html_e( 'Enter the URL where your LM Studio server is running (e.g., http://localhost:1234).', 'wp-mcp-ai' ); ?>
			<button type="button" id="wp-mcp-ai-test-lm-studio-connection" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e( 'Test Connection', 'wp-mcp-ai' ); ?></button>
			<span id="wp-mcp-ai-lm-studio-test-result" style="margin-left: 10px;"></span>
		</p>
		<?php
	}

	/**
	 * Render the LM Studio model field.
	 */
	public function render_lm_studio_model_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[lm_studio_model]" id="wp-mcp-ai-lm-studio-model" value="<?php echo esc_attr( $settings['lm_studio_model'] ); ?>" class="regular-text" placeholder="local-model" />
		<button type="button" id="wp-mcp-ai-fetch-lm-studio-models" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e( 'Fetch Models', 'wp-mcp-ai' ); ?></button>
		<p class="description"><?php esc_html_e( 'Enter a model name or click "Fetch Models" to see available models from your LM Studio server.', 'wp-mcp-ai' ); ?></p>
		<div id="wp-mcp-ai-lm-studio-models-list" style="margin-top: 10px;"></div>
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
	 * Render the Google Analytics section description.
	 */
	public function render_google_analytics_section_description() {
		?>
		<p><?php esc_html_e( 'Connect a Google Analytics 4 property so assistants can request reporting snapshots via the Data API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Google Analytics property ID field.
	 */
	public function render_google_analytics_property_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[google_analytics_property_id]" value="<?php echo esc_attr( $settings['google_analytics_property_id'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Provide the numeric GA4 property ID that should be used when a tool call does not specify one.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Google Analytics service account JSON field.
	 */
	public function render_google_analytics_credentials_field() {
		$settings = self::get_settings();
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[google_analytics_credentials_json]" rows="6" class="large-text code" autocomplete="off"><?php echo esc_textarea( $settings['google_analytics_credentials_json'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Paste the JSON credentials for a Google Cloud service account with access to the Analytics Data API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the QuickBooks section description.
	 */
	public function render_quickbooks_section_description() {
		?>
		<p><?php esc_html_e( 'Configure the credentials used by the QuickBooks Online reporting tool.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the QuickBooks company ID field.
	 */
	public function render_quickbooks_company_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[quickbooks_company_id]" value="<?php echo esc_attr( $settings['quickbooks_company_id'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter the QuickBooks Online company ID that should be used for report requests.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the QuickBooks API key field.
	 */
	public function render_quickbooks_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[quickbooks_api_key]" value="<?php echo esc_attr( $settings['quickbooks_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Provide a bearer token or API key that authorises access to the QuickBooks Online reports API.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Gmail integration section description.
	 */
	public function render_gmail_section_description() {
		$settings      = self::get_settings();
		$has_client_id = ! empty( $settings['gmail_client_id'] );
		$has_secret    = ! empty( $settings['gmail_client_secret'] );
		$login_url     = '';

		if ( $has_client_id && $has_secret ) {
			$login_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_start' ),
				'wp_mcp_ai_gmail_oauth_start'
			);
		}
		?>
		<p><?php esc_html_e( 'Provide OAuth credentials for the Gmail API. These are used by assistants to search email on your behalf.', 'wp-mcp-ai' ); ?></p>
		<?php if ( $login_url ) : ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $login_url ); ?>">
					<?php esc_html_e( 'Connect Gmail Account', 'wp-mcp-ai' ); ?>
				</a>
			</p>
			<p class="description"><?php esc_html_e( 'Launch the Google consent screen to mint and store a refresh token automatically. The plugin requests read-only access to Gmail messages.', 'wp-mcp-ai' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Save your Gmail client ID and secret, then reload this page to enable the Google login button.', 'wp-mcp-ai' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $settings['gmail_refresh_token'] ) ) : ?>
			<p class="description">
				<?php
				if ( ! empty( $settings['gmail_user_email'] ) ) {
					/* translators: %s: Gmail email address. */
					printf( esc_html__( 'A refresh token is stored for %s.', 'wp-mcp-ai' ), '<code>' . esc_html( $settings['gmail_user_email'] ) . '</code>' );
				} else {
					esc_html_e( 'A Gmail refresh token is already stored for this site.', 'wp-mcp-ai' );
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the Gmail client ID field.
	 */
	public function render_gmail_client_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gmail_client_id]" value="<?php echo esc_attr( $settings['gmail_client_id'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter the OAuth client ID from your Google Cloud project.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Gmail client secret field.
	 */
	public function render_gmail_client_secret_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gmail_client_secret]" value="<?php echo esc_attr( $settings['gmail_client_secret'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter the OAuth client secret associated with the client ID.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Gmail refresh token field.
	 */
	public function render_gmail_refresh_token_field() {
		$settings = self::get_settings();
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gmail_refresh_token]" rows="3" class="large-text" autocomplete="off"><?php echo esc_textarea( $settings['gmail_refresh_token'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Provide a long-lived refresh token issued for the Gmail API with the https://www.googleapis.com/auth/gmail.readonly scope.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Gmail user email field.
	 */
	public function render_gmail_user_email_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gmail_user_email]" value="<?php echo esc_attr( $settings['gmail_user_email'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Specify the Gmail account email address associated with the refresh token. Leave blank or enter “me” to use the authenticated account.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Kick off the Gmail OAuth consent flow.
	 */
	public function handle_gmail_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_gmail_oauth_start' );

		$settings = self::get_settings();

		if ( empty( $settings['gmail_client_id'] ) || empty( $settings['gmail_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_missing_client',
				__( 'Enter a Gmail OAuth client ID and secret before connecting the account.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$state     = wp_generate_uuid4();
		$transient = $this->get_gmail_state_transient_key( $state );

		set_transient(
			$transient,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$params = array(
			'client_id'              => $settings['gmail_client_id'],
			'redirect_uri'           => $this->get_gmail_oauth_redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::GMAIL_OAUTH_SCOPE,
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);

		if ( ! empty( $settings['gmail_user_email'] ) && 'me' !== strtolower( $settings['gmail_user_email'] ) ) {
			$params['login_hint'] = $settings['gmail_user_email'];
		}

		$authorize_url = add_query_arg( $params, self::GMAIL_OAUTH_AUTHORIZE_ENDPOINT );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Allow the Google OAuth authorize endpoint host when using wp_safe_redirect().
	 *
	 * @param string[] $allowed_hosts Existing list of allowed hosts.
	 * @param string   $redirect      Requested redirect destination.
	 *
	 * @return string[]
	 */
	public function allow_gmail_oauth_redirect_host( $allowed_hosts, $redirect = '' ) {
		$google_host = wp_parse_url( self::GMAIL_OAUTH_AUTHORIZE_ENDPOINT, PHP_URL_HOST );

		if ( $google_host ) {
			$allowed_hosts[] = $google_host;
		}

		return array_values( array_unique( $allowed_hosts ) );
	}

	/**
	 * Handle the OAuth callback from Google and persist the refresh token.
	 */
	public function handle_gmail_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_error',
				sprintf(
					/* translators: %s: Google error message. */
					__( 'Google returned an error during authorisation: %s', 'wp-mcp-ai' ),
					$error
				)
			);
			$this->redirect_to_settings_page();
		}

		$transient_key = $this->get_gmail_state_transient_key( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_state_mismatch',
				__( 'The Google authorisation request could not be verified. Please try again.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $code ) ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_missing_code',
				__( 'Google did not return an authorisation code. Please try again.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$settings = self::get_settings();

		if ( empty( $settings['gmail_client_id'] ) || empty( $settings['gmail_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_missing_client',
				__( 'Enter a Gmail OAuth client ID and secret before connecting the account.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$response = wp_remote_post(
			self::GMAIL_OAUTH_TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $settings['gmail_client_id'],
					'client_secret' => $settings['gmail_client_secret'],
					'redirect_uri'  => $this->get_gmail_oauth_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'Gmail OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			$this->add_settings_redirect_notice(
				'gmail_oauth_token_request_failed',
				__( 'Google could not exchange the authorisation code. Check the client credentials and try again.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			self::log(
				'Gmail OAuth token exchange returned an unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
			$this->add_settings_redirect_notice(
				'gmail_oauth_token_request_error',
				__( 'Google rejected the authorisation code. Review the OAuth consent configuration and try again.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			self::log( 'Gmail OAuth token response was not valid JSON.', array( 'body' => $body ) );
			$this->add_settings_redirect_notice(
				'gmail_oauth_token_invalid_json',
				__( 'Google returned an unexpected response while exchanging the authorisation code.', 'wp-mcp-ai' )
			);
			$this->redirect_to_settings_page();
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';
		$used_existing = false;

		if ( '' === $refresh_token ) {
			$existing_refresh = isset( $settings['gmail_refresh_token'] ) ? $settings['gmail_refresh_token'] : '';

			if ( '' === $existing_refresh ) {
				self::log( 'Gmail OAuth callback omitted a refresh token.', array( 'response' => $decoded ) );
				$this->add_settings_redirect_notice(
					'gmail_oauth_missing_refresh_token',
					__( 'Google did not return a refresh token. Remove any previous grants for this client and try again.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$refresh_token = $existing_refresh;
			$used_existing = true;
		}

		$email_address = '';

		if ( $access_token ) {
			$profile_response = wp_remote_get(
				self::GMAIL_PROFILE_ENDPOINT,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => 'Bearer ' . $access_token,
					),
				)
			);

			if ( is_wp_error( $profile_response ) ) {
				self::log( 'Failed to load Gmail profile after OAuth.', array( 'error' => $profile_response->get_error_message() ) );
			} else {
				$profile_status = wp_remote_retrieve_response_code( $profile_response );
				$profile_body   = wp_remote_retrieve_body( $profile_response );

				if ( 200 === (int) $profile_status ) {
					$profile_data = json_decode( $profile_body, true );

					if ( is_array( $profile_data ) && ! empty( $profile_data['emailAddress'] ) ) {
						$email_address = sanitize_email( $profile_data['emailAddress'] );
					}
				} else {
					self::log(
						'Gmail profile lookup returned an unexpected status.',
						array(
							'status' => $profile_status,
							'body'   => $profile_body,
						)
					);
				}
			}
		}

		$updated_settings                        = $settings;
		$updated_settings['gmail_refresh_token'] = $refresh_token;

		if ( $email_address ) {
			$updated_settings['gmail_user_email'] = $email_address;
		}

		$sanitized = $this->sanitize_settings( $updated_settings );
		update_option( self::OPTION_NAME, $sanitized );

		if ( $used_existing ) {
			$this->add_settings_redirect_notice(
				'gmail_oauth_success_existing_refresh',
				__( 'Google reconnected successfully. The previous refresh token is still valid, so it has been kept.', 'wp-mcp-ai' ),
				'updated'
			);
		} else {
			$notice_message = __( 'Gmail authorisation complete. A new refresh token has been stored.', 'wp-mcp-ai' );

			if ( $email_address ) {
				$notice_message = sprintf(
					/* translators: %s: Gmail email address. */
					__( 'Gmail authorisation complete for %s.', 'wp-mcp-ai' ),
					$email_address
				);
			}

			$this->add_settings_redirect_notice( 'gmail_oauth_success', $notice_message, 'updated' );
		}

		$this->redirect_to_settings_page();
	}

	/**
	 * Build the transient key used to persist OAuth state.
	 *
	 * @param string $state OAuth state string.
	 * @return string
	 */
	private function get_gmail_state_transient_key( $state ) {
		return 'wp_mcp_ai_gmail_state_' . md5( (string) $state );
	}

	/**
	 * Return the OAuth redirect URI registered in the Google Cloud console.
	 *
	 * @return string
	 */
	private function get_gmail_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_callback' );
	}

	/**
	 * Retrieve the settings page URL.
	 *
	 * @return string
	 */
	private function get_settings_page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Redirect the current request back to the settings page and exit.
	 */
	private function redirect_to_settings_page() {
		wp_safe_redirect( $this->get_settings_page_url() );
		exit;
	}

	/**
	 * Store a notice that will be displayed on the settings page after redirecting.
	 *
	 * @param string $code    Unique notice code.
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 */
	private function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
		add_settings_error( self::OPTION_NAME, $code, $message, $type );

		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => self::OPTION_NAME,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
			),
			MINUTE_IN_SECONDS
		);
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
	 * Render the web search provider selector.
	 */
	public function render_web_search_provider_field() {
		$settings  = self::get_settings();
		$current   = isset( $settings['web_search_provider'] ) ? sanitize_key( $settings['web_search_provider'] ) : 'duckduckgo';
		$providers = array(
			'duckduckgo' => __( 'DuckDuckGo Instant Answer API', 'wp-mcp-ai' ),
			'brave'      => __( 'Brave Search API', 'wp-mcp-ai' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[web_search_provider]" class="regular-text">
			<?php foreach ( $providers as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Choose the web search backend used by the Web Search tool.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Brave Search API key field.
	 */
	public function render_brave_search_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[brave_search_api_key]" value="<?php echo esc_attr( $settings['brave_search_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Required when Brave Search is selected as the provider.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the ITA Tariff Rates API key field.
	 */
	public function render_ita_tariff_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[ita_tariff_api_key]" value="<?php echo esc_attr( $settings['ita_tariff_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Store the Trade.gov API key used to query import duty rates.', 'wp-mcp-ai' ); ?></p>
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

		if ( ! isset( $sizes[ $current ] ) ) {
			$first_key = function_exists( 'array_key_first' ) ? array_key_first( $sizes ) : '1024x1024';
			$current   = $first_key ? $first_key : '1024x1024';
		}
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
	 * Render the OpenAI image response format field.
	 */
	public function render_openai_image_response_format_field() {
		$settings                 = self::get_settings();
		$response_formats         = $this->get_openai_image_response_format_choices();
		$current                  = isset( $settings['openai_image_response_format'] ) ? sanitize_key( $settings['openai_image_response_format'] ) : 'b64_json';
		$model                    = isset( $settings['openai_image_model'] ) ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1';
		$supports_response_format = true;

		if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$supports_response_format = WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $model );
		}

		if ( ! $supports_response_format && isset( $response_formats['b64_json'] ) ) {
			$response_formats = array( 'b64_json' => $response_formats['b64_json'] );
			$current          = 'b64_json';
		}
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_response_format]" class="regular-text" <?php disabled( ! $supports_response_format ); ?>>
			<?php foreach ( $response_formats as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! $supports_response_format ) : ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_response_format]" value="b64_json" />
			<p class="description"><?php esc_html_e( 'The selected image model currently returns base64 data only.', 'wp-mcp-ai' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Choose whether OpenAI should return base64 data or a downloadable URL when generating images.', 'wp-mcp-ai' ); ?></p>
		<?php endif; ?>
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
	 * Render the Cloudflare zone ID field.
	 */
	public function render_cloudflare_zone_id_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudflare_zone_id]" value="<?php echo esc_attr( $settings['cloudflare_zone_id'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Cloudflare zone identifier (a 32 character string) for the site you wish to purge.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Cloudflare API token field.
	 */
	public function render_cloudflare_api_token_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudflare_api_token]" value="<?php echo esc_attr( $settings['cloudflare_api_token'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Cloudflare API token with permission to purge cache for the configured zone.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the enable Varnish purge checkbox field.
	 */
	public function render_enable_varnish_purge_field() {
		$settings = self::get_settings();
		?>
		<label for="wp-mcp-ai-enable-varnish-purge">
			<input id="wp-mcp-ai-enable-varnish-purge" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_varnish_purge]" value="1" <?php checked( $settings['enable_varnish_purge'] ); ?> />
			<?php esc_html_e( 'Enable Varnish cache purging for this site.', 'wp-mcp-ai' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable this if your hosting environment (like Cloudways) uses Varnish caching. The plugin will send PURGE requests to 127.0.0.1 to clear the local cache.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Cloudways email field.
	 */
	public function render_cloudways_email_field() {
		$settings = self::get_settings();
		?>
		<input type="email" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudways_email]" value="<?php echo esc_attr( $settings['cloudways_email'] ); ?>" class="regular-text" autocomplete="off" placeholder="your-email@example.com" />
		<p class="description">
			<?php esc_html_e( 'Your Cloudways account email address.', 'wp-mcp-ai' ); ?>
			<a href="https://developers.cloudways.com/docs/#authentication" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn how to generate your API key', 'wp-mcp-ai' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Render the Cloudways API key field.
	 */
	public function render_cloudways_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudways_api_key]" value="<?php echo esc_attr( $settings['cloudways_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'API key from your Cloudways account settings.', 'wp-mcp-ai' ); ?></p>
		<div style="margin-top: 10px;">
			<button type="button" id="wp-mcp-ai-fetch-cloudways-data" class="button button-secondary">
				<?php esc_html_e( 'Fetch Cloudways Data', 'wp-mcp-ai' ); ?>
			</button>
			<span id="wp-mcp-ai-cloudways-fetch-result" style="margin-left: 10px;"></span>
		</div>
		<?php
	}

	/**
	 * Render the Cloudways server ID field.
	 */
	public function render_cloudways_server_id_field() {
		$settings = self::get_settings();
		?>
		<input 
			type="text" 
			id="wp-mcp-ai-cloudways-server-id"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudways_server_id]" 
			value="<?php echo esc_attr( $settings['cloudways_server_id'] ); ?>" 
			class="regular-text" 
			autocomplete="off" 
			readonly 
			aria-describedby="wp-mcp-ai-cloudways-server-id-description"
			aria-label="<?php esc_attr_e( 'Cloudways Server ID', 'wp-mcp-ai' ); ?>"
		/>
		<p id="wp-mcp-ai-cloudways-server-id-description" class="description"><?php esc_html_e( 'Server ID (auto-populated after fetching Cloudways data).', 'wp-mcp-ai' ); ?></p>
		<div id="wp-mcp-ai-cloudways-servers-list" style="margin-top: 10px;"></div>
		<?php
	}

	/**
	 * Render the Cloudways application ID field.
	 */
	public function render_cloudways_app_id_field() {
		$settings = self::get_settings();
		?>
		<input 
			type="text" 
			id="wp-mcp-ai-cloudways-app-id"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudways_app_id]" 
			value="<?php echo esc_attr( $settings['cloudways_app_id'] ); ?>" 
			class="regular-text" 
			autocomplete="off" 
			readonly 
			aria-describedby="wp-mcp-ai-cloudways-app-id-description"
			aria-label="<?php esc_attr_e( 'Cloudways Application ID', 'wp-mcp-ai' ); ?>"
		/>
		<p id="wp-mcp-ai-cloudways-app-id-description" class="description"><?php esc_html_e( 'Application ID (auto-populated after fetching Cloudways data).', 'wp-mcp-ai' ); ?></p>
		<div id="wp-mcp-ai-cloudways-apps-list" style="margin-top: 10px;"></div>
		<?php
	}

	/**
	 * Render the Mailjet API key field.
	 */
	public function render_mailjet_api_key_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_api_key]" value="<?php echo esc_attr( $settings['mailjet_api_key'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Public Mailjet API key used to authenticate requests.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Mailjet API secret field.
	 */
	public function render_mailjet_api_secret_field() {
		$settings = self::get_settings();
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_api_secret]" value="<?php echo esc_attr( $settings['mailjet_api_secret'] ); ?>" class="regular-text" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Private Mailjet API secret paired with the API key.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Mailjet from email field.
	 */
	public function render_mailjet_from_email_field() {
		$settings = self::get_settings();
		?>
		<input type="email" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_from_email]" value="<?php echo esc_attr( $settings['mailjet_from_email'] ); ?>" class="regular-text" placeholder="sender@example.com" />
		<p class="description"><?php esc_html_e( 'Default sender email used when assistants omit a from address.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the Mailjet from name field.
	 */
	public function render_mailjet_from_name_field() {
		$settings = self::get_settings();
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_from_name]" value="<?php echo esc_attr( $settings['mailjet_from_name'] ); ?>" class="regular-text" placeholder="WP MCP AI" />
		<p class="description"><?php esc_html_e( 'Optional default sender name presented to recipients.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Render the group email capability field.
	 */
	public function render_group_email_capability_field() {
		$settings   = self::get_settings();
		$capability = isset( $settings['group_email_capability'] ) ? sanitize_key( $settings['group_email_capability'] ) : '';
		$choices    = $this->get_group_email_capability_choices();

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
		$settings       = self::get_settings();
		$max_recipients = isset( $settings['group_email_max_recipients'] ) ? (int) $settings['group_email_max_recipients'] : 0;
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
		$choices  = apply_filters( 'wp_mcp_ai_allowed_providers', self::get_available_providers() );

		if ( ! is_array( $choices ) ) {
			$choices = self::get_available_providers();
		}
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_provider]" id="wp-mcp-ai-default-provider" class="regular-text">
			<?php
			foreach ( $choices as $choice ) {
				$choice = sanitize_key( $choice );
				if ( '' === $choice ) {
					continue;
				}

				if ( 'openai' === $choice ) {
					$label = __( 'OpenAI', 'wp-mcp-ai' );
				} elseif ( 'gemini' === $choice ) {
					$label = __( 'Gemini', 'wp-mcp-ai' );
				} elseif ( 'ollama' === $choice ) {
					$label = __( 'Ollama (Local AI)', 'wp-mcp-ai' );
				} elseif ( 'lm_studio' === $choice ) {
					$label = __( 'LM Studio (Local AI)', 'wp-mcp-ai' );
				} else {
					$label = ucfirst( $choice );
				}
				?>
				<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current, $choice ); ?>><?php echo esc_html( $label ); ?></option>
				<?php
			}
			?>
		</select>
		<p class="description"><?php esc_html_e( 'Default API for the system. This provider will be used by assistants and API requests when no specific provider is set. Changing this affects all new conversations.', 'wp-mcp-ai' ); ?></p>
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
		if ( ! empty( $settings['enable_logging'] ) ) :
			$entries = WP_MCP_AI_Logger::get_recent_error_messages();
			?>
			<p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'wp-mcp-ai' ); ?></p>
			<?php if ( empty( $entries ) ) : ?>
				<p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'wp-mcp-ai' ); ?></p>
			<?php else : ?>
				<ul class="wp-mcp-ai-log-preview">
					<?php
					foreach ( $entries as $entry ) :
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
			<?php
			$log_file_path    = WP_MCP_AI_Logger::get_log_file_path();
			$log_file_exists  = WP_MCP_AI_Logger::does_log_file_exist();
			$log_file_size    = WP_MCP_AI_Logger::get_log_file_size();
			$log_size_display = '';

			if ( null !== $log_file_size ) {
				$log_size_display = function_exists( 'size_format' )
					? size_format( $log_file_size, 2 )
					: $log_file_size . ' bytes';
			}
			?>
			<div class="wp-mcp-ai-log-meta">
				<?php if ( '' !== $log_file_path ) : ?>
					<p class="description">
						<?php
						if ( $log_file_exists ) {
							if ( '' === $log_size_display ) {
								$log_size_display = __( 'Unknown size', 'wp-mcp-ai' );
							}

							printf(
								/* translators: 1: Path to the PHP error log. 2: Human readable size. */
								esc_html__( 'PHP error log: %1$s (%2$s).', 'wp-mcp-ai' ),
								'<code>' . esc_html( $log_file_path ) . '</code>',
								esc_html( $log_size_display )
							);
						} else {
							printf(
								/* translators: %s: Path to the PHP error log. */
								esc_html__( 'PHP error log: %s (not created yet).', 'wp-mcp-ai' ),
								'<code>' . esc_html( $log_file_path ) . '</code>'
							);
						}
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Unable to determine the PHP error log location. Check your server configuration if you need to inspect or prune the log.', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
				<?php if ( WP_MCP_AI_Logger::can_prune_error_log() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mcp-ai-log-meta__actions">
						<?php wp_nonce_field( 'wp_mcp_ai_prune_log', 'wp_mcp_ai_prune_log_nonce' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_prune_log" />
						<?php submit_button( __( 'Prune log file', 'wp-mcp-ai' ), 'secondary', 'wp_mcp_ai_prune_log', false ); ?>
					</form>
				<?php elseif ( '' !== $log_file_path && $log_file_exists ) : ?>
					<p class="description"><?php esc_html_e( 'The PHP error log is not writable. Update the file permissions to prune it from the dashboard.', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handle pruning the PHP error log when triggered from the settings page.
	 */
	public function handle_prune_log_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_prune_log', 'wp_mcp_ai_prune_log_nonce' );

		$result  = WP_MCP_AI_Logger::prune_error_log();
		$message = '';
		$type    = 'updated';

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			$type    = 'error';
		} else {
			$message = __( 'The PHP error log was pruned successfully.', 'wp-mcp-ai' );
		}

		add_settings_error( 'wp_mcp_ai_prune_log', 'wp_mcp_ai_prune_log_notice', $message, $type );

		$errors = get_settings_errors( 'wp_mcp_ai_prune_log' );

		if ( ! empty( $errors ) ) {
			set_transient( 'settings_errors', $errors, 30 );
		}

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the default model field.
	 */
	public function render_default_model_field() {
		$settings    = self::get_settings();
		$current     = isset( $settings['default_model'] ) ? sanitize_text_field( $settings['default_model'] ) : '';
		$choices     = $this->get_openai_default_model_choices();
		$datalist_id = 'wp-mcp-ai-default-openai-models';
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_model]"
			value="<?php echo esc_attr( $current ); ?>"
			class="regular-text"
			list="<?php echo esc_attr( $datalist_id ); ?>"
		/>
		<?php if ( ! empty( $choices ) ) : ?>
			<datalist id="<?php echo esc_attr( $datalist_id ); ?>">
				<?php foreach ( $choices as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</datalist>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The Chat Completions model to use when assistants do not specify one.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Retrieve suggested OpenAI chat completion models for the default model field.
	 *
	 * @return array<string, string> Associative array of model slugs mapped to display labels.
	 */
	protected function get_openai_default_model_choices() {
		$choices = array(
			'gpt-5'                   => __( 'GPT-5', 'wp-mcp-ai' ),
			'gpt-4o'                  => __( 'GPT-4o', 'wp-mcp-ai' ),
			'gpt-4o-mini'             => __( 'GPT-4o mini', 'wp-mcp-ai' ),
			'gpt-4.1'                 => __( 'GPT-4.1', 'wp-mcp-ai' ),
			'gpt-4.1-mini'            => __( 'GPT-4.1 mini', 'wp-mcp-ai' ),
			'o4-mini'                 => __( 'o4 mini', 'wp-mcp-ai' ),
			'gpt-4o-audio-preview'    => __( 'GPT-4o audio preview', 'wp-mcp-ai' ),
			'gpt-4o-realtime-preview' => __( 'GPT-4o realtime preview', 'wp-mcp-ai' ),
		);

		/**
		 * Filter the default OpenAI model choices displayed in the settings UI.
		 *
		 * @param array<string, string> $choices Associative array of model values mapped to human-readable labels.
		 */
		$choices = apply_filters( 'wp_mcp_ai_default_openai_model_choices', $choices );

		if ( ! is_array( $choices ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $choices as $value => $label ) {
			$value = sanitize_text_field( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			if ( is_object( $label ) && method_exists( $label, '__toString' ) ) {
				$label = (string) $label;
			} elseif ( is_scalar( $label ) ) {
				$label = (string) $label;
			} else {
				$label = $value;
			}

			$label = wp_strip_all_tags( $label );

			if ( '' === $label ) {
				$label = $value;
			}

			$sanitized[ $value ] = $label;
		}

		return $sanitized;
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
	 * Render the memory file size limit field.
	 */
	public function render_memory_max_file_bytes_field() {
		$settings = self::get_settings();
		$choices  = $this->get_memory_max_file_size_choices();
		$current  = isset( $settings['memory_max_file_bytes'] ) ? absint( $settings['memory_max_file_bytes'] ) : self::DEFAULT_MEMORY_MAX_FILE_BYTES;
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[memory_max_file_bytes]" class="regular-text">
			<?php foreach ( $choices as $bytes => $label ) : ?>
				<option value="<?php echo esc_attr( $bytes ); ?>" <?php selected( $current, $bytes ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Largest attachment size that can be processed as assistant memory.', 'wp-mcp-ai' ); ?></p>
		<?php
	}

	/**
	 * Retrieve the selectable memory file size limits.
	 *
	 * @return array
	 */
	protected function get_memory_max_file_size_choices() {
		$choices = array(
			5 * MB_IN_BYTES   => __( '5 MB (default)', 'wp-mcp-ai' ),
			10 * MB_IN_BYTES  => __( '10 MB', 'wp-mcp-ai' ),
			25 * MB_IN_BYTES  => __( '25 MB', 'wp-mcp-ai' ),
			50 * MB_IN_BYTES  => __( '50 MB', 'wp-mcp-ai' ),
			100 * MB_IN_BYTES => __( '100 MB', 'wp-mcp-ai' ),
		);

		/**
		 * Filters the selectable memory file size limits shown in the admin.
		 *
		 * @param array $choices Associative array mapping byte sizes to labels.
		 */
		$choices = apply_filters( 'wp_mcp_ai_memory_max_file_size_choices', $choices );

		if ( ! is_array( $choices ) || empty( $choices ) ) {
			return array( self::DEFAULT_MEMORY_MAX_FILE_BYTES => __( '5 MB (default)', 'wp-mcp-ai' ) );
		}

		$sanitized = array();

		foreach ( $choices as $bytes => $label ) {
			$bytes = absint( $bytes );

			if ( $bytes <= 0 ) {
				continue;
			}

			$sanitized[ $bytes ] = $label;
		}

		if ( empty( $sanitized ) ) {
			$sanitized[ self::DEFAULT_MEMORY_MAX_FILE_BYTES ] = __( '5 MB (default)', 'wp-mcp-ai' );
		}

		return $sanitized;
	}

	/**
	 * Retrieve the list of available OpenAI image models.
	 *
	 * @return array
	 */
	protected function get_openai_image_model_choices() {
		$models = array(
			'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
			'dall-e-3'    => __( 'DALL·E 3', 'wp-mcp-ai' ),
			'dall-e-2'    => __( 'DALL·E 2', 'wp-mcp-ai' ),
		);

		$models = apply_filters( 'wp_mcp_ai_openai_image_models', $models );

		if ( ! is_array( $models ) || empty( $models ) ) {
			$models = array(
				'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
				'dall-e-3'    => __( 'DALL·E 3', 'wp-mcp-ai' ),
				'dall-e-2'    => __( 'DALL·E 2', 'wp-mcp-ai' ),
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
			'1024x1792' => __( '1024 × 1792 (portrait)', 'wp-mcp-ai' ),
			'1792x1024' => __( '1792 × 1024 (landscape)', 'wp-mcp-ai' ),
			'auto'      => __( 'Auto (let OpenAI decide)', 'wp-mcp-ai' ),
		);

		$sizes = apply_filters( 'wp_mcp_ai_openai_image_sizes', $sizes );

		if ( ! is_array( $sizes ) || empty( $sizes ) ) {
			$sizes = array(
				'1024x1024' => __( '1024 × 1024 (square)', 'wp-mcp-ai' ),
				'1024x1792' => __( '1024 × 1792 (portrait)', 'wp-mcp-ai' ),
				'1792x1024' => __( '1792 × 1024 (landscape)', 'wp-mcp-ai' ),
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
			'hd'       => __( 'HD (High Definition)', 'wp-mcp-ai' ),
		);

		$qualities = apply_filters( 'wp_mcp_ai_openai_image_qualities', $qualities );

		if ( ! is_array( $qualities ) || empty( $qualities ) ) {
			$qualities = array(
				'standard' => __( 'Standard', 'wp-mcp-ai' ),
				'hd'       => __( 'HD (High Definition)', 'wp-mcp-ai' ),
			);
		}

		return $qualities;
	}

	/**
	 * Retrieve the list of available OpenAI image response formats.
	 *
	 * @return array
	 */
	protected function get_openai_image_response_format_choices() {
		$formats = array(
			'b64_json' => __( 'Base64 JSON (download immediately)', 'wp-mcp-ai' ),
			'url'      => __( 'Hosted URL (download from OpenAI)', 'wp-mcp-ai' ),
		);

		$formats = apply_filters( 'wp_mcp_ai_openai_image_response_formats', $formats );

		if ( ! is_array( $formats ) || empty( $formats ) ) {
			$formats = array(
				'b64_json' => __( 'Base64 JSON (download immediately)', 'wp-mcp-ai' ),
				'url'      => __( 'Hosted URL (download from OpenAI)', 'wp-mcp-ai' ),
			);
		}

		return $formats;
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

	/**
	 * Override the memory file size limit with the admin setting.
	 *
	 * @param int $max_bytes Default maximum bytes allowed.
	 * @param int $attachment_id Attachment ID being evaluated.
	 * @return int
	 */
	public function filter_memory_max_file_bytes( $max_bytes, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$settings = self::get_settings();
		$limit    = isset( $settings['memory_max_file_bytes'] ) ? absint( $settings['memory_max_file_bytes'] ) : 0;

		if ( $limit > 0 ) {
			return $limit;
		}

		return $max_bytes;
	}

	/**
	 * Handle AJAX request to test Ollama connection.
	 */
	public function handle_test_ollama_connection() {
		check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
			return;
		}

		$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

		if ( empty( $endpoint_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
			return;
		}

		// Temporarily set the endpoint URL for testing.
		$original_settings = self::get_settings();
		$test_settings     = $original_settings;
		$test_settings['ollama_endpoint_url'] = $endpoint_url;

		// Create a temporary Ollama client instance.
		$ollama_client = new WP_MCP_AI_Ollama_Client();

		// Update settings temporarily.
		update_option( self::OPTION_NAME, $test_settings );

		$result = $ollama_client->test_connection();

		// Restore original settings.
		update_option( self::OPTION_NAME, $original_settings );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX request to fetch Ollama models.
	 */
	public function handle_fetch_ollama_models() {
		check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
			return;
		}

		$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

		if ( empty( $endpoint_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
			return;
		}

		// Temporarily set the endpoint URL for fetching models.
		$original_settings = self::get_settings();
		$test_settings     = $original_settings;
		$test_settings['ollama_endpoint_url'] = $endpoint_url;

		// Create a temporary Ollama client instance.
		$ollama_client = new WP_MCP_AI_Ollama_Client();

		// Update settings temporarily.
		update_option( self::OPTION_NAME, $test_settings );

		$models = $ollama_client->list_models();

		// Restore original settings.
		update_option( self::OPTION_NAME, $original_settings );

		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'models' => $models ) );
	}

	/**
	 * Handle AJAX request to test LM Studio connection.
	 */
	public function handle_test_lm_studio_connection() {
		check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
			return;
		}

		$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

		if ( empty( $endpoint_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
			return;
		}

		// Temporarily set the endpoint URL for testing.
		$original_settings = self::get_settings();
		$test_settings     = $original_settings;
		$test_settings['lm_studio_endpoint_url'] = $endpoint_url;

		// Create a temporary LM Studio client instance.
		$lm_studio_client = new WP_MCP_AI_LM_Studio_Client();

		// Update settings temporarily.
		update_option( self::OPTION_NAME, $test_settings );

		$result = $lm_studio_client->test_connection();

		// Restore original settings.
		update_option( self::OPTION_NAME, $original_settings );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX request to fetch LM Studio models.
	 */
	public function handle_fetch_lm_studio_models() {
		check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
			return;
		}

		$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

		if ( empty( $endpoint_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
			return;
		}

		// Temporarily set the endpoint URL for fetching models.
		$original_settings = self::get_settings();
		$test_settings     = $original_settings;
		$test_settings['lm_studio_endpoint_url'] = $endpoint_url;

		// Create a temporary LM Studio client instance.
		$lm_studio_client = new WP_MCP_AI_LM_Studio_Client();

		// Update settings temporarily.
		update_option( self::OPTION_NAME, $test_settings );

		$models = $lm_studio_client->list_models();

		// Restore original settings.
		update_option( self::OPTION_NAME, $original_settings );

		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'models' => $models ) );
	}

	/**
	 * Handle AJAX request to fetch Cloudways data (servers and applications).
	 */
	public function handle_fetch_cloudways_data() {
		check_ajax_referer( 'wp_mcp_ai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
			return;
		}

		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $email ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide both email and API key.', 'wp-mcp-ai' ) ) );
			return;
		}

		// Step 1: Get OAuth access token.
		$oauth_url  = 'https://api.cloudways.com/api/v1/oauth/access_token';
		$oauth_body = array(
			'email'   => $email,
			'api_key' => $api_key,
		);

		$oauth_response = wp_remote_post(
			$oauth_url,
			array(
				'body'    => wp_json_encode( $oauth_body ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $oauth_response ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to connect to Cloudways API: ', 'wp-mcp-ai' ) . $oauth_response->get_error_message() ) );
			return;
		}

		$oauth_code = wp_remote_retrieve_response_code( $oauth_response );
		$oauth_data = json_decode( wp_remote_retrieve_body( $oauth_response ), true );

		if ( 200 !== $oauth_code || empty( $oauth_data['access_token'] ) ) {
			$error_message = ! empty( $oauth_data['message'] ) ? $oauth_data['message'] : __( 'Invalid credentials.', 'wp-mcp-ai' );
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		$access_token = $oauth_data['access_token'];

		// Step 2: Fetch servers.
		$servers_url      = 'https://api.cloudways.com/api/v1/server';
		$servers_response = wp_remote_get(
			$servers_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $servers_response ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to fetch servers: ', 'wp-mcp-ai' ) . $servers_response->get_error_message() ) );
			return;
		}

		$servers_code = wp_remote_retrieve_response_code( $servers_response );
		$servers_data = json_decode( wp_remote_retrieve_body( $servers_response ), true );

		if ( 200 !== $servers_code || empty( $servers_data['servers'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No servers found or failed to fetch servers.', 'wp-mcp-ai' ) ) );
			return;
		}

		$servers = array();
		foreach ( $servers_data['servers'] as $server ) {
			// Validate that expected fields exist.
			if ( ! isset( $server['id'] ) || ! isset( $server['label'] ) ) {
				continue;
			}

			$servers[] = array(
				'id'     => sanitize_text_field( $server['id'] ),
				'label'  => sanitize_text_field( $server['label'] ),
				'status' => isset( $server['status'] ) ? sanitize_text_field( $server['status'] ) : 'unknown',
			);
		}

		// Step 3: Fetch applications from the first server.
		$apps = array();
		if ( ! empty( $servers ) ) {
			$first_server_id = $servers[0]['id'];
			$apps_url        = add_query_arg( 'server_id', $first_server_id, 'https://api.cloudways.com/api/v1/apps' );
			$apps_response   = wp_remote_get(
				$apps_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( ! is_wp_error( $apps_response ) ) {
				$apps_code = wp_remote_retrieve_response_code( $apps_response );
				$apps_data = json_decode( wp_remote_retrieve_body( $apps_response ), true );

				if ( 200 === $apps_code && ! empty( $apps_data['apps'] ) ) {
					foreach ( $apps_data['apps'] as $app ) {
						// Validate that expected fields exist.
						if ( ! isset( $app['id'] ) || ! isset( $app['label'] ) ) {
							continue;
						}

						$apps[] = array(
							'id'        => sanitize_text_field( $app['id'] ),
							'label'     => sanitize_text_field( $app['label'] ),
							'server_id' => $first_server_id,
						);
					}
				}
			}
		}

		wp_send_json_success(
			array(
				'servers' => $servers,
				'apps'    => $apps,
			)
		);
	}
}

