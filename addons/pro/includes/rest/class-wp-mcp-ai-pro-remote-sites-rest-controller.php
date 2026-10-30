<?php
/**
 * Remote Sites (Connections) REST controller for the Pro SPA console.
 *
 * Serves the Connections page in the console (nvoos-complete-velocity,
 * Channels → Connections in the Command Centers nav group) with a simple,
 * wizard-style surface over the existing WP_MCP_AI_Pro_Remote_Site_Manager:
 *
 *   GET    /mcp-ai-pro/v1/remote-sites              — list (secrets redacted)
 *   GET    /mcp-ai-pro/v1/remote-sites/types        — connection-type catalogue
 *   POST   /mcp-ai-pro/v1/remote-sites              — create
 *   POST   /mcp-ai-pro/v1/remote-sites/{id}         — update (blank = keep)
 *   DELETE /mcp-ai-pro/v1/remote-sites/{id}         — delete
 *   POST   /mcp-ai-pro/v1/remote-sites/{id}/test    — verify against the provider
 *
 * The base-plugin Gmail connection (wp_mcp_ai_settings) is surfaced as the
 * pseudo-connection `base-gmail` so the console shows every connection a
 * user owns in one list. Its secrets stay in the base settings option and
 * are never editable here — the UI deep-links to wp-admin for that.
 *
 * UX principles applied (industry standard — see docs/features/remote-sites.md):
 *   - Secrets are write-only: the list response never echoes credentials,
 *     only `has_credentials` flags (masked, never-echo-back pattern).
 *   - A connection is only "connected" when it actually has credentials;
 *     testing is a deliberate, separate step before trusting it.
 *   - Updates are partial: blank secret fields keep the stored value.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote Sites REST controller.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_Pro_Remote_Sites_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/remote-sites';

	/**
	 * Pseudo-ID under which the base-plugin Gmail settings connection is served.
	 *
	 * @var string
	 */
	const BASE_GMAIL_ID = 'base-gmail';

	/**
	 * Provider API URLs the wp-admin form auto-fills per type. The console
	 * wizard never asks for these (simple-first: the URL is implied by the
	 * provider), so the controller injects them exactly like the admin does.
	 *
	 * @var array<string,string>
	 */
	const DEFAULT_URLS = array(
		'gmail'              => 'https://gmail.googleapis.com',
		'google_drive'       => 'https://www.googleapis.com/drive/v3',
		'google_calendar'    => 'https://www.googleapis.com/calendar/v3',
		'google_classroom'   => 'https://classroom.googleapis.com/v1',
		'upwork'             => 'https://api.upwork.com/graphql',
		'shipengine'         => 'https://api.shipengine.com',
		'shipstation'        => 'https://ssapi.shipstation.com',
		'telegram'           => 'https://api.telegram.org',
		'slack'              => 'https://slack.com/api',
		'discord'            => 'https://discord.com/api/v10',
		'microsoft_teams'    => 'https://smba.trafficmanager.net/apis',
		'whatsapp'           => 'https://graph.facebook.com',
		'facebook_messenger' => 'https://graph.facebook.com',
		'twitter'            => 'https://api.twitter.com',
		'google_chat'        => 'https://chat.googleapis.com',
		'office365'          => 'https://graph.microsoft.com',
		'icloud'             => 'https://www.icloud.com',
		'printful'           => 'https://api.printful.com',
		'composio'           => 'https://backend.composio.dev',
		'quickbooks'         => 'https://quickbooks.api.intuit.com',
		'linkedin'           => 'https://api.linkedin.com',
	);

	/**
	 * Field keys that identify the connection's display identity (never secrets).
	 *
	 * @var array<string>
	 */
	const NON_SECRET_FIELDS = array(
		'id',
		'name',
		'connection_type',
		'url',
		'auth_type',
		'username',
		'user_email',
		'bot_username',
		'upwork_username',
		'app_id',
		'dsn_name',
		'phone_number_id',
		'business_account_id',
		'system_user_id',
		'enabled',
		'mcp_header_name',
		'mcp_timeout',
		'mcp_verify_ssl',
		'graph_api_version',
		'base_url',
		'flowhub_mode',
		'sandbox_mode',
		'folder_id',
		'calendar_id',
		'classroom_course_id',
		'created_at',
		'updated_at',
	);

	/**
	 * The connection-type catalogue that drives both /types and the form the
	 * console renders. Field keys are the stored connection keys the manager
	 * already understands; `secret` marks password-style inputs.
	 *
	 * @var array<string,array{label:string,category:string,fields:array<string,array{label:string,secret?:bool,required?:bool,help?:string,placeholder?:string}>,help?:string}>
	 */
	private static $type_catalogue = null;

	/**
	 * Register the routes on rest_api_init (module `rest_remote_sites`).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}
		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the remote-sites routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/types',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<id>[\w-]+)/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — the wp-admin Remote Sites page is manage_options.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage connections.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * The connection-type catalogue (see self::$type_catalogue docblock).
	 *
	 * Kept as a single declarative map so the REST /types payload and the
	 * console's add-connection wizard can never drift apart. Categories map
	 * to the console's grouping chips.
	 *
	 * @since 2.4.0
	 * @return array<string,array>
	 */
	public static function type_catalogue() {
		if ( null !== self::$type_catalogue ) {
			return self::$type_catalogue;
		}

		$google_oauth = array(
			'client_id'     => array(
				'label'    => __( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ),
				'required' => true,
				'help'     => __( 'From the Google Cloud Console.', 'mcp-ai-wpoos-pro' ),
			),
			'client_secret' => array(
				'label'    => __( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ),
				'secret'   => true,
				'required' => true,
			),
			'refresh_token' => array(
				'label'    => __( 'Refresh Token', 'mcp-ai-wpoos-pro' ),
				'secret'   => true,
				'required' => true,
				'help'     => __( 'Long-lived OAuth refresh token for this account.', 'mcp-ai-wpoos-pro' ),
			),
			'user_email'    => array(
				'label' => __( 'Account Email', 'mcp-ai-wpoos-pro' ),
				'help'  => __( 'The account this token belongs to.', 'mcp-ai-wpoos-pro' ),
			),
		);

		// Field keys below are the canonical *stored* keys (the wp-admin form
		// maps type-specific input names onto these — e.g. the Telegram bot
		// token is stored under api_key), so the console wizard writes exactly
		// what every consumer (tests, webhooks, tools) already reads.
		self::$type_catalogue = array(
			'wordpress'          => array(
				'label'    => __( 'WordPress / WooCommerce', 'mcp-ai-wpoos-pro' ),
				'category' => 'website',
				'help'     => __( 'A remote WordPress site. Use an Application Password for the password field.', 'mcp-ai-wpoos-pro' ),
				'fields'   => array(
					'url'      => array(
						'label'       => __( 'Site URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://…',
					),
					'username' => array(
						'label'    => __( 'Username', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'password' => array(
						'label'    => __( 'Application Password', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'generic'            => array(
				'label'    => __( 'Generic REST API', 'mcp-ai-wpoos-pro' ),
				'category' => 'website',
				'fields'   => array(
					'url'      => array(
						'label'       => __( 'API URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://…',
					),
					'username' => array( 'label' => __( 'Username (optional)', 'mcp-ai-wpoos-pro' ) ),
					'password' => array(
						'label'  => __( 'Password (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'token'    => array(
						'label'  => __( 'Bearer / API Token (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'form_data_source'   => array(
				'label'    => __( 'Form Data Source (JFB / Elementor)', 'mcp-ai-wpoos-pro' ),
				'category' => 'website',
				'fields'   => array(
					'url' => array(
						'label'       => __( 'Site URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://…',
					),
				),
			),
			'mesh_peer'          => array(
				'label'    => __( 'Mesh Peer (Distributed AI)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ai',
				'fields'   => array(
					'url'     => array(
						'label'       => __( 'Peer URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://…',
					),
					'api_key' => array(
						'label'  => __( 'Inbound API Key', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
						'help'   => __( 'The key this peer must present to reach your site.', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'mcp_server'         => array(
				'label'    => __( 'MCP Server (Elementor MCP / WordPress MCP Adapter)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ai',
				'help'     => __( 'Any Streamable HTTP MCP endpoint. Elementor MCP endpoints live at /wp-json/mcp/<slug>.', 'mcp-ai-wpoos-pro' ),
				'fields'   => array(
					'url'      => array(
						'label'       => __( 'Endpoint URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://…/wp-json/mcp/…',
					),
					'username' => array( 'label' => __( 'Username (optional)', 'mcp-ai-wpoos-pro' ) ),
					'password' => array(
						'label'  => __( 'Password / App Password (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'token'    => array(
						'label'  => __( 'Bearer Token (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'composio'           => array(
				'label'    => __( 'Composio Connect (AI Tool Aggregator)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ai',
				'fields'   => array(
					'api_key' => array(
						'label'       => __( 'Composio API Key', 'mcp-ai-wpoos-pro' ),
						'secret'      => true,
						'required'    => true,
						'placeholder' => 'ak_…',
					),
				),
			),
			'gmail'              => array(
				'label'    => __( 'Gmail (Email Service)', 'mcp-ai-wpoos-pro' ),
				'category' => 'email',
				'fields'   => $google_oauth,
			),
			'office365'          => array(
				'label'    => __( 'Office 365 (Outlook / OneDrive)', 'mcp-ai-wpoos-pro' ),
				'category' => 'email',
				'fields'   => array(
					'client_id'     => array(
						'label'    => __( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'client_secret' => array(
						'label'    => __( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'google_drive'       => array(
				'label'    => __( 'Google Drive (Cloud Storage)', 'mcp-ai-wpoos-pro' ),
				'category' => 'google',
				'fields'   => $google_oauth,
			),
			'google_calendar'    => array(
				'label'    => __( 'Google Calendar (Scheduling)', 'mcp-ai-wpoos-pro' ),
				'category' => 'google',
				'fields'   => $google_oauth,
			),
			'google_classroom'   => array(
				'label'    => __( 'Google Classroom (Education)', 'mcp-ai-wpoos-pro' ),
				'category' => 'google',
				'fields'   => $google_oauth,
			),
			'telegram'           => array(
				'label'    => __( 'Telegram (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'      => array(
						'label'    => __( 'Bot Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
						'help'     => __( 'From @BotFather.', 'mcp-ai-wpoos-pro' ),
					),
					'bot_username' => array(
						'label'       => __( 'Bot Username', 'mcp-ai-wpoos-pro' ),
						'placeholder' => 'my_bot',
					),
				),
			),
			'whatsapp'           => array(
				'label'    => __( 'WhatsApp Business (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'             => array(
						'label'    => __( 'Access Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'phone_number_id'     => array( 'label' => __( 'Phone Number ID', 'mcp-ai-wpoos-pro' ) ),
					'business_account_id' => array( 'label' => __( 'Business Account ID', 'mcp-ai-wpoos-pro' ) ),
					'app_id'              => array( 'label' => __( 'App ID', 'mcp-ai-wpoos-pro' ) ),
					'api_secret'          => array(
						'label'  => __( 'App Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'verify_token'        => array(
						'label'  => __( 'Webhook Verify Token', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'slack'              => array(
				'label'    => __( 'Slack (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'        => array(
						'label'    => __( 'Bot User OAuth Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'signing_secret' => array(
						'label'  => __( 'Signing Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
						'help'   => __( 'Verifies events are really from Slack.', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'discord'            => array(
				'label'    => __( 'Discord (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key' => array(
						'label'    => __( 'Bot Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'microsoft_teams'    => array(
				'label'    => __( 'Microsoft Teams (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'client_id'      => array( 'label' => __( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ) ),
					'client_secret'  => array(
						'label'  => __( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'signing_secret' => array(
						'label'    => __( 'Outgoing Webhook Security Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
						'help'     => __( 'HMAC key from the Teams Admin Center.', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'facebook_messenger' => array(
				'label'    => __( 'Facebook Messenger (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'    => array(
						'label'    => __( 'Page Access Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret' => array(
						'label'  => __( 'App Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'app_id'     => array( 'label' => __( 'App ID', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			'webchat'            => array(
				'label'    => __( 'WebChat P2P (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'help'     => __( 'Runs on your own site — no credentials needed.', 'mcp-ai-wpoos-pro' ),
				'fields'   => array(),
			),
			'google_chat'        => array(
				'label'    => __( 'Google Chat (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'      => array(
						'label'    => __( 'Service Account Key (JSON)', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'verify_token' => array(
						'label'  => __( 'Verification Token (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'twitter'            => array(
				'label'    => __( 'Twitter / X (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'       => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret'    => array(
						'label'    => __( 'API Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'client_id'     => array(
						'label'  => __( 'Access Token', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'client_secret' => array(
						'label'  => __( 'Access Token Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'apple_messages'     => array(
				'label'    => __( 'Apple Messages for Business (Chat Channel)', 'mcp-ai-wpoos-pro' ),
				'category' => 'chat',
				'fields'   => array(
					'api_key'    => array(
						'label'  => __( 'MSP API Key', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'api_secret' => array(
						'label'  => __( 'Webhook Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'icloud'             => array(
				'label'    => __( 'iCloud Drive (Cloud Storage)', 'mcp-ai-wpoos-pro' ),
				'category' => 'storage',
				'fields'   => array(
					'api_key' => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'shopify'            => array(
				'label'    => __( 'Shopify (E-Commerce Platform)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ecommerce',
				'fields'   => array(
					'url'        => array(
						'label'       => __( 'Store URL', 'mcp-ai-wpoos-pro' ),
						'required'    => true,
						'placeholder' => 'https://your-store.myshopify.com',
					),
					'api_key'    => array(
						'label'    => __( 'Admin API Access Token', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret' => array(
						'label'  => __( 'Storefront Token (optional)', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'printful'           => array(
				'label'    => __( 'Printful (Print-on-Demand)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ecommerce',
				'fields'   => array(
					'api_key' => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'shipengine'         => array(
				'label'    => __( 'ShipStation API (Recommended)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ecommerce',
				'fields'   => array(
					'api_key' => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'shipstation'        => array(
				'label'    => __( 'ShipStation V1 API (Legacy)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ecommerce',
				'fields'   => array(
					'api_key'    => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret' => array(
						'label'    => __( 'API Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
				),
			),
			'payhere'            => array(
				'label'    => __( 'PayHere (Payment Gateway)', 'mcp-ai-wpoos-pro' ),
				'category' => 'ecommerce',
				'fields'   => array(
					'api_key' => array(
						'label'    => __( 'Merchant ID', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'token'   => array(
						'label'  => __( 'Merchant Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'isams'              => array(
				'label'    => __( 'iSAMS (School Management)', 'mcp-ai-wpoos-pro' ),
				'category' => 'business',
				'fields'   => array(
					'url'        => array(
						'label'    => __( 'API URL', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'api_key'    => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret' => array(
						'label'  => __( 'API Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'flowhub'            => array(
				'label'    => __( 'Flowhub (POS/Retail)', 'mcp-ai-wpoos-pro' ),
				'category' => 'business',
				'fields'   => array(
					'api_key'   => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'client_id' => array( 'label' => __( 'Client ID', 'mcp-ai-wpoos-pro' ) ),
					'url'       => array( 'label' => __( 'API URL (optional)', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			'quickbooks'         => array(
				'label'    => __( 'QuickBooks (Accounting)', 'mcp-ai-wpoos-pro' ),
				'category' => 'business',
				'fields'   => array(
					'client_id'     => array(
						'label'    => __( 'Client ID', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'client_secret' => array(
						'label'    => __( 'Client Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'refresh_token' => array(
						'label'  => __( 'Refresh Token', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
				),
			),
			'quickbooks_desktop' => array(
				'label'    => __( 'QuickBooks Desktop (QODBC)', 'mcp-ai-wpoos-pro' ),
				'category' => 'business',
				'fields'   => array(
					'api_key'  => array(
						'label'  => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'dsn_name' => array( 'label' => __( 'DSN Name', 'mcp-ai-wpoos-pro' ) ),
					'url'      => array( 'label' => __( 'Server URL (optional)', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			'ezuite_erp'         => array(
				'label'    => __( 'EZuite ERP (Inventory)', 'mcp-ai-wpoos-pro' ),
				'category' => 'business',
				'fields'   => array(
					'api_key'    => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'api_secret' => array(
						'label'  => __( 'API Secret', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'url'        => array( 'label' => __( 'API URL (optional)', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			'upwork'             => array(
				'label'    => __( 'Upwork (Freelance Marketplace)', 'mcp-ai-wpoos-pro' ),
				'category' => 'freelance',
				'fields'   => array(
					'client_id'     => array(
						'label'    => __( 'API Key', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'client_secret' => array(
						'label'    => __( 'API Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'refresh_token' => array(
						'label'  => __( 'Refresh Token', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'user_email'    => array( 'label' => __( 'Account Email (optional)', 'mcp-ai-wpoos-pro' ) ),
				),
			),
			'linkedin'           => array(
				'label'    => __( 'LinkedIn (Professional Network)', 'mcp-ai-wpoos-pro' ),
				'category' => 'freelance',
				'fields'   => array(
					'client_id'     => array(
						'label'    => __( 'Client ID', 'mcp-ai-wpoos-pro' ),
						'required' => true,
					),
					'client_secret' => array(
						'label'    => __( 'Client Secret', 'mcp-ai-wpoos-pro' ),
						'secret'   => true,
						'required' => true,
					),
					'refresh_token' => array(
						'label'  => __( 'Refresh Token', 'mcp-ai-wpoos-pro' ),
						'secret' => true,
					),
					'user_email'    => array( 'label' => __( 'Account Email (optional)', 'mcp-ai-wpoos-pro' ) ),
				),
			),
		);

		return self::$type_catalogue;
	}

	/**
	 * GET /remote-sites — every connection the user owns, secrets redacted.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$manager     = 'WP_MCP_AI_Pro_Remote_Site_Manager';
		$connections = $manager::get_all_connections();

		$items = array();
		foreach ( $connections as $id => $connection ) {
			$items[] = $this->redact_connection( $id, $connection );
		}

		// Surface the base-plugin Gmail settings connection so the console
		// shows it alongside Remote Sites entries (base + pro, one list).
		$items[] = $this->get_base_gmail_item();

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => count( $items ),
			)
		);
	}

	/**
	 * GET /remote-sites/types — the connection-type catalogue.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_types( $request ) {
		$types = array();
		foreach ( self::type_catalogue() as $type_id => $definition ) {
			// Flatten the field map into the list the console wizard iterates.
			$fields = array();
			foreach ( $definition['fields'] as $field_key => $field ) {
				$field['key'] = $field_key;
				$fields[]     = $field;
			}
			$definition['fields'] = $fields;

			$types[] = array_merge(
				array( 'id' => $type_id ),
				$definition
			);
		}

		return rest_ensure_response( array( 'items' => $types ) );
	}

	/**
	 * POST /remote-sites — create a connection from the wizard payload.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$type = sanitize_key( (string) $request->get_param( 'connection_type' ) );

		$catalogue = self::type_catalogue();
		if ( ! isset( $catalogue[ $type ] ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Unknown connection type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$data = $this->build_connection_data( $request, $type );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
		if ( is_wp_error( $result ) ) {
			return $this->map_save_error( $result );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );
		return rest_ensure_response(
			array(
				'item' => $this->redact_connection( $result, is_array( $connection ) ? $connection : array() ),
			)
		);
	}

	/**
	 * POST /remote-sites/{id} — update; blank secret fields keep the stored value.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );

		$manager    = 'WP_MCP_AI_Pro_Remote_Site_Manager';
		$connection = $manager::get_connection( $id );
		if ( null === $connection ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Connection not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$type = isset( $connection['connection_type'] ) ? sanitize_key( (string) $connection['connection_type'] ) : '';
		$data = $this->build_connection_data( $request, $type, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['id'] = $id;

		$result = $manager::save_connection( $data );
		if ( is_wp_error( $result ) ) {
			return $this->map_save_error( $result );
		}

		$updated = $manager::get_connection( $id );
		return rest_ensure_response(
			array(
				'item' => $this->redact_connection( $id, is_array( $updated ) ? $updated : array() ),
			)
		);
	}

	/**
	 * DELETE /remote-sites/{id} — remove a connection (destructive).
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );

		$deleted = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $id );
		if ( ! $deleted ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Connection not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	/**
	 * POST /remote-sites/{id}/test — verify the connection against its provider.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_item( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );

		if ( self::BASE_GMAIL_ID === $id ) {
			$result = $this->test_base_gmail();
		} else {
			$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $id );
		}

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope -- REST console envelope, not a tool execute() return.
					'success' => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : __( 'Connection test completed.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Build the connection_data payload the manager's save_connection expects,
	 * allowlisted against the type catalogue so unknown keys never reach storage.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $type      Connection type id.
	 * @param bool            $is_update True when updating (skips required checks).
	 * @return array|WP_Error
	 */
	private function build_connection_data( $request, $type, $is_update = false ) {
		$catalogue = self::type_catalogue();
		$schema    = isset( $catalogue[ $type ] ) ? $catalogue[ $type ]['fields'] : array();

		$data = array(
			'connection_type' => $type,
		);

		if ( $request->has_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( (string) $request->get_param( 'name' ) ) );
		}

		$enabled = $request->get_param( 'enabled' );
		if ( null !== $enabled ) {
			$data['enabled'] = rest_sanitize_boolean( $enabled );
		}

		// Common non-secret fields every type may carry.
		foreach ( array( 'url', 'auth_type', 'username', 'mcp_header_name' ) as $common ) {
			if ( $request->has_param( $common ) ) {
				$data[ $common ] = sanitize_text_field( wp_unslash( (string) $request->get_param( $common ) ) );
			}
		}

		// Type-specific fields (secrets included — the manager encrypts them).
		foreach ( $schema as $field_key => $definition ) {
			if ( ! $request->has_param( $field_key ) ) {
				continue;
			}
			$raw = wp_unslash( $request->get_param( $field_key ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secrets must not be mangled by sanitize_text_field.
			if ( empty( $definition['secret'] ) ) {
				$data[ $field_key ] = sanitize_text_field( (string) $raw );
			} else {
				$data[ $field_key ] = is_string( $raw ) ? trim( $raw ) : '';
			}
		}

		// Provider URL auto-fill — mirror the wp-admin form so the console
		// wizard only ever asks for the provider-specific credentials.
		if ( empty( $data['url'] ) && isset( self::DEFAULT_URLS[ $type ] ) ) {
			$data['url'] = self::DEFAULT_URLS[ $type ];
		}
		if ( empty( $data['url'] ) && 'webchat' === $type ) {
			$data['url'] = home_url( '/wp-json/mcp-ai/v1/webhooks/webchat' );
		}

		// Required-field gate on create only (updates are partial by design —
		// blank secret fields must keep the stored value, not fail validation).
		if ( ! $is_update ) {
			foreach ( $schema as $field_key => $definition ) {
				if ( ! empty( $definition['required'] ) && empty( $data[ $field_key ] ) ) {
					return new WP_Error(
						'rest_invalid_param',
						sprintf(
							/* translators: %s: field label */
							__( '%s is required.', 'mcp-ai-wpoos-pro' ),
							$definition['label']
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		return $data;
	}

	/**
	 * Map a manager validation WP_Error onto a 400 REST error so the console
	 * shows the plain-language message instead of a generic 500.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Error $error Manager error.
	 * @return WP_Error
	 */
	private function map_save_error( $error ) {
		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => 400 )
		);
	}

	/**
	 * Redact a stored connection for the console list: never echo secrets,
	 * only whether credentials exist (the never-echo-back rule).
	 *
	 * @since 2.4.0
	 *
	 * @param string $id         Connection id.
	 * @param array  $connection Stored connection array.
	 * @return array
	 */
	private function redact_connection( $id, $connection ) {
		$safe = array(
			'id'              => $id,
			'source'          => 'pro',
			'has_credentials' => false,
		);

		foreach ( $connection as $key => $value ) {
			if ( in_array( $key, self::NON_SECRET_FIELDS, true ) ) {
				$safe[ $key ] = $value;
			}
			// Any stored value in a credential field counts as "has credentials".
			if ( WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( $key ) && ! empty( $value ) ) {
				$safe['has_credentials'] = true;
			}
		}

		$safe['enabled'] = ! array_key_exists( 'enabled', $connection ) || ! empty( $connection['enabled'] );

		return $safe;
	}

	/**
	 * The base-plugin Gmail settings connection, redacted.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function get_base_gmail_item() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' )
			? WP_MCP_AI_Admin_Settings::get_settings()
			: array();

		$has_credentials = ! empty( $settings['gmail_client_id'] )
			&& ! empty( $settings['gmail_refresh_token'] );

		return array(
			'id'              => self::BASE_GMAIL_ID,
			'source'          => 'base',
			'name'            => __( 'Gmail (site settings)', 'mcp-ai-wpoos-pro' ),
			'connection_type' => 'gmail',
			'url'             => 'https://gmail.googleapis.com',
			'user_email'      => ! empty( $settings['gmail_user_email'] ) ? $settings['gmail_user_email'] : '',
			'enabled'         => true,
			'has_credentials' => $has_credentials,
		);
	}

	/**
	 * Test the base-plugin Gmail settings connection through the same
	 * manager path the Remote Sites Gmail type uses.
	 *
	 * @since 2.4.0
	 * @return array|WP_Error
	 */
	private function test_base_gmail() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' )
			? WP_MCP_AI_Admin_Settings::get_settings()
			: array();

		if ( empty( $settings['gmail_client_id'] ) || empty( $settings['gmail_refresh_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_gmail_not_configured',
				__( 'Gmail is not configured in the site settings yet. Open wp-admin → NV oOS settings to add your OAuth credentials.', 'mcp-ai-wpoos-pro' )
			);
		}

		$pseudo = array(
			// The manager's validate_connection_data() runs before the
			// provider-specific test and requires a display name even for
			// pseudo-connections built from site settings.
			'name'            => __( 'Gmail (site settings)', 'mcp-ai-wpoos-pro' ),
			'connection_type' => 'gmail',
			'client_id'       => $settings['gmail_client_id'],
			'client_secret'   => isset( $settings['gmail_client_secret'] ) ? $settings['gmail_client_secret'] : '',
			'refresh_token'   => $settings['gmail_refresh_token'],
			'url'             => 'https://gmail.googleapis.com',
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $pseudo );
	}
}
