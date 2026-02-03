<?php
/**
 * Tools & Features Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Tools' ) ) {
	/**
	 * Tools & Features settings section.
	 */
	class WP_MCP_AI_Section_Tools extends WP_MCP_AI_Settings_Section {
		/**
		 * Transient key for Elementor kit import results.
		 *
		 * @var string
		 */
		const ELEMENTOR_KIT_IMPORT_TRANSIENT = 'wp_mcp_ai_elementor_kit_import_result';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_init', array( $this, 'handle_elementor_kit_import' ) );
		}

		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'tools';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Tools & Features Configuration', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure AI-powered tools and features for your WordPress site.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/tools/tool-reference.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get available WordPress capabilities for dropdown.
			$wp_capabilities = array(
				'read'              => __( 'Read (Subscriber)', 'mcp-ai-wpoos' ),
				'edit_posts'        => __( 'Edit Posts (Contributor)', 'mcp-ai-wpoos' ),
				'publish_posts'     => __( 'Publish Posts (Author)', 'mcp-ai-wpoos' ),
				'edit_others_posts' => __( 'Edit Others Posts (Editor)', 'mcp-ai-wpoos' ),
				'manage_options'    => __( 'Manage Options (Administrator)', 'mcp-ai-wpoos' ),
			);

			$fields = array(
				// Tool Configuration.
				'web_search_provider'                    => array(
					'type'        => 'select',
					'label'       => __( 'Web Search Provider', 'mcp-ai-wpoos' ),
					'description' => __( 'Choose the search engine to use for web search tool. DuckDuckGo is free but has rate limits. Brave Search requires an API key but offers higher limits and better results.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'duckduckgo' => 'DuckDuckGo (Free, Rate Limited)',
						'brave'      => 'Brave Search (API Key Required)',
					),
					'default'     => 'duckduckgo',
				),
				'enable_varnish_purge'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Varnish Purge Tool', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Varnish cache purging functionality', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows AI assistants to purge Varnish cache when making content changes. Requires Varnish HTTP cache to be configured on your server. Only enable if you have Varnish installed.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'group_email_capability'                 => array(
					'type'        => 'select',
					'label'       => __( 'Send Group Email Capability', 'mcp-ai-wpoos' ),
					'description' => __( 'WordPress capability required to use the Send Group Email tool. Controls who can send bulk emails through AI assistants. Higher capabilities = more restricted access.', 'mcp-ai-wpoos' ),
					'options'     => $wp_capabilities,
					'default'     => 'publish_posts',
				),
				'group_email_max_recipients'             => array(
					'type'        => 'number',
					'label'       => __( 'Max Email Recipients', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of recipients allowed in a single group email. Higher limits increase the risk of spam. Consider your server\'s email sending limits.', 'mcp-ai-wpoos' ),
					'default'     => 100,
					'min'         => 1,
					'max'         => 1000,
					'step'        => 10,
					'placeholder' => '100',
				),
				'search_gmail_capability'                => array(
					'type'        => 'select',
					'label'       => __( 'Search Gmail Capability', 'mcp-ai-wpoos' ),
					'description' => __( 'WordPress capability required to use the Search Gmail tool. Controls who can search Gmail messages through AI assistants. Default is Manage Options (Administrator) for security.', 'mcp-ai-wpoos' ),
					'options'     => $wp_capabilities,
					'default'     => 'manage_options',
				),
				'search_gmail_max_results'               => array(
					'type'        => 'number',
					'label'       => __( 'Max Gmail Search Results', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of Gmail messages that can be returned in a single search. Lower values improve performance and reduce API usage.', 'mcp-ai-wpoos' ),
					'default'     => 50,
					'min'         => 1,
					'max'         => 100,
					'step'        => 5,
					'placeholder' => '50',
				),
				'search_drive_capability'                => array(
					'type'        => 'select',
					'label'       => __( 'Search Google Drive Capability', 'mcp-ai-wpoos' ),
					'description' => __( 'WordPress capability required to use the Search Google Drive tool. Controls who can search Drive files through AI assistants. Default is Manage Options (Administrator) for security.', 'mcp-ai-wpoos' ),
					'options'     => $wp_capabilities,
					'default'     => 'manage_options',
				),
				'search_drive_max_results'               => array(
					'type'        => 'number',
					'label'       => __( 'Max Drive Search Results', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of Google Drive files that can be returned in a single search. Lower values improve performance and reduce API usage.', 'mcp-ai-wpoos' ),
					'default'     => 50,
					'min'         => 1,
					'max'         => 100,
					'step'        => 5,
					'placeholder' => '50',
				),

				// External Tools fields.
				'gmail_client_id'                        => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'gmail_client_secret'                    => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'gmail_refresh_token'                    => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'gmail_user_email'                       => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'brave_search_api_key'                   => array(
					'type'         => 'password',
					'label'        => __( 'Brave Search API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Brave Search API */
						__( 'API key for Brave Search integration. Get your API key from %s.', 'mcp-ai-wpoos' ),
						'<a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_api_token'                   => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'mcp-ai-wpoos' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'                     => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'cloudways_api_key'                      => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'                        => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'mcp-ai-wpoos' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'mcp-ai-wpoos' ),
					'placeholder' => 'you@example.com',
				),
				'mailjet_api_key'                        => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_api_secret'                     => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'API secret for Mailjet email service.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_api_key'                     => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'API key for QuickBooks integration.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_client_id'                   => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'mcp-ai-wpoos' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'quickbooks_client_secret'               => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'google_analytics_property_id'           => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'mcp-ai-wpoos' ),
					'placeholder' => '123456789',
				),
				'google_analytics_credentials'           => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON', 'mcp-ai-wpoos' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console.', 'mcp-ai-wpoos' ),
					'placeholder' => '{"type": "service_account", ...}',
				),

				// remove.bg API.
				'removebg_api_key'                       => array(
					'type'         => 'password',
					'label'        => __( 'remove.bg API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to remove.bg API */
						__( 'API key for remove.bg background removal service. Get your API key from %s. Note: A free tier with Python rembg is also available without an API key.', 'mcp-ai-wpoos' ),
						'<a href="https://www.remove.bg/api" target="_blank">remove.bg API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// GitHub Integration fields.
				'github_client_id'                       => array(
					'type'         => 'text',
					'label'        => __( 'GitHub OAuth Client ID', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to GitHub Developer Settings */
						__( 'OAuth 2.0 Client ID from GitHub Developer Settings. Create an OAuth app at %s.', 'mcp-ai-wpoos' ),
						'<a href="https://github.com/settings/developers" target="_blank">GitHub Developer Settings</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'github_client_secret'                   => array(
					'type'         => 'password',
					'label'        => __( 'GitHub OAuth Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret from GitHub Developer Settings.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'github_access_token'                    => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'github_username'                        => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),

				// Plugins Integration fields.
				'enable_jetengine_cct'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine CCT Storage', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable JetEngine CCT storage', 'mcp-ai-wpoos' ),
					'description'    => __( 'Use JetEngine Custom Content Types for efficient chat transcript and assistant data storage.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_jetengine_tools'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine AI Tools', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable JetEngine AI tools', 'mcp-ai-wpoos' ),
					'description'    => __( 'Activate JetEngine-specific tools for post type management, taxonomy operations, and CCT queries.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_woocommerce_tools'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable WooCommerce AI Tools', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable WooCommerce AI tools', 'mcp-ai-wpoos' ),
					'description'    => __( 'Activate WooCommerce-specific tools for managing products, orders, and customers.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_elementor_widgets'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Elementor AI Widgets', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Elementor AI widgets', 'mcp-ai-wpoos' ),
					'description'    => __( 'Add AI-powered chat widgets and other AI elements to Elementor page builder.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'enable_sitekit_integration'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Google Site Kit Integration', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Google Site Kit integration', 'mcp-ai-wpoos' ),
					'description'    => __( 'Activate Google Site Kit integration to access Analytics, Search Console, PageSpeed, and AdSense data through AI assistants. Requires <a href="https://wordpress.org/plugins/google-site-kit/" target="_blank">Google Site Kit plugin</a> to be installed and configured.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),

				// Features fields.
				'enable_quiz_system'                     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Quiz System', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable quiz creation and assessment tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables the quiz/assessment system for tutors. Provides 7 tools for creating quizzes, managing submissions, and grading. Includes automatic JetEngine CCT synchronization when available. Requires edit_posts capability to create quizzes. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'enable_media_toolkit'                   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Media Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable media template management (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables the Media Toolkit for creating and managing reusable templates for the Graphic Editor Plus tool. Templates allow you to save operation configurations (logo positions, resize settings, AI prompts) and apply them to multiple images via AI assistants or batch operations. Requires upload_files capability. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'enable_document_generation_toolkit'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Document Generation Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI-powered PDF, Word, and Excel document generation (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables the Document Generation Toolkit with 3 tools for creating professional documents: pro_pdf (PDF generation), pro_word (Word documents with templates), and pro_excel_document (Excel spreadsheets with formulas). AI generates content from natural language descriptions. Requires upload_files capability and Node.js with npm packages (pdfkit, docx, exceljs) installed on the server. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Excel and Document Generation Settings (Pro).
				'excel_default_version'                  => array(
					'type'        => 'select',
					'label'       => __( 'Excel Version Target', 'mcp-ai-wpoos' ),
					'description' => __( 'Default Excel version for formula generation. Modern (Excel 2021+/Microsoft 365) supports LAMBDA, LET, XLOOKUP, and other advanced functions. Legacy (Excel 2019 and earlier) uses traditional formulas. Excel Online supports cloud-specific features.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'modern' => __( 'Modern (Excel 2021+/Microsoft 365 - LAMBDA supported)', 'mcp-ai-wpoos' ),
						'legacy' => __( 'Legacy (Excel 2019 and earlier - Traditional formulas)', 'mcp-ai-wpoos' ),
						'online' => __( 'Excel Online (Cloud features)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'modern',
					'pro_badge'   => true,
				),
				'excel_enable_lambda'                    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable LAMBDA Functions', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Generate LAMBDA and custom functions for advanced Excel scenarios', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, the Pro Excel tool can generate LAMBDA functions for custom, reusable, and recursive formulas. LAMBDA makes Excel Turing-complete, enabling advanced programming capabilities. Requires Excel 2021+ or Microsoft 365.', 'mcp-ai-wpoos' ),
					'default'        => true,
					'pro_badge'      => true,
				),
				'excel_max_complexity'                   => array(
					'type'        => 'select',
					'label'       => __( 'Maximum Formula Complexity', 'mcp-ai-wpoos' ),
					'description' => __( 'Controls the complexity level for generated formulas. Simple formulas are easier to understand and maintain. Complex formulas offer more sophisticated solutions but may be harder to debug. Advanced formulas use cutting-edge Excel features.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'simple'   => __( 'Simple (Basic formulas, easy to understand)', 'mcp-ai-wpoos' ),
						'moderate' => __( 'Moderate (Nested functions, intermediate complexity)', 'mcp-ai-wpoos' ),
						'complex'  => __( 'Complex (Advanced formulas with multiple steps)', 'mcp-ai-wpoos' ),
						'advanced' => __( 'Advanced (LAMBDA, recursive, expert-level)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'moderate',
					'pro_badge'   => true,
				),
				'excel_include_comments'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Include Formula Comments', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Add explanatory comments to generated formulas', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, generated formulas include inline comments explaining each step and component. This makes formulas easier to understand and maintain, especially for complex calculations.', 'mcp-ai-wpoos' ),
					'default'        => true,
					'pro_badge'      => true,
				),
				'excel_optimization_level'               => array(
					'type'        => 'select',
					'label'       => __( 'Formula Optimization', 'mcp-ai-wpoos' ),
					'description' => __( 'Choose how formulas are optimized. Readability prioritizes clear, maintainable code. Performance focuses on calculation speed and efficiency. Balanced provides a compromise between the two.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'readability' => __( 'Readability (Clear, maintainable formulas)', 'mcp-ai-wpoos' ),
						'performance' => __( 'Performance (Fast, efficient calculations)', 'mcp-ai-wpoos' ),
						'balanced'    => __( 'Balanced (Compromise between both)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'balanced',
					'pro_badge'   => true,
				),

				// Media fields.
				'enable_ai_media_library'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Media Library', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically analyze images on upload', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, newly uploaded images will be automatically analyzed by AI to generate alt text and captions. This feature uses vision-capable AI models (requires OpenAI or Gemini API key).', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'ai_media_generate_alt_text'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Alt Text', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically generate alt text for accessibility', 'mcp-ai-wpoos' ),
					'description'    => __( 'Generate descriptive alt text for images to improve accessibility for screen readers and SEO.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'ai_media_generate_caption'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Captions', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically generate image captions', 'mcp-ai-wpoos' ),
					'description'    => __( 'Generate detailed captions for images to provide context and enhance content.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'ai_media_overwrite_existing'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Overwrite Existing', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Replace existing alt text and captions', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, AI will overwrite any existing alt text or captions. When disabled, AI will only fill in missing metadata.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Comments fields.
				'enable_ai_comments_moderation'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Comments Moderation', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically analyze comments for spam and toxicity', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, incoming comments will be automatically analyzed by AI to detect spam, toxic content, and other moderation concerns before they are published.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'ai_comments_sensitivity'                => array(
					'type'        => 'select',
					'label'       => __( 'Moderation Sensitivity', 'mcp-ai-wpoos' ),
					'description' => __( 'Controls how strict the AI moderation should be. Low = permissive (only flag obvious violations), Medium = balanced (flag clear issues), High = strict (flag anything questionable).', 'mcp-ai-wpoos' ),
					'options'     => array(
						'low'    => __( 'Low (Permissive)', 'mcp-ai-wpoos' ),
						'medium' => __( 'Medium (Balanced)', 'mcp-ai-wpoos' ),
						'high'   => __( 'High (Strict)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'medium',
				),
				'ai_comments_min_confidence'             => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Confidence Level', 'mcp-ai-wpoos' ),
					'description' => __( 'Only apply AI recommendations when confidence is at or above this threshold. Lower values trust AI more, higher values require more certainty.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'0.5' => __( '50% (Trust AI more)', 'mcp-ai-wpoos' ),
						'0.6' => __( '60%', 'mcp-ai-wpoos' ),
						'0.7' => __( '70% (Balanced - Recommended)', 'mcp-ai-wpoos' ),
						'0.8' => __( '80%', 'mcp-ai-wpoos' ),
						'0.9' => __( '90% (Very conservative)', 'mcp-ai-wpoos' ),
					),
					'default'     => '0.7',
				),
				'ai_comments_auto_hold_low_confidence'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Hold Low Confidence Comments', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Hold comments for manual review when AI confidence is below threshold', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, comments that AI analyzes with low confidence will be held for moderation instead of being published or marked as spam.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),

				// Project Management fields.
				'enable_project_management'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Project Management', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI-powered project, task, and event management (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered project, task, and event management. Provides 13 tools for creating, updating, listing, and deleting projects, tasks, and events. Includes automatic JetEngine CCT synchronization when available. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// AI CPT Management Interface.
				'enable_ai_cpt_management'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI CPT Management', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Add AI assistant to WordPress post/page/product/term edit screens (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI assistant integration on WordPress custom post type edit screens (posts, pages, products, terms). Adds an AI metabox that allows you to use AI tools to help create, edit, and manage content directly from the WordPress admin interface. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Places Management fields.
				'enable_places_management'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Places Management', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI-powered places and location management (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered places management for attractions, businesses, and locations. Provides 6+ tools for creating, searching, listing, and managing places with Google Maps integration. Includes geocoding, radius search, and place data enrichment. Enhances all geospatial and mapping tools. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ECA Management fields.
				'enable_eca_management'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable ECA Pro Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Extra-Curricular Activities (ECA) management toolkit (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered Extra-Curricular Activities management for schools. Provides tools for creating and managing ECAs (clubs, societies, sports), managing student enrollments, viewing schedules, and syncing with iSAMS. Includes 5+ tools for comprehensive ECA administration. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Health and Wellness Management fields.
				'enable_health_wellness_management'      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Health & Wellness Pro Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Health and Wellness management toolkit (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered health and wellness management for individuals, families, and pets. Provides 30+ tools for managing members, medical records, policies, checkups, prescriptions, and allergies. Includes secure health data storage with proper access controls. Always ensure HIPAA/GDPR compliance for healthcare deployments. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Cloudways Pro Toolkit fields.
				'enable_cloudways_toolkit'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cloudways Pro Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Cloudways server and application management toolkit (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered Cloudways hosting management for servers and applications. Provides 58+ tools for server management, application deployment, monitoring, security, backups, and performance optimization. Includes server operations, database management, SSL certificate management, and deployment automation. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// ========================================================================
				// PRO TOOLKITS - New Professional Toolkits (Phases 2-6)
				// ========================================================================

				// E-commerce Toolkit.
				'enable_ecommerce_toolkit'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable E-commerce Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable advanced WooCommerce and e-commerce tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 20 AI-powered e-commerce tools for product management, order processing, inventory tracking, customer segmentation, and sales analytics. Requires WooCommerce. Supports mesh network for multi-store inventory sync. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Social Media Management Toolkit.
				'enable_social_media_toolkit'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Social Media Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable social media management and content tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 15 AI-powered social media tools for content calendar management, multi-platform posting, analytics, hashtag tracking, competitor analysis, and automated responses. Supports mesh network for cross-site posting. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Advanced Analytics Toolkit.
				'enable_analytics_toolkit'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Analytics Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable advanced analytics and reporting tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 12 AI-powered analytics tools for revenue forecasting, customer segmentation, churn prediction, custom reports, and data warehouse integrations (BigQuery, Snowflake, Redshift). Supports mesh network for aggregated reporting. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Multilingual Content Toolkit.
				'enable_multilingual_toolkit'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Multilingual Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable multilingual content management tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 10 AI-powered multilingual tools for content translation, language detection, RTL optimization, translation memory, quality checks, and WooCommerce product translation. Supports mesh network for shared translation memory. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Video Production Toolkit.
				'enable_video_production_toolkit'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Video Production Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable video editing and production tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 12 AI-powered video tools for editing, compression, format conversion, watermarking, thumbnail generation, captions, and platform optimization. Requires FFmpeg on server. Supports mesh network for distributed rendering. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Financial Planner Toolkit.
				'enable_financial_planner_toolkit'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Financial Planner Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable financial planning and analysis tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 24 AI-powered financial tools for retirement planning, budget management, investment analysis, debt calculation, goal planning, and tax estimation. Includes Plaid API integration for bank account sync. EDUCATIONAL USE ONLY - Not financial advice. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Calendar Booking Toolkit (Phase 2.6).
				'enable_calendar_booking_toolkit'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Calendar Booking Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable appointment scheduling and booking tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 12-15 AI-powered booking tools for appointment scheduling, calendar sync (Google/Outlook/iCloud), staff management, service offerings, availability management, and payment processing. Supports mesh network for multi-location availability. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// DJ Management Toolkit (Phase 2.7).
				'enable_dj_management_toolkit'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable DJ Management Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable DJ business management tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 15-18 AI-powered DJ management tools for event booking, equipment inventory, playlist creation, contract generation, payment tracking, and music API integrations (Spotify, Apple Music, YouTube). Includes DocuSign/HelloSign for contracts. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Image Production Toolkit (Phase 2.8).
				'enable_image_production_toolkit'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Image Production Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI image generation and editing tools (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 12-15 AI-powered image tools for AI generation (DALL-E 3, Stable Diffusion), upscaling, editing, format conversion, compression, and watermarking. Supports mesh network for GPU-accelerated processing. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// AI Tool Builder Toolkit (Phase 2.9).
				'enable_ai_tool_builder_toolkit'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Tool Builder Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable meta-toolkit for building custom tools with AI (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 10 AI-powered meta-tools for building new tools: scaffolding, code generation, parameter schema design, test generation, documentation, and quality assurance. Uses OpenAI Codex for intelligent tool creation. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Architect Agent Toolkit (Self-Editing Capabilities).
				'enable_architect_agent_toolkit'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Architect Agent Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI self-editing with GitHub Copilot CLI parity (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 4 self-editing tools for AI agents: file management (read/write/list), shell command execution, git operations, and code search. Provides GitHub Copilot CLI-level capabilities for autonomous development. Requires edit_plugins capability. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Architectural Design Toolkit (Phase 2.10).
				'enable_architectural_design_toolkit'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Architectural Design Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable AI-powered architectural design and blueprinting (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 16 professional architectural tools: AI floor plan generation, space optimization, 3D modeling, photorealistic rendering, construction blueprints, code compliance checking, sustainability analysis, and cost estimation. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Site Creator Toolkit - Advanced site creation capabilities.
				'enable_site_creator_toolkit'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Site Creator Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable advanced AI-powered site creation with page/section/widget builders (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 26 site creation tools: research & best practices (4), page builders (5), section builders (6), widget builders (4), template management (4), and Architect Agent integration (3) for automated development workflows. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Regulatory Registration Toolkit - Multi-country cosmetics and perfume product registration.
				'enable_regulatory_registration_toolkit' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Regulatory Registration Pro Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable multi-country cosmetics and perfume product registration management (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 15 regulatory registration tools for managing product registrations, compliance documents, regulatory requirements, and multi-country submissions (Sri Lanka NMRA, UAE, Saudi SFDA, Qatar, Kuwait, Oman, India). This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'enable_fantasy_football'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Fantasy Football Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable fantasy football tools and management', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables 9 tools for managing fantasy football teams, analyzing trades, researching players, and generating league reports. Requires Yahoo Fantasy Sports API credentials configured in the Fantasy Football Settings page. Includes OAuth authentication, roster management, player statistics, trade analysis, and AI-powered team logo generation.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
			);

			// Site Creator is a Pro feature - show promotional notice in base version.
			$is_base_version = wp_mcp_ai_is_base_version();

			$fields['enable_site_creator']                     = array(
				'type'           => 'checkbox',
				'label'          => __( 'Enable Site Creator', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Allow AI to create and configure sites', 'mcp-ai-wpoos' ),
				'description'    => __( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content. This feature requires manage_options capability.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);
			$fields['site_creator_allow_plugin_install']       = array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow Plugin Installation', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Enable automatic plugin installation from WordPress.org', 'mcp-ai-wpoos' ),
				'description'    => __( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);
			$fields['site_creator_allow_theme_install']        = array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow Theme Installation', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Enable automatic theme installation from WordPress.org', 'mcp-ai-wpoos' ),
				'description'    => __( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);
			$fields['site_creator_allow_option_updates']       = array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow Option Updates', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Enable automatic WordPress option updates', 'mcp-ai-wpoos' ),
				'description'    => __( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);
			$fields['site_creator_allow_wp_cli_tools']         = array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow WP-CLI Tools', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Enable WP-CLI inspection and execution tools', 'mcp-ai-wpoos' ),
				'description'    => __( 'Allows AI to inspect and interact with the WP-CLI environment. This includes checking WP-CLI availability and version information.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);
			$fields['site_creator_allow_elementor_kit_import'] = array(
				'type'           => 'checkbox',
				'label'          => __( 'Allow Elementor Kit Import', 'mcp-ai-wpoos' ),
				'checkbox_label' => __( 'Enable Elementor template kit import', 'mcp-ai-wpoos' ),
				'description'    => __( 'Allows AI to import Elementor template kits from the Media Library. Requires Elementor to be active.', 'mcp-ai-wpoos' ),
				'default'        => false,
			);

			return $fields;
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			$subtab_groups = array(
				'tools_manager'       => array(
					'id'     => 'tools_manager',
					'label'  => __( 'Tools Manager', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-list-view',
					'fields' => array(), // Custom rendering, no form fields.
				),
				'features'            => array(
					'id'     => 'features',
					'label'  => __( 'Pro Features', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array( 'enable_quiz_system', 'enable_media_toolkit', 'enable_document_generation_toolkit', 'enable_project_management', 'enable_places_management', 'enable_ai_cpt_management', 'enable_eca_management', 'enable_health_wellness_management', 'enable_cloudways_toolkit', 'enable_ecommerce_toolkit', 'enable_social_media_toolkit', 'enable_analytics_toolkit', 'enable_multilingual_toolkit', 'enable_video_production_toolkit', 'enable_financial_planner_toolkit', 'enable_calendar_booking_toolkit', 'enable_dj_management_toolkit', 'enable_image_production_toolkit', 'enable_ai_tool_builder_toolkit', 'enable_architect_agent_toolkit', 'enable_architectural_design_toolkit', 'enable_site_creator_toolkit', 'enable_regulatory_registration_toolkit', 'enable_fantasy_football' ),
				),
				'configuration'       => array(
					'id'     => 'configuration',
					'label'  => __( 'Configuration', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-settings',
					'fields' => array( 'web_search_provider', 'enable_varnish_purge', 'group_email_capability', 'group_email_max_recipients', 'search_gmail_capability', 'search_gmail_max_results', 'search_drive_capability', 'search_drive_max_results' ),
				),
				'document_generation' => array(
					'id'     => 'document_generation',
					'label'  => __( 'Document Generation', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-media-spreadsheet',
					'fields' => array( 'excel_default_version', 'excel_enable_lambda', 'excel_max_complexity', 'excel_include_comments', 'excel_optimization_level' ),
				),
				'connections'         => array(
					'id'     => 'connections',
					'label'  => __( 'Connections', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-links',
					'fields' => array(), // Custom rendering via Integrations section.
				),
				'external_tools'      => array(
					'id'     => 'external_tools',
					'label'  => __( 'GitHub OAuth', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-site-alt3',
					'fields' => array(
						'github_client_id',
						'github_client_secret',
					),
				),
				'plugins'             => array(
					'id'     => 'plugins',
					'label'  => __( 'Plugins', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-plugins',
					'fields' => array(
						'enable_jetengine_cct',
						'enable_jetengine_tools',
						'enable_woocommerce_tools',
						'enable_elementor_widgets',
						'enable_sitekit_integration',
					),
				),
				'media'               => array(
					'id'     => 'media',
					'label'  => __( 'AI Media Library', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-format-image',
					'fields' => array( 'enable_ai_media_library', 'ai_media_generate_alt_text', 'ai_media_generate_caption', 'ai_media_overwrite_existing' ),
				),
				'comments'            => array(
					'id'     => 'comments',
					'label'  => __( 'AI Comments', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-comments',
					'fields' => array( 'enable_ai_comments_moderation', 'ai_comments_sensitivity', 'ai_comments_min_confidence', 'ai_comments_auto_hold_low_confidence' ),
				),
			);

			// Note: Site Creator settings have been moved to their own separate admin page.
			// See: addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php.

			return $subtab_groups;
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check for UI state.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

			// Default to 'tools_manager' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'tools_manager';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Special handling for tools_manager subtab.
			if ( 'tools_manager' === $active_subtab ) {
				$this->render_tools_manager();
				return;
			}

			// The 'connections' subtab is handled by the Integrations section itself,.
			// which will render when the subtab is active. No special rendering needed here.

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}

			// Render additional content based on active sub-tab.
			$this->render_subtab_footer( $active_subtab );
		}

		/**
		 * Render footer content for specific sub-tabs.
		 *
		 * @param string $subtab Active sub-tab ID.
		 */
		private function render_subtab_footer( $subtab ) {
			switch ( $subtab ) {
				case 'features':
					$this->render_features_footer();
					break;
				case 'external_tools':
					$this->render_external_tools_footer();
					break;
				case 'plugins':
					$this->render_plugins_footer();
					break;
				case 'media':
					$this->render_media_footer();
					break;
				case 'comments':
					$this->render_comments_footer();
					break;
				case 'site_creator':
					$this->render_site_creator_footer();
					break;
			}
		}

		/**
		 * Get estimated memory requirements (in MB) for each pro toolkit.
		 *
		 * @return array Associative array of toolkit_key => memory_in_mb.
		 */
		private function get_toolkit_memory_requirements() {
			return array(
				'enable_quiz_system'                     => 32,   // 7 tools, database operations.
				'enable_media_toolkit'                   => 48,   // Template management, image processing.
				'enable_document_generation_toolkit'     => 96,   // 3 tools, Node.js, PDF/Word/Excel generation.
				'enable_project_management'              => 64,   // 13 tools, complex data structures.
				'enable_places_management'               => 56,   // 6+ tools, Google Maps API, geocoding.
				'enable_ai_cpt_management'               => 24,   // Metabox integration, lightweight.
				'enable_eca_management'                  => 40,   // 5+ tools, iSAMS integration.
				'enable_health_wellness_management'      => 128,  // 30+ tools, secure health data storage.
				'enable_cloudways_toolkit'               => 192,  // 58+ tools, extensive server management.
				'enable_ecommerce_toolkit'               => 80,   // 20 tools, WooCommerce integration.
				'enable_social_media_toolkit'            => 64,   // 15 tools, multi-platform APIs.
				'enable_analytics_toolkit'               => 96,   // 12 tools, data warehouse integrations.
				'enable_multilingual_toolkit'            => 72,   // 10 tools, translation memory.
				'enable_video_production_toolkit'        => 256,  // 12 tools, FFmpeg, video processing.
				'enable_financial_planner_toolkit'       => 80,   // 24 tools, Plaid API integration.
				'enable_calendar_booking_toolkit'        => 64,   // 12-15 tools, calendar sync.
				'enable_dj_management_toolkit'           => 72,   // 15-18 tools, music APIs, contracts.
				'enable_image_production_toolkit'        => 192,  // 12-15 tools, AI generation, GPU processing.
				'enable_ai_tool_builder_toolkit'         => 48,   // 10 meta-tools, code generation.
				'enable_architect_agent_toolkit'         => 16,   // 4 self-editing tools (file, shell, git, search).
				'enable_architectural_design_toolkit'    => 160,  // 16 tools, 3D modeling, rendering.
				'enable_site_creator_toolkit'            => 104,  // 26 tools, page/section/widget builders, AI automation.
				'enable_regulatory_registration_toolkit' => 80,   // 15 tools, multi-country registration management.
				'enable_fantasy_football'                => 40,   // 9 tools, Yahoo Fantasy Sports API integration.
			);
		}

		/**
		 * Render features footer with toolkit memory usage counter.
		 */
		private function render_features_footer() {
			// Count currently enabled pro toolkits and calculate memory usage.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$toolkit_memory_requirements = $this->get_toolkit_memory_requirements();

			$enabled_count   = 0;
			$total_memory_mb = 0;

			foreach ( $toolkit_memory_requirements as $option => $memory_mb ) {
				if ( ! empty( $settings[ $option ] ) ) {
					++$enabled_count;
					$total_memory_mb += $memory_mb;
				}
			}

			// Memory thresholds for status indicators (no hard limit).
			$counter_class  = '';
			$counter_status = '';

			if ( $total_memory_mb >= 800 ) {
				$counter_class  = 'toolkit-limit-maximum';
				$counter_status = __( 'High Usage', 'mcp-ai-wpoos' );
			} elseif ( $total_memory_mb >= 500 ) {
				$counter_class  = 'toolkit-limit-warning';
				$counter_status = __( 'Moderate Usage', 'mcp-ai-wpoos' );
			} else {
				$counter_class  = 'toolkit-limit-good';
				$counter_status = __( 'Low Usage', 'mcp-ai-wpoos' );
			}

			?>
			<tr class="toolkit-limit-info">
				<th scope="row"></th>
				<td>
					<div class="toolkit-limit-counter-container" style="padding: 15px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; margin-top: 20px;">
						<h3 style="margin-top: 0;">
							<span class="dashicons dashicons-chart-bar"></span>
							<?php esc_html_e( 'Pro Toolkit Memory Usage', 'mcp-ai-wpoos' ); ?>
						</h3>
						<p style="font-size: 18px; margin: 10px 0;">
							<strong class="toolkit-limit-counter <?php echo esc_attr( $counter_class ); ?>">
								<span class="current-count"><?php echo esc_html( $total_memory_mb ); ?></span> 
								<?php
								esc_html_e( 'MB estimated memory usage', 'mcp-ai-wpoos' );
								?>
								<span style="font-size: 14px; color: #646970; margin-left: 10px;">
									<?php
									printf(
										/* translators: %d: Number of enabled toolkits */
										esc_html__( '(%d toolkits enabled)', 'mcp-ai-wpoos' ),
										esc_html( $enabled_count )
									);
									?>
								</span>
							</strong>
							<span class="toolkit-status-badge" style="margin-left: 10px; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
								<?php echo esc_html( $counter_status ); ?>
							</span>
						</p>
						<p class="description">
							<?php
							esc_html_e( 'This shows the estimated memory usage for all enabled pro toolkits. Memory requirements vary by toolkit complexity and tool count. You can enable as many toolkits as needed for your use case.', 'mcp-ai-wpoos' );
							?>
						</p>
					</div>

					<?php
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for admin section layout and styling on this admin page only
					?>
					<style>
						.toolkit-limit-good { color: #00a32a; }
						.toolkit-limit-warning { color: #dba617; }
						.toolkit-limit-maximum { color: #b32d2e; }
						.toolkit-status-badge { background: #f0f0f1; color: #2c3338; display: inline-block; }
						.toolkit-limit-good + .toolkit-status-badge { background: #d4edda; color: #155724; }
						.toolkit-limit-warning + .toolkit-status-badge { background: #fff3cd; color: #856404; }
						.toolkit-limit-maximum + .toolkit-status-badge { background: #f8d7da; color: #721c24; }
					</style>
					<?php
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
					?>
					<script>
					jQuery(document).ready(function($) {
						var toolkitMemory = <?php echo ( wp_json_encode( $this->get_toolkit_memory_requirements() ) ? wp_json_encode( $this->get_toolkit_memory_requirements() ) : '{}' ); ?>;
						
						// Fallback to empty object if encoding failed.
						if (!toolkitMemory || typeof toolkitMemory !== 'object') {
							toolkitMemory = {};
						}
						
						var toolkitCheckboxes = $(
							'input[name="wp_mcp_ai_settings[enable_quiz_system]"],' +
							'input[name="wp_mcp_ai_settings[enable_media_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_document_generation_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_project_management]"],' +
							'input[name="wp_mcp_ai_settings[enable_places_management]"],' +
							'input[name="wp_mcp_ai_settings[enable_ai_cpt_management]"],' +
							'input[name="wp_mcp_ai_settings[enable_eca_management]"],' +
							'input[name="wp_mcp_ai_settings[enable_health_wellness_management]"],' +
							'input[name="wp_mcp_ai_settings[enable_cloudways_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_ecommerce_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_social_media_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_analytics_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_multilingual_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_video_production_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_financial_planner_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_calendar_booking_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_dj_management_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_image_production_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_ai_tool_builder_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_architect_agent_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_architectural_design_toolkit]"],' +
							'input[name="wp_mcp_ai_settings[enable_fantasy_football]"]'
						);

						function updateToolkitMemory() {
							var totalMemory = 0;
							var counter = $('.toolkit-limit-counter .current-count');
							var statusBadge = $('.toolkit-status-badge');

							// Calculate total memory from checked toolkits
							toolkitCheckboxes.filter(':checked').each(function() {
								var inputName = $(this).attr('name');
								// Extract option name from "wp_mcp_ai_settings[option_name]"
								var match = inputName.match(/\[([^\]]+)\]/);
								if (match && match[1] && toolkitMemory[match[1]]) {
									totalMemory += toolkitMemory[match[1]];
								}
							});

							// Display memory in MB
							counter.text(totalMemory);

							// Update colors and status based on memory thresholds
							$('.toolkit-limit-counter').removeClass('toolkit-limit-good toolkit-limit-warning toolkit-limit-maximum');
							if (totalMemory >= 800) {
								$('.toolkit-limit-counter').addClass('toolkit-limit-maximum');
								statusBadge.text(<?php echo wp_json_encode( __( 'High Usage', 'mcp-ai-wpoos' ) ); ?>);
							} else if (totalMemory >= 500) {
								$('.toolkit-limit-counter').addClass('toolkit-limit-warning');
								statusBadge.text(<?php echo wp_json_encode( __( 'Moderate Usage', 'mcp-ai-wpoos' ) ); ?>);
							} else {
								$('.toolkit-limit-counter').addClass('toolkit-limit-good');
								statusBadge.text(<?php echo wp_json_encode( __( 'Low Usage', 'mcp-ai-wpoos' ) ); ?>);
							}
						}

						// Run on page load and checkbox change
						toolkitCheckboxes.on('change', updateToolkitMemory);
						updateToolkitMemory();
					});
					</script>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render GitHub OAuth footer content.
		 */
		private function render_external_tools_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$github_connected  = ! empty( $settings['github_access_token'] );
			$github_username   = isset( $settings['github_username'] ) ? $settings['github_username'] : '';
			$has_credentials   = ! empty( $settings['github_client_id'] ) && ! empty( $settings['github_client_secret'] );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_github_oauth_start' ),
				'wp_mcp_ai_github_oauth_start'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $github_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to GitHub', 'mcp-ai-wpoos' ); ?></strong>
								<?php if ( $github_username ) : ?>
									<?php
									printf(
										/* translators: %s: GitHub username */
										esc_html__( 'as %s', 'mcp-ai-wpoos' ),
										'<code>' . esc_html( $github_username ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect GitHub Account', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your GitHub account is connected. You can now use GitHub integration tools to manage repositories, create Codespaces, and develop custom tools.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'GitHub Not Connected', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect GitHub Account', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your GitHub account. You will be redirected to GitHub to grant permissions.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Required Permissions:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<?php
							// Get scopes from OAuth handler constant.
							$scopes             = WP_MCP_AI_Github_OAuth_Handler::GITHUB_OAUTH_SCOPES;
							$scope_descriptions = array(
								'repo'      => __( 'Full control of private repositories', 'mcp-ai-wpoos' ),
								'user'      => __( 'Read user profile data', 'mcp-ai-wpoos' ),
								'codespace' => __( 'Manage GitHub Codespaces', 'mcp-ai-wpoos' ),
							);
							foreach ( explode( ',', $scopes ) as $scope ) {
								$scope = trim( $scope );
								if ( isset( $scope_descriptions[ $scope ] ) ) {
									echo '<li><code>' . esc_html( $scope ) . '</code>: ' . esc_html( $scope_descriptions[ $scope ] ) . '</li>';
								}
							}
							?>
						</ul>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'GitHub OAuth Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'To connect your GitHub account, first configure your GitHub OAuth Client ID and Client Secret in the fields above, then save your settings.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ol style="margin-left: 20px;">
							<li>
								<?php
								printf(
									/* translators: %s: URL to GitHub Developer Settings */
									wp_kses_post( __( 'Go to <a href="%s" target="_blank">GitHub Developer Settings</a>', 'mcp-ai-wpoos' ) ),
									esc_url( 'https://github.com/settings/developers' )
								);
								?>
							</li>
							<li><?php esc_html_e( 'Click "New OAuth App"', 'mcp-ai-wpoos' ); ?></li>
							<li>
								<?php
								printf(
									/* translators: %s: Callback URL */
									esc_html__( 'Set Authorization callback URL to: %s', 'mcp-ai-wpoos' ),
									'<br><code>' . esc_html( admin_url( 'admin-post.php?action=wp_mcp_ai_github_oauth_callback' ) ) . '</code>'
								);
								?>
							</li>
							<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields above', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Click the "Connect GitHub Account" button that will appear', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'About GitHub OAuth:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'GitHub OAuth integration allows AI assistants to interact with your GitHub repositories, create Codespaces, and develop custom tools. Configure your OAuth credentials to enable GitHub integration.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'API credentials are stored securely in your WordPress database. Only users with manage_options capability can view or modify these settings. Never share API keys publicly or commit them to version control.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Plugins Integration footer content.
		 */
		private function render_plugins_footer() {
			$jetengine_active   = class_exists( 'Jet_Engine' );
			$woocommerce_active = class_exists( 'WooCommerce' );
			$elementor_active   = did_action( 'elementor/loaded' );
			$sitekit_active     = class_exists( 'Google\\Site_Kit\\Plugin' );
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Plugin Integration Status:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li>
							<strong><?php esc_html_e( 'JetEngine:', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $jetengine_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'mcp-ai-wpoos' ); ?></span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'WooCommerce:', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $woocommerce_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'mcp-ai-wpoos' ); ?></span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Elementor:', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $elementor_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'mcp-ai-wpoos' ); ?></span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Google Site Kit:', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $sitekit_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'mcp-ai-wpoos' ); ?></span>
								— <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=google+site+kit&tab=search' ) ); ?>"><?php esc_html_e( 'Install Site Kit', 'mcp-ai-wpoos' ); ?></a>
							<?php endif; ?>
						</li>
					</ul>
					<?php if ( $sitekit_active ) : ?>
						<p class="description" style="margin-top: 15px;">
							<strong><?php esc_html_e( 'Google Site Kit provides access to:', 'mcp-ai-wpoos' ); ?></strong>
							<ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
								<li><?php esc_html_e( 'Google Analytics — Traffic, sessions, bounce rate, user demographics', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'Google Search Console — Keywords, rankings, impressions, clicks, CTR', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'PageSpeed Insights — Performance scores, Core Web Vitals, optimization tips', 'mcp-ai-wpoos' ); ?></li>
								<li><?php esc_html_e( 'AdSense — Earnings, RPM, impressions, monetization data', 'mcp-ai-wpoos' ); ?></li>
							</ul>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: link to Site Kit setup */
									__(
										'Site Kit is configured and ready! Make sure you\'ve connected your Google account in <a href="%s">Site Kit settings</a>.',
										'mcp-ai-wpoos'
									),
									admin_url( 'admin.php?page=googlesitekit-dashboard' )
								)
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Tools and features for inactive plugins will be automatically disabled. Install and activate the corresponding plugins to enable their AI integrations.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render AI Media Library footer content.
		 */
		private function render_media_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires a vision-capable AI provider (OpenAI GPT-4o or Gemini) to be configured in the Providers tab. Image analysis will use the default provider specified in General Settings.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Each image upload will consume AI tokens. Consider the API costs when enabling this feature for high-volume sites.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render AI Comments Moderation footer content.
		 */
		private function render_comments_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How it works:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'AI analyzes comment text, author information, and context', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Detects spam indicators: promotional content, suspicious links, generic comments', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Detects toxic content: hate speech, harassment, threats, offensive language', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Provides a recommendation: approve, hold for moderation, or mark as spam', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Comments from logged-in moderators are never automatically flagged', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'AI analysis is stored as comment metadata for review by moderators', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires an AI provider (OpenAI or Gemini) to be configured. Each comment will consume a small amount of AI tokens.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Site Creator footer content.
		 */
		private function render_site_creator_footer() {
			$is_base_version = wp_mcp_ai_is_base_version();
			?>
			<?php if ( $is_base_version ) : ?>
				<tr>
					<th scope="row"></th>
					<td>
						<div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; margin: 10px 0;">
							<p style="margin: 0 0 10px 0; font-size: 14px;">
								<strong><?php esc_html_e( 'Get NV oOS Pro for Premium Features', 'mcp-ai-wpoos' ); ?></strong>
							</p>
							<p style="margin: 0 0 10px 0;">
								<?php
								echo wp_kses_post(
									__(
										'Enable AI assistants to automatically install themes, plugins, update options, and create content. More powerful features available in the Pro addon.',
										'mcp-ai-wpoos'
									)
								);
								?>
							</p>
							<p style="margin: 0;">
								<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary" style="margin-right: 10px;">
									<?php esc_html_e( 'Get NV oOS Pro', 'mcp-ai-wpoos' ); ?>
								</a>
								<a href="https://link.nvdigital.solutions/wpoos-pro-info" target="_blank" class="button">
									<?php esc_html_e( 'Learn More About Pro Tools', 'mcp-ai-wpoos' ); ?>
								</a>
							</p>
						</div>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"></th>
					<td>
						<p class="description">
							<strong><?php esc_html_e( 'Security Note:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							echo wp_kses_post(
								__(
									'Site creator tools require administrative capabilities (manage_options, install_plugins, install_themes). Only users with these capabilities can execute site creator operations. All plugins and themes are installed exclusively from the official WordPress.org repository.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Performance Consideration:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							echo wp_kses_post(
								__(
									'Site creation operations (especially plugin/theme installation) can take several minutes to complete and may temporarily impact site performance. These operations are marked as long-running and should be executed with appropriate timeouts.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
			<?php

			// Render Elementor Template Kit Import section.
			$this->render_elementor_kit_import_section();
		}

		/**
		 * Check if Elementor is active.
		 *
		 * @return bool
		 */
		protected function is_elementor_active() {
			return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin', false );
		}

		/**
		 * Render Elementor Template Kit Import section.
		 */
		protected function render_elementor_kit_import_section() {
			$is_elementor_active = $this->is_elementor_active();

			// Check for import results.
			$import_result = get_transient( 'wp_mcp_ai_elementor_kit_import_result' );
			if ( $import_result ) {
				delete_transient( 'wp_mcp_ai_elementor_kit_import_result' );
			}
			?>
			<tr>
				<th scope="row" colspan="2">
					<h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ccd0d4;">
						<span class="dashicons dashicons-layout" style="margin-right: 5px;"></span>
						<?php esc_html_e( 'Import Elementor Template Kit', 'mcp-ai-wpoos' ); ?>
					</h3>
				</th>
			</tr>

			<?php if ( ! $is_elementor_active ) : ?>
				<tr>
					<th scope="row"></th>
					<td>
						<div class="notice notice-warning inline" style="margin: 0;">
							<p>
								<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
								<?php esc_html_e( 'Elementor must be installed and activated to use this feature.', 'mcp-ai-wpoos' ); ?>
							</p>
						</div>
					</td>
				</tr>
			<?php else : ?>

				<?php if ( $import_result ) : ?>
					<tr>
						<th scope="row"></th>
						<td>
							<?php $this->render_elementor_import_result( $import_result ); ?>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"></th>
					<td>
						<p class="description" style="margin-bottom: 15px;">
							<?php esc_html_e( 'Import an Elementor template kit ZIP file from your Media Library to quickly create pages with pre-designed layouts.', 'mcp-ai-wpoos' ); ?>
						</p>

						<form method="post" id="wp-mcp-ai-elementor-kit-form">
							<?php wp_nonce_field( 'wp_mcp_ai_elementor_kit_import', 'wp_mcp_ai_elementor_kit_nonce' ); ?>
							<input type="hidden" name="wp_mcp_ai_elementor_kit_import" value="1">

							<table class="form-table" role="presentation" style="margin: 0;">
								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-kit-attachment">
											<?php esc_html_e( 'Template Kit ZIP', 'mcp-ai-wpoos' ); ?>
										</label>
									</th>
									<td>
										<div style="display: flex; align-items: center; gap: 10px;">
											<input type="hidden" name="attachment_id" id="wp-mcp-ai-kit-attachment-id" value="">
											<input type="text" id="wp-mcp-ai-kit-attachment" class="regular-text" readonly placeholder="<?php esc_attr_e( 'No file selected', 'mcp-ai-wpoos' ); ?>">
											<button type="button" class="button" id="wp-mcp-ai-select-kit">
												<?php esc_html_e( 'Select File', 'mcp-ai-wpoos' ); ?>
											</button>
										</div>
										<p class="description">
											<?php esc_html_e( 'Select a ZIP file containing an Elementor template kit from your Media Library.', 'mcp-ai-wpoos' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-max-pages">
											<?php esc_html_e( 'Max Pages', 'mcp-ai-wpoos' ); ?>
										</label>
									</th>
									<td>
										<select name="max_pages" id="wp-mcp-ai-max-pages">
											<option value="1">1</option>
											<option value="2">2</option>
											<option value="3">3</option>
											<option value="4">4</option>
											<option value="5" selected>5</option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Maximum number of pages to create from the template kit.', 'mcp-ai-wpoos' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="wp-mcp-ai-page-status">
											<?php esc_html_e( 'Page Status', 'mcp-ai-wpoos' ); ?>
										</label>
									</th>
									<td>
										<select name="page_status" id="wp-mcp-ai-page-status">
											<option value="draft" selected><?php esc_html_e( 'Draft', 'mcp-ai-wpoos' ); ?></option>
											<option value="publish"><?php esc_html_e( 'Published', 'mcp-ai-wpoos' ); ?></option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Status for created pages.', 'mcp-ai-wpoos' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row"><?php esc_html_e( 'Options', 'mcp-ai-wpoos' ); ?></th>
									<td>
										<label style="display: block; margin-bottom: 8px;">
											<input type="checkbox" name="overwrite_existing" value="1">
											<?php esc_html_e( 'Overwrite existing pages with the same title', 'mcp-ai-wpoos' ); ?>
										</label>
										<label style="display: block;">
											<input type="checkbox" name="set_front_page" value="1">
											<?php esc_html_e( 'Set Home page as static front page', 'mcp-ai-wpoos' ); ?>
										</label>
									</td>
								</tr>

								<tr>
									<th scope="row"></th>
									<td>
										<div style="display: flex; gap: 10px; margin-top: 10px;">
											<button type="submit" name="action_type" value="test" class="button button-secondary">
												<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
												<?php esc_html_e( 'Test Import', 'mcp-ai-wpoos' ); ?>
											</button>
											<button type="submit" name="action_type" value="import" class="button button-primary">
												<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
												<?php esc_html_e( 'Run Import', 'mcp-ai-wpoos' ); ?>
											</button>
										</div>
										<p class="description" style="margin-top: 10px;">
											<?php esc_html_e( 'Test Import simulates the operation without creating pages. Run Import creates the actual pages.', 'mcp-ai-wpoos' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</form>
					</td>
				</tr>
			<?php endif; ?>

			<?php
			// Add JavaScript for media library selection.
			$this->render_elementor_kit_import_script();
		}

		/**
		 * Render Elementor import result notice.
		 *
		 * @param array $result Import result data.
		 */
		protected function render_elementor_import_result( $result ) {
			if ( ! $result['success'] ) {
				?>
				<div class="notice notice-error inline" style="margin: 0 0 15px;">
					<p>
						<strong><?php esc_html_e( 'Import Failed:', 'mcp-ai-wpoos' ); ?></strong>
						<?php echo esc_html( isset( $result['message'] ) ? $result['message'] : __( 'Unknown error occurred.', 'mcp-ai-wpoos' ) ); ?>
					</p>
				</div>
				<?php
				return;
			}

			$data       = isset( $result['data'] ) ? $result['data'] : array();
			$is_dry_run = ! empty( $data['dry_run'] );
			?>
			<div class="notice notice-success inline" style="margin: 0 0 15px;">
				<p>
					<strong>
						<?php
						if ( $is_dry_run ) {
							esc_html_e( 'Test Import Complete:', 'mcp-ai-wpoos' );
						} else {
							esc_html_e( 'Import Complete:', 'mcp-ai-wpoos' );
						}
						?>
					</strong>
					<?php echo esc_html( isset( $data['summary'] ) ? $data['summary'] : '' ); ?>
				</p>

				<?php if ( ! empty( $data['pages_created'] ) || ! empty( $data['pages_updated'] ) ) : ?>
					<div style="margin-top: 10px;">
						<?php if ( ! empty( $data['pages_created'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Pages Created:', 'mcp-ai-wpoos' ); ?></strong></p>
							<ul style="margin-left: 20px; list-style: disc;">
								<?php foreach ( $data['pages_created'] as $page ) : ?>
									<li>
										<?php echo esc_html( $page['title'] ); ?>
										<?php if ( ! $is_dry_run && ! empty( $page['edit_link'] ) ) : ?>
											- <a href="<?php echo esc_url( $page['edit_link'] ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?></a>
											<?php if ( ! empty( $page['permalink'] ) ) : ?>
												| <a href="<?php echo esc_url( $page['permalink'] ); ?>" target="_blank"><?php esc_html_e( 'View', 'mcp-ai-wpoos' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $data['pages_updated'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Pages Updated:', 'mcp-ai-wpoos' ); ?></strong></p>
							<ul style="margin-left: 20px; list-style: disc;">
								<?php foreach ( $data['pages_updated'] as $page ) : ?>
									<li>
										<?php echo esc_html( $page['title'] ); ?>
										<?php if ( ! $is_dry_run && ! empty( $page['edit_link'] ) ) : ?>
											- <a href="<?php echo esc_url( $page['edit_link'] ); ?>" target="_blank"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?></a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['pages_skipped'] ) ) : ?>
					<div style="margin-top: 10px;">
						<p><strong><?php esc_html_e( 'Pages Skipped:', 'mcp-ai-wpoos' ); ?></strong></p>
						<ul style="margin-left: 20px; list-style: disc;">
							<?php foreach ( $data['pages_skipped'] as $page ) : ?>
								<li>
									<?php echo esc_html( $page['title'] ); ?>
									<?php if ( ! empty( $page['reason'] ) ) : ?>
										- <em><?php echo esc_html( $page['reason'] ); ?></em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['errors'] ) ) : ?>
					<div style="margin-top: 10px;">
						<p><strong style="color: #d63638;"><?php esc_html_e( 'Errors:', 'mcp-ai-wpoos' ); ?></strong></p>
						<ul style="margin-left: 20px; list-style: disc; color: #d63638;">
							<?php foreach ( $data['errors'] as $error ) : ?>
								<li>
									<?php
									if ( is_array( $error ) ) {
										echo esc_html( $error['template'] . ': ' . $error['message'] );
									} else {
										echo esc_html( $error );
									}
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $data['front_page'] ) ) : ?>
					<p style="margin-top: 10px;">
						<span class="dashicons dashicons-admin-home" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Front page has been set.', 'mcp-ai-wpoos' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render JavaScript for media library selection.
		 */
		protected function render_elementor_kit_import_script() {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				var mediaFrame;

				$('#wp-mcp-ai-select-kit').on('click', function(e) {
					e.preventDefault();

					if (mediaFrame) {
						mediaFrame.open();
						return;
					}

					mediaFrame = wp.media({
						title: '<?php echo esc_js( __( 'Select Template Kit ZIP', 'mcp-ai-wpoos' ) ); ?>',
						button: {
							text: '<?php echo esc_js( __( 'Use This File', 'mcp-ai-wpoos' ) ); ?>'
						},
						library: {
							type: 'application/zip'
						},
						multiple: false
					});

					mediaFrame.on('select', function() {
						var attachment = mediaFrame.state().get('selection').first().toJSON();
						$('#wp-mcp-ai-kit-attachment-id').val(attachment.id);
						$('#wp-mcp-ai-kit-attachment').val(attachment.filename || attachment.title);
					});

					mediaFrame.open();
				});
			});
			</script>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Tools settings sub-tabs', 'mcp-ai-wpoos' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'tools',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>"
								class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the Tools Manager view.
		 *
		 * Displays all registered tools in a categorized table with enable/disable functionality.
		 */
		private function render_tools_manager() {
			$registry      = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools     = $registry->get_tools();
			$tool_groups   = $registry->get_tool_group_map();
			$group_labels  = $registry->get_tool_group_labels();
			$active_subtab = $this->get_active_subtab();

			// Group tools by category.
			$categorized_tools = array(
				'wordpress-core'    => array(),
				'wordpress-plugins' => array(),
				'external-tools'    => array(),
				'other'             => array(),
			);

			foreach ( $all_tools as $tool ) {
				$slug  = $tool->get_slug();
				$group = isset( $tool_groups[ $slug ] ) ? $tool_groups[ $slug ] : 'other';

				if ( ! isset( $categorized_tools[ $group ] ) ) {
					$categorized_tools[ $group ] = array();
				}

				$categorized_tools[ $group ][] = $tool;
			}

			// Get search/filter parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$search = isset( $_GET['tool_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tool_search'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$filter_group = isset( $_GET['tool_group'] ) ? sanitize_key( $_GET['tool_group'] ) : '';

			?>
			<div class="wp-mcp-ai-tools-manager">
				<!-- Header Section -->
				<div style="margin-bottom: 20px;">
					<h3><?php esc_html_e( 'Tools Manager', 'mcp-ai-wpoos' ); ?></h3>
					<p class="description">
						<?php
						printf(
							/* translators: %d: Total number of registered tools */
							esc_html__( 'View and manage all %d registered AI tools. Tools can be filtered by category and searched by name or description.', 'mcp-ai-wpoos' ),
							count( $all_tools )
						);
						?>
					</p>
				</div>

				<!-- Search and Filter Bar -->
				<?php
				$has_active_filters = ! empty( $search ) || ! empty( $filter_group );
				$filter_bar_class   = 'wp-mcp-ai-tools-filter-bar';
				if ( $has_active_filters ) {
					$filter_bar_class .= ' has-active-filters';
				}
				?>
				<div class="<?php echo esc_attr( $filter_bar_class ); ?>" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<div class="wp-mcp-ai-tools-filter-form" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; align-items: start;">
						<div class="wp-mcp-ai-filter-group" style="display: flex; flex-direction: column; gap: 8px;">
							<label for="tool_search" style="font-weight: 600;">
								<?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?>
								<?php if ( ! empty( $search ) ) : ?>
									<span class="wp-mcp-ai-filter-active-badge">
										<span class="dashicons dashicons-filter" style="font-size: 11px; width: 11px; height: 11px;"></span>
										<?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</label>
							<input type="search"
									id="tool_search"
									name="tool_search"
									value="<?php echo esc_attr( $search ); ?>"
									placeholder="<?php esc_attr_e( 'Search tools...', 'mcp-ai-wpoos' ); ?>"
									style="width: 100%;">
						</div>

						<div class="wp-mcp-ai-filter-group" style="display: flex; flex-direction: column; gap: 8px;">
							<label for="tool_group" style="font-weight: 600;">
								<?php esc_html_e( 'Category:', 'mcp-ai-wpoos' ); ?>
								<?php if ( ! empty( $filter_group ) ) : ?>
									<span class="wp-mcp-ai-filter-active-badge">
										<span class="dashicons dashicons-filter" style="font-size: 11px; width: 11px; height: 11px;"></span>
										<?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</label>
							<select id="tool_group" name="tool_group" style="width: 100%;">
								<option value=""><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
								<?php foreach ( $group_labels as $group_key => $group_label ) : ?>
									<option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $filter_group, $group_key ); ?>>
										<?php echo esc_html( $group_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="wp-mcp-ai-filter-actions" style="grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end;">
							<button type="button" id="wp-mcp-ai-filter-tools" class="button">
								<?php esc_html_e( 'Filter', 'mcp-ai-wpoos' ); ?>
							</button>

							<?php if ( ! empty( $search ) || ! empty( $filter_group ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=tools&subtab=tools_manager' ) ); ?>" class="button">
									<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
					<?php
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Small inline script for admin section functionality on this admin page only
					?>
					<script>
					(function($) {
						$('#wp-mcp-ai-filter-tools').on('click', function() {
							const $button = $(this);

							// Add loading state
							$button.addClass('is-loading').prop('disabled', true);

							const search = $('#tool_search').val();
							const group = $('#tool_group').val();
							const url = new URL(window.location.href);

							// Update URL parameters.
							url.searchParams.set('page', '<?php echo esc_js( WP_MCP_AI_Settings_Dashboard::PAGE_SLUG ); ?>');
							url.searchParams.set('tab', 'tools');
							url.searchParams.set('subtab', '<?php echo esc_js( $active_subtab ); ?>');

							if (search) {
								url.searchParams.set('tool_search', search);
							} else {
								url.searchParams.delete('tool_search');
							}

							if (group) {
								url.searchParams.set('tool_group', group);
							} else {
								url.searchParams.delete('tool_group');
							}

							// Navigate to filtered URL.
							window.location.href = url.toString();
						});

						// Allow Enter key to trigger filter.
						$('#tool_search, #tool_group').on('keypress', function(e) {
							if (e.which === 13) {
								e.preventDefault();
								$('#wp-mcp-ai-filter-tools').click();
							}
						});
					})(jQuery);
					</script>
				</div>

				<!-- Tools by Category -->
				<?php
				foreach ( $categorized_tools as $category => $tools ) :
					// Skip if category is empty or filtered out.
					if ( empty( $tools ) || ( ! empty( $filter_group ) && $filter_group !== $category ) ) {
						continue;
					}

					// Apply search filter.
					if ( ! empty( $search ) ) {
						$tools = array_filter(
							$tools,
							function ( $tool ) use ( $search ) {
								$slug        = $tool->get_slug();
								$description = $tool->get_description();
								$name        = $this->get_tool_display_name( $slug );
								$search_term = strtolower( $search );

								return false !== stripos( $slug, $search_term ) ||
										false !== stripos( $description, $search_term ) ||
										false !== stripos( $name, $search_term );
							}
						);

						if ( empty( $tools ) ) {
							continue;
						}
					}

					$category_label = isset( $group_labels[ $category ] ) ? $group_labels[ $category ] : __( 'Other', 'mcp-ai-wpoos' );
					?>

					<div class="wp-mcp-ai-tools-category" style="margin-bottom: 30px;">
						<h4 style="margin-bottom: 10px; padding: 10px; background: #f0f0f0; border-left: 4px solid #0073aa;">
							<?php echo esc_html( $category_label ); ?>
							<span class="badge" style="background: #0073aa; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">
								<?php echo esc_html( count( $tools ) ); ?>
							</span>
						</h4>

						<table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
							<thead>
								<tr>
									<th style="width: 20%;"><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
									<th style="width: 15%;"><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
									<th style="width: 55%;"><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
									<th style="width: 10%;"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $tools as $tool ) :
									$slug        = $tool->get_slug();
									$description = $tool->get_description();
									$name        = $this->get_tool_display_name( $slug, $tool );

									// Check if tool has dependencies.
									$dependencies = $this->check_tool_dependencies( $slug );
									$is_available = $dependencies['available'];

									// Check if tool is enabled.
									$is_enabled   = $registry->is_tool_enabled( $slug );
									$status_text  = $is_enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' );
									$status_color = $is_enabled ? '#46b450' : '#999';

									// If tool is unavailable due to dependencies, override status.
									if ( ! $is_available ) {
										$status_text  = __( 'Unavailable', 'mcp-ai-wpoos' );
										$status_color = '#dc3232';
									}
									?>
									<tr data-tool-slug="<?php echo esc_attr( $slug ); ?>">
										<td>
											<strong><?php echo esc_html( $name ); ?></strong>
										</td>
										<td>
											<code style="font-size: 11px;"><?php echo esc_html( $slug ); ?></code>
										</td>
										<td>
											<?php echo esc_html( $description ); ?>
											<?php if ( ! empty( $dependencies['missing'] ) ) : ?>
												<div style="margin-top: 5px; font-size: 12px; color: #dc3232;">
													<strong><?php esc_html_e( 'Missing:', 'mcp-ai-wpoos' ); ?></strong>
													<?php echo esc_html( implode( ', ', $dependencies['missing'] ) ); ?>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $is_available ) : ?>
												<label class="wp-mcp-ai-toggle-switch" style="position: relative; display: inline-block; width: 50px; height: 24px;">
													<input type="checkbox"
															class="wp-mcp-ai-tool-toggle"
															data-tool-slug="<?php echo esc_attr( $slug ); ?>"
															<?php checked( $is_enabled ); ?>
															style="opacity: 0; width: 0; height: 0;">
													<span class="wp-mcp-ai-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; border-radius: 24px; transition: .4s;"></span>
												</label>
											<?php else : ?>
												<span class="dashicons dashicons-warning" style="color: #dc3232;" title="<?php esc_attr_e( 'Tool is unavailable due to missing dependencies', 'mcp-ai-wpoos' ); ?>"></span>
											<?php endif; ?>
										</td>
									</tr>
									<?php
									// Badge row - always show to display status.
									$has_pro_badge    = $this->is_pro_tool( $slug );
									$status_label     = $this->get_tool_status_label( $slug );
									$has_status_label = ! empty( $status_label );
									?>
									<tr data-tool-slug="<?php echo esc_attr( $slug ); ?>-badges" class="wp-mcp-ai-tool-badges-row">
										<td colspan="4" style="padding-top: 0; padding-bottom: 8px; border-top: 0;">
											<!-- Status Badge (always shown) -->
											<span class="wp-mcp-ai-tool-status" style="display: inline-block; margin-right: 6px; padding: 2px 5px; background: <?php echo esc_attr( $status_color ); ?>; color: white; border-radius: 3px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">
												<?php echo esc_html( $status_text ); ?>
											</span>
											<?php if ( $has_pro_badge ) : ?>
												<span class="wp-mcp-ai-pro-badge" style="display: inline-block; margin-right: 6px; padding: 2px 5px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 3px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">
													<?php esc_html_e( 'Pro', 'mcp-ai-wpoos' ); ?>
												</span>
											<?php endif; ?>
											<?php
											if ( $has_status_label ) :
												$label_config = $this->get_status_label_config( $status_label );
												?>
												<span class="wp-mcp-ai-tool-status-label <?php echo esc_attr( $label_config['class'] ); ?>" style="display: inline-block; padding: 2px 5px; background: <?php echo esc_attr( $label_config['color'] ); ?>; color: white; border-radius: 3px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">
													<?php echo esc_html( $label_config['text'] ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

				<?php endforeach; ?>

				<?php if ( ! empty( $search ) && empty( array_filter( $categorized_tools ) ) ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %s: Search term */
								esc_html__( 'No tools found matching "%s". Try a different search term.', 'mcp-ai-wpoos' ),
								esc_html( $search )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<!-- Information Footer -->
				<div style="margin-top: 30px; padding: 15px; background: #f0f6fc; border: 1px solid #c3e6ff; border-radius: 4px;">
					<h4 style="margin-top: 0;">
						<span class="dashicons dashicons-info" style="color: #0073aa;"></span>
						<?php esc_html_e( 'About Tool Categories', 'mcp-ai-wpoos' ); ?>
					</h4>
					<ul style="margin-left: 20px;">
						<li>
							<strong><?php esc_html_e( 'WordPress Core:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'Tools that work with base WordPress installation without any external dependencies.', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'WordPress Plugins:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'Tools that require specific third-party WordPress plugins to be installed and active.', 'mcp-ai-wpoos' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'External Tools:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'Tools that require external API credentials or third-party service integrations.', 'mcp-ai-wpoos' ); ?>
						</li>
					</ul>
					<p style="margin-bottom: 0;">
						<?php
						printf(
							/* translators: %s: Link to tool documentation */
							wp_kses_post( __( 'For detailed information about each tool and its requirements, see the <a href="%s" target="_blank">Tool Reference Documentation</a>.', 'mcp-ai-wpoos' ) ),
							esc_url( 'https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/tool-reference.md' )
						);
						?>
					</p>
				</div>
				<?php $this->render_pro_banner(); ?>
			</div>
			<?php
		}

		/**
		 * Get display name for a tool.
		 *
		 * @param string                   $slug Tool slug.
		 * @param WP_MCP_AI_Tool_Interface $tool Optional. Tool instance to get name from.
		 * @return string Display name.
		 */
		private function get_tool_display_name( $slug, $tool = null ) {
			// If tool instance provided, use its get_name() method.
			if ( $tool && method_exists( $tool, 'get_name' ) ) {
				return $tool->get_name();
			}

			// Fallback: convert slug to title case.
			$name = str_replace( '_', ' ', $slug );
			return ucwords( $name );
		}

		/**
		 * Load tool status labels from docs/tool-status.txt file.
		 *
		 * @return array Associative array of tool slug => status label.
		 */
		private function load_tool_status_labels() {
			static $status_labels = null;

			// Use static cache to avoid reading file multiple times.
			if ( null !== $status_labels ) {
				return $status_labels;
			}

			$status_labels = array();
			$status_file   = WP_MCP_AI_PATH . 'docs/tool-status.txt';

			// Check if file exists.
			if ( ! file_exists( $status_file ) ) {
				return $status_labels;
			}

			// Read file content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for configuration.
			$content = file_get_contents( $status_file );
			if ( false === $content ) {
				return $status_labels;
			}

			// Parse file line by line.
			$lines = explode( "\n", $content );
			foreach ( $lines as $line ) {
				// Trim whitespace.
				$line = trim( $line );

				// Skip empty lines and comments.
				if ( empty( $line ) || '#' === substr( $line, 0, 1 ) ) {
					continue;
				}

				// Parse line format: tool_slug = status_label.
				$parts = explode( '=', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}

				$tool_slug    = trim( $parts[0] );
				$status_label = trim( $parts[1] );

				// Validate status label (only allow alphanumeric, hyphens, underscores).
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $status_label ) ) {
					continue;
				}

				$status_labels[ $tool_slug ] = $status_label;
			}

			return $status_labels;
		}

		/**
		 * Get status label for a tool.
		 *
		 * @param string $slug Tool slug.
		 * @return string|null Status label or null if not set.
		 */
		private function get_tool_status_label( $slug ) {
			$status_labels = $this->load_tool_status_labels();
			return isset( $status_labels[ $slug ] ) ? $status_labels[ $slug ] : null;
		}

		/**
		 * Get CSS class and display text for a status label.
		 *
		 * @param string $status Status label.
		 * @return array Array with 'class', 'text', and 'color' keys.
		 */
		private function get_status_label_config( $status ) {
			$configs = array(
				'stable'       => array(
					'class' => 'wp-mcp-ai-status-stable',
					'text'  => __( 'STA', 'mcp-ai-wpoos' ),
					'color' => '#46b450',
				),
				'dev'          => array(
					'class' => 'wp-mcp-ai-status-dev',
					'text'  => __( 'DEV', 'mcp-ai-wpoos' ),
					'color' => '#f0ad4e',
				),
				'beta'         => array(
					'class' => 'wp-mcp-ai-status-beta',
					'text'  => __( 'BET', 'mcp-ai-wpoos' ),
					'color' => '#5bc0de',
				),
				'bug'          => array(
					'class' => 'wp-mcp-ai-status-bug',
					'text'  => __( 'BUG', 'mcp-ai-wpoos' ),
					'color' => '#dc3545',
				),
				'deprecated'   => array(
					'class' => 'wp-mcp-ai-status-deprecated',
					'text'  => __( 'DEP', 'mcp-ai-wpoos' ),
					'color' => '#6c757d',
				),
				'experimental' => array(
					'class' => 'wp-mcp-ai-status-experimental',
					'text'  => __( 'EXP', 'mcp-ai-wpoos' ),
					'color' => '#9b59b6',
				),
			);

			// Return config if exists, otherwise return default config.
			if ( isset( $configs[ $status ] ) ) {
				return $configs[ $status ];
			}

			// Default config for unknown status labels (first 3 chars, uppercase).
			return array(
				'class' => 'wp-mcp-ai-status-default',
				'text'  => strtoupper( substr( $status, 0, 3 ) ),
				'color' => '#999',
			);
		}

		/**
		 * Check if a tool is a Pro tool.
		 *
		 * Dynamically checks if tool has 'pro' capability flag instead of maintaining a hardcoded list.
		 *
		 * @param string $slug Tool slug.
		 * @return bool True if tool is a Pro tool, false otherwise.
		 */
		private function is_pro_tool( $slug ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $slug );

			if ( ! $tool ) {
				return false;
			}

			// Check if tool implements capability flags interface.
			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) ) {
				return false;
			}

			$flags = $tool->get_capability_flags();

			// Check if 'pro' flag is present.
			return in_array( 'pro', $flags, true );
		}

		/**
		 * Check tool dependencies.
		 *
		 * @param string $slug Tool slug.
		 * @return array Array with 'available' (bool) and 'missing' (array of missing dependencies).
		 */
		private function check_tool_dependencies( $slug ) {
			$missing = array();

			// Allowed check functions for security.
			$allowed_check_functions = array( 'class_exists', 'function_exists', 'interface_exists', 'trait_exists', 'method_exists' );

			// Check for plugin-specific tools.
			$plugin_requirements = array(
				'get_elementor_templates'        => array(
					'plugin' => 'Elementor',
					'check'  => 'class_exists',
					'value'  => '\Elementor\Plugin',
				),
				'import_elementor_template_kit'  => array(
					'plugin' => 'Elementor',
					'check'  => 'class_exists',
					'value'  => '\Elementor\Plugin',
				),
				'get_woo_recent_orders'          => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'get_woo_products'               => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'create_woo_product'             => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'get_jetengine_items'            => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'list_jetengine_rest_routes'     => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'invoke_jetengine_route'         => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'get_jetformbuilder_forms'       => array(
					'plugin' => 'JetFormBuilder',
					'check'  => 'class_exists',
					'value'  => 'Jet_Form_Builder\Plugin',
				),
				'get_jetformbuilder_submissions' => array(
					'plugin' => 'JetFormBuilder',
					'check'  => 'class_exists',
					'value'  => 'Jet_Form_Builder\Plugin',
				),
				'get_rankmath_seo'               => array(
					'plugin' => 'Rank Math SEO',
					'check'  => 'function_exists',
					'value'  => 'rank_math',
				),
				'create_wpcode_snippet'          => array(
					'plugin' => 'WPCode',
					'checks' => array(
						array(
							'check' => 'function_exists',
							'value' => 'wpcode',
						),
						array(
							'check' => 'class_exists',
							'value' => 'WPCode_Snippet',
						),
					),
				),
				'generate_simple_jwt_token'      => array(
					'plugin' => 'Simple JWT Login',
					'check'  => 'class_exists',
					'value'  => '\\SimpleJWTLogin\\Modules\\WordPressData',
				),
			);

			if ( isset( $plugin_requirements[ $slug ] ) ) {
				$requirement = $plugin_requirements[ $slug ];

				// Support both single check and multiple checks.
				if ( isset( $requirement['checks'] ) ) {
					// Multiple checks - all must pass.
					foreach ( $requirement['checks'] as $check_config ) {
						$check_func  = $check_config['check'];
						$check_value = $check_config['value'];

						// Validate check function is in allowlist.
						if ( ! in_array( $check_func, $allowed_check_functions, true ) ) {
							// Invalid check function - mark tool as unavailable.
							$missing[] = $requirement['plugin'];
							break;
						}

						if ( ! $check_func( $check_value ) ) {
							$missing[] = $requirement['plugin'];
							break; // No need to check further once one fails.
						}
					}
				} else {
					// Single check.
					$check_func  = $requirement['check'];
					$check_value = $requirement['value'];

					// Validate check function is in allowlist.
					if ( ! in_array( $check_func, $allowed_check_functions, true ) ) {
						// Invalid check function - mark tool as unavailable.
						$missing[] = $requirement['plugin'];
					} elseif ( ! $check_func( $check_value ) ) {
						// Check failed - mark tool as unavailable.
						$missing[] = $requirement['plugin'];
					}
				}
			}

			return array(
				'available' => empty( $missing ),
				'missing'   => $missing,
			);
		}

		/**
		 * Handle Elementor template kit import form submission.
		 */
		public function handle_elementor_kit_import() {
			// Check if this is a POST request.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Comparing literal value only.
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
				return;
			}

			// Check if this is our form submission.
			if ( ! isset( $_POST['wp_mcp_ai_elementor_kit_import'] ) ) {
				return;
			}

			// Verify nonce first before any other processing.
			if ( ! isset( $_POST['wp_mcp_ai_elementor_kit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_elementor_kit_nonce'] ) ), 'wp_mcp_ai_elementor_kit_import' ) ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Security check failed. Please try again.', 'mcp-ai-wpoos' ),
					),
					60
				);
				return;
			}

			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'You do not have permission to import template kits.', 'mcp-ai-wpoos' ),
					),
					60
				);
				return;
			}

			// Check if Elementor is active.
			if ( ! $this->is_elementor_active() ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Elementor must be active to import template kits.', 'mcp-ai-wpoos' ),
					),
					60
				);
				return;
			}

			// Get and sanitize form values.
			$attachment_id      = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
			$max_pages          = isset( $_POST['max_pages'] ) ? min( 5, max( 1, absint( $_POST['max_pages'] ) ) ) : 5;
			$page_status_raw    = isset( $_POST['page_status'] ) ? sanitize_text_field( wp_unslash( $_POST['page_status'] ) ) : 'draft';
			$page_status        = in_array( $page_status_raw, array( 'draft', 'publish' ), true ) ? $page_status_raw : 'draft';
			$set_front_page     = ! empty( $_POST['set_front_page'] );
			$overwrite_existing = ! empty( $_POST['overwrite_existing'] );
			$action_type        = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
			$dry_run            = 'test' === $action_type;

			if ( ! $attachment_id ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Please select a template kit ZIP file.', 'mcp-ai-wpoos' ),
					),
					60
				);
				return;
			}

			// Load the tool class if not already loaded.
			$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-import-elementor-template-kit.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}

			if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Elementor_Template_Kit' ) ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => __( 'Import tool not available.', 'mcp-ai-wpoos' ),
					),
					60
				);
				return;
			}

			// Execute the import.
			$tool   = new WP_MCP_AI_Tool_Import_Elementor_Template_Kit();
			$result = $tool->execute(
				array(
					'attachment_id'      => $attachment_id,
					'max_pages'          => $max_pages,
					'page_status'        => $page_status,
					'set_front_page'     => $set_front_page,
					'overwrite_existing' => $overwrite_existing,
					'dry_run'            => $dry_run,
				),
				array(
					'user_id' => get_current_user_id(),
				)
			);

			if ( is_wp_error( $result ) ) {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					),
					60
				);
			} else {
				set_transient(
					self::ELEMENTOR_KIT_IMPORT_TRANSIENT,
					array(
						'success' => true,
						'data'    => $result,
					),
					60
				);
			}

			// Redirect to the settings page to avoid form resubmission.
			// Use a known safe URL instead of wp_get_referer() to avoid open redirect vulnerabilities.
			$redirect_url = add_query_arg(
				array(
					'page'                   => 'wp-mcp-ai-dashboard',
					'tab'                    => 'tools',
					'subtab'                 => 'site_creator',
					'elementor_kit_imported' => '1',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		/**
		 * Render Pro addon promotional banner for base version.
		 */
		private function render_pro_banner() {
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				return;
			}
			?>
			<div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; margin: 20px 0;">
				<p style="margin: 0 0 10px 0; font-size: 14px;">
					<strong><?php esc_html_e( 'Get NV oOS Pro for Premium Features', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<p style="margin: 0 0 10px 0;">
					<?php
					echo wp_kses_post(
						__(
							'Enable AI assistants to automatically install themes, plugins, update options, and create content. More powerful features available in the Pro addon.',
							'mcp-ai-wpoos'
						)
					);
					?>
				</p>
				<p style="margin: 0;">
					<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary" style="margin-right: 10px;">
						<?php esc_html_e( 'Get NV oOS Pro', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="https://link.nvdigital.solutions/wpoos-pro-info" target="_blank" class="button">
						<?php esc_html_e( 'Learn More About Pro Tools', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}
}
