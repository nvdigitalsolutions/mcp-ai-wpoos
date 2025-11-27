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
	 * Load the plugin's default tool providers.
	 */
	protected function load_default_tools() {
		$default_tools = array(
			'WP_MCP_AI_Tool_Get_Recent_Posts'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php',
			'WP_MCP_AI_Tool_Get_User_Info'               => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info.php',
			'WP_MCP_AI_Tool_Get_Site_Summary'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-summary.php',
			'WP_MCP_AI_Tool_Get_Woo_Orders'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php',
			'WP_MCP_AI_Tool_Get_JetEngine_Items'         => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php',
			'WP_MCP_AI_Tool_List_JetEngine_Routes'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php',
			'WP_MCP_AI_Tool_Invoke_JetEngine_Route'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php',
			'WP_MCP_AI_Tool_Run_OpenAI_External_Action'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php',
			'WP_MCP_AI_Tool_Run_Crawl4AI_Job'            => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php',
			// Construction & Interior Design Tools.
			'WP_MCP_AI_Tool_CAD_Drawing_Generator'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cad-drawing-generator.php',
			'WP_MCP_AI_Tool_AI_Rendering_Assistant'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-ai-rendering-assistant.php',
			'WP_MCP_AI_Tool_Material_Color_Recommendations' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-material-color-recommendations.php',
			'WP_MCP_AI_Tool_3D_Model_Generator'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-3d-model-generator.php',
			'WP_MCP_AI_Tool_Cost_Estimation'             => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cost-estimation.php',
			// Logo & Vector Design Tools.
			'WP_MCP_AI_Tool_Logo_Generator'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-logo-generator.php',
			'WP_MCP_AI_Tool_Vector_Design_Assistant'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-vector-design-assistant.php',
			'WP_MCP_AI_Tool_Brand_Identity'              => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-brand-identity.php',
			'WP_MCP_AI_Tool_Icon_Set_Generator'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-icon-set-generator.php',
			// Newsletter Tools.
			'WP_MCP_AI_Tool_Newsletter_Add_Subscriber'   => WP_MCP_AI_PATH . 'includes/tools/newsletter/class-wp-mcp-ai-tool-newsletter-add-subscriber.php',
			'WP_MCP_AI_Tool_Newsletter_Get_Subscribers'  => WP_MCP_AI_PATH . 'includes/tools/newsletter/class-wp-mcp-ai-tool-newsletter-get-subscribers.php',
			'WP_MCP_AI_Tool_Newsletter_Send_Email'       => WP_MCP_AI_PATH . 'includes/tools/newsletter/class-wp-mcp-ai-tool-newsletter-send-email.php',
			'WP_MCP_AI_Tool_Newsletter_Get_Stats'        => WP_MCP_AI_PATH . 'includes/tools/newsletter/class-wp-mcp-ai-tool-newsletter-get-stats.php',
			'WP_MCP_AI_Tool_Newsletter_Manage_Campaigns' => WP_MCP_AI_PATH . 'includes/tools/newsletter/class-wp-mcp-ai-tool-newsletter-manage-campaigns.php',
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
